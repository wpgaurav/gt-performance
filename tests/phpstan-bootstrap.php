<?php
/**
 * PHPStan fallback constants.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wordpress/' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
defined( 'GTP_DIR' ) || define( 'GTP_DIR', dirname( __DIR__ ) );
defined( 'GTP_FILE' ) || define( 'GTP_FILE', dirname( __DIR__ ) . '/gt-performance.php' );
defined( 'GTP_BASENAME' ) || define( 'GTP_BASENAME', 'gt-performance/gt-performance.php' );
defined( 'GTP_VERSION' ) || define( 'GTP_VERSION', '0.1.0-alpha.3' );
