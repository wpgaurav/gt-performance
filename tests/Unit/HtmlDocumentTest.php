<?php
/**
 * Corruption-safe HTML round-trip tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\HtmlDocument;
use PHPUnit\Framework\TestCase;

final class HtmlDocumentTest extends TestCase {
	private function roundTrip( string $html ): string {
		$document = new HtmlDocument();
		$dom      = $document->load( $html );
		self::assertNotNull( $dom );

		$output = $document->save( $dom );
		self::assertNotNull( $output );

		return (string) $output;
	}

	public function testInlineScriptClosingTagSequencesSurviveTheRoundTrip(): void {
		$html   = '<!DOCTYPE html><html><head><title>t</title></head><body>'
			. '<script>var tpl = "<b>hi</b>"; if (a</b>c) {}</script>'
			. '<p class="x">hi</p></body></html>';
		$output = $this->roundTrip( $html );

		self::assertStringContainsString( 'if (a</b>c) {}', $output );
		self::assertStringContainsString( 'var tpl = "<b>hi</b>";', $output );
	}

	public function testJsonLdScriptContentIsPreserved(): void {
		$html   = '<!DOCTYPE html><html><head>'
			. '<script type="application/ld+json">{"@type":"</Thing>"}</script>'
			. '</head><body></body></html>';
		$output = $this->roundTrip( $html );

		self::assertStringContainsString( '{"@type":"</Thing>"}', $output );
	}

	public function testEncodingProcessingInstructionIsNotEmitted(): void {
		$html   = '<!DOCTYPE html><html><head></head><body><p>hi</p></body></html>';
		$output = $this->roundTrip( $html );

		self::assertStringNotContainsString( '<?xml', $output );
	}

	public function testXmlInAttributeValueIsNotStripped(): void {
		$html   = '<!DOCTYPE html><html><head></head><body>'
			. '<a href="data:image/svg+xml,&lt;?xml version=&#39;1.0&#39;?&gt;">svg</a>'
			. '</body></html>';
		$output = $this->roundTrip( $html );

		self::assertStringContainsString( 'data:image/svg+xml', $output );
	}
}
