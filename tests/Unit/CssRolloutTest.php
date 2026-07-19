<?php
/**
 * CSS staged rollout tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\Rollout;
use PHPUnit\Framework\TestCase;

final class CssRolloutTest extends TestCase {
	public function testRolloutBoundariesAndPreview(): void {
		$rollout = new Rollout();

		self::assertFalse( $rollout->allows( 'https://example.com/', 0 ) );
		self::assertTrue( $rollout->allows( 'https://example.com/', 100 ) );
		self::assertTrue( $rollout->allows( 'https://example.com/', 0, true ) );
		self::assertSame(
			$rollout->allows( 'https://example.com/product/', 25 ),
			$rollout->allows( 'https://example.com/product/', 25 )
		);
	}
}
