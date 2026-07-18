<?php
/**
 * Redis object-cache drop-in ownership.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Redis;

final class ObjectCacheInstaller {
	private const SIGNATURE = 'GT Performance Redis object-cache drop-in';

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

	public function install(): bool|\WP_Error {
		if ( ! class_exists( '\\Redis' ) ) {
			return new \WP_Error( 'gtp_redis_extension', __( 'The PHP Redis extension is not installed.', 'gt-performance' ) );
		}
		if ( 'conflict' === $this->status() ) {
			return new \WP_Error( 'gtp_redis_conflict', __( 'Another object-cache.php drop-in is already installed.', 'gt-performance' ) );
		}

		$source = GTP_DIR . '/dropins/object-cache.php';
		$temp   = $this->target() . '.' . wp_generate_uuid4() . '.tmp';
		if ( ! copy( $source, $temp ) || ! rename( $temp, $this->target() ) ) {
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
