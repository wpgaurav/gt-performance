<?php
/**
 * Durable warm runs and their sitemap/URL targets.
 *
 * Targets live in the plugin's own gtperf_warm_targets table, so every access is
 * a direct query. Run summaries are a short non-autoloaded option list. Only
 * the single queue runner mutates either, so neither needs its own lock. Table
 * names interpolate only the trusted WordPress table prefix.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class WarmRunRepository {
	public const OPTION = 'gt_performance_warm_runs';

	/** Rows per run, sitemaps and every variant included. */
	public const MAX_TARGETS = 50000;

	public const RETENTION_SECONDS = 7 * DAY_IN_SECONDS;

	private const KEEP_RUNS = 10;

	private const INSERT_CHUNK = 500;

	/** @var list<string> */
	public const TERMINAL = array( 'complete', 'partial', 'capacity_limited', 'superseded' );

	/**
	 * Start a run. An unfinished earlier run is superseded; its queued preloads
	 * still run, but their results no longer count against it.
	 *
	 * @param list<string> $sources Sitemap sources, as absolute URLs.
	 * @return array<string, mixed>
	 */
	public function create( array $sources ): array {
		$now  = time();
		$runs = array();
		foreach ( $this->all() as $run ) {
			if ( ! in_array( $run['state'], self::TERMINAL, true ) ) {
				$run['state']       = 'superseded';
				$run['finished_at'] = $now;
			}
			$runs[] = $run;
		}

		$run = array(
			'id'          => wp_generate_uuid4(),
			'state'       => 'discovering',
			'sources'     => array_values( array_map( array( self::class, 'relative' ), $sources ) ),
			'started_at'  => $now,
			'updated_at'  => $now,
			'finished_at' => 0,
			'warnings'    => array(),
			'wait_steps'  => 0,
		);
		array_unshift( $runs, $run );
		$this->save( $runs );

		return $run;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $run ) {
			if ( $run['id'] === $id ) {
				return $run;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function latest(): ?array {
		return $this->all()[0] ?? null;
	}

	/**
	 * @param array<string, mixed> $changes Fields to replace.
	 */
	public function update( string $id, array $changes ): void {
		$runs = $this->all();
		foreach ( $runs as &$run ) {
			if ( $run['id'] === $id ) {
				$run               = array_merge( $run, $changes );
				$run['updated_at'] = time();
			}
		}
		unset( $run );
		$this->save( $runs );
	}

	public function warn( string $id, string $code ): void {
		$run = $this->find( $id );
		if ( null === $run || in_array( $code, (array) $run['warnings'], true ) ) {
			return;
		}
		$warnings   = (array) $run['warnings'];
		$warnings[] = sanitize_key( $code );
		$this->update( $id, array( 'warnings' => array_slice( $warnings, 0, 20 ) ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return array();
		}

		$runs = array();
		foreach ( $saved as $run ) {
			if ( is_array( $run ) && isset( $run['id'], $run['state'] ) && is_string( $run['id'] ) ) {
				$runs[] = $run + array(
					'warnings'   => array(),
					'wait_steps' => 0,
				);
			}
		}

		return $runs;
	}

	/**
	 * Insert targets, ignoring any already visited in this run. A target is
	 * identified by kind and normalized URL, so a sitemap that lists itself or a
	 * cycle of indexes cannot be fetched twice.
	 *
	 * @param list<array{kind:string,url:string,variant?:string,depth?:int,priority?:int,status?:string,result?:string}> $targets Targets.
	 * @return array{inserted:int,capped:bool}
	 */
	public function add( string $runId, array $targets ): array {
		global $wpdb;

		$table     = $wpdb->prefix . 'gtperf_warm_targets';
		$room      = self::MAX_TARGETS - $this->total( $runId );
		$capped    = count( $targets ) > $room;
		$targets   = array_slice( $targets, 0, max( 0, $room ) );
		$now       = current_time( 'mysql', true );
		$inserted  = 0;

		foreach ( array_chunk( $targets, self::INSERT_CHUNK ) as $chunk ) {
			$rows   = array();
			$values = array();
			foreach ( $chunk as $target ) {
				$rows[] = '(%s, %s, %s, %s, %s, %d, %d, %s, %s, %s, %s)';
				array_push(
					$values,
					$runId,
					self::hash( $target['kind'], $target['url'] ),
					$target['kind'],
					$target['url'],
					$target['variant'] ?? 'public',
					max( 0, min( 255, (int) ( $target['depth'] ?? 0 ) ) ),
					max( 0, min( 65535, (int) ( $target['priority'] ?? 50 ) ) ),
					$target['status'] ?? 'pending',
					substr( (string) ( $target['result'] ?? '' ), 0, 191 ),
					$now,
					$now
				);
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated one row group per target above.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (run_id, target_hash, kind, url, variant, depth, priority, status, result, created_at, updated_at) VALUES " . implode( ', ', $rows ),
					$values
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$inserted += is_int( $result ) ? $result : 0;
		}

		return array(
			'inserted' => $inserted,
			'capped'   => $capped,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function pending( string $runId, string $kind, int $limit ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_warm_targets';
		$order = 'sitemap' === $kind ? 'depth ASC, id ASC' : 'priority ASC, id ASC';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, variant, depth, attempts FROM {$table}
				WHERE run_id = %s AND kind = %s AND status = 'pending'
				ORDER BY {$order} LIMIT %d",
				$runId,
				$kind,
				max( 1, min( 2000, $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	public function mark( int $id, string $status, string $result = '' ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'gtperf_warm_targets',
			array(
				'status'     => $status,
				'result'     => '' === $result ? null : substr( $result, 0, 191 ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param list<int> $ids Target IDs.
	 */
	public function markQueued( array $ids ): void {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return;
		}
		$table = $wpdb->prefix . 'gtperf_warm_targets';
		$in    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'queued', updated_at = %s WHERE status = 'pending' AND id IN ({$in})",
				array_merge( array( current_time( 'mysql', true ) ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Move every target of a kind from one status to another.
	 */
	public function transition( string $runId, string $kind, string $from, string $to, string $result ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_warm_targets';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, result = %s, updated_at = %s WHERE run_id = %s AND kind = %s AND status = %s",
				$to,
				$result,
				current_time( 'mysql', true ),
				$runId,
				$kind,
				$from
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Record a preload outcome against the run that queued it.
	 */
	public function record( string $runId, string $url, string $variant, string $status, string $result ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_warm_targets';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, result = %s, attempts = attempts + 1, updated_at = %s
				WHERE run_id = %s AND target_hash = %s AND variant = %s AND kind = 'url' AND status <> 'skipped'",
				$status,
				substr( $result, 0, 191 ),
				current_time( 'mysql', true ),
				$runId,
				self::hash( 'url', $url ),
				$variant
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * @return array{sitemap:array<string,int>,url:array<string,int>}
	 */
	public function counts( string $runId ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'gtperf_warm_targets';
		$counts = array(
			'sitemap' => array(),
			'url'     => array(),
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT kind, status, COUNT(*) AS total FROM {$table} WHERE run_id = %s GROUP BY kind, status", $runId ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$kind = 'sitemap' === $row['kind'] ? 'sitemap' : 'url';
			$counts[ $kind ][ sanitize_key( (string) $row['status'] ) ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Remove targets older than the retention window, in bounded batches, and
	 * forget the matching run summaries.
	 */
	public function purgeExpired( int $limit = 5000 ): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'gtperf_warm_targets';
		$cutoff = time() - self::RETENTION_SECONDS;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s ORDER BY created_at ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s', $cutoff ),
				max( 1, $limit )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$runs = array_values(
			array_filter(
				$this->all(),
				static fn ( array $run ): bool => ! in_array( $run['state'], self::TERMINAL, true ) || (int) $run['updated_at'] >= $cutoff
			)
		);
		if ( count( $runs ) !== count( $this->all() ) ) {
			$this->save( $runs );
		}

		return is_int( $deleted ) ? $deleted : 0;
	}

	public static function hash( string $kind, string $url ): string {
		return hash( 'sha256', $kind . '|' . self::normalize( $url ) );
	}

	/**
	 * Lowercase scheme and host, drop a default port and any fragment.
	 */
	public static function normalize( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return $url;
		}
		$scheme = strtolower( $parts['scheme'] );
		$port   = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );

		return $scheme . '://' . strtolower( $parts['host'] )
			. ( ( 'https' === $scheme ? 443 : 80 ) === $port ? '' : ':' . $port )
			. ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Path and query only, for summaries that may be exported.
	 */
	public static function relative( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		return ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	private function total( string $runId ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'gtperf_warm_targets';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE run_id = %s", $runId ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $total;
	}

	/**
	 * @param list<array<string, mixed>> $runs Runs, newest first.
	 */
	private function save( array $runs ): void {
		update_option( self::OPTION, array_slice( $runs, 0, self::KEEP_RUNS ), false );
	}
}
