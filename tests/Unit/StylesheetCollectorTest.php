<?php
/**
 * Stylesheet collection tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\StylesheetCollector;
use PHPUnit\Framework\TestCase;

final class StylesheetCollectorTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['gtperf_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'text/css' ),
			'body'     => '.middle{color:green}',
		);
	}

	public function testStylesheetsRemainInDocumentOrder(): void {
		$document = new \DOMDocument();
		$document->loadHTML(
			'<!doctype html><html><head>'
			. '<style>.before{color:red}</style>'
			. '<link rel="stylesheet" href="/middle.css">'
			. '<style>.after{color:blue}</style>'
			. '</head><body></body></html>'
		);

		$collected = ( new StylesheetCollector() )->collect( $document );

		self::assertSame(
			array( '.before{color:red}', '.middle{color:green}', '.after{color:blue}' ),
			array_map(
				static fn( $stylesheet ): string => $stylesheet->css,
				$collected['stylesheets']
			)
		);
	}

	public function testAsyncMediaIsPromotedAndNoscriptFallbackIsNotCollected(): void {
		$document = new \DOMDocument();
		$document->loadHTML(
			'<!doctype html><html><head>'
			. '<link rel="stylesheet" href="/async.css" media="print" onload="this.onload=null;this.media=\'all\'">'
			. '<noscript><link rel="stylesheet" href="/async.css"><style>.fallback{display:block}</style></noscript>'
			. '</head><body></body></html>'
		);

		$collected = ( new StylesheetCollector() )->collect( $document );

		self::assertCount( 1, $collected['stylesheets'] );
		self::assertSame( 'all', $collected['stylesheets'][0]->media );
		self::assertCount( 1, $collected['nodes'] );
		self::assertSame( 1, $document->getElementsByTagName( 'noscript' )->length );
	}

	/**
	 * The reuse key keeps only the hrefs of stylesheets a build reads, so reads()
	 * must agree with collect() on every link. Otherwise a key could ignore a
	 * stylesheet the build depends on.
	 */
	public function testReadsAgreesWithCollectOnEveryLink(): void {
		$hrefs    = array(
			'/middle.css'                                        => true,
			'https://example.com/wp-content/a.css?ver=1&amp;b=2' => true,
			'//example.com/b.css'                                => true,
			'https://cdn.example.net/wp-content/c.css?ver=9'     => false,
			'https://example.com/wp-content/excluded.css?ver=3'  => false,
			'data:text/css,.x{}'                                 => false,
		);
		$document = new \DOMDocument();
		$document->loadHTML(
			'<!doctype html><html><head>'
			. implode( '', array_map( static fn( string $href ): string => '<link rel="stylesheet" href="' . $href . '">', array_keys( $hrefs ) ) )
			. '</head><body></body></html>'
		);

		$collector = new StylesheetCollector();
		$read      = array_map(
			static fn( \DOMElement $node ): string => $node->getAttribute( 'href' ),
			$collector->collect( $document, array( 'excluded.css' ) )['nodes']
		);

		foreach ( $hrefs as $href => $expected ) {
			$value = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5 );
			self::assertSame( $expected, in_array( $value, $read, true ), 'collect(): ' . $href );
			self::assertSame( $expected, $collector->reads( $value, array( 'excluded.css' ) ), 'reads(): ' . $href );
		}
	}

	public function testAConfiguredCdnHostIsRead(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array( 'cdn' => array( 'url' => 'https://cdn.example.net' ) );

		try {
			self::assertTrue( ( new StylesheetCollector() )->reads( 'https://cdn.example.net/wp-content/c.css?ver=9' ) );
		} finally {
			unset( $GLOBALS['gtperf_test_options']['gt_performance_settings'] );
		}
	}

	public function testInlineStyleCanBeExcludedByWordPressStyleId(): void {
		$document = new \DOMDocument();
		$document->loadHTML(
			'<!doctype html><html><head>'
			. '<style id="md-critical-css">.md-icon-shop::before{content:"\\e986"}</style>'
			. '<style id="page-css">.page{display:block}</style>'
			. '</head><body></body></html>'
		);

		$collected = ( new StylesheetCollector() )->collect( $document, array( 'md-critical' ) );

		self::assertCount( 1, $collected['stylesheets'] );
		self::assertSame( '.page{display:block}', $collected['stylesheets'][0]->css );
		self::assertCount( 1, $collected['nodes'] );
		self::assertSame( 'page-css', $collected['nodes'][0]->getAttribute( 'id' ) );
		self::assertSame( 2, $document->getElementsByTagName( 'style' )->length );
	}
}
