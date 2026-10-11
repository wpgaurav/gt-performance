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
			// Cloudflare evaluates cache rules for internal single-file PURGE requests.
			'(http.request.method in {"GET" "HEAD" "PURGE"})',
			// Alternate representations must reach their renderer, never a URL-only HTML cache.
			'(not (lower(http.request.headers["accept"][0]) contains "text/markdown" or lower(http.request.headers["accept"][0]) contains "text/x-markdown"))',
			'(not any(http.request.headers["authorization"][*] ne ""))',
			// A signed preload must reach the origin to rebuild a stale disk entry.
			'(not any(http.request.headers["x-gt-preload"][*] ne ""))',
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

		$paths = array();
		foreach ( (array) ( $cache['bypass_paths'] ?? array() ) as $path ) {
			if ( '' !== (string) $path ) {
				$paths[] = rtrim( (string) $path, '/' );
			}
		}
		$paths = array_values( array_unique( $paths ) );
		$paths = array_values(
			array_filter(
				$paths,
				static function ( string $path ) use ( $paths ): bool {
					foreach ( $paths as $parent ) {
						if ( '' !== $parent && $parent !== $path && str_starts_with( $path, $parent . '/' ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		if ( $paths ) {
			$terms = array( 'http.request.uri.path in {' . implode( ' ', array_map( fn ( string $path ): string => '"' . $this->escape( '' === $path ? '/' : $path ) . '"', $paths ) ) . '}' );
			foreach ( $paths as $path ) {
				if ( '' !== $path ) {
					$terms[] = 'starts_with(http.request.uri.path, "' . $this->escape( $path ) . '/")';
				}
			}
			$parts[] = '(not (' . implode( ' or ', $terms ) . '))';
		}

		$cookies = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $cache['bypass_cookies'] ?? array() ) ), static fn ( string $cookie ): bool => '' !== $cookie ) ) );
		$cookies = array_values(
			array_filter(
				$cookies,
				static function ( string $cookie ) use ( $cookies ): bool {
					foreach ( $cookies as $parent ) {
						if ( $parent !== $cookie && str_contains( $cookie, $parent ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		if ( $cookies ) {
			$parts[] = '(not (' . implode( ' or ', array_map( fn ( string $cookie ): string => 'http.cookie contains "' . $this->escape( $cookie ) . '"', $cookies ) ) . '))';
		}

		if ( ! $requireEmptyQuery ) {
			$parameters = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', array_merge( array( 'format' ), (array) ( $cache['bypass_query_params'] ?? array() ) ) ) ), static fn ( string $name ): bool => '' !== $name ) ) );
			if ( $parameters ) {
				// Decode before matching, as PHP does. A set matches whole parameter
				// names (including empty values) without repeating the query field.
				$parts[] = '(not any(lower(url_decode(http.request.uri.args.names[*])[*])[*] in {' . implode( ' ', array_map( fn ( string $name ): string => '"' . $this->escape( $name ) . '"', $parameters ) ) . '}))';
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

		if ( ! in_array( $request->method, array( 'GET', 'HEAD', 'PURGE' ), true ) ) {
			return false;
		}
		if ( $request->alternateRepresentation() || '' !== trim( (string) ( $request->headers['authorization'] ?? '' ) ) ) {
			return false;
		}
		if ( '' !== trim( (string) ( $request->headers['x-gt-preload'] ?? '' ) ) ) {
			return false;
		}

		$query = http_build_query( $request->query, '', '&', PHP_QUERY_RFC3986 );

		// The edge sees `name[]=` in the raw query string even though the parsed
		// context carries no value for it, so the query is not empty there.
		if ( $requireEmptyQuery && ( '' !== $query || array() !== $request->arrayQuery ) ) {
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
			$parameters = array_map( 'strtolower', array_map( 'strval', (array) ( $cache['bypass_query_params'] ?? array() ) ) );
			foreach ( array_keys( $request->query ) as $name ) {
				if ( in_array( strtolower( (string) $name ), $parameters, true ) ) {
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
