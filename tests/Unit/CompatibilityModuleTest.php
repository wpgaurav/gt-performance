<?php
/**
 * Optimization compatibility tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Compatibility\CompatibilityModule;
use PHPUnit\Framework\TestCase;

final class CompatibilityModuleTest extends TestCase {
	public function testActiveCommerceStylesheetsAreExcludedFromServerSidePruning(): void {
		$exclusions = ( new CompatibilityModule() )->stylesheetExclusionsForCommerce(
			array( '/custom/keep.css' ),
			array( 'fluentcart', 'woocommerce' )
		);

		self::assertSame(
			array(
				'/custom/keep.css',
				'/plugins/fluent-cart/',
				'/plugins/fluent-cart-pro/',
				'/plugins/woocommerce/',
			),
			$exclusions
		);
	}

	public function testCommerceStylesheetExclusionsAreDeduplicated(): void {
		$exclusions = ( new CompatibilityModule() )->stylesheetExclusionsForCommerce(
			array( '/plugins/easy-digital-downloads/' ),
			array( 'edd', 'edd', 'unknown' )
		);

		self::assertSame( array( '/plugins/easy-digital-downloads/' ), $exclusions );
	}
}
