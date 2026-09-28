<?php
/**
 * Record the headers the cache layer sends. The CLI SAPI keeps none, so
 * headers_list() is always empty in tests. Kept apart from bootstrap.php
 * because a namespaced function needs its own namespace declaration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

if ( ! function_exists( __NAMESPACE__ . '\header' ) ) {
	/**
	 * Record a header, then send it as PHP would.
	 */
	function header( string $header, bool $replace = true, int $responseCode = 0 ): void {
		$GLOBALS['gtperf_test_cache_headers'][] = $header;
		\header( $header, $replace, $responseCode );
	}
}
