<?php
/**
 * Database maintenance and WordPress bloat controls.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Database;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class DatabaseModule implements Module {
	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'gt_performance_database_cleanup', array( $this, 'cleanup' ) );
		add_action( 'init', array( $this, 'applyBloatControls' ), 1 );
		add_filter( 'heartbeat_settings', array( $this, 'heartbeat' ) );
		add_filter( 'wp_revisions_to_keep', array( $this, 'revisions' ) );
	}

	public function schedule(): void {
		if ( (bool) Settings::get( 'database.enabled', false ) && ! wp_next_scheduled( 'gt_performance_database_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, (string) Settings::get( 'database.schedule', 'weekly' ), 'gt_performance_database_cleanup' );
		}
	}

	public function cleanup(): void {
		if ( (bool) Settings::get( 'database.enabled', false ) ) {
			( new Cleaner() )->run();
		}
	}

	public function applyBloatControls(): void {
		if ( (bool) Settings::get( 'bloat.disable_emojis', false ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		}

		if ( (bool) Settings::get( 'bloat.disable_embeds', false ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}
	}

	/**
	 * @param array<string, mixed> $settings Heartbeat settings.
	 * @return array<string, mixed>
	 */
	public function heartbeat( array $settings ): array {
		$settings['interval'] = max( 15, min( 120, (int) Settings::get( 'bloat.heartbeat_seconds', 60 ) ) );

		return $settings;
	}

	public function revisions( int $number ): int {
		return max( 0, (int) Settings::get( 'bloat.limit_revisions', $number ) );
	}
}
