<?php
/**
 * Atomic page cache filesystem store.
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
		return Paths::pages() . '/' . substr( $hash, 0, 2 ) . '/' . $hash . '.meta.php';
	}

	/**
	 * @param array<string, int|string> $metadata Metadata.
	 */
	public function write( string $hash, string $html, array $metadata ): bool {
		$directory = dirname( $this->pagePath( $hash ) );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$token     = wp_generate_uuid4();
		$page_temp = $this->pagePath( $hash ) . '.' . $token . '.tmp';
		$meta_temp = $this->metaPath( $hash ) . '.' . $token . '.tmp';
		$meta      = "<?php\nreturn " . var_export( $metadata, true ) . ";\n";

		if ( false === file_put_contents( $page_temp, $html, LOCK_EX ) ) {
			return false;
		}

		if ( false === file_put_contents( $meta_temp, $meta, LOCK_EX ) ) {
			@unlink( $page_temp );
			return false;
		}

		if ( ! rename( $page_temp, $this->pagePath( $hash ) ) || ! rename( $meta_temp, $this->metaPath( $hash ) ) ) {
			@unlink( $page_temp );
			@unlink( $meta_temp );
			return false;
		}

		return true;
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
				$count += @unlink( $item->getPathname() ) ? 1 : 0;
			} elseif ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			}
		}

		return $count;
	}
}
