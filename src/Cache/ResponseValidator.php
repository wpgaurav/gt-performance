<?php
/**
 * Cache response safety validation.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class ResponseValidator {
	/**
	 * @param list<string> $headers Response headers.
	 */
	public function validate( string $html, int $status, array $headers ): Decision {
		if ( 200 !== $status ) {
			return Decision::deny( 'status:' . $status );
		}

		if ( '' === trim( $html ) || ! preg_match( '/<(?:!doctype\\s+html|html)[\\s>]/i', $html ) ) {
			return Decision::deny( 'not_html' );
		}

		foreach ( $headers as $header ) {
			$lower = strtolower( $header );
			if ( str_starts_with( $lower, 'set-cookie:' ) ) {
				return Decision::deny( 'set_cookie' );
			}
			if ( str_starts_with( $lower, 'cache-control:' ) && preg_match( '/\\b(?:no-store|private)\\b/i', $lower ) ) {
				return Decision::deny( 'private_cache_control' );
			}
			if ( str_starts_with( $lower, 'content-type:' ) && ! str_contains( $lower, 'text/html' ) ) {
				return Decision::deny( 'content_type' );
			}
		}

		return Decision::allow();
	}
}
