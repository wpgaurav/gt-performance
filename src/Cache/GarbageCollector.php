<?php
/**
 * Bounded reclamation of everything the plugin writes to disk.
 *
 * Nothing ever deleted a cache entry. `generation` is part of the cache key and
 * Settings::sanitize() advances it on a cache-relevant change, so one such change
 * made the entire store unreachable and it stayed on disk forever. Measured on a
 * live 5,000-page site: 2,162 files and 285 MB, of which 33 files were reachable.
 *
 * Shared hosting bills inodes, not bytes. A SiteGround StartUp account allows
 * 20,000 files in total and this store writes two per cached variant, so an
 * unbounded cache can suspend the account it was installed to speed up.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;

final class GarbageCollector {
	public const HOOK = 'gt_performance_collect_garbage';

	/** Files to examine in one pass, so the sweep cannot outrun a PHP time limit. */
	private const SCAN_LIMIT = 4000;

	/** Entries to keep before the oldest are evicted, unless configured otherwise. */
	private const DEFAULT_ENTRY_BUDGET = 5000;

	/** Generated CSS/JS/font artifacts are reclaimed once no render has linked them for this long. */
	private const ARTIFACT_MAX_AGE = 14 * DAY_IN_SECONDS;

	/**
	 * When this site's renders began refreshing the files they link. A file written
	 * before then was never refreshed, so its age counts from this moment instead.
	 */
	public const KEEPALIVE_SINCE_OPTION = 'gt_performance_artifact_keepalive_since';


	public function __construct(
		private readonly Logger $logger = new Logger(),
		private readonly FileStore $store = new FileStore(),
	) {
	}

	/**
	 * @return array<string, int>
	 */
	public function collect(): array {
		$stats = array(
			'expired'   => 0,
			'orphaned'  => 0,
			'evicted'   => 0,
			'artifacts' => 0,
			'scanned'   => 0,
			'remaining' => 0,
		);

		$generation = (int) Settings::get( 'generation', 1 );
		$budget     = max( 0, (int) Settings::get( 'cache.entry_budget', self::DEFAULT_ENTRY_BUDGET ) );
		$now        = time();
		$survivors  = array();

		foreach ( $this->entries() as $hash => $metadata ) {
			if ( $stats['scanned'] >= self::SCAN_LIMIT ) {
				break;
			}
			++$stats['scanned'];

			// An entry written under an older generation can never be read again: the
			// generation is part of the key, so nothing will ever compute this hash.
			if ( (int) ( $metadata['generation'] ?? 0 ) !== $generation ) {
				$this->store->delete( $hash );
				++$stats['orphaned'];
				continue;
			}

			$staleUntil = (int) ( $metadata['stale_until'] ?? 0 );
			if ( $staleUntil > 0 && $now > $staleUntil ) {
				$this->store->delete( $hash );
				++$stats['expired'];
				continue;
			}

			$survivors[ $hash ] = (int) ( $metadata['stored_at'] ?? 0 );
		}

		// Enforce the ceiling last, so expiry and orphan removal get the chance to
		// free space before anything still-valid is evicted.
		if ( $budget > 0 && count( $survivors ) > $budget ) {
			asort( $survivors );
			foreach ( array_slice( array_keys( $survivors ), 0, count( $survivors ) - $budget ) as $hash ) {
				$this->store->delete( $hash );
				unset( $survivors[ $hash ] );
				++$stats['evicted'];
			}
		}

		$stats['remaining']  = count( $survivors );
		$stats['artifacts']  = $this->collectArtifacts( $now );
		Logger::removeLegacyFiles();

		if ( $stats['expired'] || $stats['orphaned'] || $stats['evicted'] || $stats['artifacts'] ) {
			$this->logger->log( 'debug', 'Garbage collection completed', $stats );
		}

		return $stats;
	}

	/**
	 * Cached entries and their metadata, one at a time.
	 *
	 * A generator rather than an array: the caller stops at SCAN_LIMIT, and building
	 * the whole list first would defeat the bound on a store with 100,000 files.
	 *
	 * @return \Generator<string, array<string, mixed>>
	 */
	private function entries(): \Generator {
		$root = Paths::pages();
		if ( ! is_dir( $root ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
				continue;
			}

			$name = $item->getFilename();
			if ( ! str_ends_with( $name, '.meta.json' ) ) {
				// Clean up temporary siblings a crashed write left behind.
				if ( str_ends_with( $name, '.tmp' ) && ( time() - (int) $item->getMTime() ) > HOUR_IN_SECONDS ) {
					@unlink( $item->getPathname() );
				}
				continue;
			}

			$raw = @file_get_contents( $item->getPathname() );
			if ( ! is_string( $raw ) ) {
				continue;
			}

			$metadata = json_decode( $raw, true );
			if ( ! is_array( $metadata ) ) {
				// Unreadable metadata means the entry can never be validated again.
				@unlink( $item->getPathname() );
				continue;
			}

			yield substr( $name, 0, -strlen( '.meta.json' ) ) => $metadata;
		}
	}

	/**
	 * Reclaim generated CSS, JavaScript and font files no render has linked lately.
	 *
	 * Artifact filenames are content hashes, so a stale one is never referenced by
	 * freshly generated HTML. Every render refreshes the modification time of the
	 * files it links (ArtifactKeepAlive), so a file whose modification time is older
	 * than the longest a page can be served is linked by nothing. Access time is not
	 * consulted: a nightly backup reads every file, and under relatime that kept
	 * every artifact looking used, so nothing was ever reclaimed.
	 */
	private function collectArtifacts( int $now ): int {
		$root = Paths::assets();
		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$cutoff = $now - $this->artifactMaxIdle();
		if ( $this->keepAliveSince( $now ) >= $cutoff ) {
			return 0;
		}

		$fonts = $root . '/fonts';
		$inUse = $this->fontsInUse( $fonts, $cutoff );

		$removed = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
				continue;
			}

			$name = $item->getFilename();
			if ( in_array( $name, array( 'index.html', '.htaccess' ), true ) || (int) $item->getMTime() >= $cutoff ) {
				continue;
			}

			// A font file is named by a localized stylesheet, never by a page, so it is
			// in use for as long as a stylesheet this pass keeps still names it.
			if ( $fonts === $item->getPath() && ! str_ends_with( $name, '.css' ) && ( null === $inUse || isset( $inUse[ $name ] ) ) ) {
				continue;
			}

			if ( @unlink( $item->getPathname() ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * When renders began refreshing the files they link, recorded on the first pass.
	 *
	 * Files written before this release were never refreshed, so on upgrade every one
	 * would look abandoned, including those a cached page still links. Counting their
	 * age from this moment gives every live page the full window to refresh its own.
	 */
	private function keepAliveSince( int $now ): int {
		$since = (int) get_option( self::KEEPALIVE_SINCE_OPTION, 0 );
		if ( $since <= 0 ) {
			$since = $now;
			update_option( self::KEEPALIVE_SINCE_OPTION, $since, false );
		}

		return $since;
	}

	/**
	 * How long a file may go without a render linking it before it is reclaimed.
	 *
	 * A render leaves each file it links at most ArtifactKeepAlive::INTERVAL older
	 * than itself, and the page it produced can then be served from this store, a
	 * browser, and the Cloudflare edge for their configured lifetimes. Defaults add up
	 * to about four days, so ARTIFACT_MAX_AGE governs unless a site keeps pages longer.
	 */
	private function artifactMaxIdle(): int {
		$settings = Settings::all();
		$cache    = is_array( $settings['cache'] ?? null ) ? $settings['cache'] : array();
		$served   = 0;
		foreach ( array( 'fresh_ttl', 'stale_ttl', 'stale_if_error', 'browser_ttl' ) as $key ) {
			$served += max( 0, (int) ( $cache[ $key ] ?? 0 ) );
		}

		$cloudflare = is_array( $settings['cloudflare'] ?? null ) ? $settings['cloudflare'] : array();
		if ( ! empty( $cloudflare['enabled'] ) ) {
			$served += max( 0, (int) ( $cloudflare['edge_ttl'] ?? 0 ) );
		}

		// A day of margin for a collection run or a clock that is late.
		return max( self::ARTIFACT_MAX_AGE, $served + ArtifactKeepAlive::INTERVAL + DAY_IN_SECONDS );
	}

	/**
	 * Font files named by the localized stylesheets this pass keeps, or null when one
	 * of them cannot be read and no font file can safely be called unused.
	 *
	 * @return array<string, true>|null
	 */
	private function fontsInUse( string $directory, int $cutoff ): ?array {
		$named       = array();
		$stylesheets = glob( $directory . '/*.css' );
		foreach ( is_array( $stylesheets ) ? $stylesheets : array() as $stylesheet ) {
			$modified = @filemtime( $stylesheet );
			if ( false !== $modified && $modified < $cutoff ) {
				continue;
			}

			$css = @file_get_contents( $stylesheet );
			if ( ! is_string( $css ) ) {
				return null;
			}

			if ( preg_match_all( '#/fonts/([a-f0-9]{64}\.(?:woff2|woff|otf|ttf))\b#', $css, $matches ) ) {
				foreach ( $matches[1] as $file ) {
					$named[ $file ] = true;
				}
			}
		}

		return $named;
	}
}
