<?php
/**
 * Durable records of operations requested by agents, and settings proposals.
 *
 * Rows live in the plugin's own gtperf_operations table, so every access is a
 * direct query. (actor, request_id) is unique: a replay finds the original row
 * instead of doing the work again. Table names interpolate only the trusted
 * WordPress table prefix.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Operations;

final class OperationRepository {
	public const RETENTION_SECONDS = 30 * DAY_IN_SECONDS;

	public const MAX_TERMINAL = 2000;

	/** @var list<string> */
	public const ACTIVE = array( 'accepted', 'running', 'proposed' );

	/**
	 * Create a row, or return the existing one for a replayed request.
	 *
	 * @param array<string, mixed> $payload Validated operation input.
	 * @return array{row:array<string,mixed>,created:bool}|\WP_Error
	 */
	public function create( int $actor, string $requestId, string $operation, array $payload, string $summary, ?int $expiresAt = null ): array|\WP_Error {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_operations';
		$hash  = self::payloadHash( $operation, $payload );
		$now   = current_time( 'mysql', true );

		$existing = $this->findByRequest( $actor, $requestId );
		if ( null === $existing ) {
			$errors = $wpdb->suppress_errors();
			try {
				$inserted = $wpdb->insert(
					$table,
					array(
						'actor'          => $actor,
						'request_id'     => $requestId,
						'payload_hash'   => $hash,
						'operation'      => $operation,
						'target_summary' => substr( $summary, 0, 191 ),
						'status'         => 'accepted',
						'payload'        => wp_json_encode( $payload ),
						'expires_at'     => null === $expiresAt ? null : gmdate( 'Y-m-d H:i:s', $expiresAt ),
						'created_at'     => $now,
						'updated_at'     => $now,
					)
				);
			} finally {
				$wpdb->suppress_errors( $errors );
			}
			if ( false !== $inserted ) {
				return array(
					'row'     => (array) $this->find( (int) $wpdb->insert_id ),
					'created' => true,
				);
			}
			// A concurrent replay of the same request won the unique key.
			$existing = $this->findByRequest( $actor, $requestId );
			if ( null === $existing ) {
				return new \WP_Error( 'gtperf_operation_store', __( 'The operation could not be recorded.', 'gt-performance' ), array( 'status' => 500 ) );
			}
		}

		if ( ! hash_equals( (string) $existing['payload_hash'], $hash ) ) {
			return new \WP_Error( 'gtperf_request_conflict', __( 'This request ID was already used with different arguments. Use a new request ID for a different operation.', 'gt-performance' ), array( 'status' => 409 ) );
		}

		return array(
			'row'     => $existing,
			'created' => false,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gtperf_operations WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByRequest( int $actor, string $requestId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gtperf_operations WHERE actor = %d AND request_id = %s", $actor, $requestId ), ARRAY_A );

		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $fields Columns to change; result is JSON-encoded.
	 */
	public function update( int $id, array $fields, string $fromStatus = '' ): bool {
		global $wpdb;

		$fields['updated_at'] = current_time( 'mysql', true );
		if ( array_key_exists( 'result', $fields ) ) {
			$fields['result'] = wp_json_encode( $fields['result'] );
		}
		if ( isset( $fields['status'] ) && ! in_array( $fields['status'], self::ACTIVE, true ) ) {
			$fields['completed_at'] = $fields['updated_at'];
		}
		$where = array( 'id' => $id );
		if ( '' !== $fromStatus ) {
			$where['status'] = $fromStatus;
		}

		return 1 === (int) $wpdb->update( $wpdb->prefix . 'gtperf_operations', $fields, $where );
	}

	/**
	 * Submissions by an actor in the last minute, for rate limiting.
	 */
	public function recentByActor( int $actor, int $seconds = MINUTE_IN_SECONDS ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}gtperf_operations WHERE actor = %d AND created_at >= %s",
				$actor,
				gmdate( 'Y-m-d H:i:s', time() - $seconds )
			)
		);
	}

	public function outstanding(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gtperf_operations WHERE status IN ('accepted', 'running')" );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list( string $status = '', int $limit = 20, string $operation = '' ): array {
		global $wpdb;

		$where  = array( '1 = 1' );
		$values = array();
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( '' !== $operation ) {
			$where[]  = 'operation = %s';
			$values[] = $operation;
		}
		$values[] = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed clauses above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gtperf_operations WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $values ), ARRAY_A );

		return array_map( array( self::class, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Expire lapsed proposals, then drop terminal rows past retention or beyond
	 * the newest 2,000. Active operations are never removed.
	 */
	public function prune(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_operations';
		$now   = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'expired', updated_at = %s, completed_at = %s WHERE status = 'proposed' AND expires_at < %s", $now, $now, $now ) );

		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status NOT IN ('accepted', 'running', 'proposed') AND created_at < %s LIMIT 1000",
				gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS )
			)
		);
		$cutoff = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status NOT IN ('accepted', 'running', 'proposed') ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_TERMINAL )
		);
		if ( null !== $cutoff ) {
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status NOT IN ('accepted', 'running', 'proposed') AND id <= %d LIMIT 1000", (int) $cutoff ) );
		}

		return $deleted;
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 */
	public static function payloadHash( string $operation, array $payload ): string {
		self::sortRecursive( $payload );

		return hash( 'sha256', $operation . '|' . wp_json_encode( $payload ) );
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		foreach ( array( 'payload', 'result' ) as $key ) {
			$decoded     = json_decode( (string) ( $row[ $key ] ?? '' ), true );
			$row[ $key ] = is_array( $decoded ) ? $decoded : array();
		}
		foreach ( array( 'id', 'actor', 'job_id' ) as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}

		return $row;
	}

	/**
	 * @param array<mixed> $values Values sorted in place.
	 */
	private static function sortRecursive( array &$values ): void {
		if ( ! array_is_list( $values ) ) {
			ksort( $values );
		}
		foreach ( $values as &$value ) {
			if ( is_array( $value ) ) {
				self::sortRecursive( $value );
			}
		}
	}
}
