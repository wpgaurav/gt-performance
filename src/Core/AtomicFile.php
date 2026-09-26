<?php
/**
 * Complete, atomic publication of local files.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class AtomicFile {
	/**
	 * PHP targets retain a PHP suffix throughout publication, so a web server
	 * never serves a temporary copy of wp-config.php as plain text. This helper
	 * adds no PHP code; runtime configuration uses encrypted JSON separately.
	 */
	public static function write( string $path, string $content, int $mode = 0644 ): bool {
		$suffix = str_ends_with( $path, '.php' ) ? '.php' : '.tmp';
		$temp = $path . '.gtperf-' . wp_generate_uuid4() . $suffix;
		$stream = @fopen( $temp, 'xb' );
		if ( false === $stream ) {
			return false;
		}
		try {
			if ( ! chmod( $temp, $mode ) || strlen( $content ) !== fwrite( $stream, $content ) || ! fflush( $stream ) ) {
				return false;
			}
			$closed = fclose( $stream );
			$stream = false;
			return $closed && rename( $temp, $path );
		} finally {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
			if ( is_file( $temp ) ) {
				@unlink( $temp );
			}
		}
	}
}
