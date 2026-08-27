<?php
/**
 * Database schema.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Database {
	public const SCHEMA_VERSION = '3';

	public static function maybeUpgrade(): void {
		if ( self::SCHEMA_VERSION === (string) get_option( 'gt_performance_schema_version', '' ) ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = $wpdb->prefix . 'gtperf_jobs';
		$deps    = $wpdb->prefix . 'gtperf_dependencies';
		$assets  = $wpdb->prefix . 'gtperf_artifacts';

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
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_available (status, available_at, priority),
				KEY lock_token (lock_token)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$deps} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				entity_type varchar(32) NOT NULL,
				entity_id varchar(191) NOT NULL,
				cache_url text NOT NULL,
				url_hash char(64) NOT NULL,
				variant varchar(64) NOT NULL DEFAULT 'public',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dependency (entity_type, entity_id, url_hash, variant),
				KEY url_hash (url_hash)
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

		// Retire this plugin's own superseded tables. Schema 3 renamed the `gtp_`
		// table prefix to `gtperf_`, so an upgraded site carries a full set of
		// tables under the old name that nothing reads any more; leaving them
		// behind would also leave the schema gate reporting success while every
		// queue query failed against a table that no longer exists. `gtp_vitals`
		// belongs to the retired real-user measurement feature.
		foreach ( array( 'gtp_jobs', 'gtp_dependencies', 'gtp_artifacts', 'gtp_vitals', 'gtperf_vitals' ) as $retired ) {
			$table = $wpdb->prefix . $retired;
			// The name interpolates only the trusted WordPress table prefix and a
			// literal from the list above. Dropping this plugin's own retired
			// tables is the entire purpose of the statement.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$settings = get_option( Settings::OPTION, array() );
		if ( is_array( $settings ) && array_key_exists( 'rum', $settings ) ) {
			unset( $settings['rum'] );
			update_option( Settings::OPTION, $settings, false );
		}

		update_option( 'gt_performance_schema_version', self::SCHEMA_VERSION, false );
	}
}
