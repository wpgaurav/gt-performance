<?php
/** Durable queue state transitions with actual SQL and separate worker processes. */
declare(strict_types=1);
namespace GTPerformance\Tests\Integration;

use GTPerformance\Core\Database;
use GTPerformance\Queue\JobRepository;
use PHPUnit\Framework\TestCase;

final class QueueDatabaseTest extends TestCase {
	private JobRepository $jobs;
	private string $table;

	protected function setUp(): void {
		global $wpdb;
		Database::install();
		self::assertTrue( Database::queueReady() );
		$this->table = $wpdb->prefix . 'gtperf_jobs';
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
		delete_option( JobRepository::PAUSE_OPTION );
		$this->jobs = new JobRepository();
	}

	public function test_two_workers_select_one_row_but_only_one_claims_it(): void {
		$id = $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/a/' ) ) );
		$results = $this->workers( 'claim' );
		$claims = array_values( array_filter( $results, 'is_array' ) );
		self::assertCount( 1, $claims );
		self::assertSame( $id, (int) $claims[0]['id'] );
		self::assertSame( 1, (int) $claims[0]['attempts'] );
	}

	public function test_concurrent_enqueue_returns_the_same_active_job(): void {
		$results = $this->workers( 'enqueue' );
		self::assertGreaterThan( 0, $results[0] );
		self::assertSame( $results[0], $results[1] );
		self::assertSame( 1, $this->jobs->pendingCount( 'preload_url' ) );
	}

