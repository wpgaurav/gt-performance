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

		$processor  = new \WP_HTML_Tag_Processor( $html );
		$index      = 0;
		$critical   = max( 0, (int) Settings::get( 'media.critical_images', 2 ) );
		$lazy       = (bool) apply_filters( 'gt_performance_media_lazy_load', (bool) Settings::get( 'media.lazy_load', true ) );
		$dimensions = (bool) apply_filters( 'gt_performance_media_add_dimensions', (bool) Settings::get( 'media.add_dimensions', true ) );

		while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			if ( $index < $critical ) {
				$processor->set_attribute( 'fetchpriority', 0 === $index ? 'high' : 'auto' );
				$processor->set_attribute( 'loading', 'eager' );
			} elseif ( $lazy ) {
				$processor->set_attribute( 'loading', 'lazy' );
			}

			$processor->set_attribute( 'decoding', 'async' );

			if ( $dimensions && ( ! $processor->get_attribute( 'width' ) || ! $processor->get_attribute( 'height' ) ) ) {
				$class = (string) $processor->get_attribute( 'class' );
				if ( preg_match( '/\\bwp-image-(\\d+)\\b/', $class, $matches ) ) {
					$metadata = wp_get_attachment_metadata( (int) $matches[1] );
					if ( is_array( $metadata ) && ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
						$processor->set_attribute( 'width', (string) (int) $metadata['width'] );
						$processor->set_attribute( 'height', (string) (int) $metadata['height'] );
					}
				}
			}

			++$index;
		}

		return $processor->get_updated_html();
	}
}
