<?php
/**
 * Takes GT Performance's footprint off a Cloudflare zone.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

use GTPerformance\Core\Settings;
use GTPerformance\XCloud\EdgeOwnership;

/**
 * The managed Cache Rule makes Cloudflare store HTML, and only this plugin purges
 * it. Left behind after deactivation, the zone keeps serving pages nothing will
 * ever refresh, so the rule goes and the site's hostnames are purged on the way out.
 */
final class Disconnector {
	/**
	 * Whether this site has a Cloudflare rule of ours worth removing.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	public static function applies( array $settings ): bool {
		return ! empty( $settings['cloudflare']['enabled'] )
			&& '' !== (string) ( $settings['cloudflare']['zone_id'] ?? '' )
			&& ! ( new EdgeOwnership() )->xcloudOwnsEdge();
	}

	/**
	 * Remove this site's managed rule, then purge this site's hostnames.
	 *
	 * @param array<string, mixed>|null $settings Plugin settings.
	 * @return array{rule: string, purged: bool}|\WP_Error
	 */
	public function detach( ?array $settings = null ): array|\WP_Error {
		$settings = $settings ?? Settings::all();
		$zoneId   = (string) ( $settings['cloudflare']['zone_id'] ?? '' );
		if ( '' === $zoneId ) {
			return new \WP_Error( 'gtperf_cloudflare_zone', __( 'Cloudflare Zone ID is missing, so there is no rule to remove.', 'gt-performance' ) );
		}

		$client = ( new ClientFactory() )->create( $settings );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$rule = ( new RuleManager( $client ) )->remove( $zoneId, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		// Pages the rule already stored would otherwise be served until they expire.
		$purge = $client->purgeHosts( $zoneId, Settings::canonicalHosts() );
		delete_option( 'gt_performance_cloudflare_plan' );

		return array(
			'rule'   => $rule,
			'purged' => ! is_wp_error( $purge ),
		);
	}

	/**
	 * Detach and stop using Cloudflare. Credentials stay unless $forget is set, so
	 * reconnecting is one sync away.
	 *
	 * @return array{rule: string, purged: bool}|\WP_Error
	 */
	public function disconnect( bool $forget ): array|\WP_Error {
		$settings = Settings::all();
		$before   = $settings;
		$result   = $this->detach( $settings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$settings['cloudflare']['enabled']    = false;
		$settings['cloudflare']['drift_hash'] = '';
		if ( $forget ) {
			foreach ( array( 'api_token', 'global_api_key', 'email', 'zone_id' ) as $key ) {
				$settings['cloudflare'][ $key ] = '';
			}
		}
		if ( ! Settings::saveChanges( $before, $settings ) ) {
			return new \WP_Error( 'gtperf_config_write', Settings::configurationError() );
		}
		delete_option( RuleManager::REMOVED_OPTION );

		return $result;
	}
}
