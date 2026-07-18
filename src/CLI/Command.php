<?php
/**
 * GT Performance WP-CLI commands.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\CLI;

use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\WpCacheConstant;
use GTPerformance\Cloudflare\ApiClient;
use GTPerformance\Cloudflare\RuleManager;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Database\Cleaner;
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
	 * : status, purge, or install-dropin.
	 *
	 * [--url=<url>]
	 * : Purge one exact URL.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function cache( array $args, array $assocArgs ): void {
		$action = (string) ( $args[0] ?? 'status' );
		if ( 'install-dropin' === $action ) {
			$result = ( new DropinInstaller() )->install();
			is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Page-cache drop-in installed.' );
		}
		if ( 'purge' === $action ) {
			$url = isset( $assocArgs['url'] ) ? esc_url_raw( (string) $assocArgs['url'] ) : '';
			if ( '' !== $url ) {
				( new Purger() )->purgeUrl( $url );
			} else {
				( new Purger() )->purgeAll();
			}
			\WP_CLI::success( 'Cache purge completed.' );
		}

		\WP_CLI::log( 'enabled=' . ( Settings::get( 'cache.enabled', false ) ? 'yes' : 'no' ) );
		\WP_CLI::log( 'dropin=' . ( new DropinInstaller() )->status() );
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
	 * : status or sync.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function cloudflare( array $args ): void {
		$action   = (string) ( $args[0] ?? 'status' );
		$settings = Settings::all();
		if ( 'status' === $action ) {
			\WP_CLI::log( 'enabled=' . ( $settings['cloudflare']['enabled'] ? 'yes' : 'no' ) );
			\WP_CLI::log( 'zone=' . ( $settings['cloudflare']['zone_id'] ? 'configured' : 'missing' ) );
			return;
		}

		$token = ( new TokenCipher() )->decrypt( (string) $settings['cloudflare']['api_token'] );
		if ( '' === $token ) {
			\WP_CLI::error( 'Cloudflare token is unavailable.' );
		}
		$client = new ApiClient( $token );
		$zoneId = (string) $settings['cloudflare']['zone_id'];
		if ( '' === $zoneId ) {
			$zone = $client->zoneByName( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			if ( is_wp_error( $zone ) ) {
				\WP_CLI::error( $zone->get_error_message() );
			}
			$zoneId = (string) $zone['id'];
		}
		$cache  = apply_filters( 'gt_performance_cache_policy', (array) $settings['cache'] );
		$result = ( new RuleManager( $client ) )->sync( $zoneId, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $cache );
		is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Cloudflare rule synchronized.' );
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
}
