<?php
/** Real WordPress and MySQL/MariaDB integration fixture. Never use a customer database. */
declare(strict_types=1);

$gtperfRoot = getenv( 'GTPERF_TEST_WP_ROOT' );
if ( ! is_string( $gtperfRoot ) || ! is_file( $gtperfRoot . '/wp-load.php' ) ) {
	throw new RuntimeException( 'Set GTPERF_TEST_WP_ROOT to a disposable WordPress installation.' );
}
define( 'WP_USE_THEMES', false );
require_once $gtperfRoot . '/wp-load.php';
if ( ! str_starts_with( DB_NAME, 'gtperf_integration_' ) ) {
	throw new RuntimeException( 'Integration tests require a disposable database named gtperf_integration_*.' );
}
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! defined( 'GTPERF_VERSION' ) ) {
	define( 'GTPERF_VERSION', 'test' );
	define( 'GTPERF_DIR', dirname( __DIR__, 2 ) );
	define( 'GTPERF_FILE', GTPERF_DIR . '/gt-performance.php' );
	define( 'GTPERF_URL', plugin_dir_url( GTPERF_FILE ) );
}
// Abilities register lazily on first registry use, so the hooks must exist before any test asks.
( new \GTPerformance\Abilities\AbilitiesModule() )->register();
