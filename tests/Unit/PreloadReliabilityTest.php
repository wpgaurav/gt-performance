<?php
/**
 * Preloads avoid duplicate work and preserve transport evidence.
 *
 * @package GTPerformance
 */

declare(strict_types=1);
namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\Preloader;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;
final class PreloadReliabilityTest extends TestCase {
	private string $hash = '';
	private string $url = 'https://example.com/gtperf-preload-reliability/';
	protected function setUp(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = Settings::defaults();
		$GLOBALS['gtperf_test_http_requests'] = array();
		$policy = Settings::get( 'cache' );
		$policy['generation'] = Settings::get( 'generation', 1 );
		$this->hash = ( new CacheKey() )->hash( ( new CacheKey() )->make( RequestContext::fromUrl( $this->url ), $policy ) );
		( new FileStore() )->delete( $this->hash );
	}
	protected function tearDown(): void {
		( new FileStore() )->delete( $this->hash );
		unset( $GLOBALS['gtperf_test_options'][ Settings::OPTION ], $GLOBALS['gtperf_test_http_requests'], $GLOBALS['gtperf_test_http_response'] );
	}
	public function test_fresh_origin_returns_without_network_even_near_worker_deadline(): void {
		self::assertTrue(
			( new FileStore() )->write(
				$this->hash,
				'<html>Fixture</html>',
				array(
					'stored_at' => time() - 60,
					'fresh_until' => time() + 3600,
					'stale_until' => time() + 7200,
				)
			)
		);
		$outcome = ( new Preloader() )->preload( $this->url, 'public', 0.1 );
		self::assertSame(
			array(
				'status' => 'origin_ready',
				'detail' => 'existing',
				'http' => 0,
			),
			$outcome
		);
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}
	public function test_cold_page_waits_for_full_request_budget_instead_of_failing_a_shortened_timeout(): void {
		self::assertSame( 'deferred', ( new Preloader() )->preload( $this->url, 'public', 14.9 )['status'] );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
		$GLOBALS['gtperf_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers' => array(),
			'body' => '<html>Fixture</html>',
		);
		( new Preloader() )->preload( $this->url, 'public', 15.0 );
		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		self::assertSame( 15, $GLOBALS['gtperf_test_http_requests'][0]['args']['timeout'] );
	}
	public function test_transport_evidence_is_preserved_without_credentials(): void {
		$GLOBALS['gtperf_test_http_response'] = new \WP_Error( 'http_request_failed', 'cURL error 28 at https://user:password@example.com/private?token=secret Bearer hidden' );
		$outcome = ( new Preloader() )->preload( $this->url );
		self::assertSame( 'request_error', $outcome['detail'] );
		self::assertSame( 'http_request_failed', $outcome['error_code'] );
		self::assertStringContainsString( 'cURL error 28', $outcome['error_message'] );
		foreach ( array( 'password', 'secret', 'hidden' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $outcome['error_message'] );
		}
	}
}
