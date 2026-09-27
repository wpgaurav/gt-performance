<?php
/** Background database cleanup against real WordPress, SQL, and the queue runner. */
declare(strict_types=1);
namespace GTPerformance\Tests\Integration;

use GTPerformance\Cache\PageCacheModule;
use GTPerformance\Core\Database;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Database\Cleaner;
use GTPerformance\Database\CleanupRun;
use GTPerformance\Queue\JobRepository;
use GTPerformance\Queue\QueueModule;
use PHPUnit\Framework\TestCase;

final class DatabaseCleanupTest extends TestCase {
	private mixed $savedSettings;

	protected function setUp(): void {
		global $wpdb;
		Database::install();
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}gtperf_jobs" );
		$this->savedSettings = get_option( Settings::OPTION );
		delete_option( CleanupRun::OPTION );
		delete_option( CleanupRun::STOP );
		// Start every test from an empty trash, spam folder, and revision table.
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' OR post_status IN ('trash', 'auto-draft')" );
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash')" );
		( new QueueModule( new Logger() ) )->register();
	}

	protected function tearDown(): void {
		update_option( Settings::OPTION, $this->savedSettings );
		delete_option( CleanupRun::OPTION );
		delete_option( CleanupRun::STOP );
	}

	public function test_start_returns_before_deleting_and_the_queue_finishes_the_run(): void {
		$post = $this->postWithRevisions( 7 );
		foreach ( range( 1, 3 ) as $i ) {
			wp_trash_post( $this->post( 'Trashed ' . $i ) );
		}
		foreach ( range( 1, 4 ) as $i ) {
			$this->comment( $post, 'spam' );
		}

		// Trashing saves a revision of each post too, so count what is really there.
		$revisions = $this->rows( "SELECT COUNT(*) FROM %i WHERE post_type = 'revision'", 'posts' );
		$run       = ( new CleanupRun() )->start( array( 'revisions', 'trashed_posts', 'spam_comments', 'expired_transients' ), false, 'manual' );

		self::assertIsArray( $run );
		self::assertSame( 'queued', $run['status'] );
		self::assertSame( 7, $this->revisions( $post ), 'Starting a run must not delete anything in the request.' );
		self::assertTrue( ( new JobRepository() )->hasActive( CleanupRun::JOB_TYPE ) );

		$this->drainQueue();
		$run = CleanupRun::current();

		self::assertSame( 'complete', $run['status'] );
		self::assertSame( array(), $run['pending'] );
		self::assertSame( $revisions, $run['done']['revisions'] );
		self::assertSame( 3, $run['done']['trashed_posts'] );
		self::assertSame( 4, $run['done']['spam_comments'] );
		self::assertSame( 0, $this->revisions( $post ) );
		self::assertSame( 0, $this->rows( "SELECT COUNT(*) FROM %i WHERE post_status = 'trash'", 'posts' ) );
		self::assertSame( 0, $this->rows( "SELECT COUNT(*) FROM %i WHERE comment_approved = 'spam'", 'comments' ) );
		self::assertFalse( CleanupRun::active() );
	}

	public function test_a_manual_run_goes_ahead_of_warming_but_behind_purges(): void {
		global $wpdb;
		$jobs = new JobRepository();
		$jobs->enqueue( 'preload_url', array( 'url' => home_url( '/warm-a/' ) ), 50 );
		$jobs->enqueue( 'purge_url', array( 'url' => home_url( '/purge/' ) ), 10 );
		( new CleanupRun() )->start( array( 'expired_transients' ), false, 'manual' );
		$priority  = $wpdb->get_var( "SELECT priority FROM {$wpdb->prefix}gtperf_jobs WHERE type = 'database_cleanup'" );

		self::assertSame( 'purge_url', $jobs->claim()['type'] );
		self::assertSame( CleanupRun::JOB_TYPE, $jobs->claim()['type'], 'A manual cleanup must not wait behind a warm run.' );
		self::assertSame( '30', (string) $priority );
	}

	public function test_scheduled_runs_keep_the_newest_revisions_of_every_post(): void {
		$settings                                 = Settings::all();
		$settings['database']['retain_revisions'] = 2;
		update_option( Settings::OPTION, $settings );
		$many = $this->postWithRevisions( 6 );
		$few  = $this->postWithRevisions( 2 );
		$newest = array_slice( $this->revisionIds( $many ), 0, 2 );

		( new CleanupRun() )->start( array( 'revisions' ), true, 'scheduled' );
		$this->drainQueue();

		self::assertSame( 'complete', CleanupRun::current()['status'] );
		self::assertSame( $newest, $this->revisionIds( $many ), 'The two newest revisions must survive.' );
		self::assertSame( 2, $this->revisions( $few ) );
	}

	public function test_short_slices_find_excess_revisions_beyond_their_window(): void {
		// Three posts each one revision over the limit: a one-row slice sees one post
		// at a time, and must keep going until none is over.
		$posts = array( $this->postWithRevisions( 2 ), $this->postWithRevisions( 2 ), $this->postWithRevisions( 2 ) );
		$settings                                 = Settings::all();
		$settings['database']['retain_revisions'] = 1;
		update_option( Settings::OPTION, $settings );

		$cleaner = new Cleaner();
		$deleted = 0;
		$slices  = 0;
		do {
			$slice    = $cleaner->slice( 'revisions', 1, true );
			$deleted += $slice['count'];
			++$slices;
		} while ( ! $slice['done'] && $slices < 10 );

		self::assertSame( 3, $deleted );
		foreach ( $posts as $post ) {
			self::assertSame( 1, $this->revisions( $post ) );
		}
	}

	public function test_one_run_at_a_time_and_stop_leaves_remaining_tasks_untouched(): void {
		$this->comment( $this->post( 'Host' ), 'trash' );
		$cleanup = new CleanupRun();
		self::assertIsArray( $cleanup->start( array( 'trashed_comments' ), false, 'manual' ) );

		$second = $cleanup->start( array( 'revisions' ), false, 'manual' );
		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'gtperf_database_busy', $second->get_error_code() );

		self::assertTrue( CleanupRun::stop() );
		$run = $cleanup->step( 5.0 );

		self::assertSame( 'stopped', $run['status'] );
		self::assertSame( array( 'trashed_comments' ), $run['pending'] );
		self::assertSame( 1, $this->rows( "SELECT COUNT(*) FROM %i WHERE comment_approved = 'trash'", 'comments' ) );
		self::assertIsArray( $cleanup->start( array( 'trashed_comments' ), false, 'manual' ), 'A stopped run must not block the next one.' );
	}

	public function test_a_run_nobody_advances_stops_blocking_new_runs(): void {
		$cleanup = new CleanupRun();
		$cleanup->start( array( 'expired_transients' ), false, 'manual' );
		$run               = CleanupRun::current();
		$run['updated_at'] = time() - HOUR_IN_SECONDS;
		update_option( CleanupRun::OPTION, $run, false );

		self::assertFalse( CleanupRun::active() );
		self::assertIsArray( $cleanup->start( array( 'expired_transients' ), false, 'manual' ) );
	}

	public function test_deleting_content_that_was_never_public_purges_nothing(): void {
		$host    = $this->post( 'Visible host' );
		$trashed = $this->post( 'Old draft' );
		wp_trash_post( $trashed );
		$spam = $this->comment( $host, 'spam' );

		$purged = array();
		$listen = static function ( array $urls ) use ( &$purged ): void {
			$purged = array_merge( $purged, $urls );
		};
		add_action( 'gt_performance_purged_urls', $listen );
		$module = new PageCacheModule( new Logger() );
		$module->purgeDeletedPost( $trashed, get_post( $trashed ) );
		$module->purgeDeletedComment( $spam, get_comment( $spam ) );
		remove_action( 'gt_performance_purged_urls', $listen );

		self::assertSame( array(), $purged );
	}

	private function drainQueue(): void {
		$queue = new QueueModule( new Logger() );
		for ( $i = 0; $i < 20 && CleanupRun::active(); $i++ ) {
			$queue->run( 25 );
		}
	}

	private function post( string $title ): int {
		return (int) wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
	}

	private function postWithRevisions( int $revisions ): int {
		$id = $this->post( 'Revised ' . wp_generate_password( 6, false ) );
		for ( $i = 1; $i <= $revisions; $i++ ) {
			wp_insert_post(
				array(
					'post_type'         => 'revision',
					'post_status'       => 'inherit',
					'post_parent'       => $id,
					'post_title'        => 'Revision ' . $i,
					'post_name'         => $id . '-revision-v' . $i,
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 + $i ),
					'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - 3600 + $i ),
				)
			);
		}

		return $id;
	}

	private function comment( int $post, string $status ): int {
		return (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $post,
				'comment_content'  => 'Comment',
				'comment_approved' => $status,
			)
		);
	}

	private function revisions( int $post ): int {
		return count( $this->revisionIds( $post ) );
	}

	/**
	 * @return list<int>
	 */
	private function revisionIds( int $post ): array {
		global $wpdb;

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_modified_gmt DESC, ID DESC", $post ) ) );
	}

	private function rows( string $sql, string $table ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $wpdb->{$table} ) );
	}
}
