<?php
/**
 * Routes GT Performance purges to xCloud Cloudflare Enterprise.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\XCloud;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;

/**
 * Collects the URLs a request purges and sends them to xCloud once, on shutdown,
 * without blocking: the same contract as xCloud's own plugin. A caller that
 * reports the result (the Purge button, purge-and-verify, WP-CLI, abilities)
 * flushes through `gt_performance_flush_edge_purges` instead, which waits for
 * xCloud's reply.
 *
 * The xCloud plugin purges a post's own URLs when it is saved. This covers what
 * it never sees: archives and related pages, Page Blocks and theme edits, the Purge
 * button, scheduled purges, and WP-CLI.
 *
 * xCloud drops a purge of more than EnterprisePurgeHook::MAX_URLS URLs. A post
 * save names its permalink, home, and archives, plus every page recorded as
 * listing it; on gauravtiwari.org that is about 500 pages, so every edit would
 * empty the whole edge. Over the limit only the primary URLs go to Enterprise and
 * the recorded dependents are purged at the origin alone, refreshing at the edge
 * when their copies expire. A purge with no primary set counts every URL as
 * primary, and a primary set still over the limit becomes a whole-site purge.
 */
final class EnterprisePurgeModule implements Module {
	public const STATUS_OPTION = 'gt_performance_xcloud_enterprise_last_purge';

	/** @var array<string, true> */
	private array $pendingUrls = array();

	/** @var array<string, true> */
	private array $primaryUrls = array();
	private bool $pendingAll   = false;
	private ?\WP_Error $lastError = null;

	public function __construct(
		private readonly Logger $logger,
		private readonly EnterprisePurgeHook $hook = new EnterprisePurgeHook(),
	) {
	}

	public function register(): void {
		add_action( 'gt_performance_purged_urls', array( $this, 'queueUrls' ), 10, 3 );
		add_action( 'gt_performance_purged_all', array( $this, 'queueAll' ) );
		add_action( 'shutdown', array( $this, 'flushOnShutdown' ), 100 );
		add_filter( 'gt_performance_flush_edge_purges', array( $this, 'flushForCaller' ) );
	}

	/**
	 * @param list<mixed> $urls    URLs.
	 * @param mixed       $source  Purge source.
	 * @param mixed       $primary The URLs the change names directly; empty means all of them.
	 */
	public function queueUrls( array $urls, mixed $source = null, mixed $primary = array() ): void {
		unset( $source );
		if ( ! $this->hook->available() ) {
			return;
		}
		$urls    = array_filter( $urls, static fn( mixed $url ): bool => is_string( $url ) && 1 === preg_match( '#^https?://#i', $url ) );
		$primary = is_array( $primary ) ? array_intersect( $primary, $urls ) : array();
		foreach ( $urls as $url ) {
			$this->pendingUrls[ $url ] = true;
		}
		foreach ( $primary ? $primary : $urls as $url ) {
			$this->primaryUrls[ $url ] = true;
		}
	}

	public function queueAll(): void {
		if ( $this->hook->available() ) {
			$this->pendingAll = true;
		}
	}

	public function flushOnShutdown(): void {
		$this->flush( false );
	}

	public function flushForCaller( bool|\WP_Error $previous ): bool|\WP_Error {
		$result = $this->flush( true );

		return is_wp_error( $previous ) ? $previous : $result;
	}

	/**
	 * Send what this request queued. A whole-site purge supersedes the URLs.
	 */
	public function flush( bool $blocking ): bool|\WP_Error {
		if ( ! $this->pendingAll && ! $this->pendingUrls ) {
			return $this->lastError ?? true;
		}

		$all               = $this->pendingAll;
		$urls              = array_keys( $this->pendingUrls );
		$primary           = array_keys( $this->primaryUrls );
		$this->pendingAll  = false;
		$this->pendingUrls = array();
		$this->primaryUrls = array();

		$originOnly = 0;
		if ( ! $all && count( $urls ) > EnterprisePurgeHook::MAX_URLS && $primary ) {
			$originOnly = count( $urls ) - count( $primary );
			$urls       = $primary;
		}

		$result = $all ? $this->hook->purgeAll( $blocking ) : $this->hook->purgeUrls( $urls, $blocking );
		if ( is_wp_error( $result ) ) {
			$this->lastError = $result;
			$this->logger->log( 'error', 'xCloud Cloudflare Enterprise purge failed', array( 'error' => $result->get_error_message() ) );
		} else {
			$this->lastError = null;
		}
		$this->storeReceipt( $all ? 'all' : 'urls', $all ? 0 : count( $urls ), $originOnly, $blocking, $result );

		return $this->lastError ?? true;
	}

	/**
	 * @param array{operation:string,entries:int,requests:int,message:string}|\WP_Error $result Purge result.
	 */
	private function storeReceipt( string $operation, int $entries, int $originOnly, bool $blocking, array|\WP_Error $result ): void {
		if ( is_wp_error( $result ) ) {
			$status  = 'failed';
			$message = Logger::redact( $result->get_error_message() );
		} else {
			$status    = $blocking ? 'accepted' : 'sent';
			$operation = $result['operation'];
			$entries   = $result['entries'];
			$message   = $result['message'];
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'operation'   => $operation,
				'status'      => $status,
				'entries'     => $entries,
				'origin_only' => 'all' === $operation ? 0 : $originOnly,
				'message'     => sanitize_text_field( $message ),
				'created_at'  => current_time( 'mysql', true ),
			),
			false
		);
	}
}
