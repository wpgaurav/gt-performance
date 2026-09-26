<?php
/**
 * WordPress asset delivery for transformations of a completed HTML response.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

final class BufferedAssets {
	/**
	 * Print only this generated stylesheet through WordPress's registered queue.
	 *
	 * The final document is available after wp_head, so capture the core printer
	 * and place its output where the replaced styles lived. OutputBuffer runs the
	 * transform outside PHP's output handler so nested buffers are permitted.
	 */
	public static function style( string $kind, string $css = '', string $url = '' ): string {
		$handle = 'gtperf-' . $kind . '-' . substr( hash( 'sha256', $css . '|' . $url ), 0, 16 );
		$styles = wp_styles();
		$done   = $styles->done;
		$concat = $styles->do_concat;
		$styles->do_concat = false;
		$styles->done = array_values( array_diff( $done, array( $handle ) ) );
		wp_register_style( $handle, '' === $url ? false : $url, array(), GTPERF_VERSION );
		wp_enqueue_style( $handle );
		if ( '' !== $css ) {
			// Style elements are HTML raw text: a literal closing tag ends them
			// even inside a CSS string or comment. CSS escapes preserve string/URL
			// values without introducing HTML entities that would change the CSS.
			wp_add_inline_style( $handle, str_replace( '<', '\\3C ', $css ) );
		}
		ob_start();
		try {
			wp_print_styles( array( $handle ) );
			return self::mark( (string) ob_get_contents(), $kind );
		} finally {
			ob_end_clean();
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			$styles->done = $done;
			$styles->do_concat = $concat;
		}
	}

	/** Print a bundled loader through the WordPress script API. */
	public static function script( string $name ): string {
		$handle = 'gtperf-' . $name;
		$scripts = wp_scripts();
		$concat = $scripts->do_concat;
		$scripts->do_concat = false;
		wp_register_script( $handle, plugins_url( 'assets/' . $name . '.js', GTPERF_FILE ), array(), GTPERF_VERSION, true );
		wp_enqueue_script( $handle );
		ob_start();
		try {
			wp_print_scripts( array( $handle ) );
			return self::mark( (string) ob_get_contents(), $name );
		} finally {
			ob_end_clean();
			wp_dequeue_script( $handle );
			$scripts->do_concat = $concat;
		}
	}

	/** Keep the optimizer's ownership marker on markup produced by WordPress. */
	private static function mark( string $markup, string $kind ): string {
		$processor = new \WP_HTML_Tag_Processor( $markup );
		while ( $processor->next_tag() ) {
			if ( in_array( $processor->get_tag(), array( 'STYLE', 'LINK', 'SCRIPT' ), true ) ) {
				$processor->set_attribute( 'data-gt-performance', $kind );
			}
		}
		return $processor->get_updated_html();
	}
}
