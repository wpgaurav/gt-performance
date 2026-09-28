<?php
/**
 * Plugin deactivation.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Deactivator {
	public static function deactivate(): void {
		$settings = Settings::all();

		// Best effort: a zone that cannot be reached must not block deactivation.
		// Failures leave the rule in place, and `cloudflare disconnect` can finish it.
		if ( \GTPerformance\Cloudflare\Disconnector::applies( $settings ) ) {
			$detached = ( new \GTPerformance\Cloudflare\Disconnector() )->detach( $settings );
			if ( ! is_wp_error( $detached ) && 'removed' === $detached['rule'] ) {
				update_option( \GTPerformance\Cloudflare\RuleManager::REMOVED_OPTION, time(), false );
			}
		}

		if ( isset( $settings['cache'] ) && is_array( $settings['cache'] ) ) {
			$settings['cache']['enabled'] = false;
			Settings::compile( $settings );
		}

		$pageDropin = new \GTPerformance\Cache\DropinInstaller();
		if ( 'owned' === $pageDropin->status() ) {
			$pageDropin->remove();
		}

		// Nothing purges while the plugin is off, so a page edited in the meantime
		// would come back from the store on reactivation (seen on
		// gtp-demo.gatilab.com: a copy from before deactivation served as a HIT).
		// The edge copies went with the Cloudflare rule above.
		( new \GTPerformance\Cache\FileStore() )->purgeAll();

		$redisDropin = new \GTPerformance\Redis\ObjectCacheInstaller();
		if ( 'owned' === $redisDropin->status() ) {
			$redisDropin->remove();
		}

		wp_clear_scheduled_hook( 'gt_performance_run_queue' );
		wp_clear_scheduled_hook( \GTPerformance\Cache\GarbageCollector::HOOK );
		wp_clear_scheduled_hook( 'gt_performance_database_cleanup' );
		wp_unschedule_hook( \GTPerformance\Cloudflare\CloudflareModule::RETRY_HOOK );
		// Scheduled by builds distributed before the WordPress.org release.
		wp_clear_scheduled_hook( 'gt_performance_verify_license' );
	}
}
