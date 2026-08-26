<?php
/**
 * xCloud edge ownership tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\XCloud\EdgeOwnership;
use PHPUnit\Framework\TestCase;

final class XCloudEdgeOwnershipTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['gtperf_test_options'] = array();
	}

	public function testEnterpriseBecomesTheEdgeOwnerWhenIntegrationIsEnabled(): void {
		$settings = Settings::defaults();
		$settings['xcloud']['enabled'] = true;
		$settings['xcloud']['enterprise_available'] = true;
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = $settings;

		self::assertTrue( ( new EdgeOwnership() )->xcloudOwnsEdge() );
	}

	public function testDirectCloudflareConflictIsExplicit(): void {
		$settings = Settings::defaults();
		$settings['cloudflare']['enabled'] = true;
		$settings['xcloud']['enabled'] = true;
		$settings['xcloud']['enterprise_available'] = true;
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = $settings;

		self::assertTrue( ( new EdgeOwnership() )->hasDirectCloudflareConflict() );
	}

	public function testDetectedEnterpriseDoesNotOwnEdgeWhileIntegrationIsDisabled(): void {
		$settings = Settings::defaults();
		$settings['xcloud']['enterprise_available'] = true;
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = $settings;

		self::assertFalse( ( new EdgeOwnership() )->xcloudOwnsEdge() );
	}
}
