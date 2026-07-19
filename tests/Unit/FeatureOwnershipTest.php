<?php
/**
 * Optimization ownership tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Compatibility\FeatureOwnership;
use PHPUnit\Framework\TestCase;

final class FeatureOwnershipTest extends TestCase {
	public function test_automatic_mode_assigns_only_enabled_gt_features_to_gt(): void {
		$ownership = new FeatureOwnership();

		self::assertTrue( $ownership->gtOwns( 'automatic', true ) );
		self::assertFalse( $ownership->gtOwns( 'automatic', false ) );
		self::assertTrue( $ownership->disablePerfmatters( 'automatic', true ) );
		self::assertFalse( $ownership->disablePerfmatters( 'automatic', false ) );
	}

	public function test_explicit_owners_are_deterministic(): void {
		$ownership = new FeatureOwnership();

		self::assertTrue( $ownership->gtOwns( 'gt_performance', false ) );
		self::assertTrue( $ownership->disablePerfmatters( 'gt_performance', false ) );
		self::assertFalse( $ownership->gtOwns( 'perfmatters', true ) );
		self::assertFalse( $ownership->disablePerfmatters( 'perfmatters', true ) );
	}
}
