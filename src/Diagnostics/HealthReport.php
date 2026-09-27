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
			$checks[] = self::check( 'queue', __( 'Background queue', 'gt-performance' ), 'warning', __( 'Schema upgrade pending; new jobs wait until an administrator screen or WP-CLI completes it.', 'gt-performance' ), 'live', $now );
		} else {
			$age      = (int) $queue['oldest_due_age'];
			$status   = (int) $queue['failed'] > 0 || $age > self::STALE_SECONDS ? 'warning' : 'pass';
			$checks[] = self::check(
				'queue',
				__( 'Background queue', 'gt-performance' ),
				$status,
				sprintf(
					/* translators: 1: pending jobs, 2: running jobs, 3: failed jobs, 4: how long the oldest due job waited, e.g. 5m. */
					__( '%1$d pending, %2$d running, %3$d failed; oldest due job waited %4$s.', 'gt-performance' ),
					(int) $queue['pending'],
					(int) $queue['running'],
					(int) $queue['failed'],
					self::duration( $age )
				)
				. ( $queue['paused'] ? ' ' . __( 'Optional work is paused.', 'gt-performance' ) : '' )
				. ( (int) $queue['failed'] > 0 ? ' ' . __( 'Retry or cancel failed jobs in Tools.', 'gt-performance' ) : '' ),
				'live',
				$now
			);
		}

		$beat     = (int) $evidence['heartbeat'];
		$checks[] = 0 === $beat
			? self::check( 'queue_heartbeat', __( 'Queue runner', 'gt-performance' ), 'warning', __( 'No scheduled queue run has been recorded yet.', 'gt-performance' ), 'saved', 0 )
			: self::check(
				'queue_heartbeat',
				__( 'Queue runner', 'gt-performance' ),
				$now - $beat > self::STALE_SECONDS ? 'warning' : 'pass',
				/* translators: %s: time since the last run, e.g. 5m. */
				sprintf( __( 'Last scheduled run %s ago.', 'gt-performance' ), self::duration( max( 0, $now - $beat ) ) ),
				'saved',
				$beat
			);

		$cron     = (array) $evidence['cron'];
		$checks[] = self::check( 'cron', __( 'WP-Cron', 'gt-performance' ), 'pass' === $cron['status'] ? 'pass' : 'warning', (string) $cron['value'], 'live', $now );

		$checks[] = self::check(
			'storage',
			__( 'Cache storage', 'gt-performance' ),
			$evidence['storage_writable'] ? 'pass' : 'fail',
			$evidence['storage_writable'] ? __( 'Cache directory is writable.', 'gt-performance' ) : __( 'Cache directory is not writable; pages and generated assets cannot be stored.', 'gt-performance' ),
			'live',
			$now
		);

		$dropin   = (string) $evidence['page_dropin'];
		$wpCache  = (string) $evidence['wp_cache'];
		$cache    = (bool) $evidence['cache_enabled'];
		$ready    = 'owned' === $dropin && 'enabled' === $wpCache;
		$checks[] = self::check(
			'page_dropin',
			__( 'Page-cache drop-in', 'gt-performance' ),
			! $cache ? 'info' : ( $ready ? 'pass' : ( 'conflict' === $dropin ? 'fail' : 'warning' ) ),
			/* translators: 1: drop-in state such as owned or missing, 2: WP_CACHE state such as enabled. */
			! $cache ? __( 'Origin page cache is disabled.', 'gt-performance' ) : sprintf( __( 'Drop-in %1$s; WP_CACHE %2$s.', 'gt-performance' ), $dropin, $wpCache ),
			'live',
			$now
		);

		if ( $evidence['redis_enabled'] ) {
			$redis    = (string) $evidence['redis_dropin'];
			/* translators: %s: drop-in state such as owned or missing. */
			$checks[] = self::check( 'object_cache_dropin', __( 'Object-cache drop-in', 'gt-performance' ), 'owned' === $redis ? 'pass' : 'warning', sprintf( __( 'Drop-in %s.', 'gt-performance' ), $redis ), 'live', $now );
		}

		if ( $evidence['edge_conflict'] ) {
			$checks[] = self::check( 'edge_ownership', __( 'Edge ownership', 'gt-performance' ), 'warning', __( 'xCloud and direct Cloudflare integration both claim the edge cache. Use one owner.', 'gt-performance' ), 'saved', $now );
		}

		$checks[] = self::check(
			'configuration',
			__( 'Runtime configuration', 'gt-performance' ),
			$evidence['config_error'] ? 'fail' : 'pass',
			$evidence['config_error'] ? __( 'The last settings save could not be published to the runtime cache; earlier settings may still apply.', 'gt-performance' ) : __( 'Saved settings are published to the runtime cache.', 'gt-performance' ),
			'saved',
			$now
		);

		$receipts = (array) $evidence['purges'];
		if ( array() === $receipts ) {
			$checks[] = self::check( 'purge_verification', __( 'Purge verification', 'gt-performance' ), 'info', __( 'No purge verification has been run.', 'gt-performance' ), 'saved', 0 );
		} else {
			$latest   = (array) $receipts[0];
			$warnings = count( array_filter( $receipts, static fn ( $receipt ): bool => 'verified' !== ( is_array( $receipt ) ? ( $receipt['status'] ?? '' ) : '' ) ) );
			$checks[] = self::check(
				'purge_verification',
				__( 'Purge verification', 'gt-performance' ),
				'verified' === ( $latest['status'] ?? '' ) ? 'pass' : 'warning',
				/* translators: 1: latest result such as verified, 2: results needing attention, 3: results checked. */
				sprintf( __( 'Latest result: %1$s. %2$d of the last %3$d need attention.', 'gt-performance' ), (string) ( $latest['status'] ?? 'unknown' ), $warnings, count( $receipts ) ),
				'saved',
				self::timestamp( (string) ( $latest['created_at'] ?? '' ) )
			);
		}

		if ( $evidence['css_enabled'] ) {
			$css      = (array) $evidence['css'];
			$checks[] = self::check(
				'css_reports',
				__( 'Unused CSS reports', 'gt-performance' ),
				(int) ( $css['failed'] ?? 0 ) > 0 ? 'warning' : 'pass',
				/* translators: 1: reports sampled, 2: ready, 3: queued or processing, 4: failed. */
				sprintf( __( 'Of the %1$d most recent reports: %2$d ready, %3$d queued or processing, %4$d failed.', 'gt-performance' ), (int) ( $css['sampled'] ?? 0 ), (int) ( $css['ready'] ?? 0 ), (int) ( $css['queued'] ?? 0 ) + (int) ( $css['processing'] ?? 0 ), (int) ( $css['failed'] ?? 0 ) ),
				'saved',
				$now
			);
		}

		$varying = array_map( 'strval', (array) ( $evidence['visitor_variation'] ?? array() ) );
		if ( $varying ) {
			$checks[] = self::check(
				'visitor_variation',
				__( 'Language and currency plugins', 'gt-performance' ),
				'warning',
				sprintf(
					/* translators: %s: comma-separated plugin names. */
					__( '%s can show different pages at the same URL. Cached pages are shared per URL; see Integrations for what to set so visitors are not served another visitor\'s language or prices.', 'gt-performance' ),
					implode( ', ', $varying )
				),
				'live',
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
			return self::check( 'warming', __( 'Cache warming', 'gt-performance' ), 'info', __( 'No warm run has been recorded.', 'gt-performance' ), 'saved', 0 );
		}

		$urls     = (array) ( $run['targets']['url'] ?? array() );
		$sitemaps = (array) ( $run['targets']['sitemap'] ?? array() );
		$state    = (string) $run['state'];
		$stalled  = in_array( $state, array( 'discovering', 'warming' ), true ) && $now - (int) $run['updated_at'] > 2 * self::STALE_SECONDS;
		$status   = $stalled || in_array( $state, array( 'partial', 'capacity_limited' ), true ) || (int) ( $urls['failed'] ?? 0 ) > 0 ? 'warning' : 'pass';
		$value    = sprintf(
			/* translators: 1: run state, 2-8: URL counts by outcome, 9: sitemaps read, 10: sitemaps that failed. */
			__( 'Latest run %1$s: %2$d origin ready, %3$d edge observed, %4$d requested, %5$d queued, %6$d pending, %7$d skipped, %8$d failed; %9$d sitemaps read, %10$d failed.', 'gt-performance' ),
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
			/* translators: %s: time without progress, e.g. 40m. */
			$value .= ' ' . sprintf( __( 'No progress for %s; check the queue for cancelled or failed warm jobs, or start a new run.', 'gt-performance' ), self::duration( $now - (int) $run['updated_at'] ) );
		}
		if ( array() !== (array) $run['warnings'] ) {
			/* translators: %s: comma-separated warning codes. */
			$value .= ' ' . sprintf( __( 'Warnings: %s.', 'gt-performance' ), implode( ', ', array_map( 'strval', (array) $run['warnings'] ) ) );
		}

		return self::check( 'warming', __( 'Cache warming', 'gt-performance' ), $status, $value, 'saved', (int) $run['updated_at'] );
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

		$cron = ( new CronHealth() )->check( null, null, false );

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
			'visitor_variation' => ( new \GTPerformance\Compatibility\PluginDetector() )->activeVisitorVariation(),
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
