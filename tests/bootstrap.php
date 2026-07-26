<?php
/**
 * PHPUnit bootstrap.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0o777, true );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand_int(),
			wp_rand_int(),
			wp_rand_int(),
			wp_rand_int() & 0x0fff | 0x4000,
			wp_rand_int() & 0x3fff | 0x8000,
			wp_rand_int(),
			wp_rand_int(),
			wp_rand_int()
		);
	}

	function wp_rand_int(): int {
		return random_int( 0, 0xffff );
	}
}

// Paths::cacheRoot() derives every cache directory from WP_CONTENT_DIR. Point it
// at a scratch directory so filesystem-backed tests never touch a real site.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	$gtp_test_content = sys_get_temp_dir() . '/gt-performance-tests-' . getmypid();
	if ( ! is_dir( $gtp_test_content ) ) {
		mkdir( $gtp_test_content, 0o777, true );
	}
	define( 'WP_CONTENT_DIR', $gtp_test_content );
}

if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'gt-performance-test-auth-key' );
}
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
	define( 'SECURE_AUTH_SALT', 'gt-performance-test-secure-auth-salt' );
}
