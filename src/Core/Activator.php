<?php
/**
 * Plugin activation.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Activator {
	public static function activate( bool $networkWide = false ): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( GTPERF_BASENAME );
			wp_die( esc_html__( 'GT Performance requires PHP 8.1 or newer.', 'gt-performance' ) );
		}

		if ( version_compare( get_bloginfo( 'version' ), '6.6', '<' ) ) {
			deactivate_plugins( GTPERF_BASENAME );
			wp_die( esc_html__( 'GT Performance requires WordPress 6.6 or newer.', 'gt-performance' ) );
		}

		if ( is_multisite() ) {
			if ( $networkWide ) {
				// Each site provisions and compiles itself on its next request, in its
				// own context, so its commerce rules come from its own plugins.
				Network::bumpRevision();
				foreach ( Paths::writableDirectories() as $directory ) {
					wp_mkdir_p( $directory );
				}
				Paths::harden();
				Network::writeSiteMap();
				return;
			}
			self::activateSite();
			Network::writeSiteMap();
			return;
		}

		self::activateSite();
	}

	/**
	 * Set up one site: its directories, tables, scheduled work, and compiled
	 * configuration. On a network this runs in a request for that site.
	 */
	public static function activateSite(): void {
		foreach ( Paths::writableDirectories() as $directory ) {
			wp_mkdir_p( $directory );
		}

		Paths::harden();
		Logger::removeLegacyFiles();

		// A network site inherits the network defaults until it saves its own.
		if ( ! is_multisite() && false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		Database::install();
		Settings::compile();

		add_filter( 'cron_schedules', array( Plugin::class, 'cronSchedules' ) );

		if ( ! wp_next_scheduled( 'gt_performance_run_queue' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'gtperf_every_minute', 'gt_performance_run_queue' );
		}

		if ( ! wp_next_scheduled( \GTPerformance\Cache\GarbageCollector::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', \GTPerformance\Cache\GarbageCollector::HOOK );
		}

		remove_filter( 'cron_schedules', array( Plugin::class, 'cronSchedules' ) );

		if ( is_multisite() ) {
			update_option( Network::SITE_REVISION, (string) get_site_option( Network::REVISION, '0' ), true );
		}
	}
}
