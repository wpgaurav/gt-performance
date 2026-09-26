<?php
/** Real WordPress asset registries/printers for offline optimizer tests. */

declare(strict_types=1);

if ( ! defined( 'GTPERF_FILE' ) ) {
	define( 'GTPERF_FILE', dirname( __DIR__ ) . '/gt-performance.php' );
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['gtperf_test_registered_actions'][ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int { return 1; }
}
if ( ! function_exists( 'do_action_ref_array' ) ) {
	function do_action_ref_array( string $hook, array $args ): void {}
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'https://example.com/wp-content/plugins/gt-performance/' . $path;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool { return false; }
}
if ( ! function_exists( 'current_theme_supports' ) ) {
	function current_theme_supports( string $feature, mixed ...$args ): bool { return true; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '', string $filter = 'raw' ): string { return '7.1'; }
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
$gtperf_wp_includes = dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/wp-includes';
if ( ! is_dir( ABSPATH ) ) {
	mkdir( ABSPATH, 0777, true );
}
if ( ! file_exists( ABSPATH . WPINC ) ) {
	symlink( $gtperf_wp_includes, ABSPATH . WPINC );
}
require_once $gtperf_wp_includes . '/script-loader.php';
