<?php
/**
 * Operations an agent may request, their limits, and their execution.
 *
 * Submission records the request and enqueues one `agent_operation` job, then
 * returns at once; the caller polls get-operation. The worker re-checks that
 * agent access is still `operate` and that the requester is still an
 * administrator before doing anything, so revoking either cancels unstarted
 * work. Edge requests already sent cannot be recalled and are reported as sent.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Operations;

use GTPerformance\Abilities\Permissions;
use GTPerformance\Cache\Purger;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\ResponseSnapshot;
use GTPerformance\Queue\JobLease;
use GTPerformance\Queue\JobRepository;

final class OperationService {
	public const JOB_TYPE = 'agent_operation';

	public const MAX_PER_MINUTE = 60;

	public const MAX_OUTSTANDING = 100;

	public const MAX_URLS = 20;

	/** Job types an agent may retry: work that is safe to repeat. */
	public const RETRYABLE = array( 'preload_url', 'generate_css', 'warm_site', 'warm_discover', 'warm_dispatch', 'generate_image_variants' );

	/** @var array<string, int> Queue priority per operation. */
	private const PRIORITY = array(
		'purge_urls'     => 10,
		'retry_job'      => 40,
		'preload_urls'   => 50,
		'regenerate_css' => 70,
	);

	public function __construct(
		private readonly OperationRepository $operations = new OperationRepository(),
		private readonly JobRepository $jobs = new JobRepository(),
	) {
	}

	/**
	 * Record an operation and queue it, or return the earlier result of a replay.
	 *
	 * @param array<string, mixed> $payload Validated input without the request ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function submit( string $operation, array $payload, string $requestId ): array|\WP_Error {
		if ( ! isset( self::PRIORITY[ $operation ] ) ) {
			return new \WP_Error( 'gtperf_operation_unknown', __( 'Unknown operation.', 'gt-performance' ), array( 'status' => 400 ) );
		}
		$actor   = get_current_user_id();
		$payload = self::normalize( $operation, $payload );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$replay = $this->operations->findByRequest( $actor, $requestId );
		if ( null === $replay ) {
			if ( $this->operations->recentByActor( $actor ) >= self::MAX_PER_MINUTE ) {
				return new \WP_Error( 'gtperf_rate_limited', __( 'Too many operations in the last minute. Wait and retry with the same request ID.', 'gt-performance' ), array( 'status' => 429 ) );
			}
			if ( $this->operations->outstanding() >= self::MAX_OUTSTANDING ) {
				return new \WP_Error( 'gtperf_too_many_outstanding', __( 'Too many agent operations are still waiting. Let them finish first.', 'gt-performance' ), array( 'status' => 429 ) );
			}
		}

		$created = $this->operations->create( $actor, $requestId, $operation, $payload, self::summary( $operation, $payload ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$row = $created['row'];
		if ( $created['created'] ) {
			$job = $this->jobs->enqueue( self::JOB_TYPE, array( 'operation' => (int) $row['id'] ), self::PRIORITY[ $operation ] );
			if ( $job <= 0 ) {
				$this->operations->update(
					(int) $row['id'],
					array(
						'status' => 'failed',
						'result' => array( 'error' => 'queue_unavailable' ),
					)
				);
			} else {
				$this->operations->update( (int) $row['id'], array( 'job_id' => $job ) );
			}
			$row = (array) $this->operations->find( (int) $row['id'] );
		}

		return self::present( $row ) + array( 'replayed' => ! $created['created'] );
	}

	/**
	 * Queue handler.
	 *
	 * @param array<string, mixed> $job Job payload.
	 */
	public function execute( array $job ): void {
		$row = $this->operations->find( (int) ( $job['operation'] ?? 0 ) );
		if ( null === $row || ! in_array( $row['status'], array( 'accepted', 'running' ), true ) ) {
			return;
		}
		$user = get_userdata( (int) $row['actor'] );
		if ( ! $user instanceof \WP_User || ! user_can( $user, 'manage_options' ) || 'operate' !== Permissions::mode() ) {
			$this->operations->update(
				(int) $row['id'],
				array(
					'status' => 'cancelled',
					'result' => array( 'reason' => 'permission_revoked' ),
				),
				(string) $row['status']
			);
			return;
		}
		$this->operations->update( (int) $row['id'], array( 'status' => 'running' ) );

		$payload = (array) $row['payload'];
		$result  = match ( (string) $row['operation'] ) {
			'purge_urls'     => $this->purge( (array) $payload['urls'] ),
			'preload_urls'   => $this->preload( (array) $payload['urls'] ),
			'regenerate_css' => $this->regenerateCss( (string) $payload['url'] ),
			'retry_job'      => $this->retry( (int) $payload['job_id'] ),
			default          => array(
				'status' => 'failed',
				'data'   => array( 'error' => 'unknown_operation' ),
			),
		};

		$this->operations->update(
			(int) $row['id'],
			array(
				'status' => $result['status'],
				'result' => $result['data'],
			)
		);
	}

	/**
	 * The public shape of an operation row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public static function present( array $row ): array {
		return array(
			'operation_id' => (int) $row['id'],
			'operation'    => (string) $row['operation'],
			'state'        => (string) $row['status'],
			'target'       => (string) $row['target_summary'],
			'job_id'       => (int) $row['job_id'],
			'created_at'   => (string) $row['created_at'],
			'completed_at' => null === $row['completed_at'] ? null : (string) $row['completed_at'],
			'result'       => (array) $row['result'],
		);
	}

	/**
	 * @param list<string> $urls URLs.
	 * @return array{status:string,data:array<string,mixed>}
	 */
	private function purge( array $urls ): array {
		$started = time();
		$purger  = new Purger();
		$items   = array();
		foreach ( $urls as $url ) {
			JobLease::checkpoint();
			$items[ $url ] = array(
				'url'    => $url,
				'origin' => $purger->purgeUrls( array( $url ) ) > 0 ? 'deleted' : 'not_cached',
			);
		}
		$flushed = apply_filters( 'gt_performance_flush_edge_purges', true );
		$edge    = EdgeReport::since( $started, $flushed );

		$verified = 0;
		foreach ( $items as $url => &$item ) {
			JobLease::checkpoint();
			$item['edge']   = $edge['status'];
			$item['public'] = self::verify( $url );
			$verified      += 'ok' === $item['public']['result'] ? 1 : 0;
		}
		unset( $item );

		$status = in_array( $edge['status'], array( 'failed', 'unknown' ), true ) || $verified < count( $items ) ? 'partial' : 'succeeded';
		if ( 'retrying' === $edge['status'] ) {
			$status = 'partial';
		}

		return array(
			'status' => $status,
			'data'   => array(
				'edge'  => $edge,
				'items' => array_values( $items ),
			),
		);
	}

	/**
	 * One public request after the purge. It does not purge again; it reports
	 * what a visitor now receives.
	 *
	 * @return array<string, mixed>
	 */
	private static function verify( string $url ): array {
		$snapshot = ( new ResponseSnapshot() )->fromWordPressResponse(
			wp_safe_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'user-agent'  => 'GT-Performance-Verifier/' . GTPERF_VERSION,
				)
			)
		);
		if ( is_wp_error( $snapshot ) ) {
			return array(
				'result' => 'request_failed',
				'error'  => Logger::redact( $snapshot->get_error_message() ),
			);
		}

		return array(
			'result'          => 200 === $snapshot['status'] && ! $snapshot['private'] ? 'ok' : 'unexpected',
			'http'            => $snapshot['status'],
			'cf_cache_status' => $snapshot['cf_cache_status'],
			'gt_cache_status' => $snapshot['gt_cache_status'],
			'age'             => $snapshot['age'],
		);
	}

	/**
	 * @param list<string> $urls URLs.
	 * @return array{status:string,data:array<string,mixed>}
	 */
	private function preload( array $urls ): array {
		$jobs = array();
		foreach ( $urls as $url ) {
			$jobs[] = array(
				'url'    => $url,
				'job_id' => $this->jobs->enqueue( 'preload_url', array( 'url' => $url ), 50 ),
			);
		}

		return array(
			'status' => array() === array_filter( $jobs, static fn ( array $job ): bool => $job['job_id'] <= 0 ) ? 'succeeded' : 'partial',
			'data'   => array( 'jobs' => $jobs ),
		);
	}

	/**
	 * @return array{status:string,data:array<string,mixed>}
	 */
	private function regenerateCss( string $url ): array {
		$maintenance = new \GTPerformance\Optimization\Css\Maintenance();
		if ( ! (bool) Settings::get( 'css.enabled', false ) ) {
			return array(
				'status' => 'failed',
				'data'   => array( 'error' => 'unused_css_disabled' ),
			);
		}
		$queued = $maintenance->enqueue( $url, true );

		return array(
			'status' => $queued ? 'succeeded' : 'failed',
			'data'   => array( 'queued' => $queued ),
		);
	}

	/**
	 * @return array{status:string,data:array<string,mixed>}
	 */
	private function retry( int $jobId ): array {
		$retried = $this->jobs->retry( $jobId );

		return array(
			'status' => $retried ? 'succeeded' : 'failed',
			'data'   => array(
				'job_id'  => $jobId,
				'retried' => $retried,
			),
		);
	}

	/**
	 * Validate and canonicalize input so equal requests hash equally.
	 *
	 * @param array<string, mixed> $payload Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function normalize( string $operation, array $payload ): array|\WP_Error {
		if ( in_array( $operation, array( 'purge_urls', 'preload_urls' ), true ) ) {
			$urls = array();
			foreach ( (array) ( $payload['urls'] ?? array() ) as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( ! self::sameSite( $url ) ) {
					return new \WP_Error( 'gtperf_invalid_url', __( 'Every URL must be a public http(s) URL on this site.', 'gt-performance' ), array( 'status' => 400 ) );
				}
				$urls[] = $url;
			}
			$urls = array_values( array_unique( $urls ) );
			sort( $urls );
			if ( array() === $urls || count( $urls ) > self::MAX_URLS ) {
				return new \WP_Error( 'gtperf_invalid_url', __( 'Send between 1 and 20 URLs.', 'gt-performance' ), array( 'status' => 400 ) );
			}
			return array( 'urls' => $urls );
		}
		if ( 'regenerate_css' === $operation ) {
			$url = esc_url_raw( (string) ( $payload['url'] ?? '' ) );
			if ( ! self::sameSite( $url ) || ! \GTPerformance\Optimization\Css\Maintenance::eligible( $url ) ) {
				return new \WP_Error( 'gtperf_invalid_url', __( 'Use a cacheable URL from this site.', 'gt-performance' ), array( 'status' => 400 ) );
			}
			return array( 'url' => $url );
		}
		$jobId = (int) ( $payload['job_id'] ?? 0 );
		$type  = self::jobType( $jobId );
		if ( null === $type || ! in_array( $type, self::RETRYABLE, true ) ) {
			return new \WP_Error( 'gtperf_retry_not_allowed', __( 'Only failed preload, warming, CSS, and image jobs can be retried by an agent.', 'gt-performance' ), array( 'status' => 400 ) );
		}

		return array( 'job_id' => $jobId );
	}

	private static function jobType( int $jobId ): ?string {
		global $wpdb;

		if ( $jobId <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table; trusted prefix.
		$type = $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$wpdb->prefix}gtperf_jobs WHERE id = %d AND status = 'failed'", $jobId ) );

		return is_string( $type ) ? $type : null;
	}

	/**
	 * @param array<string, mixed> $payload Normalized payload.
	 */
	private static function summary( string $operation, array $payload ): string {
		if ( isset( $payload['urls'] ) ) {
			$first = \GTPerformance\Cache\WarmRunRepository::relative( (string) $payload['urls'][0] );
			return count( $payload['urls'] ) > 1 ? $first . ' +' . ( count( $payload['urls'] ) - 1 ) : $first;
		}
		if ( isset( $payload['url'] ) ) {
			return \GTPerformance\Cache\WarmRunRepository::relative( (string) $payload['url'] );
		}

		return $operation . ' #' . (int) ( $payload['job_id'] ?? 0 );
	}

	private static function sameSite( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		return in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), Settings::canonicalHosts(), true );
	}
}
