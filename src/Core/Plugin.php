<?php
/**
 * Main plugin module loader.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

use GTPerformance\Contracts\Module;

final class Plugin {
	private static ?self $instance = null;

	/**
	 * @var list<Module>
	 */
	private array $modules = array();

	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register();
	}

	private function __construct() {
		$logger = new Logger();

		$this->modules = array(
			new \GTPerformance\Cache\PageCacheModule( $logger ),
			new \GTPerformance\Queue\QueueModule( $logger ),
			new \GTPerformance\Commerce\CommerceModule(),
			new \GTPerformance\Cloudflare\CloudflareModule( $logger ),
			new \GTPerformance\Optimization\OptimizationModule( $logger ),
			new \GTPerformance\Database\DatabaseModule(),
			new \GTPerformance\Redis\RedisModule(),
			new \GTPerformance\RUM\RumModule(),
			new \GTPerformance\Admin\AdminModule(),
			new \GTPerformance\CLI\CliModule(),
		);
	}

	private function register(): void {
		add_filter( 'cron_schedules', array( $this, 'cronSchedules' ) );

		foreach ( $this->modules as $module ) {
			$module->register();
		}

		do_action( 'gt_performance_loaded', $this );
	}

	/**
	 * @param array<string, array<string, int|string>> $schedules Cron schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public function cronSchedules( array $schedules ): array {
		$schedules['gtp_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (GT Performance)', 'gt-performance' ),
		);

		return $schedules;
	}
}
