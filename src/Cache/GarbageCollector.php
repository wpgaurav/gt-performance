<?php
/**
 * Bounded reclamation of everything the plugin writes to disk.
 *
 * Nothing ever deleted a cache entry. Settings::sanitize() bumps `generation` on
 * every save and `generation` is part of the cache key, so one settings change
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

	/** Generated CSS/JS/font artifacts are reclaimed once nothing has read them for this long. */
	private const ARTIFACT_MAX_AGE = 14 * DAY_IN_SECONDS;

	private const LOG_MAX_BYTES = 2 * MB_IN_BYTES;

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
		$this->rotateLog();

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
	 * Reclaim generated CSS, JavaScript and font files nothing has requested lately.
	 *
	 * Artifact filenames are content hashes, so a stale one is never referenced by
	 * freshly generated HTML. Access time is the honest signal, with modification
	 * time as the fallback on filesystems mounted noatime.
	 */
	private function collectArtifacts( int $now ): int {
		$root = Paths::assets();
		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$removed = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
				continue;
			}

			if ( in_array( $item->getFilename(), array( 'index.html', '.htaccess' ), true ) ) {
				continue;
			}

			$touched = max( (int) $item->getATime(), (int) $item->getMTime() );
			if ( ( $now - $touched ) > self::ARTIFACT_MAX_AGE && @unlink( $item->getPathname() ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Keep the diagnostic log from growing without limit.
	 *
	 * It is append-only, was never rotated, and an administrator can neither see nor
	 * clear it from the admin screens.
	 */
	private function rotateLog(): void {
		$file = Paths::logs() . '/gt-performance.log';
		if ( ! is_file( $file ) || (int) filesize( $file ) <= self::LOG_MAX_BYTES ) {
			return;
		}

		$previous = $file . '.1';
		@unlink( $previous );
		@rename( $file, $previous );
	}
}
