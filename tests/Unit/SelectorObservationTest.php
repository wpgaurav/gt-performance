<?php
/**
 * CSS training selector tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\SelectorObservation;
use PHPUnit\Framework\TestCase;

final class SelectorObservationTest extends TestCase {
	public function testOnlyStructuralSelectorsAreAccepted(): void {
		$selectors = ( new SelectorObservation() )->sanitizeMany(
			array( '#cart-drawer', 'button.is-open.primary', '[data-email="private"]', '.gtp-secret', '.modal' )
		);

		self::assertSame( array( '#cart-drawer', 'button.is-open.primary', '.modal' ), $selectors );
	}

	public function testCssEscapedUtilityClassSelectorsAreKept(): void {
		$selectors = ( new SelectorObservation() )->sanitizeMany(
			array( 'div.md\\:flex.w-1\\/2', 'button.hover\\:bg-red-500', 'div.\\32 xl\\:hidden' )
		);

		self::assertSame(
			array( 'div.md\\:flex.w-1\\/2', 'button.hover\\:bg-red-500', 'div.\\32 xl\\:hidden' ),
			$selectors
		);
	}

	public function testInjectionAttemptsAreStillRejected(): void {
		$selectors = ( new SelectorObservation() )->sanitizeMany(
			array( 'div.foo;background:red', 'a.b{color:red}', '.a,.b' )
		);

		self::assertSame( array(), $selectors );
	}
}
