<?php
/** Agent operations and settings proposals against real WordPress, SQL, and the queue runner. */
declare(strict_types=1);
namespace GTPerformance\Tests\Integration;

use GTPerformance\Cache\FileStore;
use GTPerformance\Configuration\ConfigurationService;
use GTPerformance\Configuration\RevisionRepository;
use GTPerformance\Core\Database;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\CacheInspector;
use GTPerformance\Operations\OperationRepository;
use GTPerformance\Operations\OperationService;
use GTPerformance\Operations\ProposalService;
use GTPerformance\Queue\JobRepository;
use GTPerformance\Queue\QueueModule;
use PHPUnit\Framework\TestCase;

final class OperationsTest extends TestCase {
	private mixed $savedSettings;

	private int $admin = 0;

	private \Closure $http;

	/** @var list<string> */
	private array $requests = array();

	protected function setUp(): void {
		global $wpdb;
		Database::install();
		self::assertTrue( Database::queueReady() );
		foreach ( array( 'gtperf_operations', 'gtperf_jobs' ) as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
		}
		$this->savedSettings = get_option( Settings::OPTION );
		$this->mode( 'operate' );
		$user        = get_user_by( 'login', 'gtperf_ops_admin' );
		$this->admin = $user ? (int) $user->ID : (int) wp_insert_user(
			array(
				'user_login' => 'gtperf_ops_admin',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'gtperf_ops_admin@example.test',
				'role'       => 'administrator',
			)
		);
		( new \WP_User( $this->admin ) )->set_role( 'administrator' );
		wp_set_current_user( $this->admin );
		( new QueueModule( new Logger() ) )->register();
		( new RevisionRepository() )->register();
		$this->http = function ( $pre, array $args, string $url ) {
			$this->requests[] = $url;
			return array(
				'headers'  => array( 'cf-cache-status' => 'MISS', 'x-gt-cache' => 'MISS' ),
				'body'     => '<html></html>',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->http, 10 );
		remove_all_actions( 'update_option_' . Settings::OPTION );
		update_option( Settings::OPTION, $this->savedSettings );
		delete_option( RevisionRepository::OPTION );
		wp_set_current_user( 0 );
	}

	private function mode( string $mode ): void {
		$settings                   = Settings::all();
		$settings['agents']['mode'] = $mode;
		$settings['cloudflare']['enabled'] = false;
		$settings['xcloud']['enabled']     = false;
		update_option( Settings::OPTION, $settings );
	}

	private function drain(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}gtperf_jobs SET available_at = %s WHERE status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		( new QueueModule( new Logger() ) )->run( 50 );
	}

	private function id(): string {
		return wp_generate_uuid4();
	}

	public function test_a_replay_returns_the_first_operation_and_a_changed_payload_conflicts(): void {
		$service = new OperationService();
		$request = $this->id();
		$first   = $service->submit( 'purge_urls', array( 'urls' => array( home_url( '/b/' ), home_url( '/a/' ) ) ), $request );
		$again   = $service->submit( 'purge_urls', array( 'urls' => array( home_url( '/a/' ), home_url( '/b/' ), home_url( '/a/' ) ) ), $request );

		self::assertFalse( $first['replayed'] );
		self::assertTrue( $again['replayed'], 'Order and duplicates do not make a different request.' );
		self::assertSame( $first['operation_id'], $again['operation_id'] );
		self::assertSame( 1, ( new JobRepository() )->pendingCount( OperationService::JOB_TYPE ) );

		$conflict = $service->submit( 'purge_urls', array( 'urls' => array( home_url( '/c/' ) ) ), $request );
		self::assertSame( 'gtperf_request_conflict', $conflict->get_error_code() );
	}

	public function test_urls_must_belong_to_this_site_and_stay_within_bounds(): void {
		$service = new OperationService();
		self::assertSame( 'gtperf_invalid_url', $service->submit( 'purge_urls', array( 'urls' => array( 'https://evil.test/' ) ), $this->id() )->get_error_code() );
		$many = array_map( static fn ( int $i ): string => home_url( '/p' . $i . '/' ), range( 1, 21 ) );
		self::assertSame( 'gtperf_invalid_url', $service->submit( 'preload_urls', array( 'urls' => $many ), $this->id() )->get_error_code() );
	}

