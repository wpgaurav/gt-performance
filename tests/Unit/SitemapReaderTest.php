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

	public function test_entries_carry_lastmod_and_ignore_image_locations(): void {
		$xml = '<urlset xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
			. '<url><loc>https://example.com/new/</loc><lastmod>2026-09-20T10:00:00+00:00</lastmod>'
			. '<image:image><image:loc>https://example.com/a.jpg</image:loc></image:image></url>'
			. '<url><loc>https://example.com/old/</loc><lastmod>not a date</lastmod></url>'
			. '<url><loc>https://example.com/new/</loc></url>'
			. '</urlset>';

		self::assertSame(
			array(
				'https://example.com/new/' => strtotime( '2026-09-20T10:00:00+00:00' ),
				'https://example.com/old/' => 0,
			),
			( new SitemapReader() )->entries( $xml )
		);
	}

	public function test_numeric_locations_stay_strings(): void {
		self::assertSame( array( '123', 'https://example.com/' ), ( new SitemapReader() )->locations( '<urlset><url><loc>123</loc></url><url><loc>https://example.com/</loc></url></urlset>' ) );
	}

	public function test_reads_sitemap_declarations_from_robots(): void {
		$robots = "User-agent: *\nDisallow: /wp-admin/\nSitemap: https://example.com/sitemap_index.xml\n  sitemap:https://example.com/news.xml \r\n# Sitemap: https://example.com/commented.xml\nSitemap: https://example.com/sitemap_index.xml\n";

		self::assertSame(
			array( 'https://example.com/sitemap_index.xml', 'https://example.com/news.xml' ),
			( new SitemapReader() )->robotsSitemaps( $robots )
		);
	}
}
