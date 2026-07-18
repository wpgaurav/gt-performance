<?php
/**
 * Cloudflare rule expression tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cloudflare\RuleExpression;
use PHPUnit\Framework\TestCase;

final class RuleExpressionTest extends TestCase {
	public function test_expression_contains_public_and_commerce_guards(): void {
		$expression = ( new RuleExpression() )->compile(
			'www.example.com',
			array(
				'bypass_paths'        => array( '/checkout/' ),
				'bypass_cookies'      => array( 'fct_cart_hash' ),
				'bypass_query_params' => array( 'wc-ajax' ),
			)
		);

		self::assertStringContainsString( 'http.host eq "www.example.com"', $expression );
		self::assertStringContainsString( 'starts_with(http.request.uri.path, "/checkout/")', $expression );
		self::assertStringContainsString( 'http.cookie contains "fct_cart_hash"', $expression );
		self::assertStringContainsString( 'http.request.uri.query contains "wc-ajax="', $expression );
	}
}
