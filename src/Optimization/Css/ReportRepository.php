<?php
/**
 * Persistent unused CSS generation reports.
 *
 * Reports live in the plugin's own gtperf_artifacts table, so every access is
 * necessarily a direct query, and invalidation must be immediately visible to
 * the next request rather than served from a stale object cache. Table names
 * interpolate only the trusted WordPress table prefix.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

use GTPerformance\Core\Settings;

final class ReportRepository {
	private const TYPE = 'unused_css';

	public function begin( string $url, string $mode ): string {
		global $wpdb;

		$fingerprint = hash( 'sha256', $url . '|' . $mode );
		$table       = $wpdb->prefix . 'gtperf_artifacts';
		$now         = current_time( 'mysql', true );
		$metadata    = wp_json_encode(
			array(
				'url'        => $url,
				'generation' => (int) Settings::get( 'generation', 1 ),
				'started_at' => $now,
			)
		);

		// The table name is built from the trusted WordPress prefix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE fingerprint = %s AND type = %s AND mode = %s",
				$fingerprint,
				self::TYPE,
				$mode
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $id ) {
			$wpdb->update(
				$table,
				array(
					'path'         => '',
					'metadata'     => $metadata,
					'status'       => 'processing',
					'last_used_at' => $now,
				),
				array( 'id' => (int) $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $fingerprint;
		}

		$wpdb->insert(
			$table,
			array(
				'fingerprint'  => $fingerprint,
				'type'         => self::TYPE,
				'mode'         => $mode,
				'path'         => '',
				'metadata'     => $metadata,
				'status'       => 'processing',
				'created_at'   => $now,
				'last_used_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $fingerprint;
	}

	/**
	 * @param array<string, mixed> $metadata Generation metadata.
	 */
	public function complete( string $fingerprint, string $mode, string $status, string $path, array $metadata ): void {
		global $wpdb;

		$metadata['generation'] = (int) Settings::get( 'generation', 1 );
		$metadata['ended_at']   = current_time( 'mysql', true );

		$wpdb->update(
			$wpdb->prefix . 'gtperf_artifacts',
			array(
				'path'         => $path,
				'metadata'     => wp_json_encode( $metadata ),
				'status'       => sanitize_key( $status ),
				'last_used_at' => current_time( 'mysql', true ),
			),
			array(
				'fingerprint' => $fingerprint,
				'type'        => self::TYPE,
				'mode'        => $mode,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s', '%s', '%s' )
		);
	}

	public function fail( string $fingerprint, string $mode, string $url, string $error ): void {
		$this->complete(
			$fingerprint,
			$mode,
			'failed',
			'',
			array(
				'url'   => $url,
				'error' => mb_substr( sanitize_text_field( $error ), 0, 500 ),
			)
		);
	}

	public function invalidateUrl( string $url ): int {
		global $wpdb;

		$table        = $wpdb->prefix . 'gtperf_artifacts';
		$fingerprints = array_map(
			static fn( string $mode ): string => hash( 'sha256', $url . '|' . $mode ),
			array( 'file', 'inline', 'hybrid' )
		);
		$placeholders = implode( ', ', array_fill( 0, count( $fingerprints ), '%s' ) );
		// The table name is built from the trusted WordPress prefix and the placeholder count is generated internally,
		// so the sniff cannot see that the interpolated IN () list carries one %s per fingerprint argument.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare(
			"UPDATE {$table} SET status = %s, last_used_at = %s WHERE type = %s AND fingerprint IN ({$placeholders})",
			'stale',
			current_time( 'mysql', true ),
			self::TYPE,
			...$fingerprints
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$result = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_artifacts';
		$limit = max( 1, min( 200, $limit ) );

		// The table name is built from the trusted WordPress prefix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fingerprint, mode, path, metadata, status, created_at, last_used_at
				FROM {$table}
				WHERE type = %s
				ORDER BY last_used_at DESC
				LIMIT %d",
				self::TYPE,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$currentGeneration = (int) Settings::get( 'generation', 1 );
		$reports           = array();
		foreach ( $rows as $row ) {
			$metadata = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$metadata = is_array( $metadata ) ? $metadata : array();
			$status   = (string) ( $row['status'] ?? 'failed' );
			if ( in_array( $status, array( 'ready', 'skipped' ), true ) && (int) ( $metadata['generation'] ?? 0 ) < $currentGeneration ) {
				$status = 'stale';
			}

			$row['metadata'] = $metadata;
			$row['status']   = $status;
			$reports[]       = $row;
		}

		return $reports;
	}

	/**
	 * @param list<array<string, mixed>> $reports Reports.
	 * @return array<string, int>
	 */
	public function summary( array $reports ): array {
		$summary = array(
			'processing' => 0,
			'ready'      => 0,
			'stale'      => 0,
			'failed'     => 0,
			'skipped'    => 0,
		);

		foreach ( $reports as $report ) {
			$status = (string) ( $report['status'] ?? '' );
			if ( array_key_exists( $status, $summary ) ) {
				++$summary[ $status ];
			}
		}

		return $summary;
	}
}
