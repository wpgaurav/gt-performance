<?php
/**
 * XCloud Public and capability-detected Enterprise API client.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\XCloud;

final class ApiClient {
	private const BASE = 'https://app.xcloud.host';

	public function __construct(
		private readonly string $token,
		private readonly string $baseUrl = self::BASE,
	) {
	}

	/**
	 * @param array<string, mixed>|null $body Request body.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $path, ?array $body = null, bool $requiresSuccessEnvelope = true ): array|\WP_Error {
		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'GT-Performance/' . GTP_VERSION,
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( rtrim( $this->baseUrl, '/' ) . '/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'gtp_xcloud_json', __( 'xCloud returned an invalid response.', 'gt-performance' ), array( 'status' => $code ) );
		}

		$envelopeFailed = $requiresSuccessEnvelope && array_key_exists( 'success', $data ) && empty( $data['success'] );
		if ( $code < 200 || $code >= 300 || $envelopeFailed ) {
			$message = isset( $data['message'] ) && is_string( $data['message'] )
				? $this->plainText( $data['message'] )
				: __( 'xCloud API request failed.', 'gt-performance' );

			return new \WP_Error( 'gtp_xcloud_api', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function site( string $uuid ): array|\WP_Error {
		$result = $this->request( 'GET', 'api/v1/sites/' . rawurlencode( $uuid ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$site = $result['data'] ?? null;

		return is_array( $site )
			? $site
			: new \WP_Error( 'gtp_xcloud_site', __( 'xCloud did not return the configured site.', 'gt-performance' ) );
	}

	/**
	 * Resolve an xCloud Public API site by its exact primary domain.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function siteByDomain( string $domain ): array|\WP_Error {
		$domain = $this->normalizeDomain( $domain );
		$result = $this->request( 'GET', 'api/v1/sites?search=' . rawurlencode( $domain ) . '&per_page=20' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data  = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$items = $data['items'] ?? $data['data'] ?? array();
		foreach ( is_array( $items ) ? $items : array() as $site ) {
			if ( is_array( $site ) && $this->siteMatchesDomain( $site, $domain ) ) {
				return $site;
			}
		}

		return new \WP_Error( 'gtp_xcloud_site', __( 'No xCloud site matched this exact domain.', 'gt-performance' ) );
	}

	/**
	 * Resolve the numeric IDs used by xCloud's Enterprise add-on routes. These
	 * routes are capability-detected because xCloud has not yet added them to the
	 * published Public API catalog.
	 *
	 * @return array{server_id:int,site_id:int}|\WP_Error
	 */
	public function enterpriseIdsByDomain( string $domain ): array|\WP_Error {
		$domain = $this->normalizeDomain( $domain );
		$result = $this->request(
			'GET',
			'api/site-list?search=' . rawurlencode( $domain ) . '&page=1&per_page=20',
			null,
			false
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		foreach ( $items as $site ) {
			if ( ! is_array( $site ) || $this->normalizeDomain( (string) ( $site['name'] ?? '' ) ) !== $domain ) {
				continue;
			}

			$server = isset( $site['server'] ) && is_array( $site['server'] ) ? $site['server'] : array();
			$siteId = (int) ( $site['id'] ?? 0 );
			$serverId = (int) ( $server['id'] ?? 0 );
			if ( $siteId > 0 && $serverId > 0 ) {
				return array(
					'server_id' => $serverId,
					'site_id'   => $siteId,
				);
			}
		}

		return new \WP_Error( 'gtp_xcloud_enterprise_ids', __( 'xCloud did not expose the Enterprise add-on IDs for this domain.', 'gt-performance' ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function cacheSettings( string $uuid ): array|\WP_Error {
		$result = $this->request( 'GET', 'api/v1/sites/' . rawurlencode( $uuid ) . '/cache/settings' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$settings = $result['data'] ?? null;

		return is_array( $settings )
			? $settings
			: new \WP_Error( 'gtp_xcloud_cache_settings', __( 'xCloud did not return host-cache settings for this site.', 'gt-performance' ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function enterpriseAnalytics( int $serverId, int $siteId, string $range = '12h' ): array|\WP_Error {
		if ( $serverId < 1 || $siteId < 1 ) {
			return new \WP_Error( 'gtp_xcloud_enterprise_ids', __( 'Valid xCloud Enterprise site identifiers are required.', 'gt-performance' ) );
		}

		$allowed = array( '30m', '6h', '12h', '24h', '7d', '14d' );
		$range   = in_array( $range, $allowed, true ) ? $range : '12h';

		return $this->request(
			'GET',
			$this->enterprisePath( $serverId, $siteId ) . '/analytics?range=' . rawurlencode( $range ),
			null,
			false
		);
	}

	/**
	 * Purge xCloud's host full-page cache without flushing object caches.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function purgePageCache( string $uuid ): array|\WP_Error {
		return $this->request( 'POST', 'api/v1/sites/' . rawurlencode( $uuid ) . '/cache/purge' );
	}

	/**
	 * Purge the free xCloud Cloudflare edge cache and other host-managed layers.
	 * This is intentionally separate from the Enterprise add-on purge.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function purgeAllHostCaches( string $uuid ): array|\WP_Error {
		return $this->request( 'POST', 'api/v1/sites/' . rawurlencode( $uuid ) . '/cache/purge-all' );
	}

	private function enterprisePath( int $serverId, int $siteId ): string {
		return 'addons/server/' . $serverId . '/site/' . $siteId . '/cloudflare-enterprise';
	}

	/**
	 * @param array<string, mixed> $site Site payload.
	 */
	private function siteMatchesDomain( array $site, string $domain ): bool {
		$siteDomain = $this->normalizeDomain( (string) ( $site['domain_name'] ?? $site['domain'] ?? '' ) );

		return '' !== $siteDomain && hash_equals( $domain, $siteDomain );
	}

	private function normalizeDomain( string $domain ): string {
		$domain = trim( strtolower( $domain ) );
		$url    = str_contains( $domain, '://' ) ? $domain : 'https://' . $domain;
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		return trim( strtolower( is_string( $host ) ? $host : '' ), ". \t\n\r\0\x0B" );
	}

	private function plainText( string $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', wp_strip_all_tags( $value ) ) ?? '';

		return substr( trim( $value ), 0, 300 );
	}
}
