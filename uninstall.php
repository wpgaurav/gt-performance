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
delete_option( 'gt_performance_cloudflare_backup' );
delete_option( 'gt_performance_cloudflare_state' );
delete_option( 'gt_performance_cloudflare_query_key_fallback' );
delete_option( 'gt_performance_cloudflare_diagnostics' );
delete_option( 'gt_performance_wp_cache_constant_ownership' );
delete_option( 'gt_performance_remove_data_on_uninstall' );

global $wpdb;

$tables = array(
	$wpdb->prefix . 'gtp_jobs',
	$wpdb->prefix . 'gtp_dependencies',
	$wpdb->prefix . 'gtp_artifacts',
	$wpdb->prefix . 'gtp_vitals',
);

foreach ( $tables as $table ) {
	// Table names are generated exclusively from the trusted WordPress prefix.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
