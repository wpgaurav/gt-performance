<?php
/**
 * The DOM round-trip must stay confined to the one disabled consumer.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HtmlDocumentBoundaryTest extends TestCase {
	/**
	 * @return list<string>
	 */
	private function consumers(): array {
		$found = array();
		$root  = dirname( __DIR__, 2 ) . '/src';

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = str_replace( $root . '/', '', $file->getPathname() );
			if ( 'Optimization/HtmlDocument.php' === $path ) {
				continue;
			}
			if ( str_contains( (string) file_get_contents( $file->getPathname() ), 'HtmlDocument' ) ) {
				$found[] = $path;
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * libxml lowercases inline-SVG camelCase and entity-encodes non-ASCII, and the
	 * result is written into the page cache. Every consumer that reserialises a
	 * document inherits that. Only the unused-CSS engine may, because matching
	 * selectors needs a real DOM and that engine ships disabled.
	 */
	public function test_only_the_disabled_css_engine_round_trips_the_dom(): void {
		self::assertSame(
			array( 'Optimization/Css/UnusedCssOptimizer.php' ),
			$this->consumers(),
			'A new HtmlDocument consumer would make SVG and non-ASCII corruption reachable again. '
			. 'Use WP_HTML_Tag_Processor instead, as FontOptimizer, EmbedOptimizer and CDN\\UrlRewriter do.'
		);
	}

	public function test_the_only_consumer_is_gated_behind_a_constant(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Optimization/Css/UnusedCssOptimizer.php' );

		self::assertStringContainsString( "defined( 'GTPERF_UNUSED_CSS' )", $source );
		self::assertStringContainsString( 'if ( ! self::available() )', $source );
	}

	public function test_the_migrated_optimizers_use_the_tag_processor(): void {
		foreach ( array( 'Optimization/FontOptimizer.php', 'Optimization/EmbedOptimizer.php', 'CDN/UrlRewriter.php' ) as $path ) {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/' . $path );

			// Match construction, not the word: these files explain in prose why they no
			// longer use a document, and that explanation is worth keeping.
			self::assertDoesNotMatchRegularExpression(
				'/new\s+\\?(?:DOMDocument|DOMXPath)\b/',
				$source,
				$path . ' must not parse or query a DOM document.'
			);
		}

		// Attribute rewriting belongs in the tag processor.
		foreach ( array( 'Optimization/FontOptimizer.php', 'CDN/UrlRewriter.php' ) as $path ) {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/' . $path );
			self::assertStringContainsString( 'WP_HTML_Tag_Processor', $source, $path . ' must use the tag processor.' );
		}

		// EmbedOptimizer replaces whole elements, which the tag processor cannot do, so
		// it matches the iframe tag directly. That is deliberately narrower than a
		// document reparse and cannot touch markup it was not aimed at.
		$embed = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Optimization/EmbedOptimizer.php' );
		self::assertStringContainsString( '<iframe', $embed );
	}
}
