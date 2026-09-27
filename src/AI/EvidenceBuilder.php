<?php
/**
 * The redacted, bounded report an adviser request may send.
 *
 * Everything here already exists without AI: saved diagnostics, queue state,
 * non-secret settings, cache explanations. Each item gets an evidence ID the
 * model must cite. The report carries relative paths only, no query strings,
 * no HTML bodies, cookies, headers, orders, customers, credentials, or
 * absolute server paths, and it is capped at 24 KB.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\AI;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\PurgePreview;
use GTPerformance\Cache\WarmRunRepository;
use GTPerformance\Core\Database;
use GTPerformance\Core\Logger;
use GTPerformance\Core\PublicSettings;
use GTPerformance\Diagnostics\CacheInspector;
use GTPerformance\Diagnostics\HealthReport;
use GTPerformance\Diagnostics\PurgeReceiptRepository;
use GTPerformance\Optimization\Css\ReportRepository;
use GTPerformance\Queue\JobRepository;

final class EvidenceBuilder {
	public const MAX_BYTES = 24 * 1024;

	/** @var array<string, string> */
	public const TASKS = array(
		'explain_page'    => 'Explain how this page is cached and optimized',
		'diagnose_queue'  => 'Diagnose background queue and cache warming problems',
		'review_settings' => 'Review the current optimization settings',
		'explain_purge'   => 'Explain what a purge did or would do',
	);

	/** Settings sections an adviser may see. */
	private const SETTINGS_SECTIONS = array( 'cache', 'css', 'javascript', 'media', 'fonts', 'speculation' );

	/**
	 * @param array<string, mixed> $input Task input: `url` for explain_page, optional `post_id` for explain_purge.
	 * @return array{task:string,generated_at:string,items:list<array{id:string,kind:string,data:mixed}>}|\WP_Error
	 */
	public function build( string $task, array $input = array() ): array|\WP_Error {
		if ( ! isset( self::TASKS[ $task ] ) ) {
			return new \WP_Error( 'gtperf_ai_task', __( 'Unknown adviser task.', 'gt-performance' ) );
		}
		$items = array();
		$add   = static function ( string $kind, mixed $data ) use ( &$items ): void {
			$items[] = array(
				'id'   => 'E' . ( count( $items ) + 1 ),
				'kind' => $kind,
				'data' => self::scrub( $data ),
			);
		};

		if ( 'explain_page' === $task ) {
			$url    = esc_url_raw( (string) ( $input['url'] ?? '' ) );
			$report = ( new CacheInspector() )->inspect( $url );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			$add(
				'cache_explanation',
				array(
					'path'       => WarmRunRepository::relative( $url ),
					'cacheable'  => $report['cacheable'],
					'reason'     => $report['reason'],
					'origin'     => $report['origin'],
					'cloudflare' => array(
						'enabled'      => $report['cloudflare']['enabled'],
						'expectation'  => $report['cloudflare']['expectation'],
						'edge_matches' => $report['cloudflare']['edge_matches'],
						'agrees'       => $report['cloudflare']['agrees'],
					),
				)
			);
			$css = ( new ReportRepository() )->find( $url, (string) \GTPerformance\Core\Settings::get( 'css.mode', 'file' ) );
			if ( null !== $css ) {
				$add(
					'css_report',
					array(
						'status'          => ( new ReportRepository() )->effectiveStatus( $css ),
						'original_bytes'  => (int) ( $css['metadata']['original_bytes'] ?? 0 ),
						'generated_bytes' => (int) ( $css['metadata']['generated_bytes'] ?? 0 ),
						'error'           => (string) ( $css['metadata']['error'] ?? '' ),
					)
				);
			}
			$add( 'settings', self::settings( array( 'cache', 'css', 'javascript', 'media' ) ) );
		}

		if ( 'diagnose_queue' === $task ) {
			$jobs = new JobRepository();
			if ( Database::queueReady() ) {
				$add( 'queue_counts', $jobs->counts() + array( 'oldest_due_seconds' => $jobs->oldestDueAge() ) );
				$add(
					'failed_jobs',
					array_map(
						static fn ( array $job ): array => array(
							'type'       => $job['type'],
							'attempts'   => (int) $job['attempts'],
							'last_error' => (string) $job['last_error'],
							'updated_at' => $job['updated_at'],
						),
						$jobs->list( 'failed', 15 )
					)
				);
				$warm = ( new CacheWarmer( new Logger() ) )->summary();
				if ( null !== $warm ) {
					unset( $warm['id'] );
					$add( 'warm_run', $warm );
				}
			}
			$add( 'health', self::health( array( 'queue', 'queue_heartbeat', 'cron', 'warming' ) ) );
		}

		if ( 'review_settings' === $task ) {
			$add( 'settings', self::settings( self::SETTINGS_SECTIONS ) );
			$add( 'health', self::health( array() ) );
		}

		if ( 'explain_purge' === $task ) {
			$add(
				'purge_receipts',
				array_map(
					static fn ( array $receipt ): array => array(
						'path'           => WarmRunRepository::relative( (string) ( $receipt['url'] ?? '' ) ),
						'status'         => $receipt['status'] ?? '',
						'origin_removed' => $receipt['origin_removed'] ?? null,
						'safe_response'  => $receipt['safe_response'] ?? null,
						'edge_first'     => $receipt['edge_first']['cf_cache_status'] ?? '',
						'edge_second'    => $receipt['edge_second']['cf_cache_status'] ?? '',
						'created_at'     => $receipt['created_at'] ?? '',
					),
					( new PurgeReceiptRepository() )->recent( 5 )
				)
			);
			$edge = get_option( \GTPerformance\Cloudflare\CloudflareModule::STATUS_OPTION, array() );
			if ( is_array( $edge ) && array() !== $edge ) {
				$add( 'last_edge_purge', array_intersect_key( $edge, array_flip( array( 'operation', 'status', 'created_at', 'attempt', 'entries', 'error_code' ) ) ) );
			}
			$postId = (int) ( $input['post_id'] ?? 0 );
			if ( $postId > 0 ) {
				$preview = ( new PurgePreview() )->forPost( $postId, true );
				if ( ! is_wp_error( $preview ) ) {
					$preview['urls'] = array_map(
						static fn ( array $item ): array => array(
							'path'    => WarmRunRepository::relative( (string) $item['url'] ),
							'reasons' => $item['reasons'],
						),
						array_slice( $preview['urls'], 0, 40 )
					);
					$add( 'purge_preview', $preview );
				}
			}
		}

		$report = array(
			'task'         => $task,
			'generated_at' => gmdate( 'c' ),
			'items'        => $items,
		);

		return self::bound( $report );
	}

	/**
	 * @param list<string> $sections Sections.
	 * @return array<string, mixed>
	 */
	private static function settings( array $sections ): array {
		$view = PublicSettings::view();

		return array_intersect_key( $view, array_flip( $sections ) );
	}

	/**
	 * @param list<string> $ids Check IDs, or all.
	 * @return list<array<string, mixed>>
	 */
	private static function health( array $ids ): array {
		$checks = HealthReport::redact( ( new HealthReport() )->build() )['checks'];

		return array_values( array_filter( $checks, static fn ( array $check ): bool => array() === $ids || in_array( $check['id'], $ids, true ) ) );
	}

	/**
	 * Strip absolute URLs down to paths, redact secrets, and drop server paths.
	 */
	private static function scrub( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'scrub' ), $value );
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$value = str_replace( array( ABSPATH, WP_CONTENT_DIR ), array( '[abspath]/', '[content]' ), $value );
		$value = (string) preg_replace_callback(
			'#https?://[^\s"\'<>]+#i',
			static fn ( array $m ): string => WarmRunRepository::relative( $m[0] ),
			$value
		);

		return substr( Logger::redact( $value ), 0, 600 );
	}

	/**
	 * Shrink the largest lists until the report fits.
	 *
	 * @param array{task:string,generated_at:string,items:list<array{id:string,kind:string,data:mixed}>} $report Report.
	 * @return array{task:string,generated_at:string,items:list<array{id:string,kind:string,data:mixed}>}
	 */
	private static function bound( array $report ): array {
		$size = strlen( (string) wp_json_encode( $report ) );
		while ( $size > self::MAX_BYTES ) {
			$largest = 0;
			foreach ( $report['items'] as $index => $item ) {
				if ( strlen( (string) wp_json_encode( $item ) ) > strlen( (string) wp_json_encode( $report['items'][ $largest ] ) ) ) {
					$largest = $index;
				}
			}
			$data = $report['items'][ $largest ]['data'];
			if ( is_array( $data ) && array_is_list( $data ) && count( $data ) > 1 ) {
				$report['items'][ $largest ]['data'] = array_slice( $data, 0, (int) floor( count( $data ) / 2 ) );
			} else {
				$report['items'][ $largest ]['data'] = '[omitted: too large]';
			}
			$size = strlen( (string) wp_json_encode( $report ) );
		}

		return $report;
	}
}
