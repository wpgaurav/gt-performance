<?php
/**
 * XCloud site discovery, cache status, and purge routing.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\XCloud;

use GTPerformance\Core\Settings;

final class SiteService {
	public function __construct(
		private readonly ClientFactory $factory = new ClientFactory(),
	) {
	}

	/**
	 * @param array<string, mixed>|null $settings Plugin settings.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function refresh( ?array $settings = null ): array|\WP_Error {
		$settings = $settings ?? Settings::all();
		$client   = $this->factory->create( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$xcloud = isset( $settings['xcloud'] ) && is_array( $settings['xcloud'] )
			? $settings['xcloud']
			: array();
		$domain = $this->factory->domain( $settings );
		$uuid   = trim( (string) ( $xcloud['site_uuid'] ?? '' ) );
		$site   = '' !== $uuid ? $client->site( $uuid ) : $client->siteByDomain( $domain );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$uuid = trim( (string) ( $site['uuid'] ?? '' ) );
		if ( '' === $uuid ) {
			return new \WP_Error( 'gtp_xcloud_site', __( 'xCloud did not return a UUID for this site.', 'gt-performance' ) );
		}

		$cache = $client->cacheSettings( $uuid );
		if ( is_wp_error( $cache ) ) {
			return $cache;
		}

		$ids = $client->enterpriseIdsByDomain( $domain );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$analytics = $client->enterpriseAnalytics( $ids['server_id'], $ids['site_id'] );
		$enterpriseAvailable = ! is_wp_error( $analytics ) && ! empty( $analytics['available'] );
		$totals = $enterpriseAvailable && isset( $analytics['totals'] ) && is_array( $analytics['totals'] )
			? $analytics['totals']
			: array();
		$page       = isset( $cache['page_cache'] ) && is_array( $cache['page_cache'] ) ? $cache['page_cache'] : array();
		$object     = isset( $cache['object_cache'] ) && is_array( $cache['object_cache'] ) ? $cache['object_cache'] : array();
		$freeEdge   = isset( $cache['cloudflare_edge_cache'] ) && is_array( $cache['cloudflare_edge_cache'] ) ? $cache['cloudflare_edge_cache'] : array();
		$requests   = max( 0, (int) ( $totals['requests'] ?? 0 ) );
		$edgeServed = max( 0, (int) ( $totals['served_by_cloudflare'] ?? 0 ) );

		return array(
			'site_uuid'                 => $uuid,
			'server_id'                 => $ids['server_id'],
			'site_id'                   => $ids['site_id'],
			'name'                      => sanitize_text_field( (string) ( $site['name'] ?? '' ) ),
			'domain'                    => $this->siteDomain( $site ),
			'status'                    => sanitize_key( (string) ( $site['status'] ?? '' ) ),
			'dashboard_url'             => esc_url_raw( (string) ( $site['dashboard_url'] ?? '' ) ),
			'stack'                     => sanitize_key( (string) ( $cache['stack'] ?? '' ) ),
			'page_cache_enabled'        => (bool) ( $page['enabled'] ?? false ),
			'page_cache_source'         => sanitize_text_field( (string) ( $page['source'] ?? $page['plugin'] ?? '' ) ),
			'redis_enabled'             => (bool) ( $object['redis'] ?? false ),
			'object_cache_pro'          => (bool) ( $object['object_cache_pro'] ?? false ),
			'free_edge_cache_enabled'   => (bool) ( $freeEdge['enabled'] ?? false ),
			'enterprise_available'      => $enterpriseAvailable,
			'enterprise_requests'       => $requests,
			'enterprise_edge_requests'  => $edgeServed,
			'enterprise_hit_percent'    => $requests > 0 ? round( 100 * $edgeServed / $requests, 1 ) : 0.0,
			'checked_at'                => current_time( 'mysql', true ),
		);
	}

	/**
	 * Route automatic invalidation to the free xCloud edge cache or host page
	 * cache. Enterprise remains separate and fails closed until xCloud exposes a
	 * token-authenticated purge endpoint in its Public API.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function purgeAutomatic(): array|\WP_Error {
		$settings = Settings::all();
		$xcloud   = (array) ( $settings['xcloud'] ?? array() );
		$client   = $this->factory->create( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		if ( (bool) ( $xcloud['enterprise_available'] ?? false ) ) {
			return new \WP_Error(
				'gtp_xcloud_enterprise_purge_unavailable',
				__( 'Cloudflare Enterprise is active, but xCloud does not expose its purge action through token-authenticated Public API. Purge it from the xCloud Enterprise dashboard.', 'gt-performance' )
			);
		}

		$uuid = trim( (string) ( $xcloud['site_uuid'] ?? '' ) );
		if ( '' === $uuid ) {
			return new \WP_Error( 'gtp_xcloud_site', __( 'Connect the xCloud site before enabling automatic purges.', 'gt-performance' ) );
		}

		if ( (bool) ( $xcloud['free_edge_cache_enabled'] ?? false ) ) {
			return $this->normalizePurge( $client->purgeAllHostCaches( $uuid ), 'free-edge' );
		}

		if ( (bool) ( $xcloud['page_cache_enabled'] ?? false ) ) {
			return $this->normalizePurge( $client->purgePageCache( $uuid ), 'page' );
		}

		return array(
			'mode'    => 'none',
			'message' => __( 'No active xCloud cache required purging.', 'gt-performance' ),
			'caches'  => array(),
		);
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result API result.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function normalizePurge( array|\WP_Error $result, string $mode ): array|\WP_Error {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data   = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$caches = isset( $data['caches'] ) && is_array( $data['caches'] ) ? $data['caches'] : array();
		$clean  = array();
		foreach ( $caches as $cache => $status ) {
			if ( is_scalar( $status ) ) {
				$clean[ sanitize_key( (string) $cache ) ] = sanitize_key( (string) $status );
			}
		}

		return array(
			'mode'    => $mode,
			'message' => sanitize_text_field( (string) ( $result['message'] ?? 'xCloud accepted the cache purge.' ) ),
			'caches'  => $clean,
		);
	}

	/**
	 * @param array<string, mixed> $site Site payload.
	 */
	private function siteDomain( array $site ): string {
		$value = (string) ( $site['domain_name'] ?? $site['domain'] ?? '' );
		$url   = str_contains( $value, '://' ) ? $value : 'https://' . $value;
		$host  = wp_parse_url( $url, PHP_URL_HOST );

		return trim( strtolower( is_string( $host ) ? $host : '' ), ". \t\n\r\0\x0B" );
	}
}
