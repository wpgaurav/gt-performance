<?php
/**
 * Redacted HTTP response evidence for cache verification.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Diagnostics;

final class ResponseSnapshot {
	/**
	 * @param array<string, string|list<string>> $headers Response headers.
	 * @return array<string, bool|int|string>
	 */
	public function analyze( int $status, array $headers, string $body ): array {
		$normalized = array();
		foreach ( $headers as $name => $value ) {
			$normalized[ strtolower( (string) $name ) ] = is_array( $value )
				? implode( ', ', array_map( 'strval', $value ) )
				: (string) $value;
		}

		$cacheControl = strtolower( (string) ( $normalized['cache-control'] ?? '' ) );
		$setCookie    = '' !== (string) ( $normalized['set-cookie'] ?? '' );
		$private      = $setCookie || 1 === preg_match( '/\b(?:private|no-store)\b/', $cacheControl );

		return array(
			'status'          => $status,
			'body_fingerprint' => '' === $body ? '' : substr( hash( 'sha256', $body ), 0, 16 ),
			'bytes'           => strlen( $body ),
			'cache_control'   => substr( (string) ( $normalized['cache-control'] ?? '' ), 0, 240 ),
			'cf_cache_status' => strtoupper( substr( (string) ( $normalized['cf-cache-status'] ?? '' ), 0, 32 ) ),
			'gt_cache_status' => strtoupper( substr( (string) ( $normalized['x-gt-cache'] ?? '' ), 0, 32 ) ),
			'age'             => max( 0, (int) ( $normalized['age'] ?? 0 ) ),
			'set_cookie'      => $setCookie,
			'private'         => $private,
		);
	}

	/**
	 * @return array<string, bool|int|string>|\WP_Error
	 */
	public function fromWordPressResponse( mixed $response ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$headers = wp_remote_retrieve_headers( $response );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

		return $this->analyze(
			wp_remote_retrieve_response_code( $response ),
			$headers,
			wp_remote_retrieve_body( $response )
		);
	}
}
