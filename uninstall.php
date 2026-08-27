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

global $wpdb;

$gt_performance_tables = array(
	$wpdb->prefix . 'gtperf_jobs',
	$wpdb->prefix . 'gtperf_dependencies',
	$wpdb->prefix . 'gtperf_artifacts',
	$wpdb->prefix . 'gtperf_vitals',
);

foreach ( $gt_performance_tables as $gt_performance_table ) {
	// Table names are generated exclusively from the trusted WordPress prefix.
	$wpdb->query( "DROP TABLE IF EXISTS `{$gt_performance_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/*
 * Remove the cache directory.
 *
 * Deleting options and tables alone leaves cached HTML, generated CSS and JS,
 * logs, and both configuration files on disk - and the Redis configuration
 * holds a host, username, and password. A visitor cannot read them, but someone
 * who asked for their data to be removed should not be left with credentials in
 * wp-content.
 *
 * The path is derived the same way Core\Paths does, inline, because uninstall
 * runs without the plugin loaded and should not bootstrap it. Only this
 * plugin's own directory is touched, and only after realpath confirms it still
 * resolves inside wp-content.
 */
$gt_performance_cache_root = realpath( rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/gt-performance' );
$gt_performance_content    = realpath( WP_CONTENT_DIR );

if (
	false !== $gt_performance_cache_root
	&& false !== $gt_performance_content
	&& is_dir( $gt_performance_cache_root )
	&& str_starts_with( $gt_performance_cache_root, $gt_performance_content . DIRECTORY_SEPARATOR )
) {
	$gt_performance_entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $gt_performance_cache_root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $gt_performance_entries as $gt_performance_entry ) {
		if ( $gt_performance_entry->isLink() || $gt_performance_entry->isFile() ) {
			wp_delete_file( $gt_performance_entry->getPathname() );
		} elseif ( $gt_performance_entry->isDir() ) {
			// No WordPress wrapper exists for removing a directory, and this one
			// is the plugin's own.
			@rmdir( $gt_performance_entry->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Leaves wp-content/cache itself for other plugins.
	@rmdir( $gt_performance_cache_root );
}
