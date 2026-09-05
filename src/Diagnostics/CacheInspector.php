<?php
/**
 * Explainable cache policy and local artifact inspection.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Diagnostics;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Cloudflare\RuleExpression;
use GTPerformance\Core\Settings;

final class CacheInspector {
	public function __construct(
		private readonly FileStore $store = new FileStore(),
	) {
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function inspect( string $url ): array|\WP_Error {
		$url = esc_url_raw( $url );
		if ( ! $this->sameSite( $url ) ) {
			return new \WP_Error( 'gtperf_diagnostic_url', __( 'Use a URL from this WordPress site.', 'gt-performance' ) );
		}

		$request = RequestContext::fromUrl( $url );
		if ( null === $request ) {
			return new \WP_Error( 'gtperf_diagnostic_url', __( 'The URL could not be inspected.', 'gt-performance' ) );
		}

		$cachePolicy               = (array) Settings::get( 'cache', array() );
		$cachePolicy['generation'] = (int) Settings::get( 'generation', 1 );
		$cachePolicy['hosts']      = Settings::canonicalHosts();
		$cachePolicy               = apply_filters( 'gt_performance_cache_policy', $cachePolicy );
		$decision                  = ( new Eligibility() )->decide( $request, $cachePolicy );
		$key                       = ( new CacheKey() )->make( $request, $cachePolicy );
		$hash                      = ( new CacheKey() )->hash( $key );
		$metadata                  = $this->store->metadata( $hash );
		$now                       = time();
		$state                     = 'missing';

		if ( null !== $metadata && is_file( $this->store->pagePath( $hash ) ) ) {
			$state = $now <= (int) ( $metadata['fresh_until'] ?? 0 ) ? 'fresh' : 'stale';
			if ( $now > (int) ( $metadata['stale_until'] ?? 0 ) ) {
				$state = 'expired';
			}
		}

		return array(
			'url'            => $url,
			'cacheable'      => $decision->cacheable,
			'reason'         => $decision->reason,
			'cache_key'      => $key,
			'cache_hash'     => $hash,
			'cache_hash_short' => substr( $hash, 0, 12 ),
			'origin'         => array(
				'state'       => $state,
				'bytes'       => $this->store->size( $hash ),
				'stored_at'   => (int) ( $metadata['stored_at'] ?? 0 ),
				'fresh_until' => (int) ( $metadata['fresh_until'] ?? 0 ),
				'stale_until' => (int) ( $metadata['stale_until'] ?? 0 ),
			),
			'cloudflare'     => $this->edgeExpectation( $request, $cachePolicy, $decision->cacheable ),
			'policy'         => array(
				'generation' => (int) $cachePolicy['generation'],
				'paths'      => count( (array) ( $cachePolicy['bypass_paths'] ?? array() ) ),
				'cookies'    => count( (array) ( $cachePolicy['bypass_cookies'] ?? array() ) ),
				'query'      => count( (array) ( $cachePolicy['bypass_query_params'] ?? array() ) ),
			),
		);
	}

	/**
	 * Compare what the compiled Cloudflare rule would do with this request against
	 * what the origin decided. Printing both and leaving the reader to compare is
	 * how a rule that caches what the origin refuses stays invisible; this asserts
	 * the two agree and names the disagreement when they do not.
	 *
	 * @param array<string, mixed> $cachePolicy Compiled cache policy.
	 * @return array<string, mixed>
	 */
	private function edgeExpectation( RequestContext $request, array $cachePolicy, bool $originCacheable ): array {
		$edgeTtl  = max( 0, (int) Settings::get( 'cloudflare.edge_ttl', 0 ) );
		$override = $edgeTtl > 0;
		$rule     = new RuleExpression();
		$matches  = $rule->matches( $request, $request->host, $cachePolicy, $override );

		// respect_origin leaves the origin's Cache-Control in charge, so a rule that
		// matches a bypassed request still stores nothing. Only override_origin can
		// contradict the origin, and only that combination is unsafe.
		$unsafe = $override && $matches && ! $originCacheable;

		return array(
			'enabled'         => (bool) Settings::get( 'cloudflare.enabled', false ),
			'expression'      => $rule->compile( $request->host, $cachePolicy, $override ),
			'expectation'     => $originCacheable ? 'eligible' : 'bypass',
			'edge_matches'    => $matches,
			'override_origin' => $override,
			'agrees'          => ! $unsafe,
			'disagreement'    => $unsafe
				? __( 'The managed rule matches this request and overrides origin freshness, so Cloudflare would cache a response the origin marks private. Set the Cloudflare edge cache lifetime to 0 to respect the origin.', 'gt-performance' )
				: '',
		);
	}

	private function sameSite( string $url ): bool {
		$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$homeHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$scheme   = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return '' !== $host && hash_equals( $homeHost, $host ) && in_array( $scheme, array( 'http', 'https' ), true );
	}
}
