<?php
/**
 * Reversible WP_CACHE constant management.
 *
 * The wp-config.php file is rewritten through a temporary sibling and an
 * atomic same-filesystem rename so a request can never load a half-written
 * file, which WP_Filesystem cannot guarantee.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class WpCacheConstant {
	private const OPTION = 'gt_performance_wp_cache_constant_ownership';
	private const LINE   = "define( 'WP_CACHE', true ); // Added by GT Performance";

	public function status(): string {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return 'enabled';
		}

		return 'disabled';
	}

	public function enable(): bool|\WP_Error {
		if ( 'enabled' === $this->status() ) {
			return true;
		}

		$path = $this->configPath();
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$content = file_get_contents( $path );
		if ( ! is_string( $content ) ) {
			return new \WP_Error( 'gtperf_wp_config_read', __( 'GT Performance could not read wp-config.php.', 'gt-performance' ) );
		}

		$declarationPattern = '~^[\t ]*(?:define\s*\(\s*([\'"])WP_CACHE\1\s*,[^\r\n]*\)\s*;|const\s+WP_CACHE\s*=[^\r\n]*;)[^\r\n]*$~im';
		if ( preg_match( $declarationPattern, $content, $matches ) ) {
			$updated   = preg_replace( $declarationPattern, self::LINE, $content, 1 );
			$ownership = array(
				'mode'     => 'changed',
				'original' => (string) $matches[0],
			);
		} elseif ( preg_match( '~(?:define\s*\(\s*([\'"])WP_CACHE\1\s*,|const\s+WP_CACHE\s*=)~i', $this->withoutComments( $content ) ) ) {
			return new \WP_Error(
				'gtperf_wp_cache_custom',
				__( 'wp-config.php contains a custom WP_CACHE declaration. Enable it manually before installing the drop-in.', 'gt-performance' )
			);
		} else {
			$updated   = preg_replace( '/^<\?php\s*/', "<?php\n" . self::LINE . "\n", $content, 1 );
			$ownership = array(
				'mode'     => 'inserted',
				'original' => '',
			);
		}

		if ( ! is_string( $updated ) || $updated === $content ) {
			return new \WP_Error( 'gtperf_wp_config_update', __( 'GT Performance could not add WP_CACHE to wp-config.php.', 'gt-performance' ) );
		}

		$result = $this->publish( $path, $updated );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( self::OPTION, $ownership, false );

		return true;
	}

	public function restore(): bool|\WP_Error {
		$stored = get_option( self::OPTION, '' );
		if ( is_array( $stored ) ) {
			$mode     = (string) ( $stored['mode'] ?? '' );
			$original = (string) ( $stored['original'] ?? '' );
		} else {
			$mode     = (string) $stored;
			$original = 'changed' === $mode ? "define( 'WP_CACHE', false );" : '';
		}

		if ( ! in_array( $mode, array( 'inserted', 'changed' ), true ) ) {
			return true;
		}

		$path = $this->configPath();
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$content = file_get_contents( $path );
		if ( ! is_string( $content ) || ! str_contains( $content, self::LINE ) ) {
			return new \WP_Error(
				'gtperf_wp_cache_restore',
				__( 'GT Performance no longer owns the WP_CACHE line, so it was left unchanged.', 'gt-performance' )
			);
		}

		$replacement = 'inserted' === $mode ? '' : $original;
		$updated     = str_replace( self::LINE, $replacement, $content );
		$result      = $this->publish( $path, $updated );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_option( self::OPTION );

		return true;
	}

	private function withoutComments( string $content ): string {
		$code = '';
		foreach ( token_get_all( $content ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	/**
	 * @return string|\WP_Error
	 */
	private function configPath(): string|\WP_Error {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( rtrim( ABSPATH, '/\\' ) ) . '/wp-config.php',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) && is_readable( $candidate ) && wp_is_writable( $candidate ) ) {
				return $candidate;
			}
		}

		return new \WP_Error(
			'gtperf_wp_config_writable',
			__( 'wp-config.php is not writable. Add define( \'WP_CACHE\', true ); manually.', 'gt-performance' )
		);
	}

	private function publish( string $path, string $content ): bool|\WP_Error {
		$temp = $path . '.gtperf-' . wp_generate_uuid4() . '.tmp';
		if ( false === file_put_contents( $temp, $content, LOCK_EX ) ) {
			return new \WP_Error( 'gtperf_wp_config_write', __( 'GT Performance could not write a temporary wp-config.php.', 'gt-performance' ) );
		}

		$permissions = fileperms( $path );
		if ( ! rename( $temp, $path ) ) {
			@unlink( $temp );
			return new \WP_Error( 'gtperf_wp_config_publish', __( 'GT Performance could not publish the wp-config.php change.', 'gt-performance' ) );
		}
		if ( is_int( $permissions ) ) {
			chmod( $path, $permissions & 0777 );
		}

		return true;
	}
}
