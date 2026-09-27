<?php
/**
 * Request cache eligibility.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class Eligibility {
	/** Longest value a "cache separately" parameter may carry and still be cached. */
	public const MAX_VARY_VALUE = 100;

	/**
	 * @param array<string, mixed> $config Compiled cache configuration.
	 */
	public function decide( RequestContext $request, array $config ): Decision {
		// Asset delivery must never collide with a cached HTML page, even when an
		// administrator accidentally adds this parameter to the ignored-query list.
		if ( array_key_exists( 'gtperf_js', $request->query ) ) {
			return Decision::deny( 'javascript_asset' );
		}
		if ( ! (bool) ( $config['enabled'] ?? false ) ) {
			return Decision::deny( 'cache_disabled' );
		}

		if ( ! in_array( $request->method, array( 'GET', 'HEAD' ), true ) ) {
			return Decision::deny( 'method' );
		}

		if ( '' === $request->host ) {
			return Decision::deny( 'host_missing' );
		}

		// HTTP_HOST is client-supplied. On a catch-all vhost an attacker can vary it
		// freely, and it is part of the cache key and of the URL recorded in each
		// entry's metadata, which the preload queue later fetches with wp_remote_get().
		// Refusing an unrecognised Host bounds the key space and keeps the queue from
		// being handed a URL nobody on this site chose.
		$hosts = array_map( 'strval', (array) ( $config['hosts'] ?? array() ) );
		if ( $hosts ) {
			$host = strtolower( (string) preg_replace( '/:\d+$/', '', $request->host ) );
			if ( ! in_array( $host, $hosts, true ) ) {
				return Decision::deny( 'foreign_host' );
			}
		}

		if ( '' !== trim( (string) ( $request->headers['authorization'] ?? '' ) ) ) {
			return Decision::deny( 'authorization' );
		}

		foreach ( (array) ( $config['bypass_paths'] ?? array() ) as $path ) {
			$path = (string) $path;
			if ( '' !== $path && self::pathMatches( $request->path, $path ) ) {
				return Decision::deny( 'path:' . $path );
			}
		}

		$bypass_query  = array_map( 'strtolower', (array) ( $config['bypass_query_params'] ?? array() ) );
		$ignored_query = array_map( 'strtolower', (array) ( $config['ignored_query_params'] ?? array() ) );
		$vary_query    = array_map( 'strtolower', (array) ( $config['vary_query_params'] ?? array() ) );
		foreach ( $request->query as $parameter => $value ) {
			$parameter = strtolower( (string) $parameter );
			if ( in_array( $parameter, $bypass_query, true ) ) {
				return Decision::deny( 'query:' . $parameter );
			}

			if ( in_array( $parameter, $ignored_query, true ) ) {
				continue;
			}

			// Each value is its own stored copy, so only short values qualify: long
			// strings are how a crawler or an attacker mints keys.
			if ( in_array( $parameter, $vary_query, true ) ) {
				if ( strlen( (string) $value ) > self::MAX_VARY_VALUE ) {
					return Decision::deny( 'query_value:' . $parameter );
				}
				continue;
			}

			return Decision::deny( 'unknown_query:' . $parameter );
		}

		foreach ( array_keys( $request->cookies ) as $cookie ) {
			foreach ( (array) ( $config['bypass_cookies'] ?? array() ) as $pattern ) {
				$pattern = (string) $pattern;
				if ( '' !== $pattern && str_starts_with( $cookie, $pattern ) ) {
					return Decision::deny( 'cookie:' . $pattern );
				}
			}
		}

		return Decision::allow();
	}

	/**
	 * Match a bypass path against a request path on segment boundaries.
	 *
	 * A configured bypass such as `/checkout/` must protect the canonical
	 * `/checkout` served on no-trailing-slash permalink structures, as well as
	 * `/checkout/` and everything below it, without also matching unrelated
	 * siblings like `/checkout-summary`.
	 */
	private static function pathMatches( string $requestPath, string $bypassPath ): bool {
		$prefix = rtrim( $bypassPath, '/' );

		if ( '' === $prefix ) {
			// The bypass was configured for the site root only.
			return '/' === $requestPath;
		}

		return $requestPath === $prefix || str_starts_with( $requestPath, $prefix . '/' );
	}
}
