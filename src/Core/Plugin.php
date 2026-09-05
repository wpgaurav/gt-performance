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

		// Only the modules that can affect a front-end response are constructed on a
		// front-end request. Constructing all of them eagerly loaded 75 files and
		// 2.33 MB on every request, including a 154 KB admin class and the whole
		// Symfony CssSelector graph, before deciding whether any of it was needed.
		$this->modules = array(
			new \GTPerformance\Cache\PageCacheModule( $logger ),
			new \GTPerformance\CDN\CdnModule(),
			new \GTPerformance\Queue\QueueModule( $logger ),
			new \GTPerformance\Commerce\CommerceModule(),
			new \GTPerformance\Compatibility\CoreFormsModule(),
			new \GTPerformance\Compatibility\CompatibilityModule(),
			new \GTPerformance\Optimization\OptimizationModule( $logger ),
			new \GTPerformance\Database\DatabaseModule(),
		);

		// Edge integrations hook post-save invalidation and admin actions. Neither
		// happens on a cache miss for an anonymous visitor.
		if ( self::needsManagementModules() ) {
			$this->modules[] = new \GTPerformance\Cloudflare\CloudflareModule( $logger );
			$this->modules[] = new \GTPerformance\XCloud\XCloudModule( $logger );
			$this->modules[] = new \GTPerformance\Redis\RedisModule();
		}

		if ( is_admin() ) {
			$this->modules[] = new \GTPerformance\Admin\AdminModule();
		}

		if ( is_admin() || self::hasAuthenticationCookie() ) {
			$this->modules[] = new \GTPerformance\Admin\AdminBarModule();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->modules[] = new \GTPerformance\CLI\CliModule();
		}
	}

	/**
	 * Whether this request can reach a management or invalidation path.
	 *
	 * Cron and the queue invalidate edge caches, admin-post handles the buttons, and
	 * a signed-in user can act through the admin bar. An anonymous front-end request
	 * reaches none of them.
	 */
	private static function needsManagementModules(): bool {
		return is_admin()
			|| wp_doing_cron()
			|| wp_doing_ajax()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| self::hasAuthenticationCookie()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	private function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'cronSchedules' ) );

		foreach ( $this->modules as $module ) {
			$module->register();
		}

		do_action( 'gt_performance_loaded', $this );
	}

	/**
	 * Whether the request carries a WordPress authentication cookie.
	 *
	 * Deliberately not is_user_logged_in(): this runs on plugins_loaded priority 1,
	 * and resolving the current user there fires determine_current_user before
	 * authentication plugins that hook it later have registered, which changes who
	 * WordPress thinks the visitor is. The cookie name is enough to decide whether an
	 * admin-bar module is worth constructing, and reading it has no side effects.
	 */
	private static function hasAuthenticationCookie(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a cookie name, not acting on a value.
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( str_starts_with( (string) $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}

		return false;
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
