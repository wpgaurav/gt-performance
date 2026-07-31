<?php
/**
 * Minimal Cloudflare API client.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

final class ApiClient {
	private const BASE = 'https://api.cloudflare.com/client/v4';

	public function __construct(
		private readonly ApiCredentials $credentials,
	) {
	}

	/**
	 * @param array<string, mixed>|null $body Request body.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $path, ?array $body = null ): array|\WP_Error {
		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array_merge(
				$this->credentials->headers(),
				array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'GT-Performance/' . GTP_VERSION,
				)
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE . '/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'gtp_cloudflare_json', __( 'Cloudflare returned an invalid response.', 'gt-performance' ), array( 'status' => $code ) );
		}

		if ( $code < 200 || $code >= 300 || empty( $data['success'] ) ) {
			$message = __( 'Cloudflare API request failed.', 'gt-performance' );
			if ( isset( $data['errors'][0]['message'] ) && is_string( $data['errors'][0]['message'] ) ) {
				$message = $data['errors'][0]['message'];
			}

			return new \WP_Error( 'gtp_cloudflare_api', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function zoneByName( string $hostname ): array|\WP_Error {
		$parts = explode( '.', preg_replace( '/:\\d+$/', '', strtolower( $hostname ) ) );
		for ( $offset = max( 0, count( $parts ) - 2 ); $offset >= 0; --$offset ) {
			$name   = implode( '.', array_slice( $parts, $offset ) );
			$result = $this->request( 'GET', 'zones?name=' . rawurlencode( $name ) . '&status=active&per_page=1' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['result'][0] ) && is_array( $result['result'][0] ) ) {
				return $result['result'][0];
			}
		}

		return new \WP_Error( 'gtp_cloudflare_zone', __( 'No active Cloudflare zone matched this site.', 'gt-performance' ) );
	}

	/**
	 * Purge exact URLs in Cloudflare's maximum batch size.
	 *
	 * @param list<string> $urls URLs to purge.
	 * @return bool|\WP_Error
	 */
	public function purgeUrls( string $zoneId, array $urls ): bool|\WP_Error {
		$urls = array_values( array_unique( array_filter( array_map( 'trim', $urls ) ) ) );
		foreach ( array_chunk( $urls, 30 ) as $chunk ) {
			$result = $this->request(
				'POST',
				'zones/' . rawurlencode( $zoneId ) . '/purge_cache',
				array( 'files' => $chunk )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Purge every cached item in a Cloudflare zone.
	 *
	 * @return bool|\WP_Error
	 */
	public function purgeEverything( string $zoneId ): bool|\WP_Error {
		$result = $this->request(
			'POST',
			'zones/' . rawurlencode( $zoneId ) . '/purge_cache',
			array( 'purge_everything' => true )
		);

		return is_wp_error( $result ) ? $result : true;
	}
}
