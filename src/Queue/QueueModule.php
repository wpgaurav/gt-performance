<?php
/**
 * Background queue runner.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Queue;

use GTPerformance\Cache\Purger;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;

final class QueueModule implements Module {
	private JobRepository $jobs;

	public function __construct(
		private readonly Logger $logger,
	) {
		$this->jobs = new JobRepository();
	}

	public function register(): void {
		add_action( 'gt_performance_run_queue', array( $this, 'runScheduled' ) );
		add_action( 'gt_performance_enqueue_preload', array( $this, 'enqueuePreload' ) );
		add_action( 'gt_performance_enqueue_purge', array( $this, 'enqueuePurge' ) );
	}

	public function runScheduled(): void {
		$this->run();
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
				if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal queue exception; never rendered.
					throw new \RuntimeException( is_wp_error( $response ) ? $response->get_error_message() : 'Preload returned an error response.' );
				}
				return;

			case 'purge_url':
				if ( '' === $url ) {
					throw new \RuntimeException( 'Missing purge URL.' );
				}
				( new Purger() )->purgeUrl( $url );
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
