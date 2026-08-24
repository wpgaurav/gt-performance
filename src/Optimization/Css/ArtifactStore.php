<?php
/**
 * Immutable generated CSS artifacts.
 *
 * Artifacts are content-hashed files published with an atomic same-filesystem
 * rename so a page can never reference a half-written stylesheet, which
 * WP_Filesystem cannot guarantee.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

use GTPerformance\Core\Paths;

final class ArtifactStore {
	/**
	 * @return array{path:string,url:string,hash:string}
	 */
	public function write( string $css, string $suffix = 'used' ): array {
		$hash      = hash( 'sha256', $css );
		$directory = Paths::assets() . '/css';
		$filename  = $hash . '-' . sanitize_key( $suffix ) . '.css';
		$path      = $directory . '/' . $filename;

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new \RuntimeException( 'Unable to create the generated CSS directory.' );
		}

		if ( ! is_file( $path ) ) {
			$temp = $path . '.' . wp_generate_uuid4() . '.tmp';
			if ( false === file_put_contents( $temp, $css, LOCK_EX ) || ! rename( $temp, $path ) ) {
				@unlink( $temp );
				throw new \RuntimeException( 'Unable to publish generated CSS atomically.' );
			}
		}

		return array(
			'path' => $path,
			'url'  => content_url( '/cache/gt-performance/assets/css/' . $filename ),
			'hash' => $hash,
		);
	}
}
