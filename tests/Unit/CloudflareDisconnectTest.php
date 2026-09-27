<?php
/**
 * Removing GT Performance's footprint from a Cloudflare zone.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\CLI\Command;
use GTPerformance\Cloudflare\ApiClient;
use GTPerformance\Cloudflare\ApiCredentials;
use GTPerformance\Cloudflare\Disconnector;
use GTPerformance\Cloudflare\RuleCompiler;
use GTPerformance\Cloudflare\RuleManager;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Core\Deactivator;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class CloudflareDisconnectTest extends TestCase {
	/** @var array<string, mixed>|null Entrypoint ruleset, or null for a zone that never had one. */
	private ?array $ruleset;

	protected function setUp(): void {
		$settings                          = Settings::defaults();
		$settings['cloudflare']['enabled'] = true;
		$settings['cloudflare']['zone_id'] = 'zone-1';
		$settings['cloudflare']['api_token'] = ( new TokenCipher() )->encrypt( 'test-token' );
		$GLOBALS['gtperf_test_options']    = array( Settings::OPTION => $settings );
		$GLOBALS['gtperf_test_http_requests'] = array();
		\WP_CLI::$successes = array();
		\WP_CLI::$warnings  = array();

		$this->ruleset = array(
			'id'    => 'ruleset-9',
			'rules' => array(
				array( 'id' => 'owner-rule', 'ref' => 'site-owner-bypass', 'expression' => 'true' ),
				array( 'id' => 'gt-rule', 'ref' => RuleCompiler::MANAGED_RULE_REF, 'expression' => 'true' ),
			),
		);
		$GLOBALS['gtperf_test_http_callback'] = function ( string $url, array $args ): array {
			if ( 'GET' === $args['method'] && str_ends_with( $url, '/entrypoint' ) ) {
				return null === $this->ruleset
					? array( 'response' => array( 'code' => 404 ), 'body' => '{"success":false,"errors":[{"code":10003,"message":"not found"}]}' )
					: array( 'response' => array( 'code' => 200 ), 'body' => (string) json_encode( array( 'success' => true, 'result' => $this->ruleset ) ) );
			}
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true,"result":{}}' );
		};
	}

	protected function tearDown(): void {
		foreach ( array( 'options', 'http_callback', 'http_requests' ) as $key ) {
			unset( $GLOBALS[ 'gtperf_test_' . $key ] );
		}
	}

	/** @return list<string> Method and path of each Cloudflare call after the first read. */
	private function writes(): array {
		$calls = array();
		foreach ( $GLOBALS['gtperf_test_http_requests'] as $request ) {
			if ( 'GET' !== $request['args']['method'] ) {
				$calls[] = $request['args']['method'] . ' ' . str_replace( 'https://api.cloudflare.com/client/v4/', '', $request['url'] );
			}
		}
		return $calls;
	}

	private function client(): ApiClient {
		return new ApiClient( ApiCredentials::apiToken( 'test-token' ) );
	}

	public function test_only_the_managed_rule_is_deleted(): void {
		self::assertSame( 'removed', ( new RuleManager( $this->client() ) )->remove( 'zone-1' ) );
		self::assertSame( array( 'DELETE zones/zone-1/rulesets/ruleset-9/rules/gt-rule' ), $this->writes(), 'The site owner\'s own rule must survive.' );
	}

	public function test_a_zone_without_the_managed_rule_is_left_alone(): void {
		$this->ruleset = null;
		self::assertSame( 'absent', ( new RuleManager( $this->client() ) )->remove( 'zone-1' ) );

		$this->ruleset = array( 'id' => 'ruleset-9', 'rules' => array( array( 'id' => 'owner-rule', 'ref' => 'site-owner-bypass' ) ) );
		self::assertSame( 'absent', ( new RuleManager( $this->client() ) )->remove( 'zone-1' ) );
		self::assertSame( array(), $this->writes() );
	}

	public function test_disconnect_deletes_purges_and_turns_the_integration_off(): void {
		$result = ( new Disconnector() )->disconnect( false );

		self::assertSame( array( 'rule' => 'removed', 'purged' => true ), $result );
		self::assertSame(
			array( 'DELETE zones/zone-1/rulesets/ruleset-9/rules/gt-rule', 'POST zones/zone-1/purge_cache' ),
			$this->writes(),
			'The rule goes first, then everything it stored.'
		);
		$saved = Settings::all()['cloudflare'];
		self::assertFalse( $saved['enabled'] );
		self::assertNotSame( '', $saved['api_token'], 'Credentials stay unless asked, so reconnecting is one sync.' );
		self::assertSame( 'zone-1', $saved['zone_id'] );
	}

	public function test_forget_also_deletes_the_credentials(): void {
		( new Command() )->cloudflare( array( 'disconnect' ), array( 'forget' => true ) );

		$saved = Settings::all()['cloudflare'];
		self::assertSame( array( '', '', '' ), array( $saved['api_token'], $saved['zone_id'], $saved['email'] ) );
		self::assertStringContainsString( 'managed cache rule was deleted', \WP_CLI::$successes[0] );
	}

	public function test_forget_is_refused_by_other_actions(): void {
		$this->expectExceptionMessage( '--forget is supported only by cloudflare disconnect.' );
		( new Command() )->cloudflare( array( 'purge' ), array( 'forget' => true ) );
	}

	public function test_deactivation_removes_the_rule_but_keeps_the_integration_for_reactivation(): void {
		Deactivator::deactivate();

		self::assertContains( 'DELETE zones/zone-1/rulesets/ruleset-9/rules/gt-rule', $this->writes() );
		self::assertContains( 'POST zones/zone-1/purge_cache', $this->writes() );
		self::assertGreaterThan( 0, (int) get_option( RuleManager::REMOVED_OPTION, 0 ), 'The Cloudflare tab must say the rule is gone.' );
		self::assertTrue( Settings::all()['cloudflare']['enabled'] );
	}

	public function test_deactivation_survives_an_unreachable_zone(): void {
		$GLOBALS['gtperf_test_http_callback'] = static fn (): \WP_Error => new \WP_Error( 'http_request_failed', 'Could not resolve host' );

		Deactivator::deactivate();

		self::assertFalse( get_option( RuleManager::REMOVED_OPTION, false ) );
	}

	public function test_a_successful_sync_clears_the_removed_notice(): void {
		update_option( RuleManager::REMOVED_OPTION, 1700000000, false );
		$result = ( new RuleManager( $this->client() ) )->sync( 'zone-1', 'example.com', Settings::defaults()['cache'] );

		self::assertIsArray( $result );
		self::assertFalse( get_option( RuleManager::REMOVED_OPTION, false ) );
	}
}
