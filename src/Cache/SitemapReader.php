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
		if ( '' === trim( $xml ) ) {
			return array();
		}

		if ( ! preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/is', $xml, $matches ) ) {
			return array();
		}

		$urls = array();
		foreach ( $matches[1] as $location ) {
			$url = trim( html_entity_decode( $location, ENT_QUOTES | ENT_XML1 ) );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	public function isIndex( string $xml ): bool {
		return 1 === preg_match( '/<sitemapindex\b/i', $xml );
	}
}
