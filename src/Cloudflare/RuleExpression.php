<?php
/**
 * Cloudflare Free-compatible cache rule compiler.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

use GTPerformance\Cache\RequestContext;

final class RuleExpression {
	/**
	 * Compile the managed rule expression.
	 *
	 * @param array<string, mixed> $cache             Cache policy.
	 * @param bool                 $requireEmptyQuery Restrict the rule to requests carrying no query string.
	 */
	public function compile( string $host, array $cache, bool $requireEmptyQuery = false ): string {
		$parts = array(
			'(http.host eq "' . $this->escape( $this->normalizeHost( $host ) ) . '")',
			'(http.request.method in {"GET" "HEAD"})',
		);

		// When the action overrides origin freshness, Cloudflare stops honouring the
		// `no-store` the origin sends for any request it refuses to cache. The origin
		// denies every unrecognised query parameter, and that set is unbounded, so an
		// overriding rule must be narrowed to the one shape the origin always accepts:
		// no query string at all. Without this the edge holds private responses for the
		// full edge TTL and an attacker can mint unlimited edge entries with `?x=<n>`.
		if ( $requireEmptyQuery ) {
			$parts[] = '(http.request.uri.query eq "")';
		}

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

		if ( ! $requireEmptyQuery ) {
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
		}

		return implode( ' and ', $parts );
	}

	/**
	 * Evaluate the compiled rule against a request, mirroring the terms compile()
	 * emits. This is what lets a diagnostic assert that the edge and the origin
	 * agree about a URL instead of printing both and leaving the reader to compare.
	 *
	 * @param array<string, mixed> $cache             Cache policy.
	 * @param bool                 $requireEmptyQuery Whether the rule carries the empty-query term.
	 */
	public function matches( RequestContext $request, string $host, array $cache, bool $requireEmptyQuery = false ): bool {
		if ( $this->normalizeHost( $request->host ) !== $this->normalizeHost( $host ) ) {
			return false;
		}

		if ( ! in_array( $request->method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}

		$query = http_build_query( $request->query, '', '&', PHP_QUERY_RFC3986 );

		if ( $requireEmptyQuery && '' !== $query ) {
			return false;
		}

		foreach ( (array) ( $cache['bypass_paths'] ?? array() ) as $path ) {
			$prefix = rtrim( (string) $path, '/' );
			if ( '' === $prefix ) {
				if ( '' !== (string) $path && '/' === $request->path ) {
					return false;
				}
				continue;
			}

			if ( $request->path === $prefix || str_starts_with( $request->path, $prefix . '/' ) ) {
				return false;
			}
		}

		$cookieHeader = '';
		foreach ( $request->cookies as $name => $value ) {
			$cookieHeader .= ( '' === $cookieHeader ? '' : '; ' ) . $name . '=' . $value;
		}

		foreach ( (array) ( $cache['bypass_cookies'] ?? array() ) as $cookie ) {
			$cookie = (string) $cookie;
			if ( '' !== $cookie && str_contains( $cookieHeader, $cookie ) ) {
				return false;
			}
		}

		if ( ! $requireEmptyQuery ) {
			foreach ( (array) ( $cache['bypass_query_params'] ?? array() ) as $parameter ) {
				$parameter = (string) $parameter;
				if ( '' === $parameter ) {
					continue;
				}

				$name = rawurlencode( $parameter );
				if ( str_starts_with( $query, $name . '=' ) || str_contains( $query, '&' . $name . '=' ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private function normalizeHost( string $host ): string {
		return (string) preg_replace( '/:\d+$/', '', strtolower( $host ) );
	}

	private function escape( string $value ): string {
		return addcslashes( $value, "\\\"\n\r" );
	}
}
