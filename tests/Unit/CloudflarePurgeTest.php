<?php
/**
 * Edge purge ordering, payloads, failures and bounded retry regressions.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\Purger;
use GTPerformance\CLI\Command;
use GTPerformance\Cloudflare\ApiClient;
use GTPerformance\Cloudflare\ApiCredentials;
use GTPerformance\Cloudflare\CloudflareModule;
use GTPerformance\Cloudflare\RuleCompiler;
use GTPerformance\Cloudflare\RuleExpression;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\PurgeReceiptRepository;
use GTPerformance\Diagnostics\PurgeVerifier;
use PHPUnit\Framework\TestCase;

final class CloudflarePurgeTest extends TestCase {
	private CloudflareModule $module;

	protected function setUp(): void {
		$this->tearDown();
		$settings = Settings::defaults();
		$settings['cloudflare']['enabled'] = true;
		$settings['cloudflare']['zone_id'] = 'test-zone';
		$settings['cloudflare']['api_token'] = ( new TokenCipher() )->encrypt( 'test-token' );
		$settings['debug'] = false;
		$GLOBALS['gtperf_test_options'] = array( Settings::OPTION => $settings );
		$GLOBALS['gtperf_test_http_requests'] = array();
		$GLOBALS['gtperf_test_http_response'] = $this->response();
		$this->module = new CloudflareModule( new Logger() );
		$this->module->register();
		$GLOBALS['gtperf_test_action_handlers'] = $GLOBALS['gtperf_test_registered_actions'];
		\WP_CLI::$successes = array();
	}

	protected function tearDown(): void {
		foreach ( array( 'options', 'filters', 'action_handlers', 'registered_actions', 'http_callback', 'http_requests', 'http_response', 'cron', 'cron_failure' ) as $key ) {
			unset( $GLOBALS[ 'gtperf_test_' . $key ] );
		}
	}

	private function response( int $status = 200, string $retryAfter = '' ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers' => array( 'retry-after' => $retryAfter ),
			'body' => json_encode( array( 'success' => 200 === $status, 'errors' => 200 === $status ? array() : array( array( 'message' => 'Purge rejected' ) ) ) ),
		);
	}

	private function jobs(): array {
		return array_values( $GLOBALS['gtperf_test_cron'][ CloudflareModule::RETRY_HOOK ] ?? array() );
	}

	private function receipt(): array {
		return get_option( CloudflareModule::STATUS_OPTION, array() );
	}

	public function test_rule_matches_internal_purges_without_allowing_post_requests(): void {
		$policy = array( 'bypass_paths' => array( '/checkout/' ), 'bypass_cookies' => array( 'private_session' ) );
		$rule = ( new RuleCompiler() )->rule( 'example.com', $policy, 86400 );
		self::assertStringContainsString( '{"GET" "HEAD" "PURGE"}', $rule['expression'] );
		self::assertStringContainsString( 'http.request.uri.query eq ""', $rule['expression'] );
		foreach ( array( 'GET' => true, 'HEAD' => true, 'PURGE' => true, 'POST' => false, 'DELETE' => false ) as $method => $expected ) {
			$request = new \GTPerformance\Cache\RequestContext( $method, 'https', 'example.com', '/page/', array(), array(), array(), '' );
			self::assertSame( $expected, ( new RuleExpression() )->matches( $request, 'example.com', $policy, true ) );
		}
	}

	public function test_ordinary_urls_batch_until_shutdown_and_deduplicate(): void {
		$purger = new Purger();
		$purger->purgeUrls( array( 'https://example.com/a/', 'https://example.com/a/' ) );
		$purger->purgeUrl( 'https://example.com/b/' );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
		do_action( 'shutdown' );
		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		$body = json_decode( $GLOBALS['gtperf_test_http_requests'][0]['args']['body'], true );
		self::assertSame( array( 'https://example.com/a/', 'https://example.com/b/' ), $body['files'] );
		self::assertSame( 'accepted', $this->receipt()['status'] );
	}

	public function test_manual_flush_sends_once_and_shutdown_does_not_repeat(): void {
		$purger = new Purger();
		$purger->purgeUrl( 'https://example.com/page/' );
		self::assertTrue( $purger->flushEdge() );
		do_action( 'shutdown' );
		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
	}

	public function test_verifier_purges_before_fetching_any_page(): void {
		$GLOBALS['gtperf_test_http_callback'] = function ( string $url, array $args ): array {
			if ( str_contains( $url, 'api.cloudflare.com' ) ) {
				return $this->response();
			}
			self::assertSame( 'POST', $GLOBALS['gtperf_test_http_requests'][0]['args']['method'] );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'cf-cache-status' => 'MISS' ), 'body' => '<html>Public page</html>' );
		};
		$receipt = ( new PurgeVerifier() )->verify( 'https://example.com/page/' );
		self::assertIsArray( $receipt );
		self::assertSame( 'verified', $receipt['status'] );
		self::assertCount( 3, $GLOBALS['gtperf_test_http_requests'] );
		do_action( 'shutdown' );
		self::assertCount( 3, $GLOBALS['gtperf_test_http_requests'] );
	}

	public function test_verifier_does_not_claim_success_or_fetch_after_api_failure(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->response( 403 );
		$result = ( new PurgeVerifier() )->verify( 'https://example.com/page/' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		self::assertSame( 'failed', ( new PurgeReceiptRepository() )->recent()[0]['status'] );
		self::assertSame( array(), $this->jobs() );
		self::assertSame( 'failed', $this->receipt()['status'] );
	}

	public function test_stable_http_errors_are_not_verified(): void {
		$GLOBALS['gtperf_test_http_callback'] = fn( string $url ): array => str_contains( $url, 'api.cloudflare.com' ) ? $this->response() : array( 'response' => array( 'code' => 503 ), 'body' => 'Unavailable' );
		$result = ( new PurgeVerifier() )->verify( 'https://example.com/page/' );
		self::assertSame( 'warning', $result['status'] );
	}

	public function test_device_entries_are_counted_when_chunking_and_include_all_variants(): void {
		$urls = array_map( static fn( int $i ): string => 'https://example.com/' . $i . '/', range( 1, 26 ) );
		$client = new ApiClient( ApiCredentials::apiToken( 'fake' ) );
		self::assertTrue( $client->purgeUrls( 'test-zone', array_merge( $urls, array( $urls[0] ) ), true ) );
		$bodies = array_map( static fn( array $request ): array => json_decode( $request['args']['body'], true )['files'], $GLOBALS['gtperf_test_http_requests'] );
		self::assertSame( array( 100, 4 ), array_map( 'count', $bodies ) );
		self::assertSame( $urls[0], $bodies[0][0] );
		foreach ( array( 'desktop', 'mobile', 'tablet' ) as $i => $device ) {
			self::assertSame( array( 'url' => $urls[0], 'headers' => array( 'CF-Device-Type' => $device ) ), $bodies[0][ $i + 1 ] );
		}
	}

	public function test_partial_failure_retries_only_unfinished_entries_and_honors_retry_after(): void {
		$urls = array_map( static fn( int $i ): string => 'https://example.com/' . $i . '/', range( 1, 201 ) );
		$GLOBALS['gtperf_test_http_callback'] = fn(): array => count( $GLOBALS['gtperf_test_http_requests'] ) === 1 ? $this->response() : $this->response( 429, '180' );
		$start = time();
		$this->module->purgeUrls( $urls );
		$jobs = $this->jobs();
		self::assertCount( 2, $jobs );
		self::assertGreaterThanOrEqual( $start + 180, $jobs[0]['when'] );
		self::assertSame( array_slice( $urls, 100, 100 ), $jobs[0]['args'][1] );
		self::assertSame( array_slice( $urls, 200 ), $jobs[1]['args'][1] );
		self::assertSame( 'retrying', $this->receipt()['status'] );
		unset( $GLOBALS['gtperf_test_http_callback'] );
		$this->module->retry( ...$jobs[0]['args'] );
		$last = end( $GLOBALS['gtperf_test_http_requests'] );
		self::assertSame( array_slice( $urls, 100, 100 ), json_decode( $last['args']['body'], true )['files'] );
	}

	public function test_partial_device_batch_preserves_headers_for_retry(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cache']['separate_mobile'] = true;
		$GLOBALS['gtperf_test_http_response'] = $this->response( 503 );
		$this->module->purgeUrls( array( 'https://example.com/' ) );
		self::assertSame( 'tablet', $this->jobs()[0]['args'][1][3]['headers']['CF-Device-Type'] );
	}

	public function test_transport_failures_retry_but_attempts_are_bounded(): void {
		$GLOBALS['gtperf_test_http_response'] = new \WP_Error( 'http_request_failed', 'Connection timed out' );
		$this->module->purgeUrls( array( 'https://example.com/' ) );
		self::assertCount( 1, $this->jobs() );
		$GLOBALS['gtperf_test_cron'] = array();
		$this->module->retry( 'test-zone', array( 'https://example.com/' ), 3 );
		self::assertSame( array(), $this->jobs() );
		self::assertSame( 'failed', $this->receipt()['status'] );
	}

	public function test_duplicate_failures_do_not_duplicate_cron_jobs(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->response( 500 );
		$this->module->purgeUrls( array( 'https://example.com/' ) );
		$this->module->purgeUrls( array( 'https://example.com/' ) );
		self::assertCount( 1, $this->jobs() );
	}

	public function test_cron_storage_failure_is_reported_without_claiming_a_retry(): void {
		$GLOBALS['gtperf_test_cron_failure'] = true;
		$GLOBALS['gtperf_test_http_response'] = $this->response( 429 );
		$this->module->purgeUrls( array( 'https://example.com/' ) );
		self::assertSame( 'failed', $this->receipt()['status'] );
		self::assertSame( 0, $this->receipt()['next_retry'] );
	}

	public function test_retries_stop_when_integration_is_disabled_or_zone_changes(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cloudflare']['enabled'] = false;
		$this->module->retry( 'test-zone', null, 1 );
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cloudflare']['enabled'] = true;
		$this->module->retry( 'old-zone', null, 1 );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}

	public function test_confirmed_full_purge_supersedes_queued_urls_and_retries(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->response( 500 );
		$this->module->purgeUrls( array( 'https://example.com/' ) );
		$this->module->queueUrls( array( 'https://example.com/another/' ) );
		$GLOBALS['gtperf_test_http_response'] = $this->response();
		$this->module->purgeEverything();
		self::assertTrue( ( new Purger() )->flushEdge() );
		self::assertSame( array(), $this->jobs() );
		self::assertCount( 2, $GLOBALS['gtperf_test_http_requests'] );
		self::assertSame( array( 'hosts' => array( 'example.com' ) ), json_decode( $GLOBALS['gtperf_test_http_requests'][1]['args']['body'], true ), 'A full purge covers this site\'s hostnames, not the whole zone.' );
	}

	public function test_full_purge_failure_retries_the_full_operation(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->response( 503 );
		$this->module->purgeEverything();
		self::assertNull( $this->jobs()[0]['args'][1] );
		self::assertInstanceOf( \WP_Error::class, ( new Purger() )->flushEdge() );
	}

	public function test_missing_configuration_is_reported_even_without_debug_logging(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cloudflare']['zone_id'] = '';
		$this->module->purgeEverything();
		self::assertSame( 'gtperf_cloudflare_zone', $this->receipt()['error_code'] );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
		self::assertInstanceOf( \WP_Error::class, ( new Purger() )->flushEdge() );
	}

	public function test_cache_cli_propagates_cloudflare_failure(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->response( 403 );
		try {
			( new Command() )->cache( array( 'purge' ), array( 'page-url' => 'https://example.com/' ) );
			self::fail( 'CLI must fail on an incomplete edge purge.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'Local page cache cleared; Cloudflare purge failed:', $exception->getMessage() );
		}
		self::assertSame( array(), \WP_CLI::$successes );
	}

	public function test_sync_updates_only_the_managed_rule_and_keeps_safety_guards(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cloudflare']['edge_ttl'] = 86400;
		$GLOBALS['gtperf_test_http_callback'] = function ( string $url, array $args ): array {
			if ( 'GET' === $args['method'] ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'success' => true, 'result' => array(
					'id' => 'ruleset',
					'rules' => array(
						array( 'id' => 'unrelated', 'ref' => 'commerce-safety', 'action' => 'set_cache_settings', 'enabled' => true, 'expression' => 'true', 'action_parameters' => array( 'cache' => false ) ),
						array( 'id' => 'managed', 'ref' => RuleCompiler::managedRef( 'example.com' ), 'expression' => '(http.request.method in {"GET" "HEAD"})' ),
					),
				) ) ) );
			}
			self::assertSame( 'PATCH', $args['method'] );
			self::assertStringEndsWith( '/rulesets/ruleset/rules/managed', $url );
			$rule = json_decode( $args['body'], true );
			self::assertStringContainsString( '"PURGE"', $rule['expression'] );
			self::assertStringContainsString( 'http.request.uri.query eq ""', $rule['expression'] );
			self::assertStringContainsString( '"/checkout"', $rule['expression'] );
			self::assertStringContainsString( '"fct_cart_hash"', $rule['expression'] );
			return $this->response();
		};
		$manager = new \GTPerformance\Cloudflare\RuleManager( new ApiClient( ApiCredentials::apiToken( 'fake' ) ) );
		self::assertIsArray( $manager->sync( 'test-zone', 'example.com', array( 'bypass_paths' => array( '/checkout/' ), 'bypass_cookies' => array( 'fct_cart_hash' ) ) ) );
		self::assertCount( 2, $GLOBALS['gtperf_test_http_requests'] );
	}

	public function test_disabled_and_alternate_edge_ownership_do_not_call_cloudflare(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cloudflare']['enabled'] = false;
		$this->module->purgeEverything();
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cloudflare']['enabled'] = true;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['xcloud']['enabled'] = true;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['xcloud']['enterprise_available'] = true;
		$this->module->purgeUrls( array( 'https://example.com/' ) );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}

	public function test_missing_credentials_are_reported_and_receipt_messages_are_redacted(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cloudflare']['api_token'] = '';
		$this->module->purgeEverything();
		self::assertSame( 'gtperf_cloudflare_token', $this->receipt()['error_code'] );
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cloudflare']['api_token'] = ( new TokenCipher() )->encrypt( 'fake' );
		$GLOBALS['gtperf_test_http_response'] = new \WP_Error( 'http_request_failed', 'Failed https://user:secret@example.com/private?token=secret Bearer secret' );
		$this->module->purgeEverything();
		self::assertStringNotContainsString( 'secret', $this->receipt()['message'] );
	}

	public function test_direct_cloudflare_cli_uses_device_variants(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cache']['separate_mobile'] = true;
		( new Command() )->cloudflare( array( 'purge' ), array( 'page-url' => 'https://example.com/' ) );
		$body = json_decode( $GLOBALS['gtperf_test_http_requests'][0]['args']['body'], true );
		self::assertCount( 4, $body['files'] );
		self::assertSame( 'tablet', $body['files'][3]['headers']['CF-Device-Type'] );
	}
}
