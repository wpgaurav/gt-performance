<?php
/**
 * Declared hero images: priority, preload, and precedence.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\Optimization\MediaOptimizer;
use PHPUnit\Framework\TestCase;

final class MediaHeroTest extends TestCase {
	/** @param list<string> $rules Hero rules. */
	private function rules( array $rules, int $critical = 2 ): void {
		$settings                             = Settings::defaults();
		$settings['media']['hero_rules']      = $rules;
		$settings['media']['critical_images'] = $critical;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;
	}

	/**
	 * @return array<string, string|null> Attributes of the image whose src is given.
	 */
	private function img( string $html, string $src ): array {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'IMG' ) ) {
			if ( $src === $processor->get_attribute( 'src' ) ) {
				return array(
					'fetchpriority' => $processor->get_attribute( 'fetchpriority' ),
					'loading'       => $processor->get_attribute( 'loading' ),
				);
			}
		}
		self::fail( 'No image ' . $src );
	}

	private function page( string $body, string $head = '' ): string {
		return '<html><head>' . $head . '</head><body>' . $body . '</body></html>';
	}

	public function test_a_declared_hero_outranks_document_order(): void {
		$this->rules( array( '* => .hero' ) );
		$out = ( new MediaOptimizer() )->optimize( $this->page( '<img src="/logo.png" class="logo"><img src="/big.jpg" class="hero">' ) );

		self::assertSame( array( 'fetchpriority' => 'auto', 'loading' => 'eager' ), $this->img( $out, '/logo.png' ) );
		self::assertSame( array( 'fetchpriority' => 'high', 'loading' => 'eager' ), $this->img( $out, '/big.jpg' ) );
		self::assertStringNotContainsString( 'rel="preload"', $out, 'No preload unless the rule asks for one.' );
	}

	public function test_preload_is_responsive_single_and_respects_authored_attributes(): void {
		$this->rules( array( '* => .hero preload' ) );
		$img = '<img src="/hero-1024.jpg" srcset="/hero-640.jpg 640w, /hero-1024.jpg 1024w" sizes="100vw" class="hero">';
		$out = ( new MediaOptimizer() )->optimize( $this->page( $img ) );
		self::assertSame( 1, substr_count( $out, 'rel="preload"' ) );
		self::assertStringContainsString( 'imagesrcset="/hero-640.jpg 640w, /hero-1024.jpg 1024w" imagesizes="100vw"', $out );
		self::assertLessThan( strpos( $out, '</head>' ), strpos( $out, 'rel="preload"' ) );

		$existing = '<link rel="preload" as="image" href="/hero-1024.jpg">';
		self::assertSame( 1, substr_count( ( new MediaOptimizer() )->optimize( $this->page( $img, $existing ) ), 'rel="preload"' ), 'An existing preload is not duplicated.' );

		$authored = ( new MediaOptimizer() )->optimize( $this->page( '<img src="/hero.jpg" class="hero" fetchpriority="low" loading="lazy">' ) );
		self::assertStringContainsString( 'fetchpriority="low"', $authored );
		self::assertStringContainsString( 'loading="lazy"', $authored );
		self::assertStringNotContainsString( 'rel="preload"', $authored, 'An authored lazy hero is not preloaded.' );
	}

	public function test_background_heroes_are_declared_not_guessed(): void {
		$this->rules( array( '* => url:https://example.com/wp-content/uploads/bg.jpg' ) );
		$out = ( new MediaOptimizer() )->optimize( $this->page( '<div style="background-image:url(/other.jpg)"></div><img src="/first.jpg">' ) );

		self::assertStringContainsString( 'href="https://example.com/wp-content/uploads/bg.jpg"', $out );
		self::assertStringNotContainsString( 'other.jpg" as', $out );
		self::assertSame( array( 'fetchpriority' => 'auto', 'loading' => 'eager' ), $this->img( $out, '/first.jpg' ), 'The declared background hero alone gets high priority.' );

		$this->rules( array( '* => .missing' ) );
		$fallback = ( new MediaOptimizer() )->optimize( $this->page( '<img src="/first.jpg">' ) );
		self::assertSame( 'high', $this->img( $fallback, '/first.jpg' )['fetchpriority'], 'A rule that matches nothing falls back to document order.' );
	}
}
