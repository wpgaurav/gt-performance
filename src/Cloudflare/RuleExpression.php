<?php
/**
 * Cloudflare Free-compatible cache rule compiler.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

final class RuleExpression {
	/**
	 * @param array<string, mixed> $cache Cache policy.
	 */
	public function compile( string $host, array $cache ): string {
		$parts = array(
			'(http.host eq "' . $this->escape( preg_replace( '/:\\d+$/', '', strtolower( $host ) ) ) . '")',
			'(http.request.method in {"GET" "HEAD"})',
		);

		foreach ( (array) ( $cache['bypass_paths'] ?? array() ) as $path ) {
			$prefix = rtrim( (string) $path, '/' );
			if ( '' === $prefix ) {
				if ( '' !== (string) $path ) {
					$parts[] = '(not http.request.uri.path eq "/")';
				}
				continue;
			}

			// Match the bypass on segment boundaries so `/checkout/` also protects the
			// canonical `/checkout` without matching siblings like `/checkout-summary`.
			$escaped = $this->escape( $prefix );
			$parts[] = '(not (http.request.uri.path eq "' . $escaped . '" or starts_with(http.request.uri.path, "' . $escaped . '/")))';
		}

		foreach ( (array) ( $cache['bypass_cookies'] ?? array() ) as $cookie ) {
			$cookie = (string) $cookie;
			if ( '' !== $cookie ) {
				$parts[] = '(not http.cookie contains "' . $this->escape( $cookie ) . '")';
			}
		}

		foreach ( (array) ( $cache['bypass_query_params'] ?? array() ) as $parameter ) {
			$parameter = (string) $parameter;
			if ( '' !== $parameter ) {
				// Anchor the match to a parameter boundary so bypass `s` does not also
				// exclude `?utms=` or `?forms=`. Prefixing a separator with
				// concat("&", …) expresses this in one term, but Cloudflare rejects any
				// expression that calls concat more than once (error 20127), so a rule
				// with several bypass parameters can never be saved. Testing the first
				// parameter and the later ones separately is equivalent and calls no
				// functions Cloudflare rations.
				$name    = $this->escape( rawurlencode( $parameter ) );
				$parts[] = '(not (starts_with(http.request.uri.query, "' . $name . '=") or http.request.uri.query contains "&' . $name . '="))';
			}
		}

		return implode( ' and ', $parts );
	}

	private function escape( string $value ): string {
		return addcslashes( $value, "\\\"\n\r" );
	}
}
