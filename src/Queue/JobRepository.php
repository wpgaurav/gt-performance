<?php
/**
 * Durable queue repository.
 *
 * Jobs live in the plugin's own gtperf_jobs table, so every access is necessarily
 * a direct query. Rows are claimed and mutated with row-level semantics on each
 * call; caching a work queue would serve stale claims. Table names interpolate
 * only the trusted WordPress table prefix.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Queue;

use GTPerformance\Core\Database;
use GTPerformance\Core\Logger;

final class JobRepository {
	public const PAUSE_OPTION = 'gt_performance_queue_paused';

	public const MAX_ATTEMPTS = 3;

	public const LEASE_SECONDS = 600;

	/**
	 * Priority that waiting work ages toward. Strict priority order let a steady
	 * stream of CSS generation (70) starve a warm run (80) for 18 days on a
	 * production site. Work waiting 30 minutes runs as 50, two hours as 30, and six
	 * hours as 20; nothing ages past 20, so purge invalidation (10) always leads.
	 */
	public const AGING_FLOOR = 20;

	/**
	 * Work that still runs while optional jobs are paused.
	 *
	 * @var list<string>
	 */
	private const SAFETY_TYPES = array( 'purge_url' );

	/**
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function enqueue( string $type, array $payload, int $priority = 100, int $delay = 0 ): int {
		global $wpdb;

		if ( ! Database::queueReady() || '' === sanitize_key( $type ) ) {
			return 0;
		}
		$type = sanitize_key( $type );
		$payload = $this->versionedPayload( $type, $payload );
		$key   = $this->activeKey( $type, $payload );
		$now   = current_time( 'mysql', true );
		$table = $wpdb->prefix . 'gtperf_jobs';

		$existing = $this->activeId( $key );
		if ( $existing > 0 ) {
			return $existing;
		}

		// A uniqueness collision is an expected enqueue race, not a printable SQL error.
		$errors = $wpdb->suppress_errors();
		try {
			$inserted = $wpdb->insert(
				$table,
				array(
					'type'         => $type,
					'payload'      => wp_json_encode( $payload ),
					'status'       => 'pending',
					'priority'     => max( 0, min( 65535, $priority ) ),
					'attempts'     => 0,
					'available_at' => gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) ),
					'active_key'   => '' !== $key ? $key : null,
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		} finally {
			$wpdb->suppress_errors( $errors );
		}

		if ( false === $inserted ) {
			if ( '' !== $key ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE active_key = %s AND status IN ('pending', 'running') LIMIT 1",
						$key
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return (int) $existing;
			}

			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed>|null $payload Match a URL job, or any job of this type. */
	public function hasActive( string $type, ?array $payload = null ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		$type  = sanitize_key( $type );
		if ( null !== $payload ) {
			return $this->hasActiveKey( $this->activeKey( $type, $payload ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE type = %s AND status IN ('pending', 'running') LIMIT 1",
			$type
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (bool) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function claim(): ?array {
		global $wpdb;

		if ( ! Database::queueReady() ) {
			return null;
		}
		$this->recoverExpired();

		$table = $wpdb->prefix . 'gtperf_jobs';
		$token = wp_generate_uuid4();
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::LEASE_SECONDS );
		$lease = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );
		$id    = $this->nextClaimableId( $now, $stale );
		if ( null === $id ) {
			return null;
		}

		// The conditional update is the claim. A worker that loses the race
		// updates zero rows and must not read the winner's lock token.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'running',
					locked_at = %s,
					lock_token = %s,
					lease_expires_at = %s,
					attempts = attempts + 1,
					updated_at = %s
				WHERE id = %d
				AND attempts < %d
				AND cancel_requested = 0
				AND available_at <= %s
				AND (
					status = 'pending'
					OR (status = 'running' AND (lease_expires_at IS NULL OR lease_expires_at < %s OR locked_at < %s))
				)",
				$now,
				$token,
				$lease,
				$now,
				$id,
				self::MAX_ATTEMPTS,
				$now,
				$now,
				$stale
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( 1 !== (int) $updated ) {
			return null;
		}

		return $this->rowByToken( $id, $token );
	}

	public function renew( int $id, string $token ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		$now   = current_time( 'mysql', true );
		$lease = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET locked_at = %s, lease_expires_at = %s, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = 'running' AND cancel_requested = 0 AND lease_expires_at >= %s",
				$now,
				$lease,
				$now,
				$id,
				$token,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// MySQL reports zero changed rows for two renewals in the same second.
		return false !== $updated && ( 1 === (int) $updated || $this->owns( $id, $token ) );
	}

	public function owns( int $id, string $token ): bool {
		$row = $this->rowByToken( $id, $token );
		return null !== $row && 'running' === $row['status'] && ! $row['cancel_requested']
			&& (string) $row['lease_expires_at'] >= current_time( 'mysql', true );
	}

	/**
	 * @param array<string, scalar> $result Small outcome fields kept with the job.
	 */
	public function complete( int $id, string $token, int $durationMs = 0, array $result = array() ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'complete', locked_at = NULL, lock_token = NULL, lease_expires_at = NULL, active_key = NULL, updated_at = %s, result_metadata = %s
				WHERE id = %d AND lock_token = %s AND status = 'running' AND cancel_requested = 0 AND lease_expires_at >= UTC_TIMESTAMP()",
				$now,
				wp_json_encode( array( 'duration_ms' => max( 0, $durationMs ) ) + $this->boundedResult( $result ) ),
				$id,
				$token
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === (int) $updated;
	}

	/**
	 * @param array<string, scalar> $result Handler result.
	 * @return array<string, scalar>
	 */
	private function boundedResult( array $result ): array {
		$bounded = array();
		foreach ( array_slice( $result, 0, 8, true ) as $key => $value ) {
			$bounded[ sanitize_key( (string) $key ) ] = is_string( $value ) ? substr( $value, 0, 64 ) : $value;
		}

		return $bounded;
	}

	/**
	 * Count jobs of a type that are still waiting to run.
	 *
	 * Producers that enqueue on a timer need this: the queue drains a handful of
	 * jobs per run, so a sweep that keeps adding work regardless of what is
	 * already pending grows the table without bound.
	 */
	public function pendingCount( string $type ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE type = %s AND status = 'pending'",
				sanitize_key( $type )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return max( 0, (int) $count );
	}

	/**
	 * Seconds the oldest due pending job has waited, or 0 when none is due.
	 */
	public function oldestDueAge(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$oldest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(available_at) FROM {$table} WHERE status = 'pending' AND available_at <= %s",
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$time = is_string( $oldest ) ? strtotime( $oldest . ' UTC' ) : false;

		return false === $time ? 0 : max( 0, time() - $time );
	}

	/**
	 * @return array{pending:int,running:int,failed:int,complete:int,cancelled:int,paused:bool,ready:bool}
	 */
	public function counts(): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'gtperf_jobs';
		$counts = array(
			'pending'   => 0,
			'running'   => 0,
			'failed'    => 0,
			'complete'  => 0,
			'cancelled' => 0,
			'paused'    => $this->paused(),
			'ready'     => Database::queueReady(),
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = (int) $row['total'];
				}
			}
		}

		return $counts;
	}

	/**
	 * Newest first. Pass the smallest ID from one page as $before for the next.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list( string $status = '', int $limit = 20, string $type = '', int $before = 0 ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'gtperf_jobs';
		$where  = array( '1 = 1' );
		$values = array();
		if ( '' !== sanitize_key( $status ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_key( $status );
		}
		if ( '' !== sanitize_key( $type ) ) {
			$where[]  = 'type = %s';
			$values[] = sanitize_key( $type );
		}
		if ( $before > 0 ) {
			$where[]  = 'id < %d';
			$values[] = $before;
		}
		$values[] = max( 1, min( 100, $limit ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where holds only fixed placeholder clauses.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, status, priority, attempts, available_at, lease_expires_at, cancel_requested, last_error, created_at, updated_at
				FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d',
				$values
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['last_error'] = Logger::redact( (string) ( $row['last_error'] ?? '' ) );
		}
		unset( $row );
		return $rows;
	}

	public function pause(): void {
		update_option( self::PAUSE_OPTION, '1', false );
	}

	public function resume(): void {
		delete_option( self::PAUSE_OPTION );
	}

	public function paused(): bool {
		return '1' === (string) get_option( self::PAUSE_OPTION, '' );
	}

	public function retry( int $id ): bool {
		global $wpdb;
		if ( ! Database::queueReady() ) {
			return false;
		}

		$table = $wpdb->prefix . 'gtperf_jobs';
		$now   = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT type, payload, active_key FROM {$table} WHERE id = %d AND status = 'failed'", $id ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return false;
		}

		$key = (string) ( $row['active_key'] ?? '' );
		if ( '' === $key ) {
			$payload = json_decode( (string) $row['payload'], true );
			$key     = $this->activeKey( (string) $row['type'], is_array( $payload ) ? $payload : array() );
		}
		if ( '' !== $key && $this->hasActiveKey( $key ) ) {
			return false;
		}

		$errors = $wpdb->suppress_errors();
		try {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = 'pending', attempts = 0, available_at = %s, locked_at = NULL, lock_token = NULL,
						lease_expires_at = NULL, cancel_requested = 0, active_key = %s, last_error = NULL, result_metadata = NULL, updated_at = %s
					WHERE id = %d AND status = 'failed'",
					$now,
					'' !== $key ? $key : null,
					$now,
					$id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} finally {
			$wpdb->suppress_errors( $errors );
		}

		return 1 === (int) $updated;
	}

	public function cancel( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'cancelled', active_key = NULL, locked_at = NULL, lock_token = NULL, lease_expires_at = NULL, updated_at = %s
				WHERE id = %d AND status = 'pending'",
				$now,
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 === (int) $pending ) {
			return true;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$running = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET cancel_requested = 1, updated_at = %s WHERE id = %d AND status = 'running'",
				$now,
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === (int) $running;
	}

	public function cancellationRequested( int $id, string $token ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$flag = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cancel_requested FROM {$table} WHERE id = %d AND lock_token = %s AND status = 'running'",
				$id,
				$token
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return '1' === (string) $flag || 1 === $flag;
	}

	/**
	 * Delete terminal jobs older than the retention window.
	 */
	public function purgeTerminal( int $olderThanSeconds = 3 * DAY_IN_SECONDS, int $limit = 500 ): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'gtperf_jobs';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $olderThanSeconds ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE status IN ('complete', 'failed', 'cancelled')
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

	public function fail( int $id, string $token, string $error, int $attempts ): bool {
		global $wpdb;

		$retry  = $attempts < self::MAX_ATTEMPTS;
		$status = $retry ? 'pending' : 'failed';
		$delay  = $retry ? min( HOUR_IN_SECONDS, 30 * ( 2 ** max( 0, $attempts - 1 ) ) ) : 0;
		$now    = current_time( 'mysql', true );
		$table  = $wpdb->prefix . 'gtperf_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, attempts = %d, available_at = %s, locked_at = NULL, lock_token = NULL,
					lease_expires_at = NULL, active_key = CASE WHEN %s = 'failed' THEN NULL ELSE active_key END,
					last_error = %s, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = 'running' AND cancel_requested = 0 AND lease_expires_at >= UTC_TIMESTAMP()",
				$status,
				$attempts,
				gmdate( 'Y-m-d H:i:s', time() + $delay ),
				$status,
				Logger::redact( $error ),
				$now,
				$id,
				$token
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === (int) $updated;
	}

	public function finishCancelled( int $id, string $token ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		$now   = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'cancelled', active_key = NULL, locked_at = NULL, lock_token = NULL, lease_expires_at = NULL, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = 'running'",
				$now,
				$id,
				$token
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === (int) $updated;
	}

	/**
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function activeKey( string $type, array $payload ): string {
		$type = sanitize_key( $type );
		if ( '' === $type ) {
			return '';
		}
		$payload = $this->versionedPayload( $type, $payload );

		if ( isset( $payload['url'] ) && is_string( $payload['url'] ) ) {
			$parts = wp_parse_url( $payload['url'] );
			if ( is_array( $parts ) && isset( $parts['host'], $parts['scheme'] ) ) {
				$scheme = strtolower( $parts['scheme'] );
				$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
				$payload['url'] = $scheme . '://' . strtolower( $parts['host'] )
					. ( ( 'https' === $scheme ? 443 : 80 ) === $port ? '' : ':' . $port )
					. ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			}
		}
		ksort( $payload );

		global $wpdb;
		return hash( 'sha256', $wpdb->prefix . '|' . $type . '|' . wp_json_encode( $payload ) );
	}

	/**
	 * @param array<string,mixed> $payload Job data.
	 * @return array<string,mixed>
	 */
	private function versionedPayload( string $type, array $payload ): array {
		if ( ! in_array( $type, self::SAFETY_TYPES, true ) ) {
			$payload += array( 'generation' => (int) \GTPerformance\Core\Settings::get( 'generation', 1 ) );
		}
		return $payload;
	}

	private function hasActiveKey( string $key ): bool {
		return $this->activeId( $key ) > 0;
	}

	private function activeId( string $key ): int {
		global $wpdb;

		if ( '' === $key ) {
			return 0;
		}

		$table = $wpdb->prefix . 'gtperf_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE active_key = %s AND status IN ('pending', 'running') LIMIT 1",
				$key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $id;
	}

	private function nextClaimableId( string $now, string $stale ): ?int {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		$pause = $this->paused() ? $this->safetyClause() : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE available_at <= %s
				AND attempts < %d
				AND cancel_requested = 0
				AND (
					status = 'pending'
					OR (status = 'running' AND (lease_expires_at IS NULL OR lease_expires_at < %s OR locked_at < %s))
				)
				{$pause}
				ORDER BY CASE
					WHEN priority <= %d THEN priority
					WHEN available_at <= %s THEN %d
					WHEN available_at <= %s AND priority > 30 THEN 30
					WHEN available_at <= %s AND priority > 50 THEN 50
					ELSE priority
				END ASC, id ASC
				LIMIT 1",
				$now,
				self::MAX_ATTEMPTS,
				$now,
				$stale,
				self::AGING_FLOOR,
				gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS ),
				self::AGING_FLOOR,
				gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $id ? (int) $id : null;
	}

	private function safetyClause(): string {
		$types = array();
		foreach ( self::SAFETY_TYPES as $type ) {
			$types[] = "'" . preg_replace( '/[^a-z0-9_]/', '', $type ) . "'";
		}

		return 'AND type IN (' . implode( ',', $types ) . ')';
	}

	/** Terminalize abandoned work without touching a live lease. */
	public function recoverExpired(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'gtperf_jobs';
		$now = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::LEASE_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = CASE WHEN cancel_requested = 1 THEN 'cancelled' ELSE 'failed' END,
			active_key = NULL, lock_token = NULL, locked_at = NULL, lease_expires_at = NULL,
			last_error = 'Worker lease expired.', updated_at = %s
			WHERE status = 'running' AND (cancel_requested = 1 OR attempts >= %d)
			AND (lease_expires_at < %s OR (lease_expires_at IS NULL AND (locked_at IS NULL OR locked_at < %s))) LIMIT 100",
				$now,
				self::MAX_ATTEMPTS,
				$now,
				$stale
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function rowByToken( int $id, string $token ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND lock_token = %s", $id, $token ),
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
}
