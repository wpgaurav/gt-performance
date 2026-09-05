<?php
/**
 * Regression detector for HTML the optimization pipeline must not corrupt.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\UnusedCssOptimizer;
use GTPerformance\Optimization\EmbedOptimizer;
use PHPUnit\Framework\TestCase;

/**
 * The fixture carries the four constructs that a DOM round-trip is known to
 * damage: inline SVG camelCase attribute and element names, a non-ASCII CSS
 * `content` value, non-Latin body text, and a literal `</body>` inside inline
 * script bodies.
 *
 * Nothing in the plugin parses and re-serialises a document any more, so this is a
 * regression detector: if any optimizer starts round-tripping the DOM again, these
 * constructs are the first things it will damage.
 */
final class GoldenHtmlTest extends TestCase {
	private function fixture(): string {
		$path = dirname( __DIR__ ) . '/Fixtures/golden-page.html';
		$html = file_get_contents( $path );

		self::assertIsString( $html, 'The golden fixture must be readable.' );

		return $html;
	}

	public function testTheUnusedCssEngineIsOffUnlessTurnedOn(): void {
		self::assertFalse(
			UnusedCssOptimizer::available(),
			'The engine is opt-in, and off by default.'
		);
	}

	public function testFixtureCarriesEveryConstructTheRoundTripDamages(): void {
		$html = $this->fixture();

		foreach ( array( 'viewBox', 'clipPath', 'feGaussianBlur', 'stdDeviation', 'gradientUnits' ) as $name ) {
			self::assertStringContainsString( $name, $html, 'The fixture must exercise SVG camelCase.' );
		}

		self::assertStringContainsString( 'content: "→"', $html );
		self::assertStringContainsString( 'नमस्ते', $html );
		self::assertStringContainsString( 'md:flex', $html );
		self::assertStringContainsString( '"</body> inside a JSON-LD string"', $html );
	}


	/**
	 * The point of the 1.1.0 parser migration: the embed optimizer now rewrites
	 * iframes without reserialising the document, so everything else on the page
	 * survives byte-for-byte.
	 */
	public function testEmbedOptimizerLeavesEverythingItWasNotAimedAt(): void {
		$html   = $this->fixture();
		$iframe = '<iframe width="560" src="https://www.youtube.com/embed/dQw4w9WgXcQ?start=30&amp;cc_load_policy=1" title="A talk"></iframe>'
			. '<iframe src="https://www.youtube.com/embed/videoseries?list=PL123456" title="A playlist"></iframe>';

		// Inject before the REAL closing tag, not the one inside the JSON-LD string.
		$position = strrpos( $html, '</body>' );
		self::assertIsInt( $position );
		$html = substr( $html, 0, $position ) . $iframe . substr( $html, $position );

		$method = new \ReflectionMethod( EmbedOptimizer::class, 'replaceYoutube' );
		$output = (string) $method->invoke( ( new \ReflectionClass( EmbedOptimizer::class ) )->newInstanceWithoutConstructor(), $html );

		foreach ( array( 'viewBox', 'clipPath', 'feGaussianBlur', 'stdDeviation', 'gradientUnits' ) as $name ) {
			self::assertStringContainsString( $name, $output, "SVG {$name} must survive." );
		}
		self::assertStringNotContainsString( 'viewbox', $output );
		self::assertStringContainsString( 'नमस्ते', $output, 'Non-ASCII must not be entity-encoded.' );
		self::assertStringContainsString( 'content: "→"', $output );
		self::assertStringContainsString( '"</body> inside a JSON-LD string"', $output );
		self::assertStringContainsString( 'var closing = "</body>";', $output );
		self::assertStringContainsString( 'md:flex w-1/2 2xl:block', $output );

		// And it still does its job.
		self::assertStringContainsString( 'gtp-youtube', $output );
		self::assertStringContainsString( 'start=30', $output, 'Embed parameters must be preserved.' );
		self::assertStringContainsString( 'Play video: A talk', $output, 'The iframe title becomes the button label.' );
		self::assertStringContainsString(
			'embed/videoseries?list=PL123456',
			$output,
			'A playlist has no single thumbnail, so the real embed must be left alone.'
		);
	}
}
