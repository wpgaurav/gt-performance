<?php
/**
 * Atomic page cache filesystem store.
 *
 * Cache entries are written to temporary siblings and renamed into place so the
 * advanced-cache.php drop-in can never read a half-written page. WP_Filesystem
 * cannot guarantee the atomic same-filesystem rename these hot paths require.
 *
 * Entry metadata is inert JSON. It is read with file_get_contents() and never
 * included, so the cache never generates or executes PHP.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Paths;

final class FileStore {
	public function pagePath( string $hash ): string {
		return Paths::pages() . '/' . substr( $hash, 0, 2 ) . '/' . $hash . '.html';
	}

	public function metaPath( string $hash ): string {
		return Paths::pages() . '/' . substr( $hash, 0, 2 ) . '/' . $hash . '.meta.json';
	}

	/**
	 * @param array<string, int|string|list<string>> $metadata Metadata; `headers` holds the lines a hit replays.
	 */
	public function write( string $hash, string $html, array $metadata ): bool {
		$directory = dirname( $this->pagePath( $hash ) );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$meta = wp_json_encode( $metadata );
		if ( ! is_string( $meta ) ) {
			return false;
		}
		return \GTPerformance\Core\AtomicFile::write( $this->pagePath( $hash ), $html )
			&& \GTPerformance\Core\AtomicFile::write( $this->metaPath( $hash ), $meta );
	}

	public function delete( string $hash ): bool {
		$page = $this->pagePath( $hash );
		$meta = $this->metaPath( $hash );
		$hit  = false;

		if ( is_file( $page ) && @unlink( $page ) ) {
			$hit = true;
		}
		if ( is_file( $meta ) && @unlink( $meta ) ) {
			$hit = true;
		}

		return $hit;
	}

	/**
	 * Query variants one page may store. Each variant is a separate entry, so the
	 * cap bounds how much of the store a page's parameters can claim, and keeps
	 * random values from evicting other pages.
	 */
	public const MAX_VARIANTS = 100;

	/**
	 * Record a query variant of a page, so purging the page's URL finds it.
	 *
	 * A purge addresses a URL without its query, and a variant's key includes the
	 * query, so without this index an edited product kept serving its sorted and
	 * filtered listings until they expired, at the origin and at the edge.
	 *
	 * @param string $base scheme://host/path the variant belongs to.
	 * @param string $url  The variant's full URL, for edge purges.
	 * @return bool False when the page already has MAX_VARIANTS; the caller must not store another.
	 */
	public function addVariant( string $base, string $hash, string $url ): bool {
		$path      = $this->variantPath( $base );
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$handle = @fopen( $path, 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failure is reported by the return value.
		if ( false === $handle ) {
			return false;
		}

		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return false;
			}
			$known = $this->decodeVariants( (string) stream_get_contents( $handle ) );
			if ( isset( $known[ $hash ] ) ) {
				return true;
			}
			if ( count( $known ) >= self::MAX_VARIANTS ) {
				return false;
			}
			$known[ $hash ] = $url;
			rewind( $handle );
			ftruncate( $handle, 0 );

			return false !== fwrite( $handle, (string) wp_json_encode( $known ) );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * Delete every recorded query variant of a page.
	 *
	 * @param string $base scheme://host/path.
	 * @return list<string> URLs of the variants that were recorded, for edge purges.
	 */
	public function purgeVariants( string $base ): array {
		$path = $this->variantPath( $base );
		if ( ! is_file( $path ) ) {
			return array();
		}

		$known = $this->decodeVariants( (string) @file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge is expected, not exceptional.
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge is expected, not exceptional.
		foreach ( array_keys( $known ) as $hash ) {
			$this->delete( $hash );
		}

		return array_values( array_unique( $known ) );
	}

	/**
	 * @return array<string, string> Entry hash => variant URL.
	 */
	private function decodeVariants( string $raw ): array {
		$decoded = json_decode( $raw, true );
		$known   = array();
		foreach ( is_array( $decoded ) ? $decoded : array() as $hash => $url ) {
			if ( is_string( $hash ) && preg_match( '/^[a-f0-9]{64}$/', $hash ) && is_string( $url ) ) {
				$known[ $hash ] = $url;
			}
		}

		return $known;
	}

	private function variantPath( string $base ): string {
		return Paths::pages() . '/variants/' . hash( 'sha256', $base ) . '.json';
	}

	/**
	 * @return array<string, int|string>|null
	 */
	public function metadata( string $hash ): ?array {
		$path = $this->metaPath( $hash );
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$metadata = json_decode( $raw, true );

		return is_array( $metadata ) ? $metadata : null;
	}

	public function size( string $hash ): int {
		$path = $this->pagePath( $hash );
		$size = is_file( $path ) ? filesize( $path ) : false;

		return false === $size ? 0 : max( 0, $size );
	}

	/**
	 * Collect URLs of entries that are past fresh_until but still inside
	 * stale_until.
	 *
	 * Nothing regenerates an entry during that window on its own: the drop-in
	 * serves the stale copy and exits, so without this sweep a page keeps
	 * serving its stale body until stale_until finally expires it.
	 *
	 * @return list<string>
	 */
	public function staleUrls( int $now, int $limit ): array {
		if ( $limit <= 0 || ! is_dir( Paths::pages() ) ) {
			return array();
		}

		$urls = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( Paths::pages(), \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( count( $urls ) >= $limit ) {
				break;
			}

			if ( ! $item->isFile() || ! str_ends_with( $item->getFilename(), '.meta.json' ) ) {
				continue;
			}

			// Another worker may purge between the scan and the read.
			$raw = @file_get_contents( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge is expected, not exceptional.
			if ( ! is_string( $raw ) ) {
				continue;
			}

			$metadata = json_decode( $raw, true );
			if ( ! is_array( $metadata ) ) {
				continue;
			}

			$fresh = (int) ( $metadata['fresh_until'] ?? 0 );
			$stale = (int) ( $metadata['stale_until'] ?? 0 );
			$url   = (string) ( $metadata['url'] ?? '' );

			if ( '' === $url || $now <= $fresh || $now > $stale ) {
				continue;
			}

			$urls[] = $url;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Files that keep the store unreadable from the web. They live inside the tree
	 * this walk deletes, so a full purge used to strip the directory's own guards and
	 * leave raw cached HTML fetchable by hash on Apache until something re-hardened.
	 */
	private const GUARD_FILES = array( '.htaccess', 'index.html' );

	public function purgeAll(): int {
		$count = 0;
		if ( ! is_dir( Paths::pages() ) ) {
			return $count;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( Paths::pages(), \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				if ( in_array( $item->getFilename(), self::GUARD_FILES, true ) ) {
					continue;
				}

				$count += @unlink( $item->getPathname() ) ? 1 : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge is expected, not exceptional.
			} elseif ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Non-empty directories are skipped by design.
			}
		}

		// Subdirectories are removed wholesale, so re-assert the guards afterwards.
		Paths::harden();

		return $count;
	}
}
