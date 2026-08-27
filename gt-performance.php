<?php
/*
Plugin Name: GT Performance
Plugin URI: https://gauravtiwari.org/product/gt-performance/
Description: Safe WordPress page caching, server-side optimization, Cloudflare orchestration, and commerce-aware performance controls.
Version: 1.0.3
Requires at least: 6.6
Requires PHP: 8.1
Author: Gaurav Tiwari
Author URI: https://gauravtiwari.org/
Text Domain: gt-performance
License: GPL-2.0-or-later
*/

/**
 * Plugin bootstrap.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GTPERF_VERSION', '1.0.3' );
define( 'GTPERF_FILE', __FILE__ );
define( 'GTPERF_DIR', __DIR__ );
define( 'GTPERF_BASENAME', plugin_basename( __FILE__ ) );

$gt_performance_vendor = GTPERF_DIR . '/vendor/autoload.php';
if ( is_readable( $gt_performance_vendor ) ) {
	require_once $gt_performance_vendor;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'GTPerformance\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = GTPERF_DIR . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( \GTPerformance\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \GTPerformance\Core\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \GTPerformance\Core\Plugin::class, 'boot' ), 1 );
