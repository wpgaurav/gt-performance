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
	 * Which authentication style this client is using: "token" or "global".
	 */
	public function mode(): string {
		return $this->credentials->mode();
	}

	/**
	 * @param array<string, mixed>|null $body Request body.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $path, ?array $body = null ): array|\WP_Error {
		$method = strtoupper( $method );
		$args   = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array_merge(
				$this->credentials->headers(),
				array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'GT-Performance/' . GTPERF_VERSION,
				)
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE . '/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			// A transport failure never reached Cloudflare, so say so rather than
			// letting it read as a rejected request.
			return new \WP_Error(
				'gtperf_cloudflare_transport',
				sprintf(
					/* translators: %s: transport error reported by WordPress. */
					__( 'WordPress could not reach the Cloudflare API: %s', 'gt-performance' ),
					$response->get_error_message()
				),
				array(
					'method' => $method,
					'path'   => $path,
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'gtperf_cloudflare_json',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Cloudflare returned an unreadable response (HTTP %d).', 'gt-performance' ),
					$code
				),
				array(
					'status' => $code,
					'method' => $method,
					'path'   => $path,
				)
			);
		}

		if ( $code < 200 || $code >= 300 || empty( $data['success'] ) ) {
			return new \WP_Error(
				'gtperf_cloudflare_api',
				$this->describeErrors( $data, $code ),
				array(
					'status'  => $code,
					'method'  => $method,
					'path'    => $path,
					'errors'  => $this->errorList( $data ),
				)
			);
		}

		return $data;
	}

	/**
	 * Build a message that names what Cloudflare actually objected to.
	 *
	 * Cloudflare puts the useful part in errors[].message, with a numeric code and
	 * sometimes a nested error_chain. Collapsing all of that into one generic
	 * sentence is what makes these failures hard to act on.
	 *
	 * @param array<string, mixed> $data Decoded response.
	 */
	private function describeErrors( array $data, int $status ): string {
		$parts = array();
		foreach ( $this->errorList( $data ) as $error ) {
			$text = $error['message'];
			if ( 0 !== $error['code'] ) {
				/* translators: %d: Cloudflare numeric error code. */
				$text .= ' ' . sprintf( __( '(Cloudflare code %d)', 'gt-performance' ), $error['code'] );
			}
			$parts[] = $text;
		}

		if ( ! $parts ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Cloudflare rejected the request with HTTP %d and gave no reason.', 'gt-performance' ), $status );
		}

		return sprintf(
			/* translators: 1: HTTP status code, 2: reasons reported by Cloudflare. */
			__( 'Cloudflare rejected the request (HTTP %1$d): %2$s', 'gt-performance' ),
			$status,
			implode( '; ', $parts )
		);
	}

	/**
	 * Flatten Cloudflare's errors, including any nested error_chain entries.
	 *
	 * @param array<string, mixed> $data Decoded response.
	 * @return list<array{code:int, message:string}>
	 */
	private function errorList( array $data ): array {
		$flat = array();
		foreach ( (array) ( $data['errors'] ?? array() ) as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}

			$message = trim( (string) ( $error['message'] ?? '' ) );
			if ( '' !== $message ) {
				$flat[] = array(
					'code'    => (int) ( $error['code'] ?? 0 ),
					'message' => $message,
				);
			}

			foreach ( (array) ( $error['error_chain'] ?? array() ) as $chained ) {
				if ( ! is_array( $chained ) ) {
					continue;
				}
				$chainedMessage = trim( (string) ( $chained['message'] ?? '' ) );
				if ( '' !== $chainedMessage ) {
					$flat[] = array(
						'code'    => (int) ( $chained['code'] ?? 0 ),
						'message' => $chainedMessage,
					);
				}
			}
		}

		return $flat;
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

		return new \WP_Error(
			'gtperf_cloudflare_zone',
			sprintf(
				/* translators: %s: hostname that was searched for. */
				__( 'No active Cloudflare zone matched %s on this account.', 'gt-performance' ),
				$hostname
			)
		);
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
