<?php
/**
 * Conservative JavaScript loading optimization.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Settings;

final class JavaScriptOptimizer {
	public function optimize( string $html ): string {
		$defer  = (bool) Settings::get( 'javascript.defer', false );
		$delay  = (bool) Settings::get( 'javascript.delay', false );
		$minify = (bool) Settings::get( 'javascript.minify', false );
		if ( ( ! $defer && ! $delay && ! $minify ) || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor  = new \WP_HTML_Tag_Processor( $html );
		$exclusions = array_map( 'strval', (array) Settings::get( 'javascript.exclusions', array() ) );
		$exclusions = apply_filters( 'gt_performance_javascript_exclusions', $exclusions );
		$patterns   = array_map( 'strval', (array) Settings::get( 'javascript.delay_patterns', array() ) );
		$hasDelayed = false;

		while ( $processor->next_tag( array( 'tag_name' => 'SCRIPT' ) ) ) {
			$src  = (string) $processor->get_attribute( 'src' );
			$type = strtolower( (string) $processor->get_attribute( 'type' ) );

			if ( '' === $src || ! in_array( $type, array( '', 'text/javascript', 'application/javascript' ), true ) || $this->excluded( $src, $exclusions ) ) {
				continue;
			}

			if ( str_contains( $src, 'checkout' ) || str_contains( $src, 'payment' ) || str_contains( $src, 'cart' ) ) {
				continue;
			}

			// Match the original URL before replacing it with a signed asset URL.
			$shouldDelay = $delay && $this->excluded( $src, $patterns );
			if ( $minify && null === $processor->get_attribute( 'integrity' ) ) {
				$minified = ( new JavaScriptMinifier() )->minifiedUrl( $src );
				if ( null !== $minified ) {
					$src = $minified;
					$processor->set_attribute( 'src', $src );
				}
			}

			if ( $shouldDelay ) {
				$processor->set_attribute( 'data-gtp-src', $src );
				$processor->set_attribute( 'type', 'text/gtp-delayed' );
				$processor->remove_attribute( 'src' );
				$processor->remove_attribute( 'defer' );
				$hasDelayed = true;
				continue;
			}

			if ( $defer ) {
				$processor->set_attribute( 'defer', '' );
			}
		}

		$output = $processor->get_updated_html();
		if ( $hasDelayed ) {
			$loader   = BufferedAssets::script( 'delay' );
			$position = strripos( $output, '</body>' );
			$output   = false === $position ? $output . $loader : substr( $output, 0, $position ) . $loader . substr( $output, $position );
		}

		return $output;
	}

	/**
	 * @param list<string> $exclusions Exclusions.
	 */
	private function excluded( string $src, array $exclusions ): bool {
		foreach ( $exclusions as $exclusion ) {
			if ( '' !== $exclusion && str_contains( $src, $exclusion ) ) {
				return true;
			}
		}

		return false;
	}
}
