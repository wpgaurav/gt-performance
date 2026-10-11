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
		self::assertStringContainsString( 'http.request.uri.path in {"/checkout"}', $expression );
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
		self::assertStringContainsString( 'http.request.uri.args.names', $expression );
		self::assertStringContainsString( '"s"', $expression );
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
			self::assertStringContainsString( '"' . $parameter . '"', $expression );
		}
	}
	public function test_whole_decoded_query_names_and_path_boundaries_stay_protected(): void {
		$compiler = new RuleExpression();
		$policy = array(
			'bypass_paths' => array( '/checkout/', '/wp-json/', '/wp-json/fluent-cart/' ),
			'bypass_query_params' => array( 's', 'wc-ajax', '0' ),
			'bypass_cookies' => array( 'fct_', 'fct_cart_hash' ),
		);
		foreach ( array( '/checkout', '/checkout/pay', '/wp-json/fluent-cart/cart', '/post/?s', '/post/?wc%2Dajax=cart', '/post/?WC-AJAX=cart', '/post/?0=private' ) as $path ) {
			self::assertFalse( $compiler->matches( \GTPerformance\Cache\RequestContext::fromUrl( 'https://example.com' . $path ), 'example.com', $policy ), $path );
		}
		foreach ( array( '/checkout-summary', '/post/?forms=1', '/post/?utms=1' ) as $path ) {
			self::assertTrue( $compiler->matches( \GTPerformance\Cache\RequestContext::fromUrl( 'https://example.com' . $path ), 'example.com', $policy ), $path );
		}
		$expression = $compiler->compile( 'example.com', $policy );
		self::assertStringNotContainsString( '/wp-json/fluent-cart', $expression );
		self::assertStringNotContainsString( 'fct_cart_hash', $expression );
		self::assertStringContainsString( '"0"', $expression );
	}
}
