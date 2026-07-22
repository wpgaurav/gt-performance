<?php
/**
 * Sitemap-driven cache warming.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;

final class CacheWarmer {
	private const MAX_SUBSITEMAPS = 20;

	public function __construct(
		private readonly Logger $logger,
		private readonly SitemapReader $reader = new SitemapReader(),
	) {
	}

	/**
	 * Discover site URLs and enqueue preload jobs for them.
	 *
	 * Runs from the background queue after a full purge, so the whole site is
	 * warmed back to a HIT state instead of staying cold until organic traffic
	 * refills it one page at a time.
	 */
	public function warm( int $max ): int {
		$urls = $this->discover( $max );
		if ( array() === $urls ) {
			return 0;
		}

		do_action( 'gt_performance_enqueue_preload', $urls );

		return count( $urls );
	}

	/**
	 * @return list<string>
	 */
	public function discover( int $max ): array {
		if ( $max <= 0 ) {
			return array();
		}

		$max  = min( 2000, $max );
		$home = home_url( '/' );

		$candidates = array( $home );
		$indexXml   = $this->fetch( home_url( '/wp-sitemap.xml' ) );

		if ( null !== $indexXml ) {
			$entries = $this->reader->locations( $indexXml );

			if ( $this->reader->isIndex( $indexXml ) ) {
				foreach ( array_slice( $entries, 0, self::MAX_SUBSITEMAPS ) as $subSitemap ) {
					if ( count( $candidates ) >= $max ) {
						break;
					}
					if ( ! $this->isSameOrigin( $subSitemap, $home ) ) {
						continue;
					}
					$subXml = $this->fetch( $subSitemap );
					if ( null !== $subXml ) {
						$candidates = array_merge( $candidates, $this->reader->locations( $subXml ) );
					}
				}
			} else {
				$candidates = array_merge( $candidates, $entries );
			}
		}

		$cache      = apply_filters( 'gt_performance_cache_policy', (array) Settings::get( 'cache', array() ) );
		$eligible   = new Eligibility();
		$sameOrigin = array();
		foreach ( $candidates as $url ) {
			if ( ! $this->isSameOrigin( $url, $home ) ) {
				continue;
			}

			$request = RequestContext::fromUrl( $url );
			if ( null === $request || ! $eligible->decide( $request, $cache )->cacheable ) {
				continue;
			}
			$sameOrigin[ $url ] = true;
			if ( count( $sameOrigin ) >= $max ) {
				break;
			}
		}

		return array_slice( array_keys( $sameOrigin ), 0, $max );
	}

	private function fetch( string $url ): ?string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 2,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => 'GT-Performance-Warmer/' . GTP_VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->logger->log( 'debug', 'Cache warm sitemap fetch failed', array( 'url' => $url ) );

			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	private function isSameOrigin( string $url, string $home ): bool {
		$urlParts  = wp_parse_url( $url );
		$homeParts = wp_parse_url( $home );
		if ( ! is_array( $urlParts ) || ! is_array( $homeParts ) ) {
			return false;
		}

		$urlScheme  = strtolower( (string) ( $urlParts['scheme'] ?? '' ) );
		$homeScheme = strtolower( (string) ( $homeParts['scheme'] ?? '' ) );
		$urlHost    = strtolower( (string) ( $urlParts['host'] ?? '' ) );
		$homeHost   = strtolower( (string) ( $homeParts['host'] ?? '' ) );
		$urlPort    = (int) ( $urlParts['port'] ?? ( 'https' === $urlScheme ? 443 : 80 ) );
		$homePort   = (int) ( $homeParts['port'] ?? ( 'https' === $homeScheme ? 443 : 80 ) );

		return in_array( $urlScheme, array( 'http', 'https' ), true )
			&& $urlScheme === $homeScheme
			&& '' !== $urlHost
			&& $urlHost === $homeHost
			&& $urlPort === $homePort
			&& empty( $urlParts['user'] )
			&& empty( $urlParts['pass'] );
	}
}
