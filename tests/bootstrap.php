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

if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'gt-performance-test-auth-key' );
}
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
	define( 'SECURE_AUTH_SALT', 'gt-performance-test-secure-auth-salt' );
}
