<?php
/**
 * Owned output buffers.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class OutputBuffer {
	/**
	 * Start a buffer and register the call that closes it.
	 *
	 * A page cache has to hold the buffer open across the whole template render,
	 * so it cannot be closed in the function that opened it. Instead the close is
	 * registered immediately, on `shutdown` at priority 0 — ahead of core's own
	 * `wp_ob_end_flush_all()` at priority 1 — so this plugin explicitly closes
	 * every buffer it opens rather than relying on core or on PHP's implicit
	 * end-of-request flush.
	 *
	 * Buffers are closed innermost first, which is the same order PHP would use,
	 * so a CDN rewrite wrapping a page-cache capture still sees the inner result.
	 *
	 * @param callable $callback Buffer callback.
	 */
	public static function start( callable $callback ): bool {
		if ( ! ob_start( $callback ) ) {
			return false;
		}

		$level = ob_get_level();

		add_action(
			'shutdown',
			static function () use ( $level ): void {
				self::close( $level );
			},
			0
		);

		return true;
	}

	/**
	 * Close every buffer at or above the given nesting level.
	 */
	public static function close( int $level ): void {
		while ( ob_get_level() >= $level && ob_get_level() > 0 ) {
			if ( ! ob_end_flush() ) {
				// A buffer opened without the removable flag cannot be closed;
				// looping again would spin forever.
				break;
			}
		}
	}
}
