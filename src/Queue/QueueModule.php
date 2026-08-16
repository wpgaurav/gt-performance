<?php
/**
 * Background queue runner.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Queue;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\Purger;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Optimization\ImageVariantGenerator;

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
		add_action( 'init', array( $this, 'ensureScheduled' ) );
		add_action( 'gt_performance_run_queue', array( $this, 'runScheduled' ) );
		add_action( 'gt_performance_enqueue_preload', array( $this, 'enqueuePreload' ) );
		add_action( 'gt_performance_enqueue_purge', array( $this, 'enqueuePurge' ) );
		add_action( 'gt_performance_purged_all', array( $this, 'scheduleWarm' ) );
		add_action( ImageVariantGenerator::ENQUEUE_HOOK, array( $this, 'enqueueImageVariants' ), 10, 2 );
	}

	/**
	 * Re-arm the queue cron if the event has gone missing.
	 *
	 * Activator schedules it once at activation, and nothing restored it if the
	 * event was later lost — through a cron table reset, a migration, or a
	 * restore from a backup taken before activation. The queue then stops
	 * silently: purges never preload, warms never run, and stale pages are
	 * never rebuilt. A production site was found with the event absent and jobs
	 * pending for seven days.
	 */
	public function ensureScheduled(): void {
		if ( wp_next_scheduled( 'gt_performance_run_queue' ) ) {
			return;
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'gtp_every_minute', 'gt_performance_run_queue' );
		$this->logger->log( 'warning', 'Queue cron was missing and has been rescheduled' );
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
		$this->revalidateStale();
		$this->jobs->purgeTerminal();
	}

	/**
	 * Queue preloads for entries that have gone stale.
	 *
	 * The drop-in serves a stale entry and exits, so nothing regenerates it
	 * inside the stale window; without this the body a visitor gets can be as
	 * old as fresh_ttl + stale_ttl. Preload requests carry X-GT-Preload, which
	 * the drop-in treats as a miss when the entry is stale, so each queued job
	 * rebuilds one page.
	 *
	 * Batches are small and debounced: the sweep walks the cache directory, and
	 * on a large site that should happen at a steady trickle rather than in one
	 * burst per cron tick.
	 */
	public function revalidateStale(): void {
		if ( ! (bool) Settings::get( 'cache.enabled', true ) || ! (bool) Settings::get( 'cache.preload', true ) ) {
			return;
		}

		if ( get_transient( 'gtp_revalidate_pending' ) ) {
			return;
		}

		/**
		 * Filter how many stale pages may be queued for revalidation per run.
		 *
		 * @param int $batch Maximum URLs per sweep.
		 */
		$batch = (int) apply_filters( 'gt_performance_revalidate_batch', 5 );
		$batch = max( 0, min( 200, $batch ) );
		if ( 0 === $batch ) {
			return;
		}

		// run() drains a handful of jobs per tick and enqueue() does not
		// deduplicate, so a sweep that ignores the existing backlog would add
		// work faster than the queue clears it and re-add the same URLs on the
		// next tick. Only top up once the previous batch has been worked off.
		if ( $this->jobs->pendingCount( 'preload_url' ) >= $batch ) {
			return;
		}

		$urls = ( new FileStore() )->staleUrls( time(), $batch );
		if ( array() === $urls ) {
			return;
		}

		set_transient( 'gtp_revalidate_pending', 1, MINUTE_IN_SECONDS );
		$this->enqueuePreload( $urls );

		$this->logger->log( 'debug', 'Queued stale page revalidation', array( 'count' => count( $urls ) ) );
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

	/**
	 * Queue modern variants after WordPress has saved attachment metadata.
	 *
	 * The worker is idempotent because existing target files are skipped. Keeping
	 * this work out of wp_generate_attachment_metadata prevents large uploads from
	 * blocking the Media Library while every registered sub-size is re-encoded.
	 */
	public function enqueueImageVariants( int $attachmentId, string $variantKey = 'full' ): void {
		if ( $attachmentId <= 0 ) {
			return;
		}

		$this->jobs->enqueue(
			ImageVariantGenerator::JOB_TYPE,
			array(
				'attachment_id' => $attachmentId,
				'variant_key'   => $variantKey,
			),
			20,
			5
		);
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
