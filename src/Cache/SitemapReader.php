<?php
/**
 * Extract locations from XML sitemaps.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class SitemapReader {
	/**
	 * Return every <loc> value in a sitemap index or urlset document.
	 *
	 * Parsing is done with a bounded regular expression rather than an XML reader
	 * so that hostile or malformed sitemap responses cannot trigger entity
	 * expansion or external entity resolution.
	 *
	 * @return list<string>
	 */
	public function locations( string $xml ): array {
		return array_map( 'strval', array_keys( $this->entries( $xml ) ) );
	}

	/**
	 * Locations with their <lastmod> timestamps (0 when absent or unparseable).
	 *
	 * PHP turns a numeric-string key into an integer, so callers cast keys.
	 *
	 * @return array<array-key, int> Location => Unix timestamp.
	 */
	public function entries( string $xml ): array {
		if ( '' === trim( $xml ) ) {
			return array();
		}

		if ( ! preg_match_all( '/<(url|sitemap)\b[^>]*>(.*?)<\/\1>/is', $xml, $blocks ) ) {
			return array();
		}

		$entries = array();
		foreach ( $blocks[2] as $block ) {
			if ( ! preg_match( '/<loc>\s*(.*?)\s*<\/loc>/is', $block, $loc ) ) {
				continue;
			}
			$url = trim( html_entity_decode( $loc[1], ENT_QUOTES | ENT_XML1 ) );
			if ( '' === $url || isset( $entries[ $url ] ) ) {
				continue;
			}
			$modified = 0;
			if ( preg_match( '/<lastmod>\s*(.*?)\s*<\/lastmod>/is', $block, $lastmod ) ) {
				$parsed   = strtotime( trim( $lastmod[1] ) );
				$modified = false === $parsed ? 0 : max( 0, $parsed );
			}
			$entries[ $url ] = $modified;
		}

		return $entries;
	}

	public function isIndex( string $xml ): bool {
		return 1 === preg_match( '/<sitemapindex\b/i', $xml );
	}

	/**
	 * Sitemap declarations from a robots.txt body.
	 *
	 * @return list<string>
	 */
	public function robotsSitemaps( string $robots ): array {
		if ( ! preg_match_all( '/^[ \t]*sitemap[ \t]*:[ \t]*(\S+)[ \t\r]*$/im', $robots, $matches ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'trim', $matches[1] ) ) );
	}
}
