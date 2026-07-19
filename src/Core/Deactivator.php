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
		if ( isset( $settings['cache'] ) && is_array( $settings['cache'] ) ) {
			$settings['cache']['enabled'] = false;
			Settings::compile( $settings );
		}

		$pageDropin = new \GTPerformance\Cache\DropinInstaller();
		if ( 'owned' === $pageDropin->status() ) {
			$pageDropin->remove();
		}

		$redisDropin = new \GTPerformance\Redis\ObjectCacheInstaller();
		if ( 'owned' === $redisDropin->status() ) {
			$redisDropin->remove();
		}

		wp_clear_scheduled_hook( 'gt_performance_run_queue' );
		wp_clear_scheduled_hook( 'gt_performance_database_cleanup' );
		wp_clear_scheduled_hook( 'gt_performance_verify_license' );
	}
}
