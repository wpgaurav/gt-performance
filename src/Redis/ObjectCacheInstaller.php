<?php
/**
 * Redis object-cache drop-in ownership.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Redis;

final class ObjectCacheInstaller {
	private const SIGNATURE      = 'GT Performance Redis object-cache drop-in';
	private const VERSION_OPTION = 'gt_performance_object_cache_dropin_version';

	public function target(): string {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	public function status(): string {
		if ( ! is_file( $this->target() ) ) {
			return 'missing';
		}
		$content = file_get_contents( $this->target() );

		return is_string( $content ) && str_contains( $content, self::SIGNATURE ) ? 'owned' : 'conflict';
	}

	/**
	 * Read the plugin version stamped into an owned drop-in, or '' when absent.
	 */
	public function installedVersion(): string {
		if ( 'owned' !== $this->status() ) {
			return '';
		}

		$content = (string) file_get_contents( $this->target() );
		if ( preg_match( '/' . preg_quote( self::SIGNATURE, '/' ) . ' v([0-9A-Za-z]+(?:[.\\-][0-9A-Za-z]+)*)/', $content, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Atomically refresh an owned drop-in after plugin updates. WordPress already
	 * loaded the old drop-in for this request, so the replacement is active from
	 * the next request onward.
	 */
	public static function syncVersion(): void {
		$installer = new self();
		if ( 'owned' !== $installer->status() ) {
			return;
		}

		if ( GTP_VERSION === $installer->installedVersion() ) {
			if ( GTP_VERSION !== (string) get_option( self::VERSION_OPTION, '' ) ) {
				update_option( self::VERSION_OPTION, GTP_VERSION, false );
			}
			return;
		}

		$result = $installer->publish();
		if ( is_wp_error( $result ) ) {
			return;
		}

		update_option( self::VERSION_OPTION, GTP_VERSION, false );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'cron', 'options' );
	}

	public function install(): bool|\WP_Error {
		if ( ! class_exists( '\\Redis' ) ) {
			return new \WP_Error( 'gtp_redis_extension', __( 'The PHP Redis extension is not installed.', 'gt-performance' ) );
		}
		if ( 'conflict' === $this->status() ) {
			return new \WP_Error( 'gtp_redis_conflict', __( 'Another object-cache.php drop-in is already installed.', 'gt-performance' ) );
		}
		$connection = ( new ConnectionTester() )->test();
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		return $this->publish();
	}

	private function publish(): bool|\WP_Error {
		$source = GTP_DIR . '/dropins/object-cache.php';
		$content = file_get_contents( $source );
		if ( ! is_string( $content ) ) {
			return new \WP_Error( 'gtp_redis_source', __( 'Unable to read the Redis object-cache drop-in.', 'gt-performance' ) );
		}

		$content = (string) preg_replace(
			'/' . preg_quote( self::SIGNATURE, '/' ) . '/',
			self::SIGNATURE . ' v' . GTP_VERSION,
			$content,
			1
		);
		$temp = $this->target() . '.' . wp_generate_uuid4() . '.tmp';
		if ( false === file_put_contents( $temp, $content, LOCK_EX ) || ! rename( $temp, $this->target() ) ) {
			@unlink( $temp );
			return new \WP_Error( 'gtp_redis_install', __( 'Unable to install the Redis object-cache drop-in.', 'gt-performance' ) );
		}

		return true;
	}

	public function remove(): bool|\WP_Error {
		if ( 'owned' !== $this->status() ) {
			return new \WP_Error( 'gtp_redis_not_owned', __( 'GT Performance does not own the object-cache drop-in.', 'gt-performance' ) );
		}

		return @unlink( $this->target() );
	}
}
