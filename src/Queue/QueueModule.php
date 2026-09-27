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
use GTPerformance\Cache\Preloader;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\WarmRunRepository;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Optimization\ImageVariantGenerator;

final class QueueModule implements Module {
	public const HEARTBEAT_OPTION = 'gt_performance_queue_heartbeat';

	private JobRepository $jobs;
	private CacheWarmer $warmer;

	public function __construct(
		private readonly Logger $logger,
	) {
		$this->jobs   = new JobRepository();
		$this->warmer = new CacheWarmer( $logger );
	}

	public function register(): void {
		add_action( 'init', array( JobLease::class, 'fromRequest' ), 0 );
		add_action( 'gt_performance_job_' . \GTPerformance\Optimization\Css\Maintenance::BATCH_JOB, array( new \GTPerformance\Optimization\Css\Maintenance(), 'runBatch' ) );
		add_action( 'gt_performance_job_' . \GTPerformance\Database\CleanupRun::JOB_TYPE, array( new \GTPerformance\Database\CleanupRun(), 'handleJob' ) );
		add_action( 'gt_performance_job_' . \GTPerformance\Operations\OperationService::JOB_TYPE, static fn ( array $payload ) => ( new \GTPerformance\Operations\OperationService() )->execute( $payload ) );
		add_action( 'init', array( $this, 'ensureScheduled' ) );
		add_action( 'gt_performance_run_queue', array( $this, 'runScheduled' ) );
		add_action( 'gt_performance_enqueue_preload', array( $this, 'enqueuePreload' ) );
		add_action( 'gt_performance_enqueue_purge', array( $this, 'enqueuePurge' ) );
		add_action( 'gt_performance_enqueue_font_localization', array( $this, 'enqueueFontLocalization' ) );
		add_action( 'gt_performance_enqueue_css', array( $this, 'enqueueCssGeneration' ) );
		add_action( \GTPerformance\Cache\GarbageCollector::HOOK, array( $this, 'collectGarbage' ) );
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
		// Each event is checked independently. Guarding both behind "is the queue event
		// missing" means a site that upgraded with a healthy queue never schedules
		// anything added in a later release, which is the common path, not the rare one.
		if ( ! wp_next_scheduled( 'gt_performance_run_queue' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'gtperf_every_minute', 'gt_performance_run_queue' );
			$this->logger->log( 'warning', 'Queue cron was missing and has been rescheduled' );
		}

		if ( ! wp_next_scheduled( \GTPerformance\Cache\GarbageCollector::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', \GTPerformance\Cache\GarbageCollector::HOOK );
		}
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

		if ( get_transient( 'gtperf_warm_pending' ) ) {
			return;
		}

		set_transient( 'gtperf_warm_pending', 1, MINUTE_IN_SECONDS );
		$this->jobs->enqueue( CacheWarmer::START_JOB, array(), CacheWarmer::JOB_PRIORITY, 30 );
	}

	public function runScheduled(): void {
		update_option( self::HEARTBEAT_OPTION, time(), false );
		// The 20-second budget protects the server; five jobs a minute left a
		// 1,200-page warm run taking four hours on a production site.
		$this->run( 25 );
		$this->revalidateStale();
		$this->jobs->purgeTerminal();
		if ( \GTPerformance\Core\Database::queueReady() ) {
			( new WarmRunRepository() )->purgeExpired();
			( new \GTPerformance\Cache\DependencyIndex() )->prune();
			( new \GTPerformance\Operations\OperationRepository() )->prune();
		}
	}

	/**
	 * @return array{pending:int,running:int,failed:int,complete:int,cancelled:int,paused:bool,ready:bool}
	 */
	public function status(): array {
		return $this->jobs->counts();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function jobs( string $status = '', int $limit = 20 ): array {
		return $this->jobs->list( $status, $limit );
	}

	public function pause(): void {
		$this->jobs->pause();
	}

	public function resume(): void {
		$this->jobs->resume();
	}

	public function retry( int $id ): bool {
		return $this->jobs->retry( $id );
	}

	public function cancel( int $id ): bool {
		return $this->jobs->cancel( $id );
	}

	/**
	 * Queue preloads for entries that have gone stale.
	 *
	 * The drop-in serves a stale entry and exits, so nothing regenerates it
	 * inside the stale window; without this the body a visitor gets can be as
	 * old as fresh_ttl + stale_ttl. Preload requests carry a signed X-GT-Preload
	 * token, which the drop-in treats as a miss when the entry is stale, so each
	 * queued job rebuilds one page.
	 *
	 * Batches are small and debounced: the sweep walks the cache directory, and
	 * on a large site that should happen at a steady trickle rather than in one
	 * burst per cron tick.
	 */
	public function revalidateStale(): void {
		if ( ! (bool) Settings::get( 'cache.enabled', true ) || ! (bool) Settings::get( 'cache.preload', true ) ) {
			return;
		}

		if ( get_transient( 'gtperf_revalidate_pending' ) ) {
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

		// run() drains a handful of jobs per tick, so ignoring the backlog would add
		// work faster than the queue clears it and re-add the same URLs on the
		// next tick. Only top up once the previous batch has been worked off.
		if ( $this->jobs->pendingCount( 'preload_url' ) >= $batch ) {
			return;
		}

		$urls = ( new FileStore() )->staleUrls( time(), $batch );
		if ( array() === $urls ) {
			return;
		}

		set_transient( 'gtperf_revalidate_pending', 1, MINUTE_IN_SECONDS );
		$this->enqueuePreload( $urls );

		$this->logger->log( 'debug', 'Queued stale page revalidation', array( 'count' => count( $urls ) ) );
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	public function enqueuePreload( array $urls ): void {
		// Stale revalidation only refreshes the public variant; a warm run queues mobile explicitly.
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

	public function enqueueFontLocalization( string $url ): void {
		if ( '' === $url ) {
			return;
		}

		$this->jobs->enqueue( \GTPerformance\Optimization\FontOptimizer::JOB_TYPE, array( 'url' => $url ), 60, 0 );
	}

	public function enqueueCssGeneration( string $url ): void {
		if ( '' === $url || ! $this->sameSite( $url ) ) {
			return;
		}

		( new \GTPerformance\Optimization\Css\Maintenance() )->enqueue( $url );
	}

	public function collectGarbage(): void {
		( new \GTPerformance\Cache\GarbageCollector( $this->logger ) )->collect();
	}

	public function run( int $limit = 5 ): int {
		if ( ! \GTPerformance\Core\Database::queueReady() ) {
			return 0;
		}
		// One runner per site. A dead runner's slot is freed when its MySQL
		// connection closes, or after one lease period on SQLite.
		if ( ! \GTPerformance\Core\NamedLock::acquire( 'runner', JobRepository::LEASE_SECONDS ) ) {
			return 0;
		}
		try {
			return $this->runLoop( $limit );
		} finally {
			\GTPerformance\Core\NamedLock::release( 'runner' );
		}
	}

	private function runLoop( int $limit ): int {
		$processed = 0;
		$started   = microtime( true );

		while ( $processed < max( 1, min( 100, $limit ) ) && microtime( true ) - $started < 20 ) {
			$job = $this->jobs->claim();
			if ( null === $job ) {
				break;
			}

			$id       = (int) $job['id'];
			$token    = (string) $job['lock_token'];
			$attempts = (int) $job['attempts'];
			$jobStarted = microtime( true );
			JobLease::start( $id, $token );

			try {
				JobLease::checkpoint();
				if ( $this->jobs->cancellationRequested( $id, $token ) ) {
					$this->jobs->finishCancelled( $id, $token );
				} else {
					$result = $this->handle( (string) $job['type'], (array) $job['payload'] );
					if ( $this->jobs->cancellationRequested( $id, $token ) ) {
						$this->jobs->finishCancelled( $id, $token );
					} elseif ( ! $this->jobs->complete( $id, $token, (int) round( ( microtime( true ) - $jobStarted ) * 1000 ), $result ) ) {
						$this->logger->log(
							'warning',
							'Queue completion was superseded',
							array(
								'id' => $id,
								'type' => (string) $job['type'],
							)
						);
					}
				}
			} catch ( \Throwable $throwable ) {
				if ( $this->jobs->cancellationRequested( $id, $token ) ) {
					$this->jobs->finishCancelled( $id, $token );
				} else {
					$this->jobs->fail( $id, $token, $throwable->getMessage(), $attempts );
				}
				$this->logger->log(
					'error',
					'Queue job failed',
					array(
						'type'  => (string) $job['type'],
						'error' => $throwable->getMessage(),
					)
				);
			} finally {
				JobLease::end();
			}

			++$processed;
		}

		return $processed;
	}

	/**
	 * Whether a URL belongs to this installation, host and port included.
	 */
	private function sameSite( string $url ): bool {
		$parts = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host || ! in_array( $host, \GTPerformance\Core\Settings::canonicalHosts(), true ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$defaultPort = 'https' === $scheme ? 443 : 80;
		return in_array( $scheme, array( 'http', 'https' ), true ) && ( $home['scheme'] ?? '' ) === $scheme
			&& (int) ( $parts['port'] ?? $defaultPort ) === (int) ( $home['port'] ?? $defaultPort );
	}

	/**
	 * @param array<string, mixed> $payload Job payload.
	 * @return array<string, scalar> Bounded result metadata for the job row.
	 * @phpstan-impure
	 */
	private function handle( string $type, array $payload ): array {
		$url = isset( $payload['url'] ) ? esc_url_raw( (string) $payload['url'] ) : '';

		// Job payloads are built from cached-entry metadata, whose URL was assembled
		// from a client-supplied Host header. Eligibility now refuses a foreign Host,
		// but the queue is the component that actually makes the request, so it checks
		// again rather than trusting a row written by an earlier release.
		if ( '' !== $url && \GTPerformance\Optimization\FontOptimizer::JOB_TYPE !== $type && ! $this->sameSite( $url ) ) {
			throw new \RuntimeException( 'Refusing to request a URL outside this site.' );
		}

		switch ( $type ) {
			case 'preload_url':
				if ( '' === $url ) {
					throw new \RuntimeException( 'Missing preload URL.' );
				}
				$variant = 'mobile' === ( $payload['variant'] ?? '' ) ? 'mobile' : 'public';
				$outcome = ( new Preloader() )->preload( $url, $variant );
				$this->warmer->record( $url, $variant, $outcome );
				if ( 'failed' === $outcome['status'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal queue exception; never rendered.
					throw new \RuntimeException( 'Preload failed: ' . $outcome['detail'] . '.' );
				}
				return $outcome;

			case 'purge_url':
				if ( '' === $url ) {
					throw new \RuntimeException( 'Missing purge URL.' );
				}
				( new Purger() )->purgeUrl( $url );
				return array();

			case CacheWarmer::START_JOB:
				delete_transient( 'gtperf_warm_pending' );
				return array( 'run' => $this->warmer->start() );

			case CacheWarmer::DISCOVER_JOB:
				$this->warmer->discover( (string) ( $payload['run'] ?? '' ), (int) ( $payload['step'] ?? 1 ) );
				return array();

			case CacheWarmer::DISPATCH_JOB:
				$this->warmer->dispatch( (string) ( $payload['run'] ?? '' ), (int) ( $payload['step'] ?? 1 ) );
				return array();

			default:
				if ( ! has_action( 'gt_performance_job_' . $type ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal queue exception; never rendered.
					throw new \RuntimeException( 'Unknown job type: ' . $type );
				}
				do_action( 'gt_performance_job_' . $type, $payload );
				return array();
		}
	}
}
