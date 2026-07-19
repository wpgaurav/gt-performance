<?php
/**
 * Selector safelist syntax tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\SelectorSafelist;
use PHPUnit\Framework\TestCase;

final class SelectorSafelistTest extends TestCase {
	public function test_split_preserves_regex_quantifier_commas(): void {
		$patterns = SelectorSafelist::split( ".is-open\n/^\\.item-{1,3}$/i" );

		self::assertSame( array( '.is-open', '/^\\.item-{1,3}$/i' ), $patterns );
	}

	public function test_validation_separates_invalid_regular_expressions(): void {
		$result = ( new SelectorSafelist() )->validate( array( '.partial', '/^\\.valid$/i', '/[broken/' ) );

		self::assertSame( array( '.partial', '/^\\.valid$/i' ), $result['valid'] );
		self::assertSame( array( '/[broken/' ), $result['invalid'] );
	}

	public function test_plain_patterns_match_any_selector_fragment(): void {
		self::assertTrue( ( new SelectorSafelist() )->matches( '.menu .is-expanded > a', array( 'is-expanded' ) ) );
	}
}
