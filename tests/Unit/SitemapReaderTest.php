<?php
/**
 * Sitemap location extraction tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\SitemapReader;
use PHPUnit\Framework\TestCase;

final class SitemapReaderTest extends TestCase {
	public function test_extracts_and_decodes_locations(): void {
		$xml = '<?xml version="1.0"?><urlset>'
			. '<url><loc>https://example.com/</loc></url>'
			. '<url><loc>https://example.com/shop/?a=1&amp;b=2</loc></url>'
			. '<url><loc>  https://example.com/about  </loc></url>'
			. '</urlset>';

		$urls = ( new SitemapReader() )->locations( $xml );

		self::assertSame(
			array(
				'https://example.com/',
				'https://example.com/shop/?a=1&b=2',
				'https://example.com/about',
			),
			$urls
		);
	}

	public function test_recognizes_a_sitemap_index(): void {
		$index = '<?xml version="1.0"?><SITEMAPINDEX><sitemap><loc>https://example.com/wp-sitemap-posts-post-1.xml</loc></sitemap></SITEMAPINDEX>';

		$reader = new SitemapReader();

		self::assertTrue( $reader->isIndex( $index ) );
		self::assertSame( array( 'https://example.com/wp-sitemap-posts-post-1.xml' ), $reader->locations( $index ) );
	}

	public function test_empty_or_locationless_document_returns_empty(): void {
		$reader = new SitemapReader();

		self::assertSame( array(), $reader->locations( '' ) );
		self::assertSame( array(), $reader->locations( '<urlset></urlset>' ) );
	}
}
