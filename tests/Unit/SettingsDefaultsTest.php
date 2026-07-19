<?php
/**
 * Default settings tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsDefaultsTest extends TestCase {
	public function testMaximumImpactCacheProfileIsTheDefault(): void {
		$defaults = Settings::defaults();

		self::assertTrue( $defaults['cache']['enabled'] );
		self::assertSame( 3600, $defaults['cache']['fresh_ttl'] );
		self::assertSame( 86400, $defaults['cache']['stale_ttl'] );
		self::assertSame( 86400, $defaults['cache']['stale_if_error'] );
		self::assertSame( 300, $defaults['cache']['browser_ttl'] );
	}

	public function testDatabaseDefaultsIncludeEverySafeScheduledTask(): void {
		$tasks = Settings::defaults()['database']['tasks'];

		self::assertContains( 'revisions', $tasks );
		self::assertContains( 'spam_comments', $tasks );
		self::assertContains( 'expired_transients', $tasks );
		self::assertContains( 'optimize_tables', $tasks );
		self::assertNotContains( 'all_transients', $tasks );
	}

	public function testRealUserMeasurementSettingsAreRetired(): void {
		self::assertArrayNotHasKey( 'rum', Settings::defaults() );
	}
}
