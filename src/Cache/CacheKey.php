<?php
/**
 * Deterministic page cache keys.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class CacheKey {
	/**
	 * @param array<string, mixed> $config Compiled cache configuration.
	 */
	public function make( RequestContext $request, array $config ): string {
		$query   = $request->query;
		$ignored = array_map( 'strtolower', (array) ( $config['ignored_query_params'] ?? array() ) );

		foreach ( array_keys( $query ) as $key ) {
			if ( in_array( strtolower( (string) $key ), $ignored, true ) ) {
				unset( $query[ $key ] );
			}
		}

		ksort( $query );

		$variant = 'public';
		if ( (bool) ( $config['separate_mobile'] ?? false ) && preg_match( '/Mobile|Android|iPhone|iPad/i', $request->userAgent ) ) {
			$variant = 'mobile';
		}

		return implode(
			'|',
			array(
				$request->scheme,
				strtolower( $request->host ),
				$request->path,
				http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
				$variant,
				(string) ( $config['generation'] ?? 1 ),
			)
		);
	}

	public function hash( string $key ): string {
		return hash( 'sha256', $key );
	}
}
