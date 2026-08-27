<?php
/**
 * PHPStan fallback constants.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wordpress/' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
defined( 'GTPERF_DIR' ) || define( 'GTPERF_DIR', dirname( __DIR__ ) );
defined( 'GTPERF_FILE' ) || define( 'GTPERF_FILE', dirname( __DIR__ ) . '/gt-performance.php' );
defined( 'GTPERF_BASENAME' ) || define( 'GTPERF_BASENAME', 'gt-performance/gt-performance.php' );
defined( 'GTPERF_VERSION' ) || define( 'GTPERF_VERSION', '1.0.2' );
