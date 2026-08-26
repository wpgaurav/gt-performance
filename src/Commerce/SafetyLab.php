<?php
/**
 * Non-destructive commerce cache safety lab.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\ResponseSnapshot;

final class SafetyLab {
	public function __construct(
		private readonly Registry $registry = new Registry(),
		private readonly PolicyAudit $audit = new PolicyAudit(),
		private readonly SafetyReportRepository $reports = new SafetyReportRepository(),
		private readonly ResponseSnapshot $snapshots = new ResponseSnapshot(),
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$active = $this->registry->active();
		$cache  = (array) Settings::get( 'cache', array() );
		$cache  = apply_filters( 'gt_performance_cache_policy', array_merge( $cache, array( 'enabled' => true ) ) );
		$checks = array();
		$live   = array();

		foreach ( $active as $adapter ) {
			$requirements = array(
				'paths'   => $adapter->bypassPaths(),
				'cookies' => $adapter->bypassCookies(),
				'query'   => $adapter->bypassQueryParameters(),
			);
			$checks = array_merge( $checks, $this->audit->audit( $adapter->id(), $requirements, $cache ) );

			foreach ( array_slice( $requirements['paths'], 0, 4 ) as $path ) {
				$live[] = $this->checkUrl( $adapter->id(), home_url( $path ) );
			}
		}

		$failures = count( array_filter( $checks, static fn( array $check ): bool => 'fail' === $check['status'] ) );
		$warnings = count( array_filter( $live, static fn( array $check ): bool => 'pass' !== $check['status'] ) );
		$status   = 0 === $failures && 0 === $warnings ? 'pass' : ( 0 === $failures ? 'warning' : 'fail' );
		$report   = array(
			'id'         => wp_generate_uuid4(),
			'created_at' => current_time( 'mysql', true ),
			'status'     => $status,
			'adapters'   => array_map( static fn( CommerceAdapter $adapter ): string => $adapter->id(), $active ),
			'summary'    => array(
				'policy_checks' => count( $checks ),
				'live_checks'   => count( $live ),
				'failures'      => $failures,
				'warnings'      => $warnings,
			),
			'policy'     => $checks,
			'live'       => $live,
		);

		$this->reports->add( $report );

		return $report;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function checkUrl( string $adapter, string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'user-agent'  => 'GT-Performance-Commerce-Safety-Lab/' . GTPERF_VERSION,
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);
		$snapshot = $this->snapshots->fromWordPressResponse( $response );
		if ( is_wp_error( $snapshot ) ) {
			return array(
				'adapter' => $adapter,
				'url'     => esc_url_raw( $url ),
				'status'  => 'warning',
				'reason'  => 'request_failed',
			);
		}

		$edgeCached = in_array( (string) $snapshot['cf_cache_status'], array( 'HIT', 'STALE', 'REVALIDATED' ), true )
			|| (int) $snapshot['age'] > 0;
		$protected  = (bool) $snapshot['private'] || 'BYPASS' === (string) $snapshot['gt_cache_status'];

		return array(
			'adapter' => $adapter,
			'url'     => esc_url_raw( $url ),
			'status'  => ! $edgeCached && $protected ? 'pass' : 'warning',
			'reason'  => $edgeCached ? 'edge_cache_detected' : ( $protected ? 'private_response' : 'protection_header_missing' ),
			'response' => $snapshot,
		);
	}
}