	public function test_rate_and_outstanding_limits_hold_while_replays_stay_free(): void {
		global $wpdb;
		$service = new OperationService();
		$ids     = array();
		for ( $i = 0; $i < OperationService::MAX_PER_MINUTE; ++$i ) {
			$ids[] = $this->id();
			self::assertIsArray( $service->submit( 'preload_urls', array( 'urls' => array( home_url( '/r' . $i . '/' ) ) ), end( $ids ) ) );
		}
		self::assertSame( 'gtperf_rate_limited', $service->submit( 'preload_urls', array( 'urls' => array( home_url( '/over/' ) ) ), $this->id() )->get_error_code() );
		self::assertTrue( $service->submit( 'preload_urls', array( 'urls' => array( home_url( '/r0/' ) ) ), $ids[0] )['replayed'], 'A replay is not a new submission.' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}gtperf_operations SET created_at = %s", gmdate( 'Y-m-d H:i:s', time() - 120 ) ) );
		for ( $i = 0; $i < OperationService::MAX_OUTSTANDING - OperationService::MAX_PER_MINUTE; ++$i ) {
			$wpdb->insert( $wpdb->prefix . 'gtperf_operations', array( 'actor' => $this->admin, 'request_id' => $this->id(), 'payload_hash' => str_repeat( 'a', 64 ), 'operation' => 'preload_urls', 'status' => 'accepted', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		self::assertSame( 'gtperf_too_many_outstanding', $service->submit( 'preload_urls', array( 'urls' => array( home_url( '/over/' ) ) ), $this->id() )->get_error_code() );
	}

	public function test_revoked_access_cancels_unstarted_work(): void {
		$service = new OperationService();
		$byMode  = $service->submit( 'preload_urls', array( 'urls' => array( home_url( '/x/' ) ) ), $this->id() );
		$byRole  = $service->submit( 'preload_urls', array( 'urls' => array( home_url( '/y/' ) ) ), $this->id() );

		$this->mode( 'read' );
		$this->drain();
		self::assertSame( 'cancelled', ( new OperationRepository() )->find( $byMode['operation_id'] )['status'] );
		self::assertSame( 'permission_revoked', ( new OperationRepository() )->find( $byMode['operation_id'] )['result']['reason'] );
		self::assertSame( 0, ( new JobRepository() )->pendingCount( 'preload_url' ), 'Cancelled operations enqueue nothing.' );

		unset( $byRole );
	}

	public function test_a_demoted_requester_cancels_their_queued_work(): void {
		$service = new OperationService();
		$queued  = $service->submit( 'preload_urls', array( 'urls' => array( home_url( '/z/' ) ) ), $this->id() );
		( new \WP_User( $this->admin ) )->set_role( 'editor' );
		$this->drain();
		( new \WP_User( $this->admin ) )->set_role( 'administrator' );

		self::assertSame( 'cancelled', ( new OperationRepository() )->find( $queued['operation_id'] )['status'] );
	}

	public function test_purge_reports_origin_edge_and_public_result_per_url(): void {
		$cached = home_url( '/ops-cached/' );
		$hash   = ( new CacheInspector() )->inspect( $cached )['cache_hash'];
		( new FileStore() )->write( $hash, '<html></html>', array( 'url' => $cached, 'stored_at' => time(), 'fresh_until' => time() + 3600, 'stale_until' => time() + 7200 ) );

		$op = ( new OperationService() )->submit( 'purge_urls', array( 'urls' => array( $cached, home_url( '/ops-absent/' ) ) ), $this->id() );
		self::assertSame( 'accepted', $op['state'] );
		$this->drain();

		$row   = ( new OperationRepository() )->find( $op['operation_id'] );
		$items = array_column( $row['result']['items'], null, 'url' );
		self::assertSame( 'succeeded', $row['status'] );
		self::assertSame( 'deleted', $items[ $cached ]['origin'] );
		self::assertSame( 'not_cached', $items[ home_url( '/ops-absent/' ) ]['origin'] );
		self::assertSame( 'not_configured', $row['result']['edge']['status'] );
		self::assertSame( 'ok', $items[ $cached ]['public']['result'] );
		self::assertSame( 'MISS', $items[ $cached ]['public']['cf_cache_status'] );
		self::assertSame( 'missing', ( new CacheInspector() )->inspect( $cached )['origin']['state'] );
		self::assertContains( $cached, $this->requests, 'Each purged URL is requested publicly afterwards.' );
	}

	public function test_preload_queues_jobs_and_retry_accepts_only_safe_failed_types(): void {
		global $wpdb;
		$op = ( new OperationService() )->submit( 'preload_urls', array( 'urls' => array( home_url( '/pre/' ) ) ), $this->id() );
		$this->drain();
		$row = ( new OperationRepository() )->find( $op['operation_id'] );
		self::assertSame( 'succeeded', $row['status'] );
		self::assertGreaterThan( 0, $row['result']['jobs'][0]['job_id'] );

		$jobs   = new JobRepository();
		$purge  = $jobs->enqueue( 'purge_url', array( 'url' => home_url( '/failed-purge/' ) ), 10 );
		$warm   = $jobs->enqueue( 'preload_url', array( 'url' => home_url( '/failed-preload/' ) ), 50 );
		$wpdb->query( "UPDATE {$wpdb->prefix}gtperf_jobs SET status = 'failed', active_key = NULL WHERE id IN ({$purge}, {$warm})" );

		self::assertSame( 'gtperf_retry_not_allowed', ( new OperationService() )->submit( 'retry_job', array( 'job_id' => $purge ), $this->id() )->get_error_code() );
		$retry = ( new OperationService() )->submit( 'retry_job', array( 'job_id' => $warm ), $this->id() );
		$this->drain();
		self::assertTrue( ( new OperationRepository() )->find( $retry['operation_id'] )['result']['retried'] );
	}

	public function test_a_proposal_changes_nothing_until_an_administrator_applies_it(): void {
		$configuration = new ConfigurationService();
		$proposals     = new ProposalService();
		$before        = (int) Settings::get( 'cache.preload_max_urls' );

		self::assertSame( 'gtperf_stale_settings', $proposals->propose( array( 'cache.preload_max_urls' => 77 ), str_repeat( '0', 64 ), $this->id() )->get_error_code() );
		self::assertSame( 'gtperf_proposal_field', $proposals->propose( array( 'agents.mode' => 'operate' ), $configuration->currentHash(), $this->id() )->get_error_code() );

		$proposal = $proposals->propose( array( 'cache.preload_max_urls' => 77, 'javascript.defer' => true ), $configuration->currentHash(), $this->id() );
		self::assertSame( 'proposed', $proposal['state'] );
		self::assertSame( $before, (int) Settings::get( 'cache.preload_max_urls' ), 'Proposing changes nothing.' );
		self::assertSame( array( 'cache.preload_max_urls', 'javascript.defer' ), array_column( $proposal['result']['diff'], 'path' ) );

		$applied = $proposals->apply( $proposal['operation_id'] );
		self::assertTrue( $applied['applied'] );
		self::assertSame( 77, (int) Settings::get( 'cache.preload_max_urls' ) );
		self::assertSame( 'applied', ( new OperationRepository() )->find( $proposal['operation_id'] )['status'] );
		self::assertSame( 'proposal', ( new RevisionRepository() )->all()[0]['source'] );
		self::assertSame( 'gtperf_proposal_missing', $proposals->apply( $proposal['operation_id'] )->get_error_code(), 'A proposal applies once.' );
	}

	public function test_stale_and_expired_proposals_cannot_be_applied(): void {
		global $wpdb;
		$configuration = new ConfigurationService();
		$proposals     = new ProposalService();

		$stale    = $proposals->propose( array( 'media.critical_images' => 4 ), $configuration->currentHash(), $this->id() );
		$settings = Settings::all();
		$settings['cache']['entry_budget'] = 4999;
		Settings::save( $settings );
		self::assertSame( 'gtperf_stale_settings', $proposals->apply( $stale['operation_id'] )->get_error_code() );
		self::assertSame( 'expired', ( new OperationRepository() )->find( $stale['operation_id'] )['status'] );

		$old = $proposals->propose( array( 'media.critical_images' => 5 ), $configuration->currentHash(), $this->id() );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}gtperf_operations SET expires_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s', time() - 1 ), $old['operation_id'] ) );
		self::assertSame( 'gtperf_proposal_expired', $proposals->apply( $old['operation_id'] )->get_error_code() );
		self::assertNotSame( 5, (int) Settings::get( 'media.critical_images' ) );
	}

	public function test_operate_abilities_require_operate_mode_and_validate_through_core(): void {
		$purge = wp_get_ability( 'gt-performance/purge-urls' );
		self::assertNotNull( $purge );
		$this->mode( 'read' );
		self::assertSame( 'gtperf_agents_read_only', $purge->check_permissions( array() )->get_error_code() );
		$this->mode( 'operate' );

		self::assertSame( 'ability_invalid_input', $purge->execute( array( 'urls' => array( home_url( '/a/' ) ) ) )->get_error_code(), 'A request ID is required.' );
		$result = $purge->execute( array( 'urls' => array( home_url( '/a/' ) ), 'request_id' => $this->id() ) );
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$read = wp_get_ability( 'gt-performance/get-operation' )->execute( array( 'operation_id' => $result['data']['operation_id'] ) );
		self::assertSame( 'accepted', $read['data']['state'] );

		$propose = wp_get_ability( 'gt-performance/propose-settings' );
		self::assertSame( 'ability_invalid_input', $propose->execute( array( 'changes' => array( 'css.rollout_percent' => 30 ), 'expected_hash' => ( new ConfigurationService() )->currentHash(), 'request_id' => $this->id() ) )->get_error_code(), 'Rollout accepts only its real steps.' );
	}

	public function test_prune_keeps_active_and_open_work(): void {
		global $wpdb;
		$op = ( new OperationService() )->submit( 'preload_urls', array( 'urls' => array( home_url( '/keep/' ) ) ), $this->id() );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}gtperf_operations SET created_at = %s", gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );
		( new OperationRepository() )->prune();
		self::assertNotNull( ( new OperationRepository() )->find( $op['operation_id'] ), 'Accepted work is never pruned.' );
	}
}
