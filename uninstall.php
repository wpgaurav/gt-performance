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

/**
 * Remove one site's options and tables, when that site opted in.
 *
 * @return bool Whether the site asked for its data to be removed.
 */
$gt_performance_remove_site = static function (): bool {
	wp_unschedule_hook( 'gt_performance_cloudflare_retry' );

	if ( ! (bool) get_option( 'gt_performance_remove_data_on_uninstall', false ) ) {
		return false;
	}

	delete_option( 'gt_performance_settings' );
	delete_option( 'gt_performance_diagnostic_log' );
	delete_option( 'gt_performance_private_logs_version' );
	delete_option( 'gt_performance_legacy_logs_error' );
	delete_option( 'gt_performance_runtime_config_error' );
	delete_option( 'gt_performance_schema_version' );
	delete_option( 'gt_performance_queue_paused' );
	delete_option( 'gt_performance_queue_heartbeat' );
	delete_option( 'gt_performance_warm_runs' );
	delete_option( 'gt_performance_settings_history' );
	delete_option( 'gt_performance_agent_activity' );
	delete_option( 'gt_performance_advisor_history' );
	delete_option( 'gt_performance_database_run' );
	delete_option( 'gt_performance_database_run_stop' );
	delete_option( 'gt_performance_advisor_quota' );
	delete_option( 'gt_performance_dropin_version' );
	delete_option( 'gt_performance_object_cache_dropin_version' );
	delete_option( 'gt_performance_cloudflare_backup' );
	delete_option( 'gt_performance_cloudflare_state' );
	delete_option( 'gt_performance_cloudflare_plan' );
	delete_option( 'gt_performance_cloudflare_query_key_fallback' );
	delete_option( 'gt_performance_cloudflare_diagnostics' );
	delete_option( 'gt_performance_cloudflare_last_purge' );
	delete_option( 'gt_performance_cloudflare_rule_removed' );
	delete_option( 'gt_performance_wp_cache_constant_ownership' );
	delete_option( 'gt_performance_commerce_policy_hash' );
	delete_option( 'gt_performance_commerce_safety_runs' );
	delete_option( 'gt_performance_purge_receipts' );
	delete_option( 'gtperf_css_revision' );
	delete_option( 'gt_performance_css_script_classes' );
	delete_option( 'gt_performance_css_script_scan' );
	delete_option( 'gt_performance_css_training' );
	delete_option( 'gt_performance_css_training_previous' );
	delete_option( 'gt_performance_xcloud_last_purge' );
	delete_option( 'gt_performance_fleet_site_id' );
	delete_option( 'gt_performance_fleet_events' );
	delete_option( 'gt_performance_site_revision' );
	delete_option( 'gt_performance_server_rules' );
	delete_option( 'gt_performance_server_rules_error' );
	// Written by builds distributed before the WordPress.org release.
	delete_option( 'gt_performance_license' );
	delete_option( 'gt_performance_remove_data_on_uninstall' );
	delete_transient( 'gtperf_warm_pending' );
	delete_transient( 'gtperf_revalidate_pending' );

	global $wpdb;
	// SQLite advisory lock rows (NamedLock); MySQL locks leave no rows.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'gtperf_lock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$gt_performance_tables = array(
		$wpdb->prefix . 'gtperf_jobs',
		$wpdb->prefix . 'gtperf_dependencies',
		$wpdb->prefix . 'gtperf_artifacts',
		$wpdb->prefix . 'gtperf_warm_targets',
		$wpdb->prefix . 'gtperf_operations',
		$wpdb->prefix . 'gtperf_vitals',
	);

	foreach ( $gt_performance_tables as $gt_performance_table ) {
		// Table names are generated exclusively from the trusted WordPress prefix.
		$wpdb->query( "DROP TABLE IF EXISTS `{$gt_performance_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	return true;
};

/**
 * Delete a directory tree this plugin owns. Links are removed, never followed.
 */
$gt_performance_delete_tree = static function ( string $directory ): void {
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $entries as $entry ) {
		if ( $entry->isLink() || $entry->isFile() ) {
			wp_delete_file( $entry->getPathname() );
		} elseif ( $entry->isDir() ) {
			// No WordPress wrapper exists for removing a directory, and this one
			// is the plugin's own.
			@rmdir( $entry->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Leaves wp-content/cache itself for other plugins.
	@rmdir( $directory );
};

$gt_performance_cache_root = realpath( rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/gt-performance' );
$gt_performance_content    = realpath( WP_CONTENT_DIR );
$gt_performance_root_safe  = false !== $gt_performance_cache_root
	&& false !== $gt_performance_content
	&& is_dir( $gt_performance_cache_root )
	&& $gt_performance_cache_root === $gt_performance_content . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'gt-performance'
	&& ! is_link( $gt_performance_content . '/cache' )
	&& ! is_link( $gt_performance_content . '/cache/gt-performance' );

/*
 * On a network every site decides for itself. A site that opted in loses its
 * options, tables, and its own directory under sites/; the shared files (the
 * drop-in configuration, the site map, Redis settings) go only when the main
 * site opted in, because the main site owns them.
 */
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	$gt_performance_main_removed = false;
	foreach ( get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	) as $gt_performance_blog ) {
		switch_to_blog( (int) $gt_performance_blog );
		try {
			if ( $gt_performance_remove_site() ) {
				$gt_performance_main_removed = $gt_performance_main_removed || is_main_site();
				$gt_performance_site_dir     = $gt_performance_root_safe ? realpath( $gt_performance_cache_root . '/sites/' . (int) $gt_performance_blog ) : false;
				if ( false !== $gt_performance_site_dir && dirname( $gt_performance_site_dir ) === $gt_performance_cache_root . DIRECTORY_SEPARATOR . 'sites' && ! is_link( $gt_performance_cache_root . '/sites' ) ) {
					$gt_performance_delete_tree( $gt_performance_site_dir );
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	if ( ! $gt_performance_main_removed ) {
		return;
	}
	foreach ( array( 'gt_performance_network_revision', 'gt_performance_network_defaults', 'gt_performance_dropin_version', 'gt_performance_object_cache_dropin_version', 'gt_performance_wp_cache_constant_ownership', 'gt_performance_server_rules', 'gt_performance_server_rules_error' ) as $gt_performance_network_option ) {
		delete_site_option( $gt_performance_network_option );
	}
} elseif ( ! $gt_performance_remove_site() ) {
	return;
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
if ( $gt_performance_root_safe ) {
	$gt_performance_delete_tree( $gt_performance_cache_root );
}
