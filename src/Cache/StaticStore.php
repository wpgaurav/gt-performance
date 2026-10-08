<?php
/**
 * Copies of stored pages that the web server serves without starting PHP.
 *
 * The page store is keyed by a SHA-256 of the request, which a rewrite rule
 * cannot compute, so a servable page also gets a copy at a path the rule can
 * build from the request itself:
 *
 *     static/{host}/{path}/index.html     (+ index.html.gz, index.html.br)
 *
 * The rules in ServerRules only ever see the request, never this plugin's
 * metadata, so a copy is written only where serving it blind is as safe as the
 * drop-in's answer would be:
 *
 * - the site's own scheme and a canonical host without a port;
 * - no kept query string, and a path ending in "/" made only of characters a
 *   rewrite rule and a filesystem agree on (no dot segments, no encoding);
 * - the shared (not the mobile) copy;
 * - no replayed response header except Link. A page that sends its own CSP,
 *   HSTS, framing policy, or a noindex X-Robots-Tag from PHP keeps going through
 *   the drop-in, which replays them; a static hit could not.
 *
 * Anything a rule cannot check (freshness, the generation, safe mode) is enforced
 * by deleting copies: on purge, on expiry, when the generation advances, when the
 * feature or the cache is switched off, and under safe mode.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\AtomicFile;
use GTPerformance\Core\Paths;

final class StaticStore {
	/** A path made of plain segments that cannot start with a dot. Mirrored in ServerRules. */
	public const PATH_PATTERN = '#^/(?:[A-Za-z0-9_~-][A-Za-z0-9_.~-]*/)*$#';

	/** Records the generation the copies were written under. */
	private const MARKER = '.generation';

	public function root(): string {
		return Paths::siteRoot() . '/static';
	}

	/**
	 * Where a URL's copy lives, or null when a rule could not serve it safely.
	 */
	public function pathFor( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['query'] ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path = (string) ( $parts['path'] ?? '/' );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+$/', $host ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== $home ) {
			return null;
		}
		if ( ! in_array( $host, \GTPerformance\Core\Settings::canonicalHosts(), true ) || 1 !== preg_match( self::PATH_PATTERN, $path ) ) {
			return null;
		}

		return $this->root() . '/' . $host . $path . 'index.html';
	}

	/**
	 * Whether the replayed response headers allow a static copy.
	 *
	 * @param list<string> $headers Header lines a hit replays.
	 */
	public static function headersAllow( array $headers ): bool {
		foreach ( $headers as $line ) {
			if ( 0 !== stripos( (string) $line, 'link:' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, string> $copies Content-Encoding token => compressed body.
	 * @param list<string>          $headers Replayed header lines of the response.
	 */
	public function write( string $url, string $html, array $copies, array $headers ): bool {
		$path = $this->pathFor( $url );
		if ( null === $path || ! self::headersAllow( $headers ) || ! Paths::cacheRootIsSafe() ) {
			return false;
		}
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}
		if ( ! is_file( $this->root() . '/.htaccess' ) ) {
			$this->harden();
		}

		// The rules serve a compressed copy only beside its page, so remove the old
		// ones before the page changes and write the new ones after it.
		$this->deleteEncoded( $path );
		if ( ! AtomicFile::write( $path, $html, 0644 ) ) {
			return false;
		}
		foreach ( $copies as $encoding => $body ) {
			$extension = FileStore::ENCODINGS[ $encoding ] ?? null;
			if ( null !== $extension ) {
				AtomicFile::write( $path . $extension, $body, 0644 );
			}
		}

		return true;
	}

	public function deleteUrl( string $url ): bool {
		$path = $this->pathFor( $url );
		if ( null === $path ) {
			return false;
		}
		// Compressed copies first: the rules require the page itself to exist.
		$hit = $this->deleteEncoded( $path );
		if ( is_file( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge is expected.
			$hit = true;
		}

		return $hit;
	}

	private function deleteEncoded( string $path ): bool {
		$hit = false;
		foreach ( FileStore::ENCODINGS as $extension ) {
			if ( is_file( $path . $extension ) && @unlink( $path . $extension ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge is expected.
				$hit = true;
			}
		}

		return $hit;
	}

	/**
	 * Remove every copy of this site. Compressed copies go first, for the same
	 * reason as in deleteUrl().
	 */
	public function purgeAll(): int {
		$root = $this->root();
		if ( ! is_dir( $root ) || is_link( $root ) || ! Paths::cacheRootIsSafe() ) {
			return 0;
		}

		$pages = array();
		$count = 0;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$name = $item->getFilename();
			if ( $item->isLink() || $item->isFile() ) {
				if ( '.htaccess' === $name || ( self::MARKER === $name && dirname( $item->getPathname() ) === $root ) ) {
					continue;
				}
				if ( 'index.html' === $name ) {
					$pages[] = $item->getPathname();
					continue;
				}
				$count += @unlink( $item->getPathname() ) ? 1 : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge is expected.
			}
		}
		foreach ( $pages as $page ) {
			$count += @unlink( $page ) ? 1 : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge is expected.
		}
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Non-empty directories are skipped by design.
			}
		}

		return $count;
	}

	/**
	 * Keep the copies in step with the compiled configuration: none when the
	 * feature or the cache is off, none from an older generation.
	 *
	 * Runs wherever the site compiles, which is always in that site's own context.
	 */
	public function sync( bool $enabled, int $generation ): void {
		$root = $this->root();
		if ( ! $enabled ) {
			if ( is_dir( $root ) ) {
				$this->purgeAll();
				@unlink( $root . '/' . self::MARKER ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May not exist.
			}
			return;
		}

		$marker = $root . '/' . self::MARKER;
		$stored = is_file( $marker ) ? trim( (string) @file_get_contents( $marker ) ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Absent on first use.
		if ( (string) $generation === $stored ) {
			return;
		}
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return;
		}
		$this->purgeAll();
		AtomicFile::write( $marker, (string) $generation, 0600 );
	}

	/**
	 * Headers and encodings for files served from this tree on Apache and
	 * LiteSpeed Enterprise. OpenLiteSpeed ignores .htaccess; its rules only ever
	 * serve the plain page, which it compresses itself.
	 */
	public function harden( ?int $browserTtl = null, ?bool $mobile = null ): void {
		$root = $this->root();
		if ( ! is_dir( $root ) ) {
			return;
		}
		$ttl  = $browserTtl ?? max( 0, (int) \GTPerformance\Core\Settings::get( 'cache.browser_ttl', 300 ) );
		$vary = ( $mobile ?? (bool) \GTPerformance\Core\Settings::get( 'cache.separate_mobile', false ) ) ? 'Accept-Encoding, User-Agent' : 'Accept-Encoding';
		$rules = "# GT Performance: stored pages served by the web server.\n"
			. "<IfModule mod_mime.c>\n"
			. "\tAddType text/html .gz .br\n"
			. "\tAddEncoding gzip .gz\n"
			. "\tAddEncoding br .br\n"
			. "</IfModule>\n"
			. "<IfModule mod_setenvif.c>\n"
			. "\tSetEnvIfNoCase Request_URI \"\\.(gz|br)$\" no-gzip no-brotli\n"
			. "</IfModule>\n"
			. "<IfModule mod_headers.c>\n"
			. "\tHeader set Cache-Control \"public, max-age=" . $ttl . "\"\n"
			. "\tHeader merge Vary \"" . $vary . "\"\n"
			. "\tHeader set X-GT-Cache \"STATIC\"\n"
			. "</IfModule>\n";
		$file = $root . '/.htaccess';
		if ( ! is_file( $file ) || (string) @file_get_contents( $file ) !== $rules ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Absent on first use.
			AtomicFile::write( $file, $rules, 0644 );
		}
	}
}
