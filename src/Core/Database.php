<?php
/**
 * Database schema.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Database {
	public const SCHEMA_VERSION = '2';

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
		$jobs    = $wpdb->prefix . 'gtp_jobs';
		$deps    = $wpdb->prefix . 'gtp_dependencies';
		$assets  = $wpdb->prefix . 'gtp_artifacts';

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

		$legacyVitals = $wpdb->prefix . 'gtp_vitals';
		// Remove the retired real-user measurement table during alpha upgrades.
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacyVitals}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$settings = get_option( Settings::OPTION, array() );
		if ( is_array( $settings ) && array_key_exists( 'rum', $settings ) ) {
			unset( $settings['rum'] );
			update_option( Settings::OPTION, $settings, false );
		}

		update_option( 'gt_performance_schema_version', self::SCHEMA_VERSION, false );
	}
}
