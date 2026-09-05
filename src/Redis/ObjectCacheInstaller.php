<?php
/**
 * Redis object-cache drop-in ownership.
 *
 * The drop-in is published with an atomic same-filesystem rename so a request
 * can never include a half-written file, which WP_Filesystem cannot guarantee.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
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
	/**
	 * What must match for the installed drop-in to be considered current.
	 *
	 * The version alone is not enough: a drop-in whose contents change without a
	 * version bump would never be republished, and the stale copy keeps running.
	 * That is not hypothetical — it kept a broken unserialize() live on a site after
	 * the corrected build had already been installed. filemtime() is one stat, which
	 * is the point: status() and installedVersion() each read the whole 20 KB file,
	 * so this ran three reads on every request.
	 */
	private static function signature(): string {
		$source = GTPERF_DIR . '/dropins/object-cache.php';

		return GTPERF_VERSION . '|' . GTPERF_DIR . '|' . ( is_file( $source ) ? (string) filemtime( $source ) : '' );
	}

	public static function syncVersion(): void {
		$signature = self::signature();
		if ( (string) get_option( self::VERSION_OPTION, '' ) === $signature ) {
			return;
		}

		$installer = new self();
		if ( 'owned' !== $installer->status() ) {
			return;
		}

		// No version comparison here. Getting past the signature check already means
		// the bundled drop-in changed, and the installed copy can carry the same
		// version string while holding different code — which is exactly how a broken
		// object cache stayed installed after the corrected build had shipped.
		// Republishing is only reached on a real change, so it costs nothing in the
		// common case.
		$result = $installer->publish();
		if ( is_wp_error( $result ) ) {
			return;
		}

		update_option( self::VERSION_OPTION, $signature, false );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'cron', 'options' );
	}

	public function install(): bool|\WP_Error {
		if ( ! class_exists( '\\Redis' ) ) {
			return new \WP_Error( 'gtperf_redis_extension', __( 'The PHP Redis extension is not installed.', 'gt-performance' ) );
		}
		if ( 'conflict' === $this->status() ) {
			return new \WP_Error( 'gtperf_redis_conflict', __( 'Another object-cache.php drop-in is already installed.', 'gt-performance' ) );
		}
		$connection = ( new ConnectionTester() )->test();
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		return $this->publish();
	}

	private function publish(): bool|\WP_Error {
		$source = GTPERF_DIR . '/dropins/object-cache.php';
		$content = file_get_contents( $source );
		if ( ! is_string( $content ) ) {
			return new \WP_Error( 'gtperf_redis_source', __( 'Unable to read the Redis object-cache drop-in.', 'gt-performance' ) );
		}

		$content = (string) preg_replace(
			'/' . preg_quote( self::SIGNATURE, '/' ) . '/',
			self::SIGNATURE . ' v' . GTPERF_VERSION,
			$content,
			1
		);
		$temp = $this->target() . '.' . wp_generate_uuid4() . '.tmp';
		if ( false === file_put_contents( $temp, $content, LOCK_EX ) || ! rename( $temp, $this->target() ) ) {
			@unlink( $temp );
			return new \WP_Error( 'gtperf_redis_install', __( 'Unable to install the Redis object-cache drop-in.', 'gt-performance' ) );
		}

		return true;
	}

	public function remove(): bool|\WP_Error {
		if ( 'owned' !== $this->status() ) {
			return new \WP_Error( 'gtperf_redis_not_owned', __( 'GT Performance does not own the object-cache drop-in.', 'gt-performance' ) );
		}

		return @unlink( $this->target() );
	}
}
