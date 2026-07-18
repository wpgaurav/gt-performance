<?php
/*
Plugin Name: GT Performance
Plugin URI: https://gauravtiwari.org/
Description: Safe WordPress page caching, server-side optimization, Cloudflare orchestration, and commerce-aware performance controls.
Version: 0.1.0-alpha.1
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

define( 'GTP_VERSION', '0.1.0-alpha.1' );
define( 'GTP_FILE', __FILE__ );
define( 'GTP_DIR', __DIR__ );
define( 'GTP_BASENAME', plugin_basename( __FILE__ ) );

$gtp_vendor = GTP_DIR . '/vendor/autoload.php';
if ( is_readable( $gtp_vendor ) ) {
	require_once $gtp_vendor;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'GTPerformance\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = GTP_DIR . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( \GTPerformance\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \GTPerformance\Core\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \GTPerformance\Core\Plugin::class, 'boot' ), 1 );
