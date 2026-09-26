<?php
/**
 * Owned output buffers.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class OutputBuffer {
	/** @var array<int, callable> Owned buffer levels and their transforms. */
	private static array $callbacks = array();

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
		// Transforms call WordPress asset printers, which open their own buffers.
		// PHP forbids that inside an output-handler callback. Collect first and run
		// the transform explicitly after closing the owned buffer at shutdown.
		if ( ! ob_start() ) {
			return false;
		}

		$level = ob_get_level();
		self::$callbacks[ $level ] = $callback;

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
			$current = ob_get_level();
			if ( isset( self::$callbacks[ $current ] ) ) {
				$callback = self::$callbacks[ $current ];
				unset( self::$callbacks[ $current ] );
				$html = ob_get_clean();
				if ( false === $html ) {
					break;
				}
				try {
					$output = $callback( $html );
				} catch ( \Throwable $error ) {
					( new Logger() )->log( 'error', 'Buffered transformation failed; original HTML returned', array( 'error' => $error->getMessage() ) );
					$output = $html;
				}
				// Return the completed response through PHP's output-buffer API.
				// This is already-rendered theme/plugin HTML, including scripts,
				// forms and SVG. Whole-document KSES/HTML escaping would break it;
				// transforms must escape their own additions at insertion time.
				// Only this pure return runs inside an output handler: asset printers
				// in the transform above remain free to open nested buffers.
				ob_start( static fn( string $buffer ): string => is_string( $output ) ? $output : $html );
				ob_end_flush();
				continue;
			}
			if ( ! ob_end_flush() ) {
				// A buffer opened without the removable flag cannot be closed;
				// looping again would spin forever.
				break;
			}
		}
	}
}
