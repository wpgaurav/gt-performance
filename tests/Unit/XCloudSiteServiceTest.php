<?php
/**
 * XCloud cache routing tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\SecretCipher;
use GTPerformance\Core\Settings;
use GTPerformance\XCloud\SiteService;
use PHPUnit\Framework\TestCase;

final class XCloudSiteServiceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['gtperf_test_http_requests'] = array();
		$GLOBALS['gtperf_test_options']       = array();
	}

	public function testEnterprisePurgeFailsClosedWithoutCallingHostPurgeAll(): void {
		$settings = Settings::defaults();
		$settings['xcloud']['enabled'] = true;
		$settings['xcloud']['enterprise_available'] = true;
		$settings['xcloud']['site_uuid'] = 'f8edfab2-3d0f-47cb-b947-84116dbe5e69';
		$settings['xcloud']['api_token'] = ( new SecretCipher( 'xcloud' ) )->encrypt( 'secret' );
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = $settings;

		$result = ( new SiteService() )->purgeAutomatic();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'gtperf_xcloud_enterprise_purge_unavailable', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}
}
