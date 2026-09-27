<?php
/**
 * HTML image loading and layout optimization.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Settings;

final class MediaOptimizer {
	public function optimize( string $html ): string {
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$hero       = $this->heroRule();
		$heroIndex  = null === $hero ? null : $this->findHero( $html, $hero );
		$processor  = new \WP_HTML_Tag_Processor( $html );
		$index      = 0;
		$critical   = max( 0, (int) Settings::get( 'media.critical_images', 2 ) );
		$preload    = null;
		$lazy       = (bool) apply_filters( 'gt_performance_media_lazy_load', (bool) Settings::get( 'media.lazy_load', true ) );
		$dimensions = (bool) apply_filters( 'gt_performance_media_add_dimensions', (bool) Settings::get( 'media.add_dimensions', true ) );

		while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			// An author, a theme, or WordPress core's own Image Prioritizer may already
			// have decided how this image loads, using information this class does not
			// have. Document order is a guess; a stated intent is not. Overwriting one
			// with the other is how a tracking pixel wins fetchpriority=high and the real
			// hero image ends up lazy.
			$hasLoading  = null !== $processor->get_attribute( 'loading' );
			$hasPriority = null !== $processor->get_attribute( 'fetchpriority' );

			if ( $index === $heroIndex ) {
				// A declared hero outranks document order, but an authored attribute still wins.
				if ( ! $hasPriority ) {
					$processor->set_attribute( 'fetchpriority', 'high' );
				}
				if ( ! $hasLoading ) {
					$processor->set_attribute( 'loading', 'eager' );
				}
				if ( null !== $hero && $hero['preload'] && 'lazy' !== strtolower( (string) $processor->get_attribute( 'loading' ) ) ) {
					$preload = array(
						'href'   => (string) $processor->get_attribute( 'src' ),
						'srcset' => (string) $processor->get_attribute( 'srcset' ),
						'sizes'  => (string) $processor->get_attribute( 'sizes' ),
					);
				}
			} elseif ( $index < $critical ) {
				if ( ! $hasPriority ) {
					// Only one resource is the most important: a found hero or a declared background.
					$processor->set_attribute( 'fetchpriority', 0 === $index && null === $heroIndex && ( null === $hero || 'url' !== $hero['target'] ) ? 'high' : 'auto' );
				}
				if ( ! $hasLoading ) {
					$processor->set_attribute( 'loading', 'eager' );
				}
			} elseif ( $lazy && ! $hasLoading ) {
				$processor->set_attribute( 'loading', 'lazy' );
			}

			if ( null === $processor->get_attribute( 'decoding' ) ) {
				$processor->set_attribute( 'decoding', 'async' );
			}

			if ( $dimensions && ( ! $processor->get_attribute( 'width' ) || ! $processor->get_attribute( 'height' ) ) ) {
				$class = (string) $processor->get_attribute( 'class' );
				if ( preg_match( '/\\bwp-image-(\\d+)\\b/', $class, $matches ) ) {
					$metadata = $this->attachmentMetadata( (int) $matches[1] );
					if ( is_array( $metadata ) && ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
						$processor->set_attribute( 'width', (string) (int) $metadata['width'] );
						$processor->set_attribute( 'height', (string) (int) $metadata['height'] );
					}
				}
			}

			++$index;
		}

		$output = $processor->get_updated_html();
		if ( null !== $hero && 'url' === $hero['target'] ) {
			$preload = array(
				'href'   => $hero['ref'],
				'srcset' => '',
				'sizes'  => '',
			);
		}

		return null === $preload || '' === $preload['href'] ? $output : self::preload( $output, $preload );
	}

	/**
	 * The hero rule for this request: the page's own setting, else the first
	 * site rule whose scope matches.
	 *
	 * @return array{scope:string,value:string,target:string,ref:string,preload:bool}|null
	 */
	private function heroRule(): ?array {
		$page = trim( PageOverrides::hero() );
		if ( '' !== $page ) {
			return HeroRules::parseLine( '* => ' . $page );
		}

		return HeroRules::match( HeroRules::parse( array_map( 'strval', (array) Settings::get( 'media.hero_rules', array() ) ) ), PageOverrides::context() );
	}

	/**
	 * Position of the hero among the document's images, if present.
	 *
	 * @param array{scope:string,value:string,target:string,ref:string,preload:bool} $hero Rule.
	 */
	private function findHero( string $html, array $hero ): ?int {
		if ( 'url' === $hero['target'] ) {
			return null;
		}
		$stem = '';
		if ( 'attachment' === $hero['target'] ) {
			$file = (string) wp_get_attachment_url( (int) $hero['ref'] );
			$path = (string) wp_parse_url( $file, PHP_URL_PATH );
			$stem = '' === $path ? '' : preg_replace( '/\.[a-z0-9]+$/i', '', $path );
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		$index     = 0;
		while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			$class = ' ' . (string) $processor->get_attribute( 'class' ) . ' ';
			$src   = (string) wp_parse_url( (string) $processor->get_attribute( 'src' ), PHP_URL_PATH );
			$found = 'class' === $hero['target']
				? str_contains( $class, ' ' . $hero['ref'] . ' ' )
				: str_contains( $class, ' wp-image-' . $hero['ref'] . ' ' ) || ( '' !== $stem && str_starts_with( $src, (string) $stem ) );
			if ( $found ) {
				return $index;
			}
			++$index;
		}

		return null;
	}

	/**
	 * Add one responsive image preload to <head>, unless the page already has it.
	 *
	 * @param array{href:string,srcset:string,sizes:string} $image Image.
	 */
	private static function preload( string $html, array $image ): string {
		$head = stripos( $html, '</head>' );
		if ( false === $head ) {
			return $html;
		}
		$path      = (string) wp_parse_url( $image['href'], PHP_URL_PATH );
		$processor = new \WP_HTML_Tag_Processor( substr( $html, 0, $head ) );
		while ( $processor->next_tag( array( 'tag_name' => 'LINK' ) ) ) {
			if ( 'preload' !== strtolower( (string) $processor->get_attribute( 'rel' ) ) ) {
				continue;
			}
			$existing = (string) $processor->get_attribute( 'href' ) . ' ' . (string) $processor->get_attribute( 'imagesrcset' );
			if ( '' !== $path && str_contains( $existing, $path ) ) {
				return $html;
			}
		}

		$link = '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( $image['href'] ) . '"'
			. ( '' !== $image['srcset'] ? ' imagesrcset="' . esc_attr( $image['srcset'] ) . '"' : '' )
			. ( '' !== $image['sizes'] ? ' imagesizes="' . esc_attr( $image['sizes'] ) . '"' : '' )
			. ' data-gt-performance="hero">' . "\n";

		return substr( $html, 0, $head ) . $link . substr( $html, $head );
	}

	/**
	 * Attachment metadata, memoized per request.
	 *
	 * A gallery of forty images issued forty uncached post-meta queries, one per
	 * image, on every cache miss.
	 *
	 * @return array<string, mixed>|false
	 */
	private function attachmentMetadata( int $attachmentId ): array|false {
		static $cache = array();

		if ( ! array_key_exists( $attachmentId, $cache ) ) {
			$cache[ $attachmentId ] = wp_get_attachment_metadata( $attachmentId );
		}

		return $cache[ $attachmentId ];
	}
}