	public function test_crashes_exhaust_attempts_and_allow_explicit_retry(): void {
		$id = $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/a/' ) ) );
		$old = '';
		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$claim = $this->jobs->claim();
			self::assertNotNull( $claim );
			self::assertSame( $attempt, (int) $claim['attempts'] );
			self::assertFalse( $this->jobs->complete( $id, $old ) );
			self::assertFalse( $this->jobs->fail( $id, $old, 'late', 1 ) );
			$old = (string) $claim['lock_token'];
			$this->expire( $id );
			self::assertFalse( $this->jobs->renew( $id, $old ) );
			self::assertFalse( $this->jobs->complete( $id, $old ) );
		}
		self::assertNull( $this->jobs->claim() );
		self::assertSame( 1, $this->jobs->counts()['failed'] );
		self::assertTrue( $this->jobs->retry( $id ) );
		self::assertSame( 1, (int) $this->jobs->claim()['attempts'] );
	}

	public function test_waiting_work_ages_ahead_of_a_steady_stream_but_never_past_purges(): void {
		global $wpdb;
		$warm = $this->jobs->enqueue( 'warm_site', array(), 80 );
		$wpdb->update( $this->table, array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 7 * HOUR_IN_SECONDS ) ), array( 'id' => $warm ) );
		$css = $this->jobs->enqueue( 'generate_css', array( 'url' => home_url( '/fresh/' ) ), 70 );
		$recent = $this->jobs->enqueue( 'warm_discover', array( 'run' => 'r', 'step' => 1 ), 80 );
		$wpdb->update( $this->table, array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * MINUTE_IN_SECONDS ) ), array( 'id' => $recent ) );
		$purge = $this->jobs->enqueue( 'purge_url', array( 'url' => home_url( '/a/' ) ), 10 );

		self::assertSame( $purge, (int) $this->jobs->claim()['id'], 'Invalidation still leads.' );
		self::assertSame( $warm, (int) $this->jobs->claim()['id'], 'Six hours of waiting outranks fresh priority-70 work.' );
		self::assertSame( $recent, (int) $this->jobs->claim()['id'], 'Thirty minutes of waiting runs as priority 50.' );
		self::assertSame( $css, (int) $this->jobs->claim()['id'] );
	}

	public function test_cancel_pending_running_and_abandoned_jobs(): void {
		$id = $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/a/' ) ) );
		self::assertTrue( $this->jobs->cancel( $id ) );
		self::assertNull( $this->jobs->claim() );
		$id = $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/a/' ) ) );
		$claim = $this->jobs->claim();
		self::assertTrue( $this->jobs->renew( $id, $claim['lock_token'] ) );
		self::assertTrue( $this->jobs->renew( $id, $claim['lock_token'] ) );
		self::assertTrue( $this->jobs->cancel( $id ) );
		self::assertTrue( $this->jobs->cancellationRequested( $id, $claim['lock_token'] ) );
		self::assertFalse( $this->jobs->renew( $id, $claim['lock_token'] ) );
		self::assertFalse( $this->jobs->complete( $id, $claim['lock_token'] ) );
		self::assertNull( $this->jobs->claim() );
		$this->expire( $id );
		self::assertNull( $this->jobs->claim() );
		self::assertSame( 2, $this->jobs->counts()['cancelled'] );
	}

	public function test_pause_preserves_purge_priority_and_resume(): void {
		$this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/a/' ) ), 1 );
		$id = $this->jobs->enqueue( 'purge_url', array( 'url' => home_url( '/a/' ) ), 10 );
		$this->jobs->pause();
		self::assertSame( $id, (int) $this->jobs->claim()['id'] );
		self::assertNull( $this->jobs->claim() );
		$this->jobs->resume();
		self::assertSame( 'preload_url', $this->jobs->claim()['type'] );
	}

	public function test_failure_backoff_redaction_and_new_work_after_completion(): void {
		$id = $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/a/' ) ) );
		$job = $this->jobs->claim();
		self::assertTrue( $this->jobs->fail( $id, $job['lock_token'], 'token=private-value https://example.test/?key=secret', 1 ) );
		self::assertNull( $this->jobs->claim() );
		$row = $this->jobs->list()[0];
		self::assertStringNotContainsString( 'private-value', $row['last_error'] );
		self::assertStringNotContainsString( 'secret', $row['last_error'] );
		global $wpdb;
		$wpdb->query( "UPDATE {$this->table} SET available_at = '2000-01-01' WHERE id = {$id}" );
		$job = $this->jobs->claim();
		self::assertTrue( $this->jobs->complete( $id, $job['lock_token'] ) );
		self::assertGreaterThan( $id, $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/a/' ) ) ) );
	}

	public function test_additive_migration_is_bounded_and_preserves_a_running_lease(): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		for ( $i = 0; $i < 510; ++$i ) {
			$wpdb->insert( $this->table, array( 'type' => 'preload_url', 'payload' => wp_json_encode( array( 'url' => home_url( '/old-' . $i ) ) ), 'status' => 0 === $i ? 'running' : 'pending', 'locked_at' => 0 === $i ? $now : null, 'lock_token' => 0 === $i ? 'old-live-token' : null, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
		}
		// An old duplicate pending row must be consolidated, without touching the running token.
		$wpdb->query( "INSERT INTO {$this->table} (type,payload,status,available_at,created_at,updated_at) SELECT type,payload,'pending',available_at,created_at,updated_at FROM {$this->table} WHERE id = 1" );
		$wpdb->query( "ALTER TABLE {$this->table} DROP INDEX active_key, DROP COLUMN active_key, DROP COLUMN lease_expires_at, DROP COLUMN cancel_requested, DROP COLUMN result_metadata" );
		update_option( 'gt_performance_schema_version', '3', false );
		self::assertNull( $this->jobs->claim() );
		Database::install();
		self::assertFalse( Database::queueReady() );
		self::assertSame( 0, $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/blocked/' ) ) ) );
		Database::install();
		self::assertTrue( Database::queueReady() );
		self::assertSame( 1, $this->jobs->counts()['cancelled'] );
		$row = $wpdb->get_row( "SELECT * FROM {$this->table} WHERE id = 1", ARRAY_A );
		self::assertSame( 'old-live-token', $row['lock_token'] );
		self::assertGreaterThan( $now, $row['lease_expires_at'] );
		self::assertNotSame( 1, (int) $this->jobs->claim()['id'] );
	}

	public function test_late_artifacts_and_reports_cannot_replace_the_new_workers_result(): void {
		$id = $this->jobs->enqueue( 'generate_css', array( 'url' => home_url( '/fenced/' ) ) );
		$first = $this->jobs->claim();
		$this->expire( $id );
		$second = $this->jobs->claim();
		$path = sys_get_temp_dir() . '/gtperf-publish-' . wp_generate_uuid4();
		$reports = new \GTPerformance\Optimization\Css\ReportRepository();
		$fingerprint = $reports->begin( home_url( '/fenced/' ), 'inline' );
		try {
			\GTPerformance\Queue\JobLease::start( $id, $second['lock_token'] );
			self::assertTrue( \GTPerformance\Core\AtomicFile::write( $path, 'winner' ) );
			$reports->complete( $fingerprint, 'inline', 'ready', '', array( 'url' => home_url( '/fenced/' ), 'generated_bytes' => 20 ) );
			\GTPerformance\Queue\JobLease::start( $id, $first['lock_token'] );
			self::assertFalse( \GTPerformance\Core\AtomicFile::write( $path, 'late' ) );
			$reports->begin( home_url( '/fenced/' ), 'inline' );
			$reports->complete( $fingerprint, 'inline', 'failed', '', array( 'url' => home_url( '/fenced/' ) ) );
			self::assertSame( 'winner', file_get_contents( $path ) );
			self::assertSame( 'ready', $reports->find( home_url( '/fenced/' ), 'inline' )['status'] );
			self::assertSame( array(), glob( $path . '.gtperf-*' ) );
		} finally {
			\GTPerformance\Queue\JobLease::end();
			unlink( $path );
		}
	}

	public function test_runner_cooperates_with_cancellation_and_records_a_bounded_result(): void {
		$id = $this->jobs->enqueue( 'integration_unit', array() );
		$cancel = function () use ( $id ): void { $this->jobs->cancel( $id ); };
		add_action( 'gt_performance_job_integration_unit', $cancel );
		$runner = new \GTPerformance\Queue\QueueModule( new \GTPerformance\Core\Logger() );
		try {
			self::assertSame( 1, $runner->run() );
			self::assertSame( 1, $this->jobs->counts()['cancelled'] );
			self::assertSame( array(), \GTPerformance\Queue\JobLease::headers() );
		} finally {
			remove_action( 'gt_performance_job_integration_unit', $cancel );
		}
		$id = $this->jobs->enqueue( 'integration_unit', array() );
		$done = static function (): void {};
		add_action( 'gt_performance_job_integration_unit', $done );
		try {
			self::assertSame( 1, $runner->run() );
			global $wpdb;
			$metadata = json_decode( $wpdb->get_var( "SELECT result_metadata FROM {$this->table} WHERE id = {$id}" ), true );
			self::assertIsInt( $metadata['duration_ms'] );
			self::assertSame( 1, $this->jobs->counts()['complete'] );
		} finally {
			remove_action( 'gt_performance_job_integration_unit', $done );
		}
	}

	public function test_migration_lock_and_frontend_guard_do_not_advance_schema(): void {
		global $wpdb;
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock = 'gtperf_schema_' . substr( hash( 'sha256', $wpdb->dbname . '|' . $wpdb->prefix ), 0, 40 );
		update_option( 'gt_performance_schema_version', '3', false );
		$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		try {
			Database::install();
			self::assertFalse( Database::queueReady() );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$other->close();
		}
		Database::maybeUpgrade(); // Real frontend context: neither admin nor WP-CLI.
		self::assertFalse( Database::queueReady() );
		Database::install();
		self::assertTrue( Database::queueReady() );
	}

	public function test_live_duplicate_leases_wait_for_expiry_before_consolidation(): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		for ( $i = 0; $i < 2; ++$i ) {
			$wpdb->insert( $this->table, array( 'type' => 'preload_url', 'payload' => wp_json_encode( array( 'url' => home_url( '/same/' ) ) ), 'status' => 'running', 'locked_at' => $now, 'lock_token' => 'live-' . $i, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
		}
		update_option( 'gt_performance_schema_version', '3', false );
		Database::install();
		self::assertFalse( Database::queueReady() );
		self::assertSame( 2, $this->jobs->counts()['running'] );
		$this->expire( 2 );
		Database::install();
		self::assertTrue( Database::queueReady() );
		self::assertSame( 1, $this->jobs->counts()['running'] );
		self::assertSame( 1, $this->jobs->counts()['cancelled'] );
	}

	public function test_runner_caps_work_and_retry_cannot_duplicate_new_active_work(): void {
		for ( $i = 0; $i < 110; ++$i ) {
			$this->jobs->enqueue( 'integration_unit', array( 'unit' => $i ) );
		}
		$done = static function (): void {};
		add_action( 'gt_performance_job_integration_unit', $done );
		try {
			$runner = new \GTPerformance\Queue\QueueModule( new \GTPerformance\Core\Logger() );
			self::assertSame( 100, $runner->run( 9999 ) );
			self::assertSame( 10, $this->jobs->pendingCount( 'integration_unit' ) );
		} finally {
			remove_action( 'gt_performance_job_integration_unit', $done );
		}
		$id = $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/duplicate-retry/' ) ), 1 );
		$claim = $this->jobs->claim();
		self::assertTrue( $this->jobs->fail( $id, $claim['lock_token'], 'terminal fixture', 3 ) );
		self::assertGreaterThan( $id, $this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/duplicate-retry/' ) ) ) );
		self::assertFalse( $this->jobs->retry( $id ) );
	}

	public function test_preload_does_not_follow_redirects_with_lease_credentials_or_report_them_complete(): void {
		$this->jobs->enqueue( 'preload_url', array( 'url' => home_url( '/redirect-fixture/' ) ) );
		$seen = array();
		$http = static function ( $pre, $args, $url ) use ( &$seen ) {
			$seen[] = $args;
			return array( 'response' => array( 'code' => 302 ), 'headers' => array( 'location' => 'https://foreign.invalid/' ), 'body' => '' );
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$runner = new \GTPerformance\Queue\QueueModule( new \GTPerformance\Core\Logger() );
			self::assertSame( 1, $runner->run() );
			self::assertCount( 1, $seen );
			self::assertSame( 0, $seen[0]['redirection'] );
			self::assertNotEmpty( $seen[0]['headers']['X-GT-Job-Token'] );
			self::assertSame( 0, $this->jobs->counts()['complete'] );
			self::assertSame( 1, $this->jobs->counts()['pending'] );
		} finally {
			remove_filter( 'pre_http_request', $http, 10 );
		}
	}

	private function expire( int $id ): void {
		global $wpdb;
		$wpdb->query( "UPDATE {$this->table} SET locked_at = '2000-01-01', lease_expires_at = '2000-01-01' WHERE id = {$id}" );
	}

	/** @return list<mixed> */
	private function workers( string $mode ): array {
		$barrier = sys_get_temp_dir() . '/gtperf-queue-' . bin2hex( random_bytes( 8 ) );
		$env = array_merge( getenv(), array( 'GTPERF_BARRIER' => $barrier, 'GTPERF_WORKER_MODE' => $mode ) );
		$processes = array();
		for ( $i = 0; $i < 2; ++$i ) {
			$processes[] = proc_open( array( PHP_BINARY, __DIR__ . '/queue-worker.php' ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $barrier . '.out', 'a' ), 2 => array( 'file', $barrier . '.err', 'a' ) ), $pipes, null, $env );
		}
		try {
			$deadline = microtime( true ) + 10;
			do {
				$selected = glob( $barrier . '.[0-9]*' ) ?: array();
				if ( count( $selected ) === 2 ) { break; }
				usleep( 10000 );
			} while ( microtime( true ) < $deadline );
			self::assertCount( 2, $selected, (string) @file_get_contents( $barrier . '.err' ) );
			file_put_contents( $barrier . '.go', 'release' );
			foreach ( $processes as $process ) {
				self::assertSame( 0, proc_close( $process ), (string) @file_get_contents( $barrier . '.err' ) );
			}
			$processes = array();
			$results = array_map( static fn( string $path ): mixed => json_decode( file_get_contents( $path ), true ), glob( $barrier . '.result.*' ) ?: array() );
			self::assertCount( 2, $results );
			return $results;
		} finally {
			foreach ( $processes as $process ) { if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); } }
			foreach ( glob( $barrier . '.*' ) ?: array() as $path ) { unlink( $path ); }
		}
	}
}
