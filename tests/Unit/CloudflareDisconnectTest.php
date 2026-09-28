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
				// Another install in the same zone, under the pre-1.2.0 shared ref.
				array( 'id' => 'shop-rule', 'ref' => RuleCompiler::LEGACY_RULE_REF, 'expression' => '(http.host eq "shop.example.com") and (http.request.method in {"GET"})' ),
				array( 'id' => 'gt-rule', 'ref' => RuleCompiler::managedRef( 'example.com' ), 'expression' => '(http.host eq "example.com") and (http.request.method in {"GET"})' ),
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
		self::assertSame( 'removed', ( new RuleManager( $this->client() ) )->remove( 'zone-1', 'example.com' ) );
		self::assertSame( array( 'DELETE zones/zone-1/rulesets/ruleset-9/rules/gt-rule' ), $this->writes(), 'The owner\'s rule and another site\'s rule in the same zone must survive.' );
	}

	public function test_a_legacy_rule_naming_this_host_is_still_ours(): void {
		$this->ruleset['rules'][2] = array( 'id' => 'legacy-own', 'ref' => RuleCompiler::LEGACY_RULE_REF, 'expression' => '(http.host eq "example.com") and (http.request.method in {"GET"})' );

		self::assertSame( 'removed', ( new RuleManager( $this->client() ) )->remove( 'zone-1', 'example.com' ) );
		self::assertSame( array( 'DELETE zones/zone-1/rulesets/ruleset-9/rules/legacy-own' ), $this->writes() );
	}

	public function test_the_purge_on_disconnect_covers_only_this_sites_hostnames(): void {
		( new Disconnector() )->disconnect( false );
		$purge = end( $GLOBALS['gtperf_test_http_requests'] );

		self::assertSame( array( 'hosts' => array( 'example.com' ) ), json_decode( (string) $purge['args']['body'], true ), 'shop.example.com shares the zone and keeps its cache.' );
	}

	public function test_a_zone_without_the_managed_rule_is_left_alone(): void {
		$this->ruleset = null;
		self::assertSame( 'absent', ( new RuleManager( $this->client() ) )->remove( 'zone-1', 'example.com' ) );

		$this->ruleset = array( 'id' => 'ruleset-9', 'rules' => array( array( 'id' => 'owner-rule', 'ref' => 'site-owner-bypass' ) ) );
		self::assertSame( 'absent', ( new RuleManager( $this->client() ) )->remove( 'zone-1', 'example.com' ) );
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

	/** @return list<array{method:string,path:string,body:array<string,mixed>}> */
	private function writeBodies(): array {
		$calls = array();
		foreach ( $GLOBALS['gtperf_test_http_requests'] as $request ) {
			if ( 'GET' !== $request['args']['method'] ) {
				$calls[] = array(
					'method' => $request['args']['method'],
					'path'   => str_replace( 'https://api.cloudflare.com/client/v4/', '', $request['url'] ),
					'body'   => (array) json_decode( (string) ( $request['args']['body'] ?? '' ), true ),
				);
			}
		}
		return $calls;
	}

	public function test_a_site_sharing_the_zone_creates_its_own_rule_instead_of_taking_anothers(): void {
		unset( $this->ruleset['rules'][2] ); // Only shop.example.com's legacy rule is left.
		( new RuleManager( $this->client() ) )->sync( 'zone-1', 'example.com', Settings::defaults()['cache'] );
		$writes = $this->writeBodies();

		self::assertSame( 'POST', $writes[0]['method'], 'shop.example.com\'s rule is not patched.' );
		self::assertSame( 'zones/zone-1/rulesets/ruleset-9/rules', $writes[0]['path'] );
		self::assertSame( RuleCompiler::managedRef( 'example.com' ), $writes[0]['body']['ref'] );
		self::assertStringStartsWith( '(http.host eq "example.com")', $writes[0]['body']['expression'] );
	}

	public function test_an_adopted_legacy_rule_is_updated_in_place_and_keeps_its_ref(): void {
		$this->ruleset['rules'][2] = array( 'id' => 'legacy-own', 'ref' => RuleCompiler::LEGACY_RULE_REF, 'expression' => '(http.host eq "example.com") and (http.request.method in {"GET"})' );
		( new RuleManager( $this->client() ) )->sync( 'zone-1', 'example.com', Settings::defaults()['cache'] );
		$writes = $this->writeBodies();

		self::assertSame( 'PATCH', $writes[0]['method'] );
		self::assertSame( 'zones/zone-1/rulesets/ruleset-9/rules/legacy-own', $writes[0]['path'] );
		self::assertSame( RuleCompiler::LEGACY_RULE_REF, $writes[0]['body']['ref'], 'Cloudflare rejects a ref change (error 20142).' );
	}

	public function test_the_plan_neither_claims_nor_flags_a_subdomain_sites_rule(): void {
		unset( $this->ruleset['rules'][2] );
		$plan = ( new RuleCompiler() )->plan( 'example.com', Settings::defaults()['cache'], array_values( $this->ruleset['rules'] ) );

		self::assertFalse( $plan['managed_exists'] );
		self::assertSame( 'create', $plan['operation'] );
		self::assertNotContains( 'shop-rule', array_column( $plan['conflicts'], 'id' ), '"example.com" is a substring of "shop.example.com", not the same host.' );
	}

	public function test_a_sync_purges_this_sites_hostnames_and_reports_a_failed_purge(): void {
		$inner = $GLOBALS['gtperf_test_http_callback'];
		$GLOBALS['gtperf_test_http_callback'] = static function ( string $url, array $args ) use ( $inner ): array {
			return str_ends_with( $url, '/purge_cache' )
				? array( 'response' => array( 'code' => 429 ), 'body' => '{"success":false,"errors":[{"code":10000,"message":"Rate limited"}]}' )
				: $inner( $url, $args );
		};
		$result = ( new RuleManager( $this->client() ) )->sync( 'zone-1', 'example.com', Settings::defaults()['cache'] );
		$last   = end( $GLOBALS['gtperf_test_http_requests'] );

		self::assertIsArray( $result, 'The rule was written; a failed purge does not undo that.' );
		self::assertStringEndsWith( '/purge_cache', $last['url'] );
		self::assertSame( array( 'hosts' => array( 'example.com' ) ), json_decode( (string) $last['args']['body'], true ) );
		self::assertStringContainsString( 'Rate limited', $result['gtperf_purge'] );
	}

	public function test_deactivation_empties_the_local_page_store(): void {
		$store = new \GTPerformance\Cache\FileStore();
		$hash  = hash( 'sha256', 'deactivation-fixture' );
		$store->write( $hash, '<html>old</html>', array( 'stored_at' => time(), 'fresh_until' => time() + 3600, 'stale_until' => time() + 7200, 'url' => 'https://example.com/old/', 'generation' => 1 ) );
		self::assertFileExists( $store->pagePath( $hash ) );

		Deactivator::deactivate();

		self::assertFileDoesNotExist( $store->pagePath( $hash ), 'Pages edited while the plugin is off must not come back on reactivation.' );
	}
}
