<?php
/**
 * Database schema.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Database {
	private static bool $installing = false;
	public const SCHEMA_VERSION = '7';

	public static function maybeUpgrade(): void {
		if ( self::SCHEMA_VERSION === (string) get_option( 'gt_performance_schema_version', '' ) ) {
			return;
		}

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::install();
		}
	}

	public static function queueReady(): bool {
		return ! self::$installing && self::SCHEMA_VERSION === (string) get_option( 'gt_performance_schema_version', '' );
	}

	/**
	 * WordPress Studio and Playground run WordPress on SQLite through a MySQL
	 * translation layer. It emulates the SQL this plugin uses except advisory
	 * locks, which NamedLock replaces.
	 */
	public static function isSqlite(): bool {
		global $wpdb;

		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || is_a( $wpdb, 'WP_SQLite_DB' );
	}

	public static function install(): void {
		if ( ! NamedLock::acquire( 'schema', 5 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		self::$installing = true;
		try {
			self::installLocked();
		} finally {
			self::$installing = false;
			NamedLock::release( 'schema' );
		}
	}

	private static function installLocked(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = $wpdb->prefix . 'gtperf_jobs';
		$deps    = $wpdb->prefix . 'gtperf_dependencies';
		$assets  = $wpdb->prefix . 'gtperf_artifacts';
		$warm    = $wpdb->prefix . 'gtperf_warm_targets';
		$ops     = $wpdb->prefix . 'gtperf_operations';

		dbDelta(
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(64) NOT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				priority smallint(5) unsigned NOT NULL DEFAULT 100,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				available_at datetime NOT NULL,
				locked_at datetime NULL,
				lock_token varchar(64) NULL,
				lease_expires_at datetime NULL,
				active_key char(64) NULL,
				cancel_requested tinyint(1) NOT NULL DEFAULT 0,
				last_error text NULL,
				result_metadata text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_available (status, available_at, priority),
				KEY lock_token (lock_token),
				UNIQUE KEY active_key (active_key)
			) ENGINE=InnoDB {$charset};"
		);

		$queueReady = self::prepareQueueUniqueness( $jobs );

		dbDelta(
			"CREATE TABLE {$deps} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				entity_type varchar(32) NOT NULL,
				entity_id varchar(191) NOT NULL,
				cache_url text NOT NULL,
				url_hash char(64) NOT NULL,
				variant varchar(64) NOT NULL DEFAULT 'public',
				generation int(10) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dependency (entity_type, entity_id, url_hash, variant),
				KEY url_hash (url_hash),
				KEY entity_generation (entity_type, entity_id, generation)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$assets} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				fingerprint char(64) NOT NULL,
				type varchar(32) NOT NULL,
				mode varchar(20) NOT NULL,
				path text NOT NULL,
				metadata longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'ready',
				created_at datetime NOT NULL,
				last_used_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY artifact (fingerprint, type, mode),
				KEY last_used (last_used_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$warm} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id char(36) NOT NULL,
				target_hash char(64) NOT NULL,
				kind varchar(16) NOT NULL,
				url text NOT NULL,
				variant varchar(16) NOT NULL DEFAULT 'public',
				depth tinyint(3) unsigned NOT NULL DEFAULT 0,
				priority smallint(5) unsigned NOT NULL DEFAULT 50,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				result varchar(191) NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_target (run_id, target_hash, variant),
				KEY run_work (run_id, kind, status, priority),
				KEY created_at (created_at)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$ops} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				actor bigint(20) unsigned NOT NULL,
				request_id char(36) NOT NULL,
				payload_hash char(64) NOT NULL,
				operation varchar(32) NOT NULL,
				target_summary varchar(191) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'accepted',
				job_id bigint(20) unsigned NULL,
				payload longtext NULL,
				result longtext NULL,
				expires_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY actor_request (actor, request_id),
				KEY status (status),
				KEY actor_created (actor, created_at)
			) ENGINE=InnoDB {$charset};"
		);

		$settings = get_option( Settings::OPTION, array() );
		if ( is_array( $settings ) && array_key_exists( 'rum', $settings ) ) {
			unset( $settings['rum'] );
			update_option( Settings::OPTION, $settings, false );
		}

		if ( $queueReady ) {
			update_option( 'gt_performance_schema_version', self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Backfill at most 500 keys per administrative request. NULL keys let dbDelta
	 * install the unique index before backfill. Running leases keep their tokens;
	 * duplicate running jobs block completion until their leases end.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	private static function prepareQueueUniqueness( string $table ): bool {
		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0 );
		if ( ! is_array( $columns ) || array_diff( array( 'active_key', 'lease_expires_at', 'cancel_requested', 'result_metadata' ), $columns ) ) {
			return false;
		}
		$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'active_key'", $table ), ARRAY_A );
		$info = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
		if ( ! is_array( $info ) || 'innodb' !== strtolower( (string) $info['Engine'] ) ) {
			return false;
		}
		if ( ! is_array( $index ) || 0 !== (int) $index['Non_unique'] ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - \GTPerformance\Queue\JobRepository::LEASE_SECONDS );
		// Old workers may have died before this upgrade. Retire their token before
		// consolidation; never cancel a worker whose original lease is still live.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'pending' END, lock_token = NULL, locked_at = NULL, lease_expires_at = NULL
			WHERE status = 'running' AND active_key IS NULL
			AND ((lease_expires_at IS NULL AND (locked_at IS NULL OR locked_at < %s)) OR lease_expires_at < %s) LIMIT 500",
				$table,
				$stale,
				$now
			)
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, payload, status FROM %i WHERE active_key IS NULL AND status IN ('pending', 'running')
				ORDER BY (status = 'running') DESC, id ASC LIMIT 500",
				$table
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return false;
		}
		$jobs = new \GTPerformance\Queue\JobRepository();
		foreach ( $rows as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			$key = $jobs->activeKey( (string) $row['type'], is_array( $payload ) ? $payload : array() );
			$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE active_key = %s LIMIT 1', $table, $key ) );
			if ( $existing > 0 ) {
				if ( 'pending' === $row['status'] ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE %i SET status = 'cancelled', updated_at = %s WHERE id = %d AND status = 'pending' AND active_key IS NULL",
							$table,
							$now,
							(int) $row['id']
						)
					);
				}
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET active_key = %s,
				lease_expires_at = CASE WHEN status = 'running' THEN COALESCE(lease_expires_at, DATE_ADD(locked_at, INTERVAL 600 SECOND)) ELSE lease_expires_at END
				WHERE id = %d AND active_key IS NULL AND status IN ('pending', 'running')",
					$table,
					$key,
					(int) $row['id']
				)
			);
		}
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE active_key IS NULL AND status IN ('pending', 'running')", $table ) );
		return null !== $remaining && 0 === (int) $remaining;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
