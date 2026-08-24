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
use GTPerformance\Diagnostics\CronHealth;
use GTPerformance\Diagnostics\PurgeVerifier;
use GTPerformance\Fleet\PolicyService;
use GTPerformance\Queue\QueueModule;
use GTPerformance\Redis\ObjectCacheInstaller;
use GTPerformance\XCloud\EdgeOwnership;
use GTPerformance\XCloud\SiteService;

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
				'status' => wp_is_writable( Paths::cacheRoot() ) ? 'pass' : 'fail',
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
			array(
				'check'  => 'xCloud',
				'value'  => Settings::get( 'xcloud.enabled', false ) ? 'enabled' : 'disabled',
				'status' => ( new EdgeOwnership() )->hasDirectCloudflareConflict() ? 'warning' : 'pass',
			),
			( new CronHealth() )->check(),
		);

		\WP_CLI\Utils\format_items( 'table', $checks, array( 'check', 'value', 'status' ) );
	}

	/**
	 * Manage page cache.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, purge, warm, install-dropin, explain, or verify. Defaults to status.
	 *
	 * [--page-url=<url>]
	 * : Target URL for purge, explain, or verify. Defaults to the home page for explain and verify.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function cache( array $args, array $assocArgs ): void {
		$action = $this->action(
			$args,
			'status',
			array( 'status', 'purge', 'warm', 'install-dropin', 'explain', 'verify' ),
			'cache'
		);
		if ( null === $action ) {
			return;
		}
		if ( $this->pageUrlRequested( $assocArgs ) && ! in_array( $action, array( 'purge', 'explain', 'verify' ), true ) ) {
			\WP_CLI::error( '--page-url is supported only by cache purge, explain, and verify.' );
			return;
		}

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
			if ( null === $url ) {
				return;
			}
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
			if ( null === $url ) {
				return;
			}
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
	}

	/**
	 * Use page-url because WP-CLI reserves --url for multisite context selection.
	 * Keep the old key as a programmatic fallback for callers invoking the method.
	 *
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	private function pageUrl( array $assocArgs ): ?string {
		if ( ! $this->pageUrlRequested( $assocArgs ) ) {
			return '';
		}

		$value  = esc_url_raw( trim( (string) ( $assocArgs['page-url'] ?? $assocArgs['url'] ?? '' ) ) );
		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $value, PHP_URL_HOST );
		if ( '' === $value || ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			\WP_CLI::error( 'Use --page-url with a complete HTTP or HTTPS URL.' );
			return null;
		}

		return $value;
	}

	/**
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	private function pageUrlRequested( array $assocArgs ): bool {
		return array_key_exists( 'page-url', $assocArgs ) || array_key_exists( 'url', $assocArgs );
	}

	/**
	 * Run queued jobs.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : run. Defaults to run.
	 *
	 * [--limit=<number>]
	 * : Positive maximum number of jobs to process.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function queue( array $args, array $assocArgs ): void {
		$action = $this->action( $args, 'run', array( 'run' ), 'queue' );
		if ( null === $action ) {
			return;
		}

		$limit = filter_var(
			$assocArgs['limit'] ?? 20,
			FILTER_VALIDATE_INT,
			array( 'options' => array( 'min_range' => 1 ) )
		);
		if ( false === $limit ) {
			\WP_CLI::error( 'Use --limit with a positive whole number.' );
			return;
		}

		$count = ( new QueueModule( new \GTPerformance\Core\Logger() ) )->run( $limit );
		\WP_CLI::success( "Processed {$count} job(s)." );
	}

	/**
	 * Manage Cloudflare.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, plan, sync, or purge. Defaults to status.
	 *
	 * [--page-url=<url>]
	 * : Purge one exact URL instead of the entire Cloudflare zone cache.
	 *
	 * @param list<string>          $args      Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function cloudflare( array $args, array $assocArgs ): void {
		$action = $this->action( $args, 'status', array( 'status', 'plan', 'sync', 'purge' ), 'Cloudflare' );
		if ( null === $action ) {
			return;
		}
		if ( $this->pageUrlRequested( $assocArgs ) && 'purge' !== $action ) {
			\WP_CLI::error( '--page-url is supported only by cloudflare purge.' );
			return;
		}

		$settings = Settings::all();
		if ( 'status' === $action ) {
			$domain = ( new ClientFactory() )->domain( $settings );
			\WP_CLI::log( 'enabled=' . ( $settings['cloudflare']['enabled'] ? 'yes' : 'no' ) );
			\WP_CLI::log( 'auth=' . (string) $settings['cloudflare']['auth_mode'] );
			\WP_CLI::log( 'domain=' . ( '' !== $domain ? $domain : 'missing' ) );
			\WP_CLI::log( 'zone=' . ( $settings['cloudflare']['zone_id'] ? 'configured' : 'missing' ) );
			return;
		}
		if ( 'sync' === $action && ( new EdgeOwnership() )->xcloudOwnsEdge() ) {
			\WP_CLI::error( 'xCloud Cloudflare Enterprise is active. Disable one edge owner before synchronizing a direct Cloudflare cache rule.' );
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

		if ( 'purge' === $action ) {
			$url = $this->pageUrl( $assocArgs );
			if ( null === $url ) {
				return;
			}
			$result = '' !== $url
				? $client->purgeUrls( $zoneId, array( $url ) )
				: $client->purgeEverything( $zoneId );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
				return;
			}
			\WP_CLI::success( '' !== $url ? 'Cloudflare URL purge completed.' : 'Cloudflare full purge completed.' );
			return;
		}

		$cache = apply_filters( 'gt_performance_cache_policy', (array) $settings['cache'] );
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
	 * Manage the xCloud hosting cache integration.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, refresh, or purge. Defaults to status.
	 *
	 * `purge` selects the narrowest operation for the last refreshed cache
	 * state. Enterprise purge fails closed because xCloud's current Public API
	 * does not expose a token-authenticated mutation for that add-on.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function xcloud( array $args ): void {
		$action = $this->action( $args, 'status', array( 'status', 'refresh', 'purge' ), 'xCloud' );
		if ( null === $action ) {
			return;
		}

		$settings = Settings::all();
		if ( 'status' === $action ) {
			$xcloud = (array) $settings['xcloud'];
			\WP_CLI::log( 'enabled=' . ( $xcloud['enabled'] ? 'yes' : 'no' ) );
			\WP_CLI::log( 'domain=' . ( $xcloud['domain'] ? $xcloud['domain'] : 'home-domain' ) );
			\WP_CLI::log( 'site=' . ( $xcloud['site_uuid'] ? 'configured' : 'missing' ) );
			\WP_CLI::log( 'page_cache=' . ( $xcloud['page_cache_enabled'] ? 'enabled' : 'disabled' ) );
			\WP_CLI::log( 'cloudflare_enterprise=' . ( $xcloud['enterprise_available'] ? 'active' : 'not-detected' ) );
			\WP_CLI::log( 'free_edge_cache=' . ( $xcloud['free_edge_cache_enabled'] ? 'enabled' : 'disabled' ) );
			\WP_CLI::log( 'enterprise_12h=' . (int) $xcloud['enterprise_edge_requests'] . '/' . (int) $xcloud['enterprise_requests'] . ' (' . (float) $xcloud['enterprise_hit_percent'] . '%)' );
			\WP_CLI::log( 'checked_at=' . ( $xcloud['checked_at'] ? $xcloud['checked_at'] : 'never' ) );
			return;
		}

		$service = new SiteService();
		if ( 'refresh' === $action ) {
			$status = $service->refresh( $settings );
			if ( is_wp_error( $status ) ) {
				\WP_CLI::error( $status->get_error_message() );
			}

			foreach (
				array(
					'site_uuid',
					'server_id',
					'site_id',
					'domain',
					'dashboard_url',
					'stack',
					'page_cache_enabled',
					'page_cache_source',
					'redis_enabled',
					'object_cache_pro',
					'free_edge_cache_enabled',
					'enterprise_available',
					'enterprise_requests',
					'enterprise_edge_requests',
					'enterprise_hit_percent',
					'checked_at',
				) as $key
			) {
				$settings['xcloud'][ $key ] = $status[ $key ];
			}
			$settings['xcloud']['enabled'] = true;
			Settings::save( $settings );
			\WP_CLI::line( (string) wp_json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$result = $service->purgeAutomatic();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		\WP_CLI::success( 'xCloud cache purge accepted.' );
	}

	/**
	 * Preview or execute database cleanup.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : preview or run. Defaults to preview.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function database( array $args ): void {
		$action = $this->action( $args, 'preview', array( 'preview', 'run' ), 'database' );
		if ( null === $action ) {
			return;
		}

		$cleaner = new Cleaner();
		$result  = 'run' === $action ? $cleaner->run() : $cleaner->preview();
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
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : export or import. Defaults to export.
	 *
	 * [--file=<path>]
	 * : JSON bundle to import. Export prints JSON to standard output.
	 *
	 * @param list<string>          $args      Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function fleet( array $args, array $assocArgs ): void {
		$action = $this->action( $args, 'export', array( 'export', 'import' ), 'fleet' );
		if ( null === $action ) {
			return;
		}
		if ( 'export' === $action && array_key_exists( 'file', $assocArgs ) ) {
			\WP_CLI::error( '--file is supported only by fleet import.' );
			return;
		}

		$service = new PolicyService();
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

	/**
	 * Resolve and validate a command-family action before constructing services
	 * or performing work. Invalid actions must never fall through to a default
	 * operation, especially for mutating commands such as Cloudflare sync.
	 *
	 * @param list<string> $args    Positional arguments.
	 * @param list<string> $allowed Allowed actions.
	 */
	private function action( array $args, string $default, array $allowed, string $family ): ?string {
		$action = strtolower( trim( (string) ( $args[0] ?? $default ) ) );
		if ( in_array( $action, $allowed, true ) ) {
			return $action;
		}

		$last = array_pop( $allowed );
		if ( count( $allowed ) > 1 ) {
			$choices = implode( ', ', $allowed ) . ', or ' . $last;
		} elseif ( $allowed ) {
			$choices = $allowed[0] . ' or ' . $last;
		} else {
			$choices = (string) $last;
		}
		\WP_CLI::error( sprintf( 'Unknown %s action. Use %s.', $family, $choices ) );
		return null;
	}
}
