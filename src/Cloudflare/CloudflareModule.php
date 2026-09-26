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
	public const RETRY_HOOK = 'gt_performance_cloudflare_retry';
	public const STATUS_OPTION = 'gt_performance_cloudflare_last_purge';
	private const MAX_RETRIES = 3;
	private ?\WP_Error $lastError = null;
	public function __construct(
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		// Collect during the request, send once on shutdown. Each post save previously
		// made one blocking API call per URL with a 20s timeout and no de-duplication,
		// so a bulk edit or a stock sync could hold the request open for minutes.
		add_action( 'gt_performance_purged_urls', array( $this, 'queueUrls' ) );
		add_action( 'shutdown', array( $this, 'flushOnShutdown' ), 100 );
		add_action( 'gt_performance_purged_all', array( $this, 'purgeEverything' ) );
		add_filter( 'gt_performance_flush_edge_purges', array( $this, 'flushForCaller' ) );
		add_action( self::RETRY_HOOK, array( $this, 'retry' ), 10, 3 );
	}

	/**
	 * @var list<string>
	 */
	private array $pendingUrls = array();

	public function flushOnShutdown(): void {
		$this->flushQueuedUrls();
	}

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

	/** @return bool|\WP_Error */
	public function flushQueuedUrls(): bool|\WP_Error {
		if ( ! $this->pendingUrls ) {
			return $this->lastError ?? true;
		}

		$urls              = array_values( array_unique( $this->pendingUrls ) );
		$this->pendingUrls = array();

		$this->purgeUrls( $urls );
		return $this->lastError ?? true;
	}

	/** @return bool|\WP_Error */
	public function flushForCaller( bool|\WP_Error $previous ): bool|\WP_Error {
		$result = $this->flushQueuedUrls();
		return is_wp_error( $previous ) ? $previous : $result;
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	public function purgeUrls( array $urls ): void {
		if ( ! $this->enabled() || ! $urls ) {
			return;
		}
		$this->perform(
			(string) Settings::get( 'cloudflare.zone_id', '' ),
			ApiClient::purgeEntries( $urls, (bool) Settings::get( 'cache.separate_mobile', false ) )
		);
	}

	public function purgeEverything(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		$this->perform( (string) Settings::get( 'cloudflare.zone_id', '' ), null );
	}

	private function enabled(): bool {
		return (bool) Settings::get( 'cloudflare.enabled', false ) && ! ( new EdgeOwnership() )->xcloudOwnsEdge();
	}

	/**
	 * Cron carries no credentials and never retries against a different zone.
	 *
	 * @param list<string|array{url:string,headers:array<string,string>}>|null $files Remaining keys; null means full purge.
	 */
	public function retry( string $zone, ?array $files, int $attempt ): void {
		$currentZone = (string) Settings::get( 'cloudflare.zone_id', '' );
		if ( ! $this->enabled() || $zone !== $currentZone || 1 > $attempt || self::MAX_RETRIES < $attempt ) {
			return;
		}
		$this->perform( $zone, $files, $attempt );
	}

	/** @param list<string|array{url:string,headers:array<string,string>}>|null $files Cache keys. */
	private function perform( string $zone, ?array $files, int $attempt = 0 ): void {
		$client = $this->client();
		if ( '' === $zone ) {
			$result = new \WP_Error( 'gtperf_cloudflare_zone', __( 'Cloudflare Zone ID is missing. Connect/sync Cloudflare before purging.', 'gt-performance' ) );
		} elseif ( is_wp_error( $client ) ) {
			$result = $client;
		} else {
			$result = null === $files ? $client->purgeEverything( $zone ) : $client->purgeFiles( $zone, $files );
		}

		$nextRetry = 0;
		$retryComplete = true;
		if ( is_wp_error( $result ) ) {
			$this->lastError = $result;
			$data = (array) $result->get_error_data();
			$status = (int) ( $data['status'] ?? 0 );
			$transient = 'gtperf_cloudflare_transport' === $result->get_error_code() || 429 === $status || ( $status >= 500 && $status <= 599 );
			if ( $transient && $attempt < self::MAX_RETRIES ) {
				$remaining = null === $files ? array( null ) : array_chunk( (array) ( $data['remaining_files'] ?? $files ), 100 );
				$when = time() + max( 60 * ( 2 ** $attempt ), (int) ( $data['retry_after'] ?? 0 ) );
				foreach ( $remaining as $batch ) {
					$args = array( $zone, $batch, $attempt + 1 );
					$scheduled = wp_next_scheduled( self::RETRY_HOOK, $args );
					if ( ! $scheduled ) {
						$scheduled = wp_schedule_single_event( $when, self::RETRY_HOOK, $args, true );
					}
					if ( is_wp_error( $scheduled ) || ! $scheduled ) {
						$retryComplete = false;
					} else {
						$nextRetry = $when;
					}
				}
			}
			$this->logger->log( 'error', 'Cloudflare purge failed', array( 'error' => $result->get_error_message() ) );
		} elseif ( null === $files ) {
			// A confirmed zone purge supersedes every older URL retry in this site.
			$this->pendingUrls = array();
			$this->lastError = null;
			wp_unschedule_hook( self::RETRY_HOOK );
		}
		update_option(
			self::STATUS_OPTION,
			array(
				'operation' => null === $files ? 'all' : 'urls',
				'status' => is_wp_error( $result ) ? ( $nextRetry && $retryComplete ? 'retrying' : 'failed' ) : 'accepted',
				'created_at' => current_time( 'mysql', true ),
				'attempt' => $attempt,
				'entries' => null === $files ? 0 : count( $files ),
				'next_retry' => $nextRetry,
				'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '',
				'message' => is_wp_error( $result ) ? Logger::redact( $result->get_error_message() ) : __( 'Cloudflare accepted the purge request. Use Purge and verify to check the public response.', 'gt-performance' ),
			),
			false
		);
	}

	/**
	 * @return ApiClient|\WP_Error
	 */
	public function client(): ApiClient|\WP_Error {
		return ( new ClientFactory() )->create();
	}
}
