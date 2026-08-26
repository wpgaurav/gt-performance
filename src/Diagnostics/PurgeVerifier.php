<?php
/**
 * Exact-URL purge verification with redacted response evidence.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Diagnostics;

use GTPerformance\Cache\Purger;
use GTPerformance\Core\Settings;

final class PurgeVerifier {
	public function __construct(
		private readonly CacheInspector $inspector = new CacheInspector(),
		private readonly PurgeReceiptRepository $receipts = new PurgeReceiptRepository(),
		private readonly ResponseSnapshot $snapshots = new ResponseSnapshot(),
	) {
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function verify( string $url ): array|\WP_Error {
		$before = $this->inspector->inspect( $url );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		( new Purger() )->purgeUrl( $url );
		$afterPurge = $this->inspector->inspect( $url );
		if ( is_wp_error( $afterPurge ) ) {
			return $afterPurge;
		}

		$edgeOne = $this->fetch( $url );
		$edgeTwo = $this->fetch( $url );
		if ( is_wp_error( $edgeOne ) || is_wp_error( $edgeTwo ) ) {
			$error = is_wp_error( $edgeOne ) ? $edgeOne : $edgeTwo;
			return new \WP_Error( 'gtperf_purge_verification_http', $error->get_error_message() );
		}

		$originRemoved = 'missing' === (string) ( $afterPurge['origin']['state'] ?? '' );
		$bodyStable    = '' !== (string) $edgeOne['body_fingerprint']
			&& hash_equals( (string) $edgeOne['body_fingerprint'], (string) $edgeTwo['body_fingerprint'] );
		$safeResponse  = ! (bool) $edgeOne['private'] && ! (bool) $edgeTwo['private'];
		$firstWasOldHit = (bool) Settings::get( 'cloudflare.enabled', false )
			&& in_array( (string) $edgeOne['cf_cache_status'], array( 'HIT', 'STALE' ), true )
			&& (int) $edgeOne['age'] > 0;

		$status = $originRemoved && $bodyStable && $safeResponse && ! $firstWasOldHit ? 'verified' : 'warning';
		$receipt = array(
			'id'             => wp_generate_uuid4(),
			'url'            => esc_url_raw( $url ),
			'status'         => $status,
			'created_at'     => current_time( 'mysql', true ),
			'origin_removed' => $originRemoved,
			'body_stable'    => $bodyStable,
			'safe_response'  => $safeResponse,
			'first_was_old_hit' => $firstWasOldHit,
			'before'         => array(
				'state' => (string) ( $before['origin']['state'] ?? 'missing' ),
				'hash'  => (string) ( $before['cache_hash_short'] ?? '' ),
			),
			'edge_first'     => $edgeOne,
			'edge_second'    => $edgeTwo,
		);

		$this->receipts->add( $receipt );

		return $receipt;
	}

	/**
	 * @return array<string, bool|int|string>|\WP_Error
	 */
	private function fetch( string $url ): array|\WP_Error {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'user-agent'  => 'GT-Performance-Purge-Verifier/' . GTPERF_VERSION,
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		return $this->snapshots->fromWordPressResponse( $response );
	}
}
