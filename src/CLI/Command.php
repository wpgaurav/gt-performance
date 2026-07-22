<?php
/**
 * GT Performance WP-CLI commands.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\CLI;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\WpCacheConstant;
use GTPerformance\Cloudflare\ClientFactory;
use GTPerformance\Cloudflare\RuleManager;
use GTPerformance\Commerce\SafetyLab;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Database\Cleaner;
use GTPerformance\Diagnostics\CacheInspector;
use GTPerformance\Diagnostics\PurgeVerifier;
use GTPerformance\Fleet\PolicyService;
use GTPerformance\Queue\QueueModule;
use GTPerformance\Redis\ObjectCacheInstaller;

final class Command {
	/**
	 * Show environment and integration health.
	 */
	public function doctor(): void {
		$checks = array(
			array(
				'check'  => 'PHP',
				'value'  => PHP_VERSION,
				'status' => version_compare( PHP_VERSION, '8.1', '>=' ) ? 'pass' : 'fail',
			),
			array(
				'check'  => 'WordPress',
				'value'  => get_bloginfo( 'version' ),
				'status' => version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ? 'pass' : 'fail',
			),
			array(
				'check'  => 'Cache directory',
				'value'  => Paths::cacheRoot(),
				'status' => is_writable( Paths::cacheRoot() ) ? 'pass' : 'fail',
			),
			array(
				'check'  => 'Page drop-in',
				'value'  => ( new DropinInstaller() )->status(),
				'status' => 'owned' === ( new DropinInstaller() )->status() ? 'pass' : 'warning',
			),
			array(
				'check'  => 'WP_CACHE',
				'value'  => ( new WpCacheConstant() )->status(),
				'status' => 'enabled' === ( new WpCacheConstant() )->status() ? 'pass' : 'warning',
			),
			array(
				'check'  => 'Redis drop-in',
				'value'  => ( new ObjectCacheInstaller() )->status(),
				'status' => 'owned' === ( new ObjectCacheInstaller() )->status() ? 'pass' : 'warning',
			),
			array(
				'check'  => 'Cloudflare',
				'value'  => Settings::get( 'cloudflare.enabled', false ) ? 'enabled' : 'disabled',
				'status' => Settings::get( 'cloudflare.enabled', false ) ? 'pass' : 'warning',
			),
		);

		\WP_CLI\Utils\format_items( 'table', $checks, array( 'check', 'value', 'status' ) );
	}

	/**
	 * Manage page cache.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status, purge, warm, install-dropin, explain, or verify.
	 *
	 * [--page-url=<url>]
	 * : Target URL for purge, explain, or verify. Defaults to the home page for explain and verify.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function cache( array $args, array $assocArgs ): void {
		$action = (string) ( $args[0] ?? 'status' );
		if ( 'install-dropin' === $action ) {
			$result = ( new DropinInstaller() )->install();
			is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Page-cache drop-in installed.' );
			return;
		}
		if ( 'warm' === $action ) {
			$queued = ( new CacheWarmer( new \GTPerformance\Core\Logger() ) )->warm( (int) Settings::get( 'cache.preload_max_urls', 200 ) );
			\WP_CLI::success( "Queued {$queued} URL(s) for preloading." );
			return;
		}
		if ( 'purge' === $action ) {
			$url = $this->pageUrl( $assocArgs );
			if ( '' !== $url ) {
				( new Purger() )->purgeUrl( $url );
			} else {
				( new Purger() )->purgeAll();
			}
			\WP_CLI::success( 'Cache purge completed.' );
			return;
		}
		if ( 'explain' === $action || 'verify' === $action ) {
			$url = $this->pageUrl( $assocArgs );
			$url = '' !== $url ? $url : home_url( '/' );
			$result = 'verify' === $action
				? ( new PurgeVerifier() )->verify( $url )
				: ( new CacheInspector() )->inspect( $url );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'status' === $action ) {
			\WP_CLI::log( 'enabled=' . ( Settings::get( 'cache.enabled', false ) ? 'yes' : 'no' ) );
			\WP_CLI::log( 'dropin=' . ( new DropinInstaller() )->status() );
			return;
		}

		\WP_CLI::error( 'Unknown cache action. Use status, purge, warm, install-dropin, explain, or verify.' );
	}

	/**
	 * Use page-url because WP-CLI reserves --url for multisite context selection.
	 * Keep the old key as a programmatic fallback for callers invoking the method.
	 *
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	private function pageUrl( array $assocArgs ): string {
		$value = (string) ( $assocArgs['page-url'] ?? $assocArgs['url'] ?? '' );

		return esc_url_raw( $value );
	}

	/**
	 * Run queued jobs.
	 *
	 * [--limit=<number>]
	 * : Maximum jobs to process.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function queue( array $args, array $assocArgs ): void {
		unset( $args );
		$limit = max( 1, (int) ( $assocArgs['limit'] ?? 20 ) );
		$count = ( new QueueModule( new \GTPerformance\Core\Logger() ) )->run( $limit );
		\WP_CLI::success( "Processed {$count} job(s)." );
	}

	/**
	 * Manage Cloudflare.
	 *
	 * <action>
	 * : status, plan, or sync.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function cloudflare( array $args ): void {
		$action   = (string) ( $args[0] ?? 'status' );
		$settings = Settings::all();
		if ( 'status' === $action ) {
			$domain = ( new ClientFactory() )->domain( $settings );
			\WP_CLI::log( 'enabled=' . ( $settings['cloudflare']['enabled'] ? 'yes' : 'no' ) );
			\WP_CLI::log( 'auth=' . (string) $settings['cloudflare']['auth_mode'] );
			\WP_CLI::log( 'domain=' . ( '' !== $domain ? $domain : 'missing' ) );
			\WP_CLI::log( 'zone=' . ( $settings['cloudflare']['zone_id'] ? 'configured' : 'missing' ) );
			return;
		}

		$factory = new ClientFactory();
		$client  = $factory->create( $settings );
		if ( is_wp_error( $client ) ) {
			\WP_CLI::error( $client->get_error_message() );
		}

		$zoneId = (string) $settings['cloudflare']['zone_id'];
		if ( '' === $zoneId ) {
			$zone = $client->zoneByName( $factory->domain( $settings ) );
			if ( is_wp_error( $zone ) ) {
				\WP_CLI::error( $zone->get_error_message() );
			}
			$zoneId = (string) $zone['id'];
		}
		$cache  = apply_filters( 'gt_performance_cache_policy', (array) $settings['cache'] );
		if ( 'plan' === $action ) {
			$plan = ( new RuleManager( $client ) )->preview( $zoneId, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $cache );
			if ( is_wp_error( $plan ) ) {
				\WP_CLI::error( $plan->get_error_message() );
			}
			\WP_CLI::line( (string) wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$result = ( new RuleManager( $client ) )->sync( $zoneId, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $cache );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		$settings['cloudflare']['enabled']    = true;
		$settings['cloudflare']['zone_id']    = $zoneId;
		$settings['cloudflare']['drift_hash'] = hash( 'sha256', (string) wp_json_encode( $cache ) );
		Settings::save( $settings );
		\WP_CLI::success( 'Cloudflare rule synchronized.' );
	}

	/**
	 * Preview or execute database cleanup.
	 *
	 * <action>
	 * : preview or run.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function database( array $args ): void {
		$cleaner = new Cleaner();
		$result  = 'run' === ( $args[0] ?? 'preview' ) ? $cleaner->run() : $cleaner->preview();
		$rows    = array();
		foreach ( $result as $type => $count ) {
			$rows[] = array(
				'type'  => $type,
				'count' => $count,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'count' ) );
	}

	/**
	 * Run the non-destructive commerce cache Safety Lab.
	 */
	public function safety(): void {
		$result = ( new SafetyLab() )->run();
		\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( 'fail' === (string) $result['status'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Create or apply a signed fleet policy.
	 *
	 * <action>
	 * : export or import.
	 *
	 * [--file=<path>]
	 * : JSON bundle to import. Export prints JSON to standard output.
	 *
	 * @param list<string>          $args      Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function fleet( array $args, array $assocArgs ): void {
		$service = new PolicyService();
		$action  = (string) ( $args[0] ?? 'export' );
		if ( 'import' === $action ) {
			$file = (string) ( $assocArgs['file'] ?? '' );
			if ( '' === $file || ! is_readable( $file ) ) {
				\WP_CLI::error( 'Use --file with a readable signed policy bundle.' );
			}
			$json   = file_get_contents( $file );
			$result = $service->applyJson( is_string( $json ) ? $json : '' );
		} else {
			$result = $service->create();
		}

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
