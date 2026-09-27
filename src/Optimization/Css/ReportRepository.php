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

	/** @var array<string, array{generation:int,revision:string}> */
	private array $buildVersions = array();

	public function begin( string $url, string $mode ): string {
		$fingerprint = hash( 'sha256', $url . '|' . $mode );
		\GTPerformance\Queue\JobLease::publish(
			function () use ( $url, $mode ): bool {
				$this->beginOwned( $url, $mode );
				return true;
			}
		);
		return $fingerprint;
	}

	private function beginOwned( string $url, string $mode ): string {
		global $wpdb;

		$fingerprint = hash( 'sha256', $url . '|' . $mode );
		$table       = $wpdb->prefix . 'gtperf_artifacts';
		$now         = current_time( 'mysql', true );
		$this->buildVersions[ $fingerprint ] = array(
			'generation' => (int) Settings::get( 'generation', 1 ),
			'revision' => (string) get_option( 'gtperf_css_revision', 1 ),
		);
		$metadata    = wp_json_encode(
			array(
				'url'        => $url,
				'generation' => (int) Settings::get( 'generation', 1 ),
				'started_at' => $now,
				'revision'   => (string) get_option( 'gtperf_css_revision', 1 ),
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
		\GTPerformance\Queue\JobLease::publish(
			function () use ( $fingerprint, $mode, $status, $path, $metadata ): bool {
				$this->writeComplete( $fingerprint, $mode, $status, $path, $metadata );
				return true;
			}
		);
	}

	/** @param array<string,mixed> $metadata Generation metadata. */
	private function writeComplete( string $fingerprint, string $mode, string $status, string $path, array $metadata ): void {
		global $wpdb;

		$metadata['generation'] = $this->buildVersions[ $fingerprint ]['generation'] ?? (int) Settings::get( 'generation', 1 );
		$metadata['revision']   = $this->buildVersions[ $fingerprint ]['revision'] ?? (string) get_option( 'gtperf_css_revision', 1 );
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

		// Outlive reusable markup so older variants cannot reappear after a forced build.
		set_transient( 'gtperf_css_url_revision_' . hash( 'sha256', $url ), wp_generate_uuid4(), 2 * DAY_IN_SECONDS );

		foreach ( array( 'file', 'inline', 'hybrid' ) as $mode ) {
			$report = $this->find( $url, $mode );
			$key = (string) ( $report['metadata']['reuse_key'] ?? '' );
			if ( '' !== $key ) {
				delete_transient( 'gtperf_css_reuse_' . $key );
			}
		}

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
	public function recent( int $limit = 50, int $offset = 0 ): array {
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
				ORDER BY last_used_at DESC, id DESC
				LIMIT %d OFFSET %d",
				self::TYPE,
				$limit,
				max( 0, $offset )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$reports = array();
		foreach ( $rows as $row ) {
			$metadata        = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$row['metadata'] = is_array( $metadata ) ? $metadata : array();
			$row['status']   = $this->effectiveStatus( $row );
			$reports[]       = $row;
		}

		return $reports;
	}

	/**
	 * A stored ready report is stale once settings or the CSS revision moved on,
	 * or when one of its generated files has gone.
	 *
	 * @param array<string, mixed> $row Report with decoded metadata.
	 */
	public function effectiveStatus( array $row ): string {
		$metadata = (array) ( $row['metadata'] ?? array() );
		$status   = (string) ( $row['status'] ?? 'failed' );
		if ( in_array( $status, array( 'ready', 'skipped' ), true ) && ( (int) ( $metadata['generation'] ?? 0 ) < (int) Settings::get( 'generation', 1 ) || (string) ( $metadata['revision'] ?? 1 ) !== (string) get_option( 'gtperf_css_revision', 1 ) ) ) {
			return 'stale';
		}
		if ( 'ready' === $status ) {
			foreach ( (array) ( $metadata['outputs'] ?? array() ) as $output ) {
				if ( ! empty( $output['path'] ) && ! is_file( (string) $output['path'] ) ) {
					return 'stale';
				}
			}
		}

		return $status;
	}

	/** @return array<string, mixed>|null */
	public function find( string $url, string $mode ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'gtperf_artifacts';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND fingerprint = %s AND mode = %s", self::TYPE, hash( 'sha256', $url . '|' . $mode ), $mode ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}
		$metadata = json_decode( (string) $row['metadata'], true );
		$row['metadata'] = is_array( $metadata ) ? $metadata : array();
		return $row;
	}

	/**
	 * Report totals; byte savings include current ready reports only.
	 *
	 * With a delivery mode, results built for another mode are left out of the
	 * status counts and totalled as `other_mode`: they are history from before a
	 * mode change, and counting them made a site look mostly stale.
	 *
	 * @return array<string, int>
	 */
	public function statistics( string $mode = '' ): array {
		$totals = $this->summary( array() ) + array(
			'total'           => 0,
			'original_bytes'  => 0,
			'generated_bytes' => 0,
			'other_mode'      => 0,
		);
		foreach ( $this->all() as $rows ) {
			foreach ( $rows as $row ) {
				if ( '' !== $mode && (string) ( $row['mode'] ?? '' ) !== $mode ) {
					++$totals['other_mode'];
					continue;
				}
				++$totals['total'];
				if ( isset( $totals[ $row['status'] ] ) ) {
					++$totals[ $row['status'] ];
				}
				if ( 'ready' === $row['status'] ) {
					$totals['original_bytes']  += max( 0, (int) ( $row['metadata']['original_bytes'] ?? 0 ) );
					$totals['generated_bytes'] += max( 0, (int) ( $row['metadata']['generated_bytes'] ?? 0 ) );
				}
			}
		}

		return $totals;
	}

	/**
	 * Current Hybrid builds: how many fell back to one file, and the critical
	 * CSS sizes of those that recorded one.
	 *
	 * @return array{builds:int,fallbacks:int,sizes:list<int>}
	 */
	public function hybridBudget(): array {
		$result = array(
			'builds'    => 0,
			'fallbacks' => 0,
			'sizes'     => array(),
		);
		foreach ( $this->all() as $rows ) {
			foreach ( $rows as $row ) {
				if ( 'hybrid' !== (string) ( $row['mode'] ?? '' ) || 'ready' !== $row['status'] ) {
					continue;
				}
				++$result['builds'];
				if ( ReportExplainer::FALLBACK_BUDGET === ( $row['metadata']['fallback'] ?? '' ) ) {
					++$result['fallbacks'];
				}
				if ( isset( $row['metadata']['critical_bytes'] ) ) {
					$result['sizes'][] = (int) $row['metadata']['critical_bytes'];
				}
			}
		}

		return $result;
	}

	/**
	 * Every report, 200 at a time.
	 *
	 * @return \Generator<int, list<array<string, mixed>>>
	 */
	private function all(): \Generator {
		$offset = 0;
		do {
			$rows    = $this->recent( 200, $offset );
			$fetched = count( $rows );
			yield $rows;
			$offset += 200;
		} while ( 200 === $fetched );
	}

	/**
	 * Stable cursor for background rebuilding, independent of report timestamps.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function batch( int $afterId ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'gtperf_artifacts';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, metadata FROM {$table} WHERE type = %s AND id > %d ORDER BY id ASC LIMIT 100", self::TYPE, $afterId ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param list<array<string, mixed>> $reports Reports.
	 * @return array<string, int>
	 */
	public function summary( array $reports ): array {
		$summary = array(
			'queued'     => 0,
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
