<?php
/**
 * Reversible WP_CACHE constant management.
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
			return new \WP_Error( 'gtp_wp_config_read', __( 'GT Performance could not read wp-config.php.', 'gt-performance' ) );
		}

		$pattern = '~define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*(?:false|0)\s*\)\s*;[^\r\n]*~i';
		if ( preg_match( $pattern, $content ) ) {
			$updated   = preg_replace( $pattern, self::LINE, $content, 1 );
			$ownership = 'changed';
		} elseif ( str_contains( $content, 'WP_CACHE' ) ) {
			return new \WP_Error(
				'gtp_wp_cache_custom',
				__( 'wp-config.php contains a custom WP_CACHE declaration. Enable it manually before installing the drop-in.', 'gt-performance' )
			);
		} else {
			$updated   = preg_replace( '/^<\?php\s*/', "<?php\n" . self::LINE . "\n", $content, 1 );
			$ownership = 'inserted';
		}

		if ( ! is_string( $updated ) || $updated === $content ) {
			return new \WP_Error( 'gtp_wp_config_update', __( 'GT Performance could not add WP_CACHE to wp-config.php.', 'gt-performance' ) );
		}

		$result = $this->publish( $path, $updated );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( self::OPTION, $ownership, false );

		return true;
	}

	public function restore(): bool|\WP_Error {
		$ownership = (string) get_option( self::OPTION, '' );
		if ( ! in_array( $ownership, array( 'inserted', 'changed' ), true ) ) {
			return true;
		}

		$path = $this->configPath();
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$content = file_get_contents( $path );
		if ( ! is_string( $content ) || ! str_contains( $content, self::LINE ) ) {
			return new \WP_Error(
				'gtp_wp_cache_restore',
				__( 'GT Performance no longer owns the WP_CACHE line, so it was left unchanged.', 'gt-performance' )
			);
		}

		$replacement = 'inserted' === $ownership ? '' : "define( 'WP_CACHE', false );";
		$updated     = str_replace( self::LINE, $replacement, $content );
		$result      = $this->publish( $path, $updated );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_option( self::OPTION );

		return true;
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
			if ( is_file( $candidate ) && is_readable( $candidate ) && is_writable( $candidate ) ) {
				return $candidate;
			}
		}

		return new \WP_Error(
			'gtp_wp_config_writable',
			__( 'wp-config.php is not writable. Add define( \'WP_CACHE\', true ); manually.', 'gt-performance' )
		);
	}

	private function publish( string $path, string $content ): bool|\WP_Error {
		$temp = $path . '.gtp-' . wp_generate_uuid4() . '.tmp';
		if ( false === file_put_contents( $temp, $content, LOCK_EX ) ) {
			return new \WP_Error( 'gtp_wp_config_write', __( 'GT Performance could not write a temporary wp-config.php.', 'gt-performance' ) );
		}

		$permissions = fileperms( $path );
		if ( ! rename( $temp, $path ) ) {
			@unlink( $temp );
			return new \WP_Error( 'gtp_wp_config_publish', __( 'GT Performance could not publish the wp-config.php change.', 'gt-performance' ) );
		}
		if ( is_int( $permissions ) ) {
			chmod( $path, $permissions & 0777 );
		}

		return true;
	}
}
