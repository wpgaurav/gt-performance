<?php
/**
 * Plugin activation.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Activator {
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( GTPERF_BASENAME );
			wp_die( esc_html__( 'GT Performance requires PHP 8.1 or newer.', 'gt-performance' ) );
		}

		if ( version_compare( get_bloginfo( 'version' ), '6.6', '<' ) ) {
			deactivate_plugins( GTPERF_BASENAME );
			wp_die( esc_html__( 'GT Performance requires WordPress 6.6 or newer.', 'gt-performance' ) );
		}

		// One compiled config file and one cache root are shared by the whole network,
		// so the last subsite to save settings decides the bypass rules every other
		// subsite is cached under, and a network purge wipes every site at once. Refusing
		// is honest; a half-correct network mode would cache one subsite's checkout for
		// another's visitors.
		if ( is_multisite() ) {
			deactivate_plugins( GTPERF_BASENAME );
			wp_die( esc_html__( 'GT Performance does not support WordPress multisite. Its cache configuration and cache directory are shared across a network, which would let one site\'s settings decide another site\'s cache behavior.', 'gt-performance' ) );
		}

		foreach ( Paths::writableDirectories() as $directory ) {
			wp_mkdir_p( $directory );
		}

		Paths::harden();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		Database::install();
		Settings::compile();

		add_filter( 'cron_schedules', array( Plugin::class, 'cronSchedules' ) );

		if ( ! wp_next_scheduled( 'gt_performance_run_queue' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'gtperf_every_minute', 'gt_performance_run_queue' );
		}

		remove_filter( 'cron_schedules', array( Plugin::class, 'cronSchedules' ) );
	}
}
