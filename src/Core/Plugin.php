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

		Database::maybeUpgrade();
		\GTPerformance\Cache\DropinInstaller::syncVersion();
		\GTPerformance\Redis\ObjectCacheInstaller::syncVersion();

		self::$instance = new self();
		self::$instance->register();
	}

	private function __construct() {
		$logger = new Logger();

		$this->modules = array(
			new \GTPerformance\Cache\PageCacheModule( $logger ),
			new \GTPerformance\CDN\CdnModule(),
			new \GTPerformance\Queue\QueueModule( $logger ),
			new \GTPerformance\Commerce\CommerceModule(),
			new \GTPerformance\PrivateFragments\PrivateFragmentsModule(),
			new \GTPerformance\Compatibility\CoreFormsModule(),
			new \GTPerformance\Compatibility\CompatibilityModule(),
			new \GTPerformance\Cloudflare\CloudflareModule( $logger ),
			new \GTPerformance\XCloud\XCloudModule( $logger ),
			new \GTPerformance\Optimization\OptimizationModule( $logger ),
			new \GTPerformance\Optimization\Css\TrainingModule(),
			new \GTPerformance\Database\DatabaseModule(),
			new \GTPerformance\Redis\RedisModule(),
			new \GTPerformance\Licensing\LicenseModule(),
			new \GTPerformance\Fleet\FleetModule(),
			new \GTPerformance\Admin\AdminModule(),
			new \GTPerformance\Admin\AdminBarModule(),
			new \GTPerformance\CLI\CliModule(),
		);
	}

	private function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'cronSchedules' ) );

		foreach ( $this->modules as $module ) {
			$module->register();
		}

		do_action( 'gt_performance_loaded', $this );
	}

	/**
	 * @param array<string, array<string, int|string>> $schedules Cron schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public static function cronSchedules( array $schedules ): array {
		$schedules['gtperf_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (GT Performance)', 'gt-performance' ),
		);
		$schedules['gtperf_weekly']       = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly (GT Performance)', 'gt-performance' ),
		);
		$schedules['gtperf_monthly']      = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly (GT Performance)', 'gt-performance' ),
		);

		return $schedules;
	}
}
