<?php
/**
 * The proxy and APO stages of the Cloudflare connection check.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cloudflare\ConnectionDiagnostics;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class ConnectionDiagnosticsTest extends TestCase {
	/** @var array<string, array<string, mixed>|int> Path fragment => result body, or an HTTP error status. */
	private array $api = array();

	/** @var array<string, string> Headers on the site's own home page. */
	private array $homeHeaders = array();

	protected function setUp(): void {
		$settings                            = Settings::defaults();
		$settings['cloudflare']['enabled']   = true;
		$settings['cloudflare']['zone_id']   = 'zone-1';
		$settings['cloudflare']['api_token'] = ( new TokenCipher() )->encrypt( 'test-token' );
		$GLOBALS['gtperf_test_options']      = array( Settings::OPTION => $settings );
		$GLOBALS['gtperf_test_http_requests'] = array();

		$this->api = array(
			'user/tokens/verify'                  => array( 'status' => 'active' ),
			'zones/zone-1/dns_records'            => array( array( 'type' => 'A', 'name' => 'example.com', 'proxied' => true ) ),
			'automatic_platform_optimization'     => array( 'id' => 'automatic_platform_optimization', 'value' => array( 'enabled' => false ) ),
			'http_request_cache_settings'         => 404,
			'zones/zone-1'                        => array( 'name' => 'example.com', 'plan' => array( 'name' => 'Free Website' ) ),
		);
		$GLOBALS['gtperf_test_http_callback'] = function ( string $url ): array {
			if ( ! str_contains( $url, 'api.cloudflare.com' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'headers' => $this->homeHeaders, 'body' => '' );
			}
			foreach ( $this->api as $fragment => $result ) {
				if ( str_contains( $url, $fragment ) ) {
					return is_int( $result )
						? array( 'response' => array( 'code' => $result ), 'body' => (string) json_encode( array( 'success' => false, 'errors' => array( array( 'code' => 9109, 'message' => 'Unauthorized to access requested resource' ) ) ) ) )
						: array( 'response' => array( 'code' => 200 ), 'body' => (string) json_encode( array( 'success' => true, 'result' => $result ) ) );
				}
			}
			self::fail( 'Unexpected Cloudflare call: ' . $url );
		};
	}

	protected function tearDown(): void {
		foreach ( array( 'options', 'http_callback', 'http_requests' ) as $key ) {
			unset( $GLOBALS[ 'gtperf_test_' . $key ] );
		}
	}

	/** @return array<string, mixed> */
	private function step( string $key ): array {
		$report = ( new ConnectionDiagnostics() )->run();
		foreach ( $report['steps'] as $step ) {
			if ( $key === $step['key'] ) {
				return $step + array( 'report_ok' => $report['ok'] );
			}
		}
		self::fail( "No {$key} step." );
	}

	public function test_proxied_records_pass(): void {
		self::assertSame( 'pass', $this->step( 'proxied' )['status'] );
	}

	public function test_a_grey_cloud_record_is_named_as_the_reason_nothing_caches(): void {
		$this->api['zones/zone-1/dns_records'] = array(
			array( 'type' => 'A', 'name' => 'example.com', 'proxied' => true ),
			array( 'type' => 'AAAA', 'name' => 'example.com', 'proxied' => false ),
		);
		$step = $this->step( 'proxied' );

		self::assertSame( 'warn', $step['status'] );
		self::assertStringContainsString( 'grey cloud', $step['detail'] );
		self::assertTrue( $step['report_ok'], 'The connection itself works; this is advice, not a blocked stage.' );
	}

	public function test_without_dns_permission_the_public_cf_ray_header_decides(): void {
		$this->api['zones/zone-1/dns_records'] = 403;
		$this->homeHeaders                     = array( 'cf-ray' => '8c1f2a3b4c5d6e7f-SIN' );
		self::assertSame( 'pass', $this->step( 'proxied' )['status'] );

		$this->homeHeaders = array();
		$step              = $this->step( 'proxied' );
		self::assertSame( 'warn', $step['status'] );
		self::assertStringContainsString( 'Could not confirm', $step['detail'] );
	}

	public function test_apo_on_is_flagged_and_off_passes(): void {
		self::assertSame( 'pass', $this->step( 'apo' )['status'] );

		$this->api['automatic_platform_optimization'] = array( 'value' => array( 'enabled' => true, 'cf' => true, 'wordpress' => true ) );
		$step = $this->step( 'apo' );
		self::assertSame( 'warn', $step['status'] );
		self::assertStringContainsString( 'APO is on', $step['detail'] );
	}

	public function test_an_unreadable_apo_setting_is_skipped_not_failed(): void {
		$this->api['automatic_platform_optimization'] = 403;
		$step = $this->step( 'apo' );

		self::assertSame( 'skip', $step['status'] );
		self::assertTrue( $step['report_ok'] );
	}
}
