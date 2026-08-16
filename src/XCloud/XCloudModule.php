<?php
/**
 * XCloud cache invalidation integration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\XCloud;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;

final class XCloudModule implements Module {
	private bool $purgedThisRequest = false;

	public function __construct(
		private readonly Logger $logger,
		private readonly SiteService $sites = new SiteService(),
	) {
	}

	public function register(): void {
		add_action( 'gt_performance_purged_urls', array( $this, 'purge' ) );
		add_action( 'gt_performance_purged_all', array( $this, 'purge' ) );
	}

	/**
	 * Route one state-aware purge per request, even when a WordPress operation
	 * emits several GT purge actions. Enterprise fails closed in SiteService
	 * until xCloud publishes a token-authenticated purge endpoint.
	 */
	public function purge(): void {
		if ( $this->purgedThisRequest || ! (bool) Settings::get( 'xcloud.enabled', false ) ) {
			return;
		}

		$this->purgedThisRequest = true;
		$result                  = $this->sites->purgeAutomatic();
		if ( is_wp_error( $result ) ) {
			$this->logger->log( 'error', 'xCloud cache purge failed', array( 'error' => $result->get_error_message() ) );
			$this->storeReceipt(
				array(
					'mode'    => 'error',
					'message' => $result->get_error_message(),
					'caches'  => array(),
				)
			);
			return;
		}

		$this->storeReceipt( $result );
	}

	/**
	 * @param array<string, mixed> $result Purge result.
	 */
	private function storeReceipt( array $result ): void {
		update_option(
			'gt_performance_xcloud_last_purge',
			array(
				'mode'       => sanitize_key( (string) ( $result['mode'] ?? '' ) ),
				'message'    => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
				'caches'     => isset( $result['caches'] ) && is_array( $result['caches'] ) ? $result['caches'] : array(),
				'created_at' => current_time( 'mysql', true ),
			),
			false
		);
	}
}
