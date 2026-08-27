<?php
/**
 * GT Performance uninstall handler.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! (bool) get_option( 'gt_performance_remove_data_on_uninstall', false ) ) {
	return;
}

delete_option( 'gt_performance_settings' );
delete_option( 'gt_performance_schema_version' );
delete_option( 'gt_performance_dropin_version' );
delete_option( 'gt_performance_object_cache_dropin_version' );
delete_option( 'gt_performance_cloudflare_backup' );
delete_option( 'gt_performance_cloudflare_state' );
delete_option( 'gt_performance_cloudflare_plan' );
delete_option( 'gt_performance_cloudflare_query_key_fallback' );
delete_option( 'gt_performance_cloudflare_diagnostics' );
delete_option( 'gt_performance_wp_cache_constant_ownership' );
delete_option( 'gt_performance_commerce_policy_hash' );
delete_option( 'gt_performance_commerce_safety_runs' );
delete_option( 'gt_performance_purge_receipts' );
delete_option( 'gt_performance_css_training' );
delete_option( 'gt_performance_css_training_previous' );
delete_option( 'gt_performance_xcloud_last_purge' );
delete_option( 'gt_performance_fleet_site_id' );
delete_option( 'gt_performance_fleet_events' );
// Written by builds distributed before the WordPress.org release.
delete_option( 'gt_performance_license' );
delete_option( 'gt_performance_remove_data_on_uninstall' );
delete_transient( 'gtperf_warm_pending' );
delete_transient( 'gtperf_revalidate_pending' );
delete_transient( 'gtp_warm_pending' );
delete_transient( 'gtp_revalidate_pending' );

global $wpdb;

$gt_performance_tables = array(
	$wpdb->prefix . 'gtperf_jobs',
	$wpdb->prefix . 'gtperf_dependencies',
	$wpdb->prefix . 'gtperf_artifacts',
	$wpdb->prefix . 'gtperf_vitals',
	// Names used before the schema 3 table rename. Normally dropped during the
	// upgrade, listed here so uninstalling can never leave one behind.
	$wpdb->prefix . 'gtp_jobs',
	$wpdb->prefix . 'gtp_dependencies',
	$wpdb->prefix . 'gtp_artifacts',
	$wpdb->prefix . 'gtp_vitals',
);

foreach ( $gt_performance_tables as $gt_performance_table ) {
	// Table names are generated exclusively from the trusted WordPress prefix.
	$wpdb->query( "DROP TABLE IF EXISTS `{$gt_performance_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}
