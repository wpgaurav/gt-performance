<?php
/**
 * Conservative JavaScript loading optimization.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Paths;
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

			if ( '' === $src || 'module' === $type || $this->excluded( $src, $exclusions ) ) {
				continue;
			}

			if ( str_contains( $src, 'checkout' ) || str_contains( $src, 'payment' ) || str_contains( $src, 'cart' ) ) {
				continue;
			}

			if ( $minify ) {
				$local = $this->minifiedUrl( $src );
				if ( null !== $local ) {
					$processor->set_attribute( 'src', $local );
					$src = $local;
				}
			}

			if ( $delay && $this->excluded( $src, $patterns ) ) {
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
			$loader = "<script data-gt-performance=\"delay\">(()=>{let r=false;const l=()=>{if(r)return;r=true;document.querySelectorAll('script[type=\"text/gtp-delayed\"][data-gtp-src]').forEach((o,i)=>{const s=document.createElement('script');s.src=o.dataset.gtpSrc;s.async=false;s.defer=true;s.dataset.gtpOrder=String(i);o.replaceWith(s)})};['pointerdown','keydown','touchstart'].forEach(e=>addEventListener(e,l,{once:true,passive:true}));setTimeout(l,5000)})();</script>";
			$output = str_contains( $output, '</body>' )
				? str_replace( '</body>', $loader . '</body>', $output )
				: $output . $loader;
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

	private function minifiedUrl( string $url ): ?string {
		$contentUrl = content_url( '/' );
		if ( ! str_starts_with( $url, $contentUrl ) || str_contains( $url, '.min.js' ) ) {
			return null;
		}

		$relative = (string) wp_parse_url( substr( $url, strlen( $contentUrl ) ), PHP_URL_PATH );
		$source   = realpath( WP_CONTENT_DIR . '/' . ltrim( $relative, '/' ) );
		$root     = realpath( WP_CONTENT_DIR );
		if ( ! is_string( $source ) || ! is_string( $root ) || ! str_starts_with( $source, $root . DIRECTORY_SEPARATOR ) || ! is_readable( $source ) ) {
			return null;
		}
		$size = filesize( $source );
		if ( ! is_int( $size ) || $size > 2 * MB_IN_BYTES ) {
			return null;
		}

		try {
			$minifier = new \MatthiasMullie\Minify\JS( $source );
			$code     = $minifier->minify();
		} catch ( \Throwable ) {
			return null;
		}

		$directory = Paths::assets() . '/js';
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return null;
		}
		$file = hash( 'sha256', $code ) . '.js';
		if ( ! is_file( $directory . '/' . $file ) && false === file_put_contents( $directory . '/' . $file, $code, LOCK_EX ) ) {
			return null;
		}

		return content_url( '/cache/gt-performance/assets/js/' . $file );
	}
}
