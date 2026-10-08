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
defined( 'GTPERF_VERSION' ) || define( 'GTPERF_VERSION', '1.3.0' );

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * WordPress 7.0 AI Client entry point, absent from the stubs this project pins.
	 * Returns WP_AI_Client_Prompt_Builder, whose fluent API is declared with @method.
	 *
	 * @param mixed $prompt Prompt.
	 * @return mixed
	 */
	function wp_ai_client_prompt( $prompt = null ) {
		return $prompt;
	}
}
