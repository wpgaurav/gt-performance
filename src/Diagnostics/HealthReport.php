<?php
/**
 * One bounded health report shared by Tools, WP-CLI, Site Health, and the
 * support export.
 *
 * Every check reads saved state or a cheap local probe; nothing here requests a
 * URL, walks the cache directory, or contacts an edge provider. Each check names
 * its source and observation time so a saved result is never mistaken for a
 * live one. It does not estimate a site-wide hit rate: early cache and edge hits
 * never run WordPress, so the plugin cannot count them.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Diagnostics;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Cache\WpCacheConstant;
use GTPerformance\Core\Database;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Optimization\Css\ReportRepository;
use GTPerformance\Queue\JobRepository;
use GTPerformance\Queue\QueueModule;
use GTPerformance\Redis\ObjectCacheInstaller;
use GTPerformance\XCloud\EdgeOwnership;

final class HealthReport {
	public const SCHEMA_VERSION = 1;

	private const STALE_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$now = time();

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'observed_at'    => gmdate( 'c', $now ),
			'versions'       => array(
				'plugin'    => GTPERF_VERSION,
				'wordpress' => (string) get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'schema'    => (string) get_option( 'gt_performance_schema_version', '' ),
			),
			'checks'         => self::evaluate( $this->evidence(), $now ),
		);
	}

	/**
	 * The report with free-text values redacted, for export outside wp-admin.
	 *
	 * @param array<string, mixed> $report Built report.
	 * @return array<string, mixed>
	 */
	public static function redact( array $report ): array {
		array_walk_recursive(
			$report,
			static function ( mixed &$value ): void {
				if ( is_string( $value ) ) {
					$value = Logger::redact( str_replace( array( ABSPATH, WP_CONTENT_DIR ), array( '[abspath]/', '[content]' ), $value ) );
				}
			}
		);

		return $report;
	}

	/**
	 * Worst status across checks.
	 *
	 * @param list<array<string, mixed>> $checks Checks.
	 */
	public static function overall( array $checks ): string {
		$rank = array(
			'info'    => 0,
			'pass'    => 0,
			'warning' => 1,
			'fail'    => 2,
		);
		$worst = 'pass';
		foreach ( $checks as $check ) {
			$status = (string) ( $check['status'] ?? 'pass' );
			if ( ( $rank[ $status ] ?? 0 ) > $rank[ $worst ] ) {
				$worst = $status;
			}
		}

		return $worst;
	}

	/**
	 * Turn gathered evidence into checks. Pure, so every threshold is testable.
	 *
	 * @param array<string, mixed> $evidence Evidence from evidence().
	 * @return list<array{id:string,label:string,status:string,value:string,source:string,observed_at:int}>
	 */
	public static function evaluate( array $evidence, int $now ): array {
		$checks = array();
		$queue  = (array) $evidence['queue'];

		if ( ! $queue['ready'] ) {
			$checks[] = self::check( 'queue', 'Background queue', 'warning', 'Schema upgrade pending; new jobs wait until an administrator screen or WP-CLI completes it.', 'live', $now );
		} else {
			$age      = (int) $queue['oldest_due_age'];
			$status   = (int) $queue['failed'] > 0 || $age > self::STALE_SECONDS ? 'warning' : 'pass';
			$checks[] = self::check(
				'queue',
				'Background queue',
				$status,
				sprintf(
					'%d pending, %d running, %d failed%s; oldest due job waited %s.%s',
					(int) $queue['pending'],
					(int) $queue['running'],
					(int) $queue['failed'],
					$queue['paused'] ? ', optional work paused' : '',
					self::duration( $age ),
					(int) $queue['failed'] > 0 ? ' Retry or cancel failed jobs in Tools.' : ''
				),
				'live',
				$now
			);
		}

		$beat     = (int) $evidence['heartbeat'];
		$checks[] = 0 === $beat
			? self::check( 'queue_heartbeat', 'Queue runner', 'warning', 'No scheduled queue run has been recorded yet.', 'saved', 0 )
			: self::check(
				'queue_heartbeat',
				'Queue runner',
				$now - $beat > self::STALE_SECONDS ? 'warning' : 'pass',
				'Last scheduled run ' . self::duration( max( 0, $now - $beat ) ) . ' ago.',
				'saved',
				$beat
			);

		$cron     = (array) $evidence['cron'];
		$checks[] = self::check( 'cron', 'WP-Cron', 'pass' === $cron['status'] ? 'pass' : 'warning', (string) $cron['value'], 'live', $now );

		$checks[] = self::check(
			'storage',
			'Cache storage',
			$evidence['storage_writable'] ? 'pass' : 'fail',
			$evidence['storage_writable'] ? 'Cache directory is writable.' : 'Cache directory is not writable; pages and generated assets cannot be stored.',
			'live',
			$now
		);

		$dropin   = (string) $evidence['page_dropin'];
		$wpCache  = (string) $evidence['wp_cache'];
		$cache    = (bool) $evidence['cache_enabled'];
		$ready    = 'owned' === $dropin && 'enabled' === $wpCache;
		$checks[] = self::check(
			'page_dropin',
			'Page-cache drop-in',
			! $cache ? 'info' : ( $ready ? 'pass' : ( 'conflict' === $dropin ? 'fail' : 'warning' ) ),
			! $cache ? 'Origin page cache is disabled.' : sprintf( 'Drop-in %s; WP_CACHE %s.', $dropin, $wpCache ),
			'live',
			$now
		);

		if ( $evidence['redis_enabled'] ) {
			$redis    = (string) $evidence['redis_dropin'];
			$checks[] = self::check( 'object_cache_dropin', 'Object-cache drop-in', 'owned' === $redis ? 'pass' : 'warning', 'Drop-in ' . $redis . '.', 'live', $now );
		}

		if ( $evidence['edge_conflict'] ) {
			$checks[] = self::check( 'edge_ownership', 'Edge ownership', 'warning', 'xCloud and direct Cloudflare integration both claim the edge cache. Use one owner.', 'saved', $now );
		}

		$checks[] = self::check(
			'configuration',
			'Runtime configuration',
			$evidence['config_error'] ? 'fail' : 'pass',
			$evidence['config_error'] ? 'The last settings save could not be published to the runtime cache; earlier settings may still apply.' : 'Saved settings are published to the runtime cache.',
			'saved',
			$now
		);

		$receipts = (array) $evidence['purges'];
		if ( array() === $receipts ) {
			$checks[] = self::check( 'purge_verification', 'Purge verification', 'info', 'No purge verification has been run.', 'saved', 0 );
		} else {
			$latest   = (array) $receipts[0];
			$warnings = count( array_filter( $receipts, static fn ( $receipt ): bool => 'verified' !== ( is_array( $receipt ) ? ( $receipt['status'] ?? '' ) : '' ) ) );
			$checks[] = self::check(
				'purge_verification',
				'Purge verification',
				'verified' === ( $latest['status'] ?? '' ) ? 'pass' : 'warning',
				sprintf( 'Latest result: %s. %d of the last %d need attention.', (string) ( $latest['status'] ?? 'unknown' ), $warnings, count( $receipts ) ),
				'saved',
				self::timestamp( (string) ( $latest['created_at'] ?? '' ) )
			);
		}

		if ( $evidence['css_enabled'] ) {
			$css      = (array) $evidence['css'];
			$checks[] = self::check(
				'css_reports',
				'Unused CSS reports',
				(int) ( $css['failed'] ?? 0 ) > 0 ? 'warning' : 'pass',
				sprintf( 'Of the %d most recent reports: %d ready, %d queued or processing, %d failed.', (int) ( $css['sampled'] ?? 0 ), (int) ( $css['ready'] ?? 0 ), (int) ( $css['queued'] ?? 0 ) + (int) ( $css['processing'] ?? 0 ), (int) ( $css['failed'] ?? 0 ) ),
				'saved',
				$now
			);
		}

		$checks[] = self::warming( $evidence['warm'], $now );

		return $checks;
	}

	/**
	 * @param array<string, mixed>|null $run Latest warm run summary.
	 * @return array{id:string,label:string,status:string,value:string,source:string,observed_at:int}
	 */
	private static function warming( ?array $run, int $now ): array {
		if ( null === $run ) {
			return self::check( 'warming', 'Cache warming', 'info', 'No warm run has been recorded.', 'saved', 0 );
		}

		$urls     = (array) ( $run['targets']['url'] ?? array() );
		$sitemaps = (array) ( $run['targets']['sitemap'] ?? array() );
		$state    = (string) $run['state'];
		$stalled  = in_array( $state, array( 'discovering', 'warming' ), true ) && $now - (int) $run['updated_at'] > 2 * self::STALE_SECONDS;
		$status   = $stalled || in_array( $state, array( 'partial', 'capacity_limited' ), true ) || (int) ( $urls['failed'] ?? 0 ) > 0 ? 'warning' : 'pass';
		$value    = sprintf(
			'Latest run %s: %d origin ready, %d edge observed, %d requested, %d queued, %d pending, %d skipped, %d failed; %d sitemap(s) read, %d failed.',
			str_replace( '_', ' ', $state ),
			(int) ( $urls['origin_ready'] ?? 0 ),
			(int) ( $urls['edge_observed'] ?? 0 ),
			(int) ( $urls['requested'] ?? 0 ),
			(int) ( $urls['queued'] ?? 0 ),
			(int) ( $urls['pending'] ?? 0 ),
			(int) ( $urls['skipped'] ?? 0 ),
			(int) ( $urls['failed'] ?? 0 ),
			(int) ( $sitemaps['fetched'] ?? 0 ),
			(int) ( $sitemaps['failed'] ?? 0 )
		);
		if ( $stalled ) {
			$value .= ' No progress for ' . self::duration( $now - (int) $run['updated_at'] ) . '; check the queue for cancelled or failed warm jobs, or start a new run.';
		}
		if ( array() !== (array) $run['warnings'] ) {
			$value .= ' Warnings: ' . implode( ', ', array_map( 'strval', (array) $run['warnings'] ) ) . '.';
		}

		return self::check( 'warming', 'Cache warming', $status, $value, 'saved', (int) $run['updated_at'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function evidence(): array {
		$ready = Database::queueReady();
		$jobs  = new JobRepository();
		$queue = $ready ? $jobs->counts() : array(
			'pending' => 0,
			'running' => 0,
			'failed'  => 0,
			'paused'  => $jobs->paused(),
		);
		$queue['ready']          = $ready;
		$queue['oldest_due_age'] = $ready ? $jobs->oldestDueAge() : 0;

		$cron = ( new CronHealth() )->check();
		// The runner command carries an absolute server path; doctor prints it.
		$cron['value'] = str_replace( 'Install this five-minute runner: ' . ( new CronHealth() )->runnerCommand(), '`wp gt-performance doctor` prints a five-minute server runner command.', (string) $cron['value'] );

		$css = array( 'sampled' => 0 );
		if ( (bool) Settings::get( 'css.enabled', false ) ) {
			$reports = ( new ReportRepository() )->recent( 50 );
			$css     = ( new ReportRepository() )->summary( $reports ) + array( 'sampled' => count( $reports ) );
		}

		return array(
			'queue'            => $queue,
			'heartbeat'        => (int) get_option( QueueModule::HEARTBEAT_OPTION, 0 ),
			'cron'             => $cron,
			'storage_writable' => wp_is_writable( Paths::cacheRoot() ),
			'cache_enabled'    => (bool) Settings::get( 'cache.enabled', true ),
			'page_dropin'      => ( new DropinInstaller() )->status(),
			'wp_cache'         => ( new WpCacheConstant() )->status(),
			'redis_enabled'    => (bool) Settings::get( 'redis.enabled', false ),
			'redis_dropin'     => ( new ObjectCacheInstaller() )->status(),
			'edge_conflict'    => ( new EdgeOwnership() )->hasDirectCloudflareConflict(),
			'config_error'     => '' !== (string) get_option( Settings::CONFIG_ERROR, '' ),
			'purges'           => ( new PurgeReceiptRepository() )->recent( 5 ),
			'css_enabled'      => (bool) Settings::get( 'css.enabled', false ),
			'css'              => $css,
			'warm'             => $ready ? ( new CacheWarmer( new Logger() ) )->summary() : null,
		);
	}

	/**
	 * @return array{id:string,label:string,status:string,value:string,source:string,observed_at:int}
	 */
	private static function check( string $id, string $label, string $status, string $value, string $source, int $observedAt ): array {
		return array(
			'id'          => $id,
			'label'       => $label,
			'status'      => $status,
			'value'       => $value,
			'source'      => $source,
			'observed_at' => $observedAt,
		);
	}

	private static function duration( int $seconds ): string {
		if ( $seconds < MINUTE_IN_SECONDS ) {
			return $seconds . 's';
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			return (int) floor( $seconds / MINUTE_IN_SECONDS ) . 'm';
		}

		return (int) floor( $seconds / HOUR_IN_SECONDS ) . 'h';
	}

	private static function timestamp( string $mysql ): int {
		$time = '' === $mysql ? false : strtotime( $mysql . ' UTC' );

		return false === $time ? 0 : $time;
	}
}
