<?php
/**
 * Request cache eligibility.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class Eligibility {
	/**
	 * @param array<string, mixed> $config Compiled cache configuration.
	 */
	public function decide( RequestContext $request, array $config ): Decision {
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
		foreach ( array_keys( $request->query ) as $parameter ) {
			$parameter = strtolower( (string) $parameter );
			if ( in_array( $parameter, $bypass_query, true ) ) {
				return Decision::deny( 'query:' . $parameter );
			}

			if ( ! in_array( $parameter, $ignored_query, true ) ) {
				return Decision::deny( 'unknown_query:' . $parameter );
			}
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
