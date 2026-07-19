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
}
