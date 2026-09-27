<?php
/**
 * External WP-Cron health diagnostics.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Diagnostics;

final class CronHealth {
	private const MATERIAL_OVERDUE_SECONDS = 15 * 60;

	/**
	 * @param array<int|string, mixed>|null $cron Scheduled event array.
	 * @return array{check: string, value: string, status: string}
	 */
	public function check( ?array $cron = null, ?int $now = null, bool $withCommand = true ): array {
		if ( ! defined( 'DISABLE_WP_CRON' ) || true !== (bool) DISABLE_WP_CRON ) {
			return array(
				'check'  => 'WP-Cron',
				'value'  => __( 'WordPress request spawning is enabled.', 'gt-performance' ),
				'status' => 'pass',
			);
		}

		$cron       = null === $cron ? _get_cron_array() : $cron;
		$now        = $now ?? time();
		$cutoff     = $now - self::MATERIAL_OVERDUE_SECONDS;
		$oldest     = null;
		$eventCount = 0;

		foreach ( $cron as $timestamp => $hooks ) {
			$timestamp = (int) $timestamp;
			if ( $timestamp > $cutoff || ! is_array( $hooks ) ) {
				continue;
			}

			$oldest = null === $oldest ? $timestamp : min( $oldest, $timestamp );
			foreach ( $hooks as $events ) {
				$eventCount += is_array( $events ) ? count( $events ) : 0;
			}
		}

		if ( null === $oldest ) {
			return array(
				'check'  => 'WP-Cron',
				'value'  => __( 'Request spawning is disabled; no events are more than 15 minutes overdue.', 'gt-performance' ),
				'status' => 'pass',
			);
		}

		$minutes = max( 15, (int) floor( ( $now - $oldest ) / 60 ) );

		return array(
			'check'  => 'WP-Cron',
			'value'  => sprintf(
				/* translators: 1: number of overdue cron events, 2: minutes the oldest is late. */
				_n( '%1$d event overdue; oldest is %2$d minutes late.', '%1$d events overdue; oldest is %2$d minutes late.', $eventCount, 'gt-performance' ),
				$eventCount,
				$minutes
			) . ' ' . ( $withCommand
				? sprintf(
					/* translators: %s: crontab line. */
					__( 'Install this five-minute runner: %s', 'gt-performance' ),
					$this->runnerCommand()
				)
				// The runner carries an absolute server path, which reports shared
				// outside wp-admin must not include.
				: __( '`wp gt-performance doctor` prints a five-minute server runner command.', 'gt-performance' ) ),
			'status' => 'warning',
		);
	}

	public function runnerCommand(): string {
		return sprintf(
			'*/5 * * * * flock -n /tmp/gt-performance-wp-cron.lock %s %s >/dev/null 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( ABSPATH . 'wp-cron.php' )
		);
	}
}
