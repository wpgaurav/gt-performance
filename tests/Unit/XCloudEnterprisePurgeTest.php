<?php
/**
 * Cloudflare Enterprise purges through xCloud's own purge plugin.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Logger;
use GTPerformance\Core\SecretCipher;
use GTPerformance\Core\Settings;
use GTPerformance\XCloud\EdgeOwnership;
use GTPerformance\XCloud\EnterprisePurgeHook;
use GTPerformance\XCloud\EnterprisePurgeModule;
use GTPerformance\XCloud\SiteService;
use PHPUnit\Framework\TestCase;

final class XCloudEnterprisePurgeTest extends TestCase {
	private const ENDPOINT = 'https://app.xcloud.host/api/site/cloudflare-enterprise/auto-purge';

	private string $muDir;

	protected function setUp(): void {
		$this->tearDown();
		$GLOBALS['gtperf_test_options']       = array( Settings::OPTION => Settings::defaults() );
		$GLOBALS['gtperf_test_http_requests'] = array();
		$GLOBALS['gtperf_test_http_response'] = $this->reply( 200, true, 'Cache purge queued' );

		$this->muDir = sys_get_temp_dir() . '/gtperf-xcloud-mu-' . getmypid();
		if ( ! is_dir( $this->muDir ) ) {
			mkdir( $this->muDir, 0o777, true );
		}
		file_put_contents( $this->muDir . '/' . EnterprisePurgeHook::MU_PLUGIN, "<?php\n" );
	}

	protected function tearDown(): void {
		foreach ( array( 'options', 'filters', 'http_callback', 'http_requests', 'http_response' ) as $key ) {
			unset( $GLOBALS[ 'gtperf_test_' . $key ] );
		}
		if ( isset( $this->muDir ) && is_file( $this->muDir . '/' . EnterprisePurgeHook::MU_PLUGIN ) ) {
			unlink( $this->muDir . '/' . EnterprisePurgeHook::MU_PLUGIN );
		}
	}

	/**
	 * @param array<string, string> $overrides Credential overrides.
	 */
	private function hook( array $overrides = array(), ?string $dir = null ): EnterprisePurgeHook {
		return new EnterprisePurgeHook(
			$overrides + array(
				'url'          => self::ENDPOINT,
				'auth_token'   => 'auth-secret',
				'verify_token' => 'verify-secret',
				'version'      => '1.2.1',
			),
			$dir ?? $this->muDir
		);
	}

	private function reply( int $code, bool $success, string $message ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) json_encode(
				array(
					'success' => $success,
					'message' => $message,
				)
			),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function payloads(): array {
		return array_map(
			static fn( array $request ): array => (array) json_decode( (string) $request['args']['body'], true ),
			$GLOBALS['gtperf_test_http_requests']
		);
	}

	public function testHookIsAvailableOnlyWithTheMuPluginFileAndAToken(): void {
		self::assertTrue( $this->hook()->available() );
		self::assertFalse( $this->hook( array(), $this->muDir . '/missing' )->available(), 'No mu-plugin file means no xCloud edge.' );
		self::assertFalse( $this->hook( array( 'auth_token' => '' ) )->available() );
		self::assertFalse( ( new EnterprisePurgeHook( null, $this->muDir . '/missing' ) )->installed() );
	}

	public function testHookRefusesAnEndpointOutsideXcloud(): void {
		self::assertFalse( $this->hook( array( 'url' => 'https://attacker.example/collect' ) )->available() );
		self::assertFalse( $this->hook( array( 'url' => 'http://app.xcloud.host/api/site/cloudflare-enterprise/auto-purge' ) )->available() );
		self::assertFalse( $this->hook( array( 'url' => 'https://xcloud.host.attacker.example/' ) )->available() );
		self::assertTrue( $this->hook()->installed(), 'The file is still reported as installed.' );
	}

	public function testSiteCanTurnTheHookOff(): void {
		add_filter( 'gt_performance_xcloud_enterprise_purge', static fn(): bool => false );

		self::assertFalse( $this->hook()->available() );
	}

	public function testHookPausesDirectCloudflareInsteadOfReportingAConflict(): void {
		$settings                          = Settings::defaults();
		$settings['cloudflare']['enabled'] = true;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;

		$owner = new EdgeOwnership( $this->hook() );

		self::assertTrue( $owner->xcloudOwnsEdge(), 'No xCloud API token is needed.' );
		self::assertTrue( $owner->directCloudflarePaused() );
		self::assertFalse( $owner->hasDirectCloudflareConflict() );

		$without = new EdgeOwnership( $this->hook( array(), $this->muDir . '/missing' ) );
		self::assertFalse( $without->xcloudOwnsEdge(), 'Direct Cloudflare resumes once the plugin is removed.' );
		self::assertFalse( $without->directCloudflarePaused() );
	}

	public function testQueuedUrlsGoToXcloudInTheMuPluginShape(): void {
		$module = new EnterprisePurgeModule( new Logger(), $this->hook() );
		$module->queueUrls( array( 'https://example.com/a/', 'https://example.com/a/', '/relative/', 42 ) );
		$module->queueUrls( array( 'https://example.com/b/' ) );

		self::assertTrue( $module->flush( true ) );
		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		$request = $GLOBALS['gtperf_test_http_requests'][0];
		self::assertSame( self::ENDPOINT, $request['url'] );
		self::assertSame( 'POST', $request['args']['method'] );
		self::assertTrue( $request['args']['blocking'] );
		self::assertSame(
			array(
				'auth_token'   => 'auth-secret',
				'verify_token' => 'verify-secret',
				'source'       => 'wordpress_plugin',
				'version'      => '1.2.1',
				'urls'         => array( 'https://example.com/a/', 'https://example.com/b/' ),
			),
			$this->payloads()[0]
		);

		$receipt = get_option( EnterprisePurgeModule::STATUS_OPTION );
		self::assertSame( 'accepted', $receipt['status'] );
		self::assertSame( 'urls', $receipt['operation'] );
		self::assertSame( 2, $receipt['entries'] );
		self::assertSame( 'Cache purge queued', $receipt['message'] );
		self::assertTrue( $module->flush( true ), 'Nothing is resent once the queue is empty.' );
		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
	}

	public function testLongUrlListsAreBatchedAndVeryLongOnesBecomeOnePurgeAll(): void {
		$urls = array_map( static fn( int $i ): string => 'https://example.com/p/' . $i . '/', range( 1, 120 ) );
		$result = $this->hook()->purgeUrls( $urls, true );

		self::assertIsArray( $result );
		self::assertSame( 3, $result['requests'] );
		self::assertSame( array( 50, 50, 20 ), array_map( static fn( array $p ): int => count( $p['urls'] ), $this->payloads() ) );

		$GLOBALS['gtperf_test_http_requests'] = array();
		$urls   = array_map( static fn( int $i ): string => 'https://example.com/p/' . $i . '/', range( 1, EnterprisePurgeHook::MAX_URLS + 1 ) );
		$result = $this->hook()->purgeUrls( $urls, true );

		self::assertSame( 'all', $result['operation'] );
		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		self::assertTrue( $this->payloads()[0]['purge_all'] );
		self::assertArrayNotHasKey( 'urls', $this->payloads()[0] );
	}

	/**
	 * @return list<string>
	 */
	private function urls( string $prefix, int $count ): array {
		return array_map( static fn( int $i ): string => 'https://example.com/' . $prefix . '/' . $i . '/', range( 1, $count ) );
	}

	public function testOverTheLimitOnlyPrimaryUrlsReachEnterprise(): void {
		$related    = array( 'https://example.com/post/', 'https://example.com/', 'https://example.com/category/news/' );
		$dependents = $this->urls( 'listing', 500 );
		$module     = new EnterprisePurgeModule( new Logger(), $this->hook() );
		$module->queueUrls( array_merge( $related, $dependents ), 'origin', $related );
		$module->queueUrls( array( 'https://example.com/old-slug/' ), 'origin' );
		$module->flush( true );

		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		self::assertSame( array_merge( $related, array( 'https://example.com/old-slug/' ) ), $this->payloads()[0]['urls'], 'A purge with no primary set counts as primary.' );
		$receipt = get_option( EnterprisePurgeModule::STATUS_OPTION );
		self::assertSame( 4, $receipt['entries'] );
		self::assertSame( 500, $receipt['origin_only'] );
	}

	public function testUnderTheLimitDependentsAreSentToo(): void {
		$related = array( 'https://example.com/post/' );
		$module  = new EnterprisePurgeModule( new Logger(), $this->hook() );
		$module->queueUrls( array_merge( $related, $this->urls( 'listing', 99 ) ), 'origin', $related );
		$module->flush( true );

		self::assertSame( 100, array_sum( array_map( static fn( array $p ): int => count( $p['urls'] ), $this->payloads() ) ) );
		self::assertSame( 0, get_option( EnterprisePurgeModule::STATUS_OPTION )['origin_only'] );
	}

	public function testOverTheLimitWithoutPrimaryUrlsPurgesTheWholeSite(): void {
		$module = new EnterprisePurgeModule( new Logger(), $this->hook() );
		$module->queueUrls( $this->urls( 'listing', 400 ) );
		$module->flush( true );

		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		self::assertTrue( $this->payloads()[0]['purge_all'] );
		self::assertSame( 0, get_option( EnterprisePurgeModule::STATUS_OPTION )['origin_only'] );
	}

	public function testWholeSitePurgeSupersedesQueuedUrls(): void {
		$module = new EnterprisePurgeModule( new Logger(), $this->hook() );
		$module->queueUrls( array( 'https://example.com/a/' ) );
		$module->queueAll();
		$module->flush( true );

		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		self::assertTrue( $this->payloads()[0]['purge_all'] );
		self::assertSame( 'all', get_option( EnterprisePurgeModule::STATUS_OPTION )['operation'] );
	}

	public function testShutdownSendsWithoutWaitingForXcloud(): void {
		$module = new EnterprisePurgeModule( new Logger(), $this->hook() );
		$module->queueUrls( array( 'https://example.com/a/' ) );
		$module->flushOnShutdown();

		self::assertFalse( $GLOBALS['gtperf_test_http_requests'][0]['args']['blocking'] );
		self::assertSame( 'sent', get_option( EnterprisePurgeModule::STATUS_OPTION )['status'] );
	}

	public function testRejectedPurgeIsReportedToTheCallerWithoutTheTokens(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->reply( 401, false, 'Invalid token' );
		$module = new EnterprisePurgeModule( new Logger(), $this->hook() );
		$module->queueAll();
		$result = $module->flushForCaller( true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'Invalid token', $result->get_error_message() );
		$receipt = get_option( EnterprisePurgeModule::STATUS_OPTION );
		self::assertSame( 'failed', $receipt['status'] );
		self::assertStringNotContainsString( 'auth-secret', (string) wp_json_encode( $receipt ) );
	}

	public function testNothingIsQueuedWithoutTheHook(): void {
		$module = new EnterprisePurgeModule( new Logger(), $this->hook( array(), $this->muDir . '/missing' ) );
		$module->queueUrls( array( 'https://example.com/a/' ) );
		$module->queueAll();

		self::assertTrue( $module->flush( true ) );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
		self::assertFalse( get_option( EnterprisePurgeModule::STATUS_OPTION ) );
	}

	public function testHostCachePurgeNoLongerFailsClosedOnceEnterpriseHasTheHook(): void {
		$settings                                  = Settings::defaults();
		$settings['xcloud']['enabled']              = true;
		$settings['xcloud']['enterprise_available'] = true;
		$settings['xcloud']['site_uuid']            = 'f8edfab2-3d0f-47cb-b947-84116dbe5e69';
		$settings['xcloud']['api_token']            = ( new SecretCipher( 'xcloud' ) )->encrypt( 'secret' );
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;

		$result = ( new SiteService( enterpriseHook: $this->hook() ) )->purgeAutomatic();

		self::assertIsArray( $result );
		self::assertSame( 'none', $result['mode'], 'The Enterprise edge is the purge module\'s; no host cache is on here.' );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}
}
