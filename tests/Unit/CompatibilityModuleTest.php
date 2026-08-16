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
	protected function setUp(): void {
		$GLOBALS['gtp_test_options'] = array();
	}

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

	public function testEwwwUploadCompressionAloneKeepsGtImageVariantsEnabled(): void {
		$this->activateEwww();

		self::assertTrue( ( new CompatibilityModule() )->allowGtImageVariants( true ) );
	}

	public function testEwwwWebpConversionOwnsModernImageVariants(): void {
		$this->activateEwww();
		$GLOBALS['gtp_test_options']['ewww_image_optimizer_webp'] = true;

		self::assertFalse( ( new CompatibilityModule() )->allowGtImageVariants( true ) );
	}

	public function testEwwwLazyLoadOnlyYieldsOverlappingMediaFeatures(): void {
		$this->activateEwww();
		$GLOBALS['gtp_test_options']['ewww_image_optimizer_lazy_load'] = true;

		$compatibility = new CompatibilityModule();

		self::assertFalse( $compatibility->allowGtMediaLazyLoad( true ) );
		self::assertTrue( $compatibility->allowGtMediaDimensions( true ) );

		$GLOBALS['gtp_test_options']['ewww_image_optimizer_add_missing_dims'] = true;

		self::assertFalse( $compatibility->allowGtMediaDimensions( true ) );
	}

	public function testEasyIoOwnsAllOverlappingImageFeatures(): void {
		$this->activateEwww();
		$GLOBALS['gtp_test_options']['easyio_exactdn'] = true;

		$compatibility = new CompatibilityModule();

		self::assertFalse( $compatibility->allowGtImageVariants( true ) );
		self::assertFalse( $compatibility->allowGtMediaLazyLoad( true ) );
		self::assertFalse( $compatibility->allowGtMediaDimensions( true ) );
	}

	public function testInactiveEwwwDoesNotChangeGtImageOwnership(): void {
		$GLOBALS['gtp_test_options']['ewww_image_optimizer_webp'] = true;

		self::assertTrue( ( new CompatibilityModule() )->allowGtImageVariants( true ) );
	}

	private function activateEwww(): void {
		$GLOBALS['gtp_test_options']['active_plugins'] = array( 'ewww-image-optimizer/ewww-image-optimizer.php' );
	}
}
