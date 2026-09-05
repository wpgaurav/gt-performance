<?php
/**
 * Regression detector for HTML the optimization pipeline must not corrupt.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\UnusedCssOptimizer;
use GTPerformance\Optimization\HtmlDocument;
use PHPUnit\Framework\TestCase;

/**
 * The fixture carries the four constructs that a DOM round-trip is known to
 * damage: inline SVG camelCase attribute and element names, a non-ASCII CSS
 * `content` value, non-Latin body text, and a literal `</body>` inside inline
 * script bodies.
 *
 * These assertions are written against the pipeline as it behaves today, so they
 * are a regression detector rather than a description of code that does not exist
 * yet. The characterization test below deliberately asserts the current damage;
 * when HtmlDocument is replaced by WP_HTML_Tag_Processor it must be inverted, and
 * failing then is the point.
 */
final class GoldenHtmlTest extends TestCase {
	private function fixture(): string {
		$path = dirname( __DIR__ ) . '/Fixtures/golden-page.html';
		$html = file_get_contents( $path );

		self::assertIsString( $html, 'The golden fixture must be readable.' );

		return $html;
	}

	public function testUnusedCssEngineIsUnreachableWithoutTheConstant(): void {
		self::assertFalse(
			UnusedCssOptimizer::available(),
			'The unused-CSS engine must stay unreachable unless GTPERF_UNUSED_CSS is defined. '
			. 'It is the only default-reachable path into the DOM round-trip.'
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
	 * Characterization: what the DOM round-trip destroys today.
	 *
	 * Every caller of HtmlDocument is opt-in as of 1.1.0, which is why this damage
	 * is scheduled rather than urgent. Invert these assertions when the class goes.
	 */
	public function testDomRoundTripStillDamagesSvgAndNonAscii(): void {
		$document = new HtmlDocument();
		$previous = libxml_use_internal_errors( true );
		$dom      = $document->load( $this->fixture() );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		self::assertNotNull( $dom );
		$output = $document->save( $dom );
		self::assertIsString( $output );

		self::assertStringNotContainsString( 'viewBox', $output, 'Known defect: SVG camelCase is lowercased.' );
		self::assertStringContainsString( 'viewbox', $output );
		self::assertStringNotContainsString( 'नमस्ते', $output, 'Known defect: non-ASCII is entity-encoded.' );

		// The script masking does hold: inline script bodies survive verbatim, which is
		// the one corruption already fixed and must not regress.
		self::assertStringContainsString( '"</body> inside a JSON-LD string"', $output );
		self::assertStringContainsString( 'var closing = "</body>";', $output );
	}
}
