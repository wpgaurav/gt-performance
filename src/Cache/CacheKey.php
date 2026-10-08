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
		$query = $this->keptQuery( $request, $config );

		$variant = $this->isMobile( $request, $config ) ? 'mobile' : 'public';

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

	/**
	 * Whether the request gets the separately stored mobile copy.
	 *
	 * @param array<string, mixed> $config Compiled cache configuration.
	 */
	public function isMobile( RequestContext $request, array $config ): bool {
		return (bool) ( $config['separate_mobile'] ?? false ) && 1 === preg_match( '/' . self::MOBILE_AGENTS . '/i', $request->userAgent );
	}

	/** User-agent pattern for the mobile copy. ServerRules mirrors it. */
	public const MOBILE_AGENTS = 'Mobile|Android|iPhone|iPad';

	/**
	 * The query string that selects a separately cached copy: everything but the
	 * ignored parameters, in a fixed order. Empty for the page's main copy.
	 *
	 * @param array<string, mixed> $config Compiled cache configuration.
	 */
	public function variant( RequestContext $request, array $config ): string {
		return http_build_query( $this->keptQuery( $request, $config ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * @param array<string, mixed> $config Compiled cache configuration.
	 * @return array<string, mixed>
	 */
	private function keptQuery( RequestContext $request, array $config ): array {
		$query   = $request->query;
		$ignored = array_map( 'strtolower', (array) ( $config['ignored_query_params'] ?? array() ) );

		foreach ( array_keys( $query ) as $key ) {
			if ( in_array( strtolower( (string) $key ), $ignored, true ) ) {
				unset( $query[ $key ] );
			}
		}

		ksort( $query );

		return $query;
	}

	public function hash( string $key ): string {
		return hash( 'sha256', $key );
	}
}
