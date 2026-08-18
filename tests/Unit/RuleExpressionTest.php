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
		self::assertStringContainsString( 'http.cookie contains "fct_cart_hash"', $expression );
	}

	public function test_path_bypass_matches_the_canonical_slashless_variant(): void {
		$expression = ( new RuleExpression() )->compile(
			'www.example.com',
			array( 'bypass_paths' => array( '/checkout/' ) )
		);

		// Both the canonical `/checkout` and everything under `/checkout/` must be excluded.
		self::assertStringContainsString( 'http.request.uri.path eq "/checkout"', $expression );
		self::assertStringContainsString( 'starts_with(http.request.uri.path, "/checkout/")', $expression );
	}

	public function test_query_param_bypass_is_anchored_to_a_parameter_boundary(): void {
		$expression = ( new RuleExpression() )->compile(
			'www.example.com',
			array( 'bypass_query_params' => array( 's' ) )
		);

		// The boundary-anchored form must not degrade to the substring `contains "s="`,
		// which would also exclude unrelated params such as `utms=`. Both the leading
		// parameter and any later one have to be covered.
		self::assertStringContainsString( 'starts_with(http.request.uri.query, "s=")', $expression );
		self::assertStringContainsString( 'http.request.uri.query contains "&s="', $expression );
		self::assertStringNotContainsString( 'query contains "s="', $expression );
	}

	public function test_expression_never_calls_concat(): void {
		// Cloudflare rejects an expression that calls concat more than once (error
		// 20127), so a rule carrying several bypass parameters could never be saved.
		$expression = ( new RuleExpression() )->compile(
			'www.example.com',
			array(
				'bypass_query_params' => array( 'add-to-cart', 'wc-ajax', 'preview', 's', 'elementor-preview', 'fluent-cart', 'customize_changeset_uuid' ),
			)
		);

		self::assertStringNotContainsString( 'concat(', $expression );
	}

	public function test_every_bypass_parameter_survives_compilation(): void {
		$parameters = array( 'add-to-cart', 'wc-ajax', 'preview', 's' );
		$expression = ( new RuleExpression() )->compile( 'www.example.com', array( 'bypass_query_params' => $parameters ) );

		foreach ( $parameters as $parameter ) {
			self::assertStringContainsString( 'starts_with(http.request.uri.query, "' . $parameter . '=")', $expression );
			self::assertStringContainsString( 'http.request.uri.query contains "&' . $parameter . '="', $expression );
		}
	}
}
