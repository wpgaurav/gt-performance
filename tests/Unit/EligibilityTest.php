<?php
/**
 * Eligibility tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use PHPUnit\Framework\TestCase;

final class EligibilityTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private function config(): array {
		return array(
			'enabled'              => true,
			'ignored_query_params' => array( 'utm_source' ),
			'bypass_query_params'  => array( 'wc-ajax' ),
			'bypass_paths'         => array( '/checkout/', '/wp-admin/' ),
			'bypass_cookies'       => array( 'wordpress_logged_in_', 'fct_cart_hash' ),
		);
	}

	public function test_public_request_is_cacheable(): void {
		$request = new RequestContext( 'GET', 'https', 'example.com', '/', array(), array(), array(), '' );

		self::assertTrue( ( new Eligibility() )->decide( $request, $this->config() )->cacheable );
	}

	public function test_tracking_query_is_ignored(): void {
		$request = new RequestContext( 'GET', 'https', 'example.com', '/', array( 'utm_source' => 'newsletter' ), array(), array(), '' );

		self::assertTrue( ( new Eligibility() )->decide( $request, $this->config() )->cacheable );
	}

	/**
	 * @dataProvider bypassProvider
	 *
	 * @param array<string, string> $query Query.
	 * @param array<string, string> $cookies Cookies.
	 */
	public function test_stateful_requests_bypass( string $path, array $query, array $cookies, string $expected ): void {
		$request  = new RequestContext( 'GET', 'https', 'example.com', $path, $query, $cookies, array(), '' );
		$decision = ( new Eligibility() )->decide( $request, $this->config() );

		self::assertFalse( $decision->cacheable );
		self::assertStringStartsWith( $expected, $decision->reason );
	}

	/**
	 * @return iterable<string, array{string,array<string,string>,array<string,string>,string}>
	 */
	public static function bypassProvider(): iterable {
		yield 'checkout path' => array( '/checkout/', array(), array(), 'path:' );
		yield 'checkout without trailing slash' => array( '/checkout', array(), array(), 'path:' );
		yield 'checkout child path' => array( '/checkout/order-pay', array(), array(), 'path:' );
		yield 'Woo AJAX' => array( '/', array( 'wc-ajax' => 'add_to_cart' ), array(), 'query:' );
		yield 'FluentCart session' => array( '/', array(), array( 'fct_cart_hash' => 'abc' ), 'cookie:' );
		yield 'unknown query' => array( '/', array( 'anything' => '1' ), array(), 'unknown_query:' );
	}

	public function test_sibling_of_a_bypass_path_stays_cacheable(): void {
		// `/checkout-summary` must not be captured by the `/checkout/` bypass.
		$request  = new RequestContext( 'GET', 'https', 'example.com', '/checkout-summary', array(), array(), array(), '' );
		$decision = ( new Eligibility() )->decide( $request, $this->config() );

		self::assertTrue( $decision->cacheable );
	}
}
