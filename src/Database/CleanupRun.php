<?php
/**
 * Database cleanup as a background run.
 *
 * Deleting a thousand revisions one wp_delete_post() at a time held the admin
 * request open until every task had finished. A run is now saved state that
 * the background queue works through in short slices, one job per slice, so
 * the button returns at once. While an administrator watches the Database tab,
 * its status poll also runs a short slice, so progress does not wait for the
 * next queue tick. A named lock keeps the two from ever working at once.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Database;

use GTPerformance\Core\NamedLock;
use GTPerformance\Queue\JobRepository;

final class CleanupRun {
	public const OPTION   = 'gt_performance_database_run';
	public const STOP     = 'gt_performance_database_run_stop';
	public const JOB_TYPE = 'database_cleanup';

	/** Rows deleted per slice; each deletion runs WordPress's own delete hooks. */
	private const SLICE_ITEMS = 100;

	/**
	 * Queue priority. Someone who pressed the button is waiting, so a manual run
	 * goes ahead of warming and preloads (50) but never ahead of purges (10). At
	 * 60 it waited behind a whole warm run on a production site. A scheduled run
	 * stays behind visitor-facing work.
	 */
	private const PRIORITY_MANUAL    = 30;
	private const PRIORITY_SCHEDULED = 60;

	/** A run nobody has advanced for this long is treated as abandoned. */
	private const ABANDONED_AFTER = 30 * MINUTE_IN_SECONDS;

	private const LOCK = 'database-cleanup';

	public function __construct( private readonly Cleaner $cleaner = new Cleaner() ) {
	}

	/**
	 * @param list<string> $tasks  Tasks to run.
	 * @param string       $source manual or scheduled.
	 * @return array<string, mixed>|\WP_Error The new run.
	 */
	public function start( array $tasks, bool $respectRevisionRetention, string $source ): array|\WP_Error {
		$tasks = $this->cleaner->sanitizeTasks( $tasks );
		if ( array() === $tasks ) {
			return new \WP_Error( 'gtperf_database_no_tasks', __( 'Select at least one cleanup task.', 'gt-performance' ) );
		}
		if ( self::active() ) {
			return new \WP_Error( 'gtperf_database_busy', __( 'A database cleanup is already running. Wait for it to finish or stop it first.', 'gt-performance' ) );
		}

		$now = time();
		$run = array(
			'id'         => wp_generate_uuid4(),
			'source'     => 'scheduled' === $source ? 'scheduled' : 'manual',
			'priority'   => 'scheduled' === $source ? self::PRIORITY_SCHEDULED : self::PRIORITY_MANUAL,
			'user_id'    => get_current_user_id(),
			'retain'     => $respectRevisionRetention,
			'tasks'      => $tasks,
			'pending'    => $tasks,
			'cursor'     => array(),
			'done'       => array_fill_keys( $tasks, 0 ),
			'status'     => 'queued',
			'error'      => '',
			'created_at' => $now,
			'updated_at' => $now,
			'ended_at'   => 0,
		);
		delete_option( self::STOP );
		update_option( self::OPTION, $run, false );
		$this->enqueue( $run['id'], 1, (int) $run['priority'] );

		return $run;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		wp_cache_delete( self::OPTION, 'options' );
		$run = get_option( self::OPTION, null );

		return is_array( $run ) && isset( $run['id'], $run['status'] ) ? $run : null;
	}

	public static function active(): bool {
		$run = self::current();

		return null !== $run
			&& in_array( $run['status'], array( 'queued', 'running' ), true )
			&& (int) $run['updated_at'] > time() - self::ABANDONED_AFTER;
	}

	/**
	 * Ask the current run to stop after its current slice.
	 */
	public static function stop(): bool {
		$run = self::current();
		if ( null === $run || ! self::active() ) {
			return false;
		}
		update_option( self::STOP, (string) $run['id'], false );

		return true;
	}

	/**
	 * Advance the current run for about $seconds.
	 *
	 * @return array<string, mixed>|null The run as it now stands.
	 */
	public function step( float $seconds ): ?array {
		if ( ! self::active() || ! NamedLock::acquire( self::LOCK, 120 ) ) {
			return self::current();
		}

		try {
			$run     = (array) self::current();
			$started = microtime( true );
			if ( 'queued' === $run['status'] ) {
				$run['status'] = 'running';
			}

			while ( array() !== $run['pending'] ) {
				wp_cache_delete( self::STOP, 'options' );
				if ( get_option( self::STOP ) === $run['id'] ) {
					$run['status']   = 'stopped';
					$run['ended_at'] = time();
					break;
				}
				if ( microtime( true ) - $started >= $seconds ) {
					break;
				}

				$task  = (string) $run['pending'][0];
				$slice = $this->cleaner->slice( $task, self::SLICE_ITEMS, (bool) $run['retain'], (array) ( $run['cursor'][ $task ] ?? array() ) );

				$run['done'][ $task ]   = (int) ( $run['done'][ $task ] ?? 0 ) + $slice['count'];
				$run['cursor'][ $task ] = $slice['cursor'];
				if ( $slice['done'] ) {
					array_shift( $run['pending'] );
					unset( $run['cursor'][ $task ] );
				}
				$run['updated_at'] = time();
				update_option( self::OPTION, $run, false );
			}

			if ( 'running' === $run['status'] && array() === $run['pending'] ) {
				$run['status']   = 'complete';
				$run['ended_at'] = time();
			}
		} catch ( \Throwable $error ) {
			$run['status']   = 'failed';
			$run['error']    = \GTPerformance\Core\Logger::redact( $error->getMessage() );
			$run['ended_at'] = time();
		} finally {
			$run['updated_at'] = time();
			update_option( self::OPTION, $run, false );
			NamedLock::release( self::LOCK );
		}

		return $run;
	}

	/**
	 * Queue job: one slice, then queue the next while work remains.
	 *
	 * @param array<string, mixed> $payload Run ID and step.
	 */
	public function handleJob( array $payload ): void {
		$run = self::current();
		if ( null === $run || ( $payload['run'] ?? '' ) !== $run['id'] ) {
			return;
		}
		$before = (int) $run['updated_at'];
		$run    = $this->step( 15.0 );
		if ( null !== $run && ( $payload['run'] ?? '' ) === $run['id'] && self::active() ) {
			// The step number keeps each continuation distinct from the job still running.
			// No progress means the admin screen holds the lock; check back shortly.
			$this->enqueue( (string) $run['id'], (int) ( $payload['step'] ?? 1 ) + 1, (int) ( $run['priority'] ?? self::PRIORITY_MANUAL ), (int) $run['updated_at'] === $before ? 20 : 0 );
		}
	}

	private function enqueue( string $runId, int $step, int $priority, int $delay = 0 ): void {
		( new JobRepository() )->enqueue(
			self::JOB_TYPE,
			array(
				'run'  => $runId,
				'step' => $step,
			),
			$priority,
			$delay
		);
	}
}
