<?php
/**
 * What the configured edge said about a purge, read from its own receipt.
 *
 * Only a receipt written at or after the operation started counts; otherwise
 * the result is `unknown`, never an older success. An agent asking cannot turn
 * an unavailable purge API (xCloud Enterprise) into success.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Operations;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\XCloud\EdgeOwnership;

final class EdgeReport {
	/**
	 * @param bool|\WP_Error $flushed Result of the edge flush filter.
	 * @return array{provider:string,status:string,message:string}
	 */
	public static function since( int $started, bool|\WP_Error $flushed ): array {
		$xcloud     = ( new EdgeOwnership() )->xcloudOwnsEdge();
		$cloudflare = (bool) Settings::get( 'cloudflare.enabled', false ) && ! $xcloud;

		if ( $cloudflare ) {
			$receipt = get_option( \GTPerformance\Cloudflare\CloudflareModule::STATUS_OPTION, array() );
			if ( ! self::fresh( $receipt, $started ) ) {
				return self::report( 'cloudflare', is_wp_error( $flushed ) ? 'failed' : 'unknown', is_wp_error( $flushed ) ? $flushed->get_error_message() : 'No Cloudflare purge receipt was recorded for this operation.' );
			}
			$status = in_array( $receipt['status'] ?? '', array( 'accepted', 'retrying', 'failed' ), true ) ? (string) $receipt['status'] : 'unknown';

			return self::report( 'cloudflare', $status, (string) ( $receipt['message'] ?? '' ) );
		}

		if ( (bool) Settings::get( 'xcloud.enabled', false ) ) {
			$receipt = get_option( 'gt_performance_xcloud_last_purge', array() );
			if ( ! self::fresh( $receipt, $started ) ) {
				return self::report( 'xcloud', 'unknown', 'No xCloud purge receipt was recorded for this operation.' );
			}

			return self::report( 'xcloud', 'error' === ( $receipt['mode'] ?? '' ) ? 'failed' : 'accepted', (string) ( $receipt['message'] ?? '' ) );
		}

		return self::report( 'none', 'not_configured', 'No edge cache integration is enabled; only the origin cache was purged.' );
	}

	private static function fresh( mixed $receipt, int $started ): bool {
		if ( ! is_array( $receipt ) || empty( $receipt['created_at'] ) ) {
			return false;
		}
		$at = strtotime( (string) $receipt['created_at'] . ' UTC' );

		return false !== $at && $at >= $started - 1;
	}

	/**
	 * @return array{provider:string,status:string,message:string}
	 */
	private static function report( string $provider, string $status, string $message ): array {
		return array(
			'provider' => $provider,
			'status'   => $status,
			'message'  => Logger::redact( $message ),
		);
	}
}
