<?php
/**
 * First-run setup: host cache detection, the suggested mode, and verification.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\Setup\SetupReport;
use PHPUnit\Framework\TestCase;

final class SetupReportTest extends TestCase {
	/** @var list<array{status:int,headers:array<string,string>}> Responses the home page returns, in order. */
	private array $responses = array();

	protected function setUp(): void {
		$GLOBALS['gtperf_test_options']       = array( Settings::OPTION => Settings::defaults() );
		$GLOBALS['gtperf_test_http_requests'] = array();
		$GLOBALS['gtperf_test_http_callback'] = function (): array {
			$next = count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];

			return array(
				'response' => array( 'code' => $next['status'] ),
				'headers'  => $next['headers'],
				'body'     => '<!doctype html><html><body>home</body></html>',
			);
		};
	}

	protected function tearDown(): void {
		foreach ( array( 'options', 'http_callback', 'http_requests' ) as $key ) {
			unset( $GLOBALS[ 'gtperf_test_' . $key ] );
		}
	}

	private function set( string $path, mixed $value ): void {
		$parts = explode( '.', $path );
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ][ $parts[0] ][ $parts[1] ] = $value;
	}

	public function test_host_caches_are_named_from_response_headers(): void {
		self::assertSame( array(), SetupReport::hostCachesFromHeaders( array( 'content-type' => 'text/html', 'cf-cache-status' => 'DYNAMIC' ) ), 'Cloudflare is not a host page cache.' );
		self::assertSame( array( 'LiteSpeed Cache' ), SetupReport::hostCachesFromHeaders( array( 'X-LiteSpeed-Cache' => 'miss' ) ), 'A MISS still proves the cache is there.' );
		self::assertSame( array( 'Hostinger' ), SetupReport::hostCachesFromHeaders( array( 'platform' => 'hostinger' ) ) );
		self::assertSame(
			array( 'Hostinger Site Optimizer' ),
			SetupReport::hostCachesFromHeaders( array( 'server' => 'cloudflare', 'x-site-optimizer' => '4.0', 'x-cache-status' => 'STALE', 'x-turbo-charged-by' => 'LiteSpeed' ) ),
			'The headers gatilab.com returns through Hostinger\'s layer.'
		);
		self::assertSame( array( 'WP Engine' ), SetupReport::hostCachesFromHeaders( array( 'x-cacheable' => 'SHORT', 'x-wpe-cached' => 'HIT' ) ) );
	}

	public function test_a_host_cache_suggests_optimize_only(): void {
		self::assertSame( 'store', SetupReport::recommendedMode( array() ) );
		self::assertSame( 'optimize', SetupReport::recommendedMode( array( 'Kinsta' ) ) );
	}

	public function test_probe_records_what_answered(): void {
		$this->responses = array( array( 'status' => 200, 'headers' => array( 'x-kinsta-cache' => 'MISS' ) ) );
		$probe           = ( new SetupReport() )->probe();

		self::assertSame( array( 'Kinsta' ), $probe['host_caches'] );
		self::assertSame( $probe, get_option( SetupReport::PROBE_OPTION ) );
		self::assertArrayNotHasKey( 'cookies', array_filter( $GLOBALS['gtperf_test_http_requests'][0]['args'] ), 'A visitor carries no cookies.' );
	}

	public function test_store_mode_passes_once_the_origin_answers_hit_and_completes_setup(): void {
		$this->responses = array(
			array( 'status' => 200, 'headers' => array( 'x-gt-cache' => 'MISS' ) ),
			array( 'status' => 200, 'headers' => array( 'x-gt-cache' => 'HIT' ) ),
		);
		$result = ( new SetupReport( 0 ) )->verify();

		self::assertTrue( $result['passed'] );
		self::assertSame( 'HIT', $result['origin'] );
		self::assertCount( 2, $GLOBALS['gtperf_test_http_requests'], 'It stops as soon as the cache answers.' );
		self::assertGreaterThan( 0, (int) get_option( SetupReport::COMPLETE_OPTION, 0 ) );
	}

	public function test_with_cloudflare_an_edge_miss_is_reported_without_failing_a_working_origin(): void {
		$this->set( 'cloudflare.enabled', true );
		$this->responses = array( array( 'status' => 200, 'headers' => array( 'x-gt-cache' => 'HIT', 'cf-cache-status' => 'MISS' ) ) );
		$result          = ( new SetupReport( 0 ) )->verify();

		self::assertTrue( $result['passed'], 'gatilab.com: the edge answered MISS for a while after a full purge while the origin served HITs.' );
		self::assertSame( 'MISS', $result['edge'] );
		self::assertCount( 4, $GLOBALS['gtperf_test_http_requests'], 'It still gives the edge four chances, then reports.' );
		self::assertStringContainsString( 'Cloudflare has not answered HIT yet', $result['detail'] );

		$this->responses = array( array( 'status' => 200, 'headers' => array( 'cf-cache-status' => 'HIT', 'age' => '30' ) ) );
		self::assertTrue( ( new SetupReport( 0 ) )->verify()['passed'], 'An edge HIT never reaches PHP, and proves the origin stored the page.' );
	}

	public function test_a_page_the_origin_never_serves_from_cache_fails(): void {
		$this->responses = array( array( 'status' => 200, 'headers' => array( 'x-gt-cache' => 'MISS' ) ) );
		$result          = ( new SetupReport( 0 ) )->verify();

		self::assertFalse( $result['passed'] );
		self::assertStringContainsString( 'not served from the origin cache', $result['detail'] );
		self::assertFalse( get_option( SetupReport::COMPLETE_OPTION, false ) );
	}

	public function test_a_private_home_page_is_named_as_the_reason(): void {
		$this->responses = array( array( 'status' => 200, 'headers' => array( 'cache-control' => 'private, no-cache', 'set-cookie' => 'session=1' ) ) );
		$result          = ( new SetupReport( 0 ) )->verify();

		self::assertFalse( $result['passed'] );
		self::assertStringContainsString( 'private or sets a cookie', $result['detail'] );
	}

	public function test_optimize_only_passes_on_a_public_page_and_reports_the_host_cache(): void {
		$this->set( 'cache.mode', 'optimize' );
		$this->responses = array( array( 'status' => 200, 'headers' => array( 'x-litespeed-cache' => 'hit' ) ) );
		$result          = ( new SetupReport( 0 ) )->verify();

		self::assertTrue( $result['passed'] );
		self::assertSame( 'optimize', $result['mode'] );
		self::assertSame( array( 'LiteSpeed Cache' ), $result['host_caches'] );
	}
}
