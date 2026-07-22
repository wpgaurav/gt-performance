<?php
/**
 * Background queue runner.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Queue;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\Purger;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;

final class QueueModule implements Module {
	private JobRepository $jobs;
	private CacheWarmer $warmer;

	public function __construct(
		private readonly Logger $logger,
	) {
		$this->jobs   = new JobRepository();
		$this->warmer = new CacheWarmer( $logger );
	}

	public function register(): void {
		add_action( 'gt_performance_run_queue', array( $this, 'runScheduled' ) );
		add_action( 'gt_performance_enqueue_preload', array( $this, 'enqueuePreload' ) );
		add_action( 'gt_performance_enqueue_purge', array( $this, 'enqueuePurge' ) );
		add_action( 'gt_performance_purged_all', array( $this, 'scheduleWarm' ) );
	}

	/**
	 * Queue a single site-warm job after a full purge. A short-lived transient
	 * debounces bursts of full purges so a theme switch plus a menu save do not
	 * stack redundant warm jobs.
	 */
	public function scheduleWarm(): void {
		if ( ! (bool) Settings::get( 'cache.enabled', true ) || ! (bool) Settings::get( 'cache.preload', true ) ) {
			return;
		}

		if ( get_transient( 'gtp_warm_pending' ) ) {
			return;
		}

		set_transient( 'gtp_warm_pending', 1, MINUTE_IN_SECONDS );
		$this->jobs->enqueue( 'warm_site', array(), 80, 30 );
	}

	public function runScheduled(): void {
		$this->run();
		$this->jobs->purgeTerminal();
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	public function enqueuePreload( array $urls ): void {
		foreach ( array_unique( array_filter( $urls, 'is_string' ) ) as $url ) {
			$this->jobs->enqueue( 'preload_url', array( 'url' => $url ), 50 );
		}
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	public function enqueuePurge( array $urls ): void {
		foreach ( array_unique( array_filter( $urls, 'is_string' ) ) as $url ) {
			$this->jobs->enqueue( 'purge_url', array( 'url' => $url ), 10 );
		}
	}

	public function run( int $limit = 5 ): int {
		$processed = 0;
		$started   = microtime( true );

		while ( $processed < max( 1, $limit ) && microtime( true ) - $started < 20 ) {
			$job = $this->jobs->claim();
			if ( null === $job ) {
				break;
			}

			$id       = (int) $job['id'];
			$token    = (string) $job['lock_token'];
			$attempts = (int) $job['attempts'] + 1;

			try {
				$this->handle( (string) $job['type'], (array) $job['payload'] );
				$this->jobs->complete( $id, $token );
			} catch ( \Throwable $throwable ) {
				$this->jobs->fail( $id, $token, $throwable->getMessage(), $attempts );
				$this->logger->log(
					'error',
					'Queue job failed',
					array(
						'type'  => (string) $job['type'],
						'error' => $throwable->getMessage(),
					)
				);
			}

			++$processed;
		}

		return $processed;
	}

	/**
	 * @param array<string, mixed> $payload Job payload.
	 */
	private function handle( string $type, array $payload ): void {
		$url = isset( $payload['url'] ) ? esc_url_raw( (string) $payload['url'] ) : '';

		switch ( $type ) {
			case 'preload_url':
				if ( '' === $url ) {
					throw new \RuntimeException( 'Missing preload URL.' );
				}
				$response = wp_remote_get(
					$url,
					array(
						'timeout'     => 15,
						'redirection' => 3,
						'headers'     => array( 'X-GT-Preload' => '1' ),
						'user-agent'  => 'GT-Performance-Preloader/' . GTP_VERSION,
					)
				);
				$status = wp_remote_retrieve_response_code( $response );
				if ( is_wp_error( $response ) || $status >= 400 ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal queue exception; never rendered.
					throw new \RuntimeException( is_wp_error( $response ) ? $response->get_error_message() : 'Preload returned HTTP ' . $status . '.' );
				}
				return;

			case 'purge_url':
				if ( '' === $url ) {
					throw new \RuntimeException( 'Missing purge URL.' );
				}
				( new Purger() )->purgeUrl( $url );
				return;

			case 'warm_site':
				delete_transient( 'gtp_warm_pending' );
				$this->warmer->warm( (int) Settings::get( 'cache.preload_max_urls', 200 ) );
				return;

			default:
				if ( ! has_action( 'gt_performance_job_' . $type ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal queue exception; never rendered.
					throw new \RuntimeException( 'Unknown job type: ' . $type );
				}
				do_action( 'gt_performance_job_' . $type, $payload );
		}
	}
}
