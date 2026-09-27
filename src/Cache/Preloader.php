<?php
/**
 * One preload request and an evidence-based outcome for it.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\ResponseSnapshot;
use GTPerformance\Queue\JobLease;

final class Preloader {
	/**
	 * Matches CacheKey's mobile pattern, so the request lands in the mobile variant.
	 */
	public const MOBILE_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile GT-Performance-Preloader/';

	/** @var list<string> */
	private const EDGE_HITS = array( 'HIT', 'STALE', 'UPDATING', 'REVALIDATED' );

	public function __construct(
		private readonly FileStore $store = new FileStore(),
	) {
	}

	/**
	 * Request a URL as a preload and report what actually happened.
	 *
	 * @return array{status:string,detail:string,http:int}
	 */
	public function preload( string $url, string $variant = 'public' ): array {
		$agent   = 'mobile' === $variant ? self::MOBILE_AGENT . GTPERF_VERSION : 'GT-Performance-Preloader/' . GTPERF_VERSION;
		$policy  = $this->policy();
		$request = RequestContext::fromUrl( $url, array(), array(), $agent );
		if ( null === $request ) {
			return $this->outcome( 'skipped', 'invalid_url', 0 );
		}
		$decision = ( new Eligibility() )->decide( $request, $policy );
		if ( ! $decision->cacheable ) {
			return $this->outcome( 'skipped', sanitize_key( $decision->reason ), 0 );
		}

		$started  = time();
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array( 'X-GT-Preload' => '1' ) + JobLease::headers(),
				'user-agent'  => $agent,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->outcome( 'failed', 'request_error', 0 );
		}

		$snapshot = ( new ResponseSnapshot() )->fromWordPressResponse( $response );
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$hash     = ( new CacheKey() )->hash( ( new CacheKey() )->make( $request, $policy ) );
		$metadata = is_file( $this->store->pagePath( $hash ) ) ? $this->store->metadata( $hash ) : null;

		return self::classify(
			$status,
			is_array( $snapshot ) ? (string) $snapshot['cf_cache_status'] : '',
			is_array( $snapshot ) ? (string) $snapshot['gt_cache_status'] : '',
			$metadata,
			$started,
			time()
		);
	}

	/**
	 * HTTP 200 alone is not success. The origin artifact is checked separately
	 * from the response: an edge HIT means the request never reached the origin,
	 * so it is reported as edge evidence rather than an origin rebuild.
	 *
	 * @param array<string, mixed>|null $metadata Origin cache metadata, if an entry exists.
	 * @return array{status:string,detail:string,http:int}
	 */
	public static function classify( int $http, string $edge, string $origin, ?array $metadata, int $started, int $now ): array {
		if ( 200 !== $http ) {
			return array(
				'status' => 'failed',
				'detail' => 'http_' . $http,
				'http'   => $http,
			);
		}

		if ( null !== $metadata && (int) ( $metadata['fresh_until'] ?? 0 ) >= $now ) {
			return array(
				'status' => 'origin_ready',
				'detail' => (int) ( $metadata['stored_at'] ?? 0 ) >= $started - 1 ? 'rebuilt' : 'existing',
				'http'   => $http,
			);
		}

		$edge = strtoupper( $edge );
		if ( in_array( $edge, self::EDGE_HITS, true ) ) {
			return array(
				'status' => 'edge_observed',
				'detail' => 'cf_' . strtolower( $edge ),
				'http'   => $http,
			);
		}

		return array(
			'status' => 'requested',
			'detail' => '' === $origin ? 'no_artifact' : 'gt_' . sanitize_key( strtolower( $origin ) ),
			'http'   => $http,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function policy(): array {
		$policy               = (array) Settings::get( 'cache', array() );
		$policy['generation'] = (int) Settings::get( 'generation', 1 );
		$policy['hosts']      = Settings::canonicalHosts();

		return (array) apply_filters( 'gt_performance_cache_policy', $policy );
	}

	/**
	 * @return array{status:string,detail:string,http:int}
	 */
	private function outcome( string $status, string $detail, int $http ): array {
		return array(
			'status' => $status,
			'detail' => $detail,
			'http'   => $http,
		);
	}
}
