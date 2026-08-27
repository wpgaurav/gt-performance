<?php
/**
 * Store-build upgrade migration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Migration;

use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Redis\ObjectCacheInstaller;

/**
 * Brings a site installed before 1.0.1 onto the current layout.
 *
 * This ships only in the build distributed from gauravtiwari.org, because that
 * is the only channel with installs that predate the 1.0.1 rename. The
 * WordPress.org build has no legacy state and carries none of this.
 *
 * Timing is the whole design. The drop-in written by 1.0.0 loads a fixed list of
 * runtime files that predates ConfigFile, so the first request after the plugin
 * files change raises a fatal from wp-settings.php - before WordPress exists,
 * before mu-plugins, before anything this class could hook. Nothing running
 * inside WordPress can rescue that request.
 *
 * What can be done is to never let that request happen. `upgrader_process_complete`
 * fires in the same request that replaced the files, while the old drop-in and
 * old files are still the consistent pair that booted it. Replacing the drop-in
 * there means the next request loads a matched set. The `admin_init` pass is the
 * fallback for a site whose files were replaced without the upgrader, where the
 * damage is already done and an administrator is repairing it.
 */
final class Migrator implements Module {
	private const OPTION = 'gt_performance_migrated_version';

	/**
	 * Configuration filenames written before 1.0.1.
	 */
	private const LEGACY_CONFIG = array( 'config.php', 'redis-config.php' );

	/**
	 * Table suffixes used before the schema 3 rename.
	 */
	private const LEGACY_TABLES = array( 'gtp_jobs', 'gtp_dependencies', 'gtp_artifacts', 'gtp_vitals' );

	/**
	 * Transients written before the 1.0.1 rename.
	 */
	private const LEGACY_TRANSIENTS = array( 'gtp_warm_pending', 'gtp_revalidate_pending' );

	public function __construct( private readonly Logger $logger = new Logger() ) {
	}

	public function register(): void {
		add_action( 'upgrader_process_complete', array( $this, 'afterUpgrade' ), 5, 2 );
		add_action( 'admin_init', array( $this, 'maybeRun' ) );
	}

	/**
	 * Run immediately after this plugin's files are replaced.
	 *
	 * @param mixed                $upgrader Upgrader instance.
	 * @param array<string, mixed> $context  Upgrade context.
	 */
	public function afterUpgrade( $upgrader, $context ): void {
		unset( $upgrader );

		if ( 'plugin' !== ( $context['type'] ?? '' ) ) {
			return;
		}

		$plugins = (array) ( $context['plugins'] ?? array() );
		if ( isset( $context['plugin'] ) ) {
			$plugins[] = (string) $context['plugin'];
		}

		foreach ( $plugins as $plugin ) {
			if ( GTPERF_BASENAME === $plugin ) {
				$this->run();
				return;
			}
		}
	}

	/**
	 * Repair a site whose files were replaced without the upgrader.
	 */
	public function maybeRun(): void {
		if ( GTPERF_VERSION === (string) get_option( self::OPTION, '' ) ) {
			return;
		}

		$this->run();
	}

	/**
	 * Perform every migration step. Safe to run more than once.
	 */
	public function run(): void {
		$done = array();

		if ( $this->republishDropins() ) {
			$done[] = 'dropins';
		}
		if ( $this->removeLegacyConfig() ) {
			$done[] = 'config';
		}
		if ( $this->dropLegacyTables() ) {
			$done[] = 'tables';
		}
		if ( $this->removeLegacyTransients() ) {
			$done[] = 'transients';
		}

		$constants = $this->retiredConstants();
		if ( $constants ) {
			$this->logger->log(
				'warning',
				'Constants in wp-config.php still use the retired GTP_ prefix and are no longer read. Rename them to GTPERF_.',
				array( 'constants' => implode( ', ', $constants ) )
			);
		}

		update_option( self::OPTION, GTPERF_VERSION, false );

		$this->logger->log(
			'info',
			'GT Performance migration completed',
			array(
				'version' => GTPERF_VERSION,
				'steps'   => $done ? implode( ', ', $done ) : 'nothing to migrate',
			)
		);
	}

	/**
	 * Replace drop-ins written by an older release.
	 *
	 * The compiled configuration is written first: the bundled drop-in reads the
	 * plugin directory out of it, so publishing the drop-in before that file
	 * exists would leave the next request loading nothing.
	 *
	 * Both installers already expose an idempotent, version-gated republish.
	 * ObjectCacheInstaller::install() additionally tests the Redis connection and
	 * refuses when it fails, which would leave a stale drop-in in place on a site
	 * whose Redis is merely unreachable at this moment. syncVersion() replaces
	 * the file without that gate, which is what a migration needs.
	 */
	private function republishDropins(): bool {
		$page   = new DropinInstaller();
		$object = new ObjectCacheInstaller();

		$stale = ( 'owned' === $page->status() && GTPERF_VERSION !== $page->installedVersion() )
			|| ( 'owned' === $object->status() && GTPERF_VERSION !== $object->installedVersion() );

		Settings::compile();

		DropinInstaller::syncVersion();
		ObjectCacheInstaller::syncVersion();

		if ( $stale ) {
			$this->logger->log(
				'info',
				'Republished drop-ins during migration',
				array(
					'page_cache'   => $page->installedVersion(),
					'object_cache' => $object->installedVersion(),
				)
			);
		}

		return $stale;
	}

	private function removeLegacyConfig(): bool {
		$changed = false;

		foreach ( self::LEGACY_CONFIG as $name ) {
			$path = Paths::cacheRoot() . '/' . $name;
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
				$changed = true;
			}
		}

		return $changed;
	}

	private function dropLegacyTables(): bool {
		global $wpdb;

		$changed = false;

		foreach ( self::LEGACY_TABLES as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// The name is built from the trusted WordPress prefix and a literal
			// from the list above. Dropping this plugin's own retired tables is
			// the entire purpose of the statement.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $exists ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$changed = true;
			}
		}

		return $changed;
	}

	private function removeLegacyTransients(): bool {
		$changed = false;

		foreach ( self::LEGACY_TRANSIENTS as $transient ) {
			if ( false !== get_transient( $transient ) ) {
				delete_transient( $transient );
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Retired constants still defined in wp-config.php, reported by name only.
	 *
	 * @return list<string>
	 */
	private function retiredConstants(): array {
		$found = array();

		foreach ( array( 'GTP_LICENSE_KEY', 'GTP_FLEET_SIGNING_SECRET', 'GTP_XCLOUD_API_TOKEN', 'GTP_XCLOUD_API_BASE_URL', 'GTP_CLOUDFLARE_API_TOKEN', 'GTP_CLOUDFLARE_GLOBAL_API_KEY', 'GTP_CLOUDFLARE_EMAIL', 'GTP_CLOUDFLARE_DOMAIN', 'GTP_REDIS_ENABLED', 'GTP_REDIS_HOST', 'GTP_REDIS_PORT', 'GTP_REDIS_DATABASE', 'GTP_REDIS_USERNAME', 'GTP_REDIS_PASSWORD', 'GTP_REDIS_TLS', 'GTP_REDIS_PERSISTENT', 'GTP_REDIS_PREFIX', 'GTP_REDIS_TIMEOUT', 'GTP_REDIS_READ_TIMEOUT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$found[] = $constant;
			}
		}

		return $found;
	}
}
