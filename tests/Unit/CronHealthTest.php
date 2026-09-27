<?php
/**
 * External WP-Cron diagnostics tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Diagnostics\CronHealth;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CronHealthTest extends TestCase {
	public function testEnabledRequestSpawningPasses(): void {
		$check = ( new CronHealth() )->check( array(), 10000 );

		self::assertSame( 'pass', $check['status'] );
	}

	#[RunInSeparateProcess]
	public function testDisabledCronWithMateriallyOverdueEventsWarnsAndProvidesRunner(): void {
		define( 'DISABLE_WP_CRON', true );
		$check = ( new CronHealth() )->check(
			array(
				8000 => array(
					'site_maintenance_refresh' => array(
						'instance' => array( 'schedule' => 'hourly' ),
					),
				),
			),
			10000
		);

		self::assertSame( 'warning', $check['status'] );
		self::assertStringContainsString( '1 event overdue', $check['value'] );
		self::assertStringContainsString( '*/5 * * * * flock -n', $check['value'] );
		self::assertStringContainsString( ABSPATH . 'wp-cron.php', $check['value'] );

		$shared = ( new CronHealth() )->check( array( 8000 => array( 'site_maintenance_refresh' => array( 'instance' => array() ) ) ), 10000, false );
		self::assertStringNotContainsString( ABSPATH, $shared['value'], 'The health report is exported; it must not carry the server path.' );
		self::assertStringContainsString( 'wp gt-performance doctor', $shared['value'] );
	}
}
