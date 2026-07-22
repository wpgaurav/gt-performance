<?php
/**
 * Plugin compatibility detection tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Compatibility\PluginDetector;
use PHPUnit\Framework\TestCase;

final class PluginDetectorTest extends TestCase {
	public function test_catalog_contains_common_analytics_plugins(): void {
		$catalog = ( new PluginDetector() )->catalog();

		self::assertArrayHasKey( 'independent-analytics', $catalog );
		self::assertArrayHasKey( 'burst-statistics', $catalog );
		self::assertArrayHasKey( 'koko-analytics', $catalog );
		self::assertArrayHasKey( 'matomo-analytics', $catalog );
		self::assertArrayHasKey( 'wp-statistics', $catalog );
		self::assertArrayHasKey( 'google-site-kit', $catalog );
		self::assertArrayHasKey( 'monsterinsights', $catalog );
		self::assertArrayHasKey( 'exactmetrics', $catalog );
		self::assertArrayHasKey( 'pixelyoursite', $catalog );
	}

	public function test_independent_analytics_free_and_pro_are_detected(): void {
		$detector = new PluginDetector();

		self::assertTrue( $detector->detected( 'independent-analytics', array( 'independent-analytics/iawp.php' ) ) );
		self::assertTrue( $detector->detected( 'independent-analytics', array( 'independent-analytics-pro/iawp.php' ) ) );
	}

	public function test_only_active_analytics_plugins_add_script_exclusions(): void {
		$detector   = new PluginDetector();
		$exclusions = $detector->javascriptExclusionsForPlugins(
			array(
				'independent-analytics-pro/iawp.php',
				'google-site-kit/google-site-kit.php',
			)
		);

		self::assertContains( '/plugins/independent-analytics-pro/', $exclusions );
		self::assertContains( '/plugins/google-site-kit/', $exclusions );
		self::assertContains( 'google-analytics.com', $exclusions );
		self::assertContains( 'googletagmanager.com', $exclusions );
		self::assertNotContains( '/plugins/koko-analytics/', $exclusions );
	}

	public function test_shared_analytics_domains_are_deduplicated(): void {
		$detector   = new PluginDetector();
		$exclusions = $detector->javascriptExclusionsForPlugins(
			array(
				'google-site-kit/google-site-kit.php',
				'googleanalytics/googleanalytics.php',
			)
		);

		self::assertSame( 1, array_count_values( $exclusions )['googletagmanager.com'] );
		self::assertSame( 1, array_count_values( $exclusions )['google-analytics.com'] );
	}
}
