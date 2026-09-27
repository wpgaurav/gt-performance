<?php
/**
 * WP_CLI\Utils stand-ins. Kept apart from bootstrap.php because a namespaced
 * function needs its own namespace declaration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace WP_CLI\Utils;

if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
	/**
	 * Record the rows a command would print.
	 *
	 * @param list<array<string, mixed>> $items  Rows.
	 * @param list<string>|string        $fields Columns.
	 */
	function format_items( string $format, array $items, array|string $fields ): void {
		$GLOBALS['gtperf_test_cli_items'][] = array(
			'format' => $format,
			'items'  => $items,
			'fields' => $fields,
		);
	}
}
