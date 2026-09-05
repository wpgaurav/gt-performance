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
	public function test_nothing_round_trips_the_dom_any_more(): void {
		self::assertSame(
			array(),
			$this->consumers(),
			'Serialising a parsed document lowercases inline-SVG camelCase and entity-encodes '
			. 'all non-ASCII, and the result is written into the page cache. Use '
			. 'WP_HTML_Tag_Processor for attributes, or match the element directly.'
		);

		self::assertFileDoesNotExist(
			dirname( __DIR__, 2 ) . '/src/Optimization/HtmlDocument.php',
			'The helper existed only to make the round trip survivable. Nothing round trips now.'
		);
	}

	/**
	 * The CSS engine still needs a document to match selectors against, but it must
	 * only ever read it.
	 */
	public function test_the_css_engine_parses_for_matching_and_never_serialises(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Optimization/Css/UnusedCssOptimizer.php' );

		self::assertStringContainsString( 'readOnlyDocument', $source );
		self::assertStringNotContainsString( '->saveHTML(', $source );
		self::assertStringContainsString( 'replaceStylesheets', $source, 'Emission happens on the HTML string.' );
	}

	public function test_the_migrated_optimizers_use_the_tag_processor(): void {
		foreach ( array( 'Optimization/FontOptimizer.php', 'Optimization/EmbedOptimizer.php', 'CDN/UrlRewriter.php', 'Optimization/MediaOptimizer.php' ) as $path ) {
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
