<?php
/**
 * Image loading hints.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\MediaOptimizer;
use PHPUnit\Framework\TestCase;

final class MediaOptimizerTest extends TestCase {
	protected function setUp(): void {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			self::markTestSkipped( 'The WordPress HTML API is required.' );
		}
	}

	public function test_the_first_image_is_prioritised_and_later_ones_are_lazy(): void {
		$html = '<img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg">';

		$out = ( new MediaOptimizer() )->optimize( $html );

		// The tag processor emits attributes in its own order, so assert per image.
		[ $a, $b, $c ] = self::images( $out );

		self::assertStringContainsString( 'fetchpriority="high"', $a );
		self::assertStringContainsString( 'loading="eager"', $a );
		self::assertStringContainsString( 'decoding="async"', $a );

		self::assertStringContainsString( 'fetchpriority="auto"', $b );
		self::assertStringContainsString( 'loading="eager"', $b );

		self::assertStringContainsString( 'loading="lazy"', $c );
		self::assertStringNotContainsString( 'fetchpriority', $c );
	}

	/**
	 * The defect: hints were assigned purely by document order, overwriting whatever
	 * the author or WordPress core's Image Prioritizer had already decided. A hidden
	 * tracking pixel first in the source won fetchpriority=high, and an explicitly
	 * eager hero further down was forced to lazy.
	 */
	public function test_an_author_set_hint_is_never_overwritten(): void {
		$html = '<img src="/pixel.gif" loading="lazy" fetchpriority="low"><img src="/hero.jpg" loading="eager" fetchpriority="high">';

		$out = ( new MediaOptimizer() )->optimize( $html );

		self::assertStringContainsString( 'src="/pixel.gif" loading="lazy" fetchpriority="low"', $out );
		self::assertStringContainsString( 'src="/hero.jpg" loading="eager" fetchpriority="high"', $out );
		self::assertStringNotContainsString( 'fetchpriority="auto"', $out );
	}

	public function test_an_explicitly_eager_image_below_the_threshold_stays_eager(): void {
		$html = '<img src="/a.jpg"><img src="/b.jpg"><img src="/late-hero.jpg" loading="eager">';

		$out = ( new MediaOptimizer() )->optimize( $html );

		self::assertStringNotContainsString( 'src="/late-hero.jpg" loading="lazy"', $out );
		self::assertStringContainsString( 'loading="eager"', $out );
	}

	/**
	 * WordPress core emits sizes="auto" on lazy images so the browser can pick a
	 * smaller candidate. Rewriting the tag must leave it in place.
	 */
	public function test_core_responsive_attributes_survive(): void {
		$html = '<img src="/a.jpg" srcset="/a-300.jpg 300w, /a-900.jpg 900w" sizes="auto, (max-width: 600px) 100vw, 600px">';

		$out = ( new MediaOptimizer() )->optimize( $html );

		self::assertStringContainsString( 'sizes="auto, (max-width: 600px) 100vw, 600px"', $out );
		self::assertStringContainsString( 'srcset="/a-300.jpg 300w, /a-900.jpg 900w"', $out );
	}

	/**
	 * @return list<string>
	 */
	private static function images( string $html ): array {
		preg_match_all( '/<img\b[^>]*>/i', $html, $matches );

		return $matches[0];
	}

	public function test_markup_that_is_not_an_image_is_untouched(): void {
		$html = '<svg viewBox="0 0 10 10"><clipPath id="c"><rect/></clipPath></svg><p>नमस्ते</p>';

		self::assertSame( $html, ( new MediaOptimizer() )->optimize( $html ) );
	}
}
