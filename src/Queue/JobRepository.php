<?php
/**
 * Durable queue repository.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Queue;

final class JobRepository {
	/**
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function enqueue( string $type, array $payload, int $priority = 100, int $delay = 0 ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'gtp_jobs',
			array(
				'type'         => sanitize_key( $type ),
				'payload'      => wp_json_encode( $payload ),
				'status'       => 'pending',
				'priority'     => max( 0, min( 65535, $priority ) ),
				'attempts'     => 0,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function claim(): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'gtp_jobs';
		$token = wp_generate_uuid4();
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );

		// The table name is built from the trusted WordPress prefix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE available_at <= %s
				AND (status = 'pending' OR (status = 'running' AND locked_at < %s))
				ORDER BY priority ASC, id ASC
				LIMIT 1",
				$now,
				$stale
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $id ) {
			return null;
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'running',
				'locked_at'  => $now,
				'lock_token' => $token,
				'updated_at' => $now,
			),
			array(
				'id' => (int) $id,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( 1 !== $updated ) {
			return null;
		}

		// The table name is built from the trusted WordPress prefix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND lock_token = %s", (int) $id, $token ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		$payload        = json_decode( (string) $row['payload'], true );
		$row['payload'] = is_array( $payload ) ? $payload : array();

		return $row;
	}

	public function complete( int $id, string $token ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'gtp_jobs',
			array(
				'status'     => 'complete',
				'locked_at'  => null,
				'lock_token' => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'         => $id,
				'lock_token' => $token,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Delete terminal (complete/failed) jobs older than the retention window.
	 *
	 * Every post save, comment action, and product change enqueues jobs; without
	 * pruning, terminal rows accumulate in the queue table indefinitely. The
	 * bounded LIMIT keeps each sweep cheap enough to run inline on the queue cron.
	 */
	public function purgeTerminal( int $olderThanSeconds = 3 * DAY_IN_SECONDS, int $limit = 500 ): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'gtp_jobs';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $olderThanSeconds ) );

		// The table name is built from the trusted WordPress prefix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE status IN ('complete', 'failed')
				AND updated_at < %s
				ORDER BY updated_at ASC
				LIMIT %d",
				$cutoff,
				max( 1, $limit )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_int( $deleted ) ? $deleted : 0;
	}

	public function fail( int $id, string $token, string $error, int $attempts ): void {
		global $wpdb;

		$retry  = $attempts < 3;
		$status = $retry ? 'pending' : 'failed';
		$delay  = $retry ? min( HOUR_IN_SECONDS, 30 * ( 2 ** $attempts ) ) : 0;

		$wpdb->update(
			$wpdb->prefix . 'gtp_jobs',
			array(
				'status'       => $status,
				'attempts'     => $attempts,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_at'    => null,
				'lock_token'   => null,
				'last_error'   => mb_substr( $error, 0, 2000 ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array(
				'id'         => $id,
				'lock_token' => $token,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}
}
