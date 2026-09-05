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
use GTPerformance\XCloud\EdgeOwnership;

final class CloudflareModule implements Module {
	public function __construct(
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		// Collect during the request, send once on shutdown. Each post save previously
		// made one blocking API call per URL with a 20s timeout and no de-duplication,
		// so a bulk edit or a stock sync could hold the request open for minutes.
		add_action( 'gt_performance_purged_urls', array( $this, 'queueUrls' ) );
		add_action( 'shutdown', array( $this, 'flushQueuedUrls' ), 100 );
		add_action( 'gt_performance_purged_all', array( $this, 'purgeEverything' ) );
	}

	/**
	 * @var list<string>
	 */
	private array $pendingUrls = array();

	/**
	 * @param list<string> $urls URLs.
	 */
	public function queueUrls( array $urls ): void {
		foreach ( $urls as $url ) {
			if ( is_string( $url ) && '' !== $url ) {
				$this->pendingUrls[] = $url;
			}
		}
	}

	public function flushQueuedUrls(): void {
		if ( ! $this->pendingUrls ) {
			return;
		}

		$urls              = array_values( array_unique( $this->pendingUrls ) );
		$this->pendingUrls = array();

		$this->purgeUrls( $urls );
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	public function purgeUrls( array $urls ): void {
		if ( ! (bool) Settings::get( 'cloudflare.enabled', false ) || ( new EdgeOwnership() )->xcloudOwnsEdge() || ! $urls ) {
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
		if ( ! (bool) Settings::get( 'cloudflare.enabled', false ) || ( new EdgeOwnership() )->xcloudOwnsEdge() ) {
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
