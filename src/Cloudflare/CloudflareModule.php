<?php
/**
 * Cloudflare orchestration module.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;

final class CloudflareModule implements Module {
	public function __construct(
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( 'gt_performance_purged_urls', array( $this, 'purgeUrls' ) );
		add_action( 'gt_performance_purged_all', array( $this, 'purgeEverything' ) );
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	public function purgeUrls( array $urls ): void {
		if ( ! (bool) Settings::get( 'cloudflare.enabled', false ) || ! $urls ) {
			return;
		}

		$client = $this->client();
		$zone   = (string) Settings::get( 'cloudflare.zone_id', '' );
		if ( is_wp_error( $client ) || '' === $zone ) {
			return;
		}

		$result = $client->purgeUrls( $zone, $urls );
		if ( is_wp_error( $result ) ) {
			$this->logger->log( 'error', 'Cloudflare URL purge failed', array( 'error' => $result->get_error_message() ) );
		}
	}

	public function purgeEverything(): void {
		if ( ! (bool) Settings::get( 'cloudflare.enabled', false ) ) {
			return;
		}

		$client = $this->client();
		$zone   = (string) Settings::get( 'cloudflare.zone_id', '' );
		if ( is_wp_error( $client ) || '' === $zone ) {
			return;
		}

		$result = $client->purgeEverything( $zone );
		if ( is_wp_error( $result ) ) {
			$this->logger->log( 'error', 'Cloudflare full purge failed', array( 'error' => $result->get_error_message() ) );
		}
	}

	/**
	 * @return ApiClient|\WP_Error
	 */
	public function client(): ApiClient|\WP_Error {
		return ( new ClientFactory() )->create();
	}
}
