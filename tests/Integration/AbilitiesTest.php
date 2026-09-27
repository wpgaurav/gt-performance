<?php
/** GT Performance abilities through the real WordPress Abilities API and its REST routes. */
declare(strict_types=1);
namespace GTPerformance\Tests\Integration;

use GTPerformance\Core\Database;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class AbilitiesTest extends TestCase {
	/** @var array<string, int> */
	private static array $users = array();

	private static mixed $savedSettings;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			self::markTestSkipped( 'The fixture WordPress has no Abilities API.' );
		}
		Database::install();
		self::$savedSettings = get_option( Settings::OPTION );
		self::mode( 'read' );
		foreach ( array( 'administrator', 'editor', 'subscriber' ) as $role ) {
			$login = 'gtperf_' . $role;
			$user  = get_user_by( 'login', $login );
			self::$users[ $role ] = $user ? (int) $user->ID : (int) wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => wp_generate_password( 24 ),
					'user_email' => $login . '@example.test',
					'role'       => $role,
				)
			);
		}
	}

	public static function tearDownAfterClass(): void {
		update_option( Settings::OPTION, self::$savedSettings );
		wp_set_current_user( 0 );
	}

	protected function setUp(): void {
		self::mode( 'read' );
		wp_set_current_user( self::$users['administrator'] );
	}

	private static function mode( string $mode ): void {
		$settings                   = Settings::all();
		$settings['agents']['mode'] = $mode;
		update_option( Settings::OPTION, $settings );
	}

	/** @return mixed */
	private function call( string $name, mixed $input = null ) {
		$ability = wp_get_ability( 'gt-performance/' . $name );
		self::assertNotNull( $ability, $name . ' is registered' );
		return $ability->execute( $input );
	}

	public function test_all_read_abilities_register_in_their_category_and_pass_output_validation(): void {
		$names = array_map( static fn ( $a ) => $a->get_name(), wp_get_abilities() );
		foreach ( array( 'get-status', 'explain-url', 'get-health', 'list-jobs', 'get-css-report', 'get-settings', 'list-purge-receipts' ) as $name ) {
			self::assertContains( 'gt-performance/' . $name, $names );
			self::assertSame( 'gt-performance', wp_get_ability( 'gt-performance/' . $name )->get_category() );
		}

		$inputs = array(
			'get-status'          => null,
			'get-health'          => array(),
			'list-jobs'           => array( 'limit' => 5 ),
			'get-settings'        => array( 'section' => 'cache' ),
			'list-purge-receipts' => null,
			'explain-url'         => array( 'url' => home_url( '/sample-page/' ) ),
			'get-css-report'      => array( 'url' => home_url( '/sample-page/' ) ),
		);
		foreach ( $inputs as $name => $input ) {
			$result = $this->call( $name, $input );
			self::assertIsArray( $result, $name . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			self::assertSame( 1, $result['schema_version'] );
			self::assertSame( get_current_blog_id(), $result['site_id'] );
			self::assertIsArray( $result['data'] );
		}
	}

	public function test_only_administrators_may_call_and_off_denies_even_a_known_ability(): void {
		// execute() replaces the callback's reason with a generic code so it cannot leak;
		// REST and the MCP Adapter surface check_permissions() to the caller instead.
		$ability = wp_get_ability( 'gt-performance/get-status' );
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			wp_set_current_user( self::$users[ $role ] );
			self::assertSame( 'gtperf_forbidden', $ability->check_permissions()->get_error_code(), $role );
			self::assertSame( 'ability_invalid_permissions', @$this->call( 'get-status' )->get_error_code(), $role ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Core reports the hidden reason with _doing_it_wrong().
		}
		wp_set_current_user( 0 );
		self::assertSame( 'gtperf_forbidden', wp_get_ability( 'gt-performance/get-health' )->check_permissions()->get_error_code() );

		wp_set_current_user( self::$users['administrator'] );
		self::mode( 'off' );
		self::assertSame( 'gtperf_agents_disabled', $ability->check_permissions()->get_error_code() );
		self::assertSame( 'ability_invalid_permissions', @$this->call( 'get-status' )->get_error_code() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		self::mode( 'read' );
		self::assertIsArray( $this->call( 'get-status' ) );
	}

	public function test_inputs_are_closed_bounded_and_same_site(): void {
		self::assertSame( 'ability_invalid_input', $this->call( 'list-jobs', array( 'limit' => 101 ) )->get_error_code() );
		self::assertSame( 'ability_invalid_input', $this->call( 'get-status', array( 'purge' => true ) )->get_error_code() );
		self::assertSame( 'ability_invalid_input', $this->call( 'explain-url', array() )->get_error_code() );
		self::assertSame( 'gtperf_diagnostic_url', $this->call( 'explain-url', array( 'url' => 'https://evil.test/' ) )->get_error_code() );
		self::assertSame( 'gtperf_invalid_url', $this->call( 'get-css-report', array( 'url' => 'https://evil.test/' ) )->get_error_code() );
	}

	public function test_settings_exclude_secrets_and_hash_tracks_saves(): void {
		$settings                              = Settings::all();
		$settings['cloudflare']['api_token']   = 'integration-secret-token';
		$settings['redis']['password']         = 'integration-redis-password';
		update_option( Settings::OPTION, $settings );

		$result = $this->call( 'get-settings' );
		$json   = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( 'integration-secret-token', $json );
		self::assertStringNotContainsString( 'integration-redis-password', $json );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['settings_hash'] );

		$settings['generation'] = (int) $settings['generation'] + 1;
		update_option( Settings::OPTION, $settings );
		self::assertNotSame( $result['settings_hash'], $this->call( 'get-settings' )['settings_hash'] );
	}

	public function test_list_jobs_pages_newest_first_without_payloads(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}gtperf_jobs" );
		$jobs = new \GTPerformance\Queue\JobRepository();
		$ids  = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$ids[] = $jobs->enqueue( 'preload_url', array( 'url' => home_url( '/page-' . $i . '/?token=secret' ) ) );
		}

		$first = $this->call( 'list-jobs', array( 'limit' => 3 ) )['data'];
		self::assertSame( array_reverse( array_slice( $ids, 2 ) ), array_column( $first['items'], 'id' ) );
		self::assertSame( $ids[2], $first['next_cursor'] );
		self::assertArrayNotHasKey( 'payload', $first['items'][0] );
		self::assertStringNotContainsString( 'token=secret', (string) wp_json_encode( $first ) );

		$second = $this->call( 'list-jobs', array( 'limit' => 3, 'cursor' => $first['next_cursor'] ) )['data'];
		self::assertSame( array( $ids[1], $ids[0] ), array_column( $second['items'], 'id' ) );
		self::assertNull( $second['next_cursor'] );
		self::assertSame( array(), $this->call( 'list-jobs', array( 'type' => 'generate_css' ) )['data']['items'] );
	}

	public function test_core_rest_route_runs_read_abilities_for_administrators_only(): void {
		$request  = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/gt-performance/get-status/run' );
		$response = rest_do_request( $request );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 1, $response->get_data()['schema_version'] );

		wp_set_current_user( self::$users['subscriber'] );
		self::assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
		wp_set_current_user( 0 );
		self::assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
	}
}
