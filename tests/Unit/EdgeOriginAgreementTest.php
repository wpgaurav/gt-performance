<?php
/**
 * The edge must never be told to cache what the origin refuses.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Cloudflare\RuleCompiler;
use GTPerformance\Cloudflare\RuleExpression;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class EdgeOriginAgreementTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private function policy(): array {
		return array(
			'enabled'              => true,
			'ignored_query_params' => array( 'utm_source' ),
			'bypass_query_params'  => array( 'wc-ajax' ),
			'bypass_paths'         => array( '/checkout/' ),
			'bypass_cookies'       => array( 'wordpress_logged_in_' ),
		);
	}

	public function test_default_edge_ttl_respects_the_origin(): void {
		$defaults = Settings::defaults();

		self::assertSame( 0, $defaults['cloudflare']['edge_ttl'] );

		$rule = ( new RuleCompiler() )->rule( 'example.com', $this->policy(), (int) $defaults['cloudflare']['edge_ttl'] );

		self::assertSame(
			array( 'mode' => 'respect_origin' ),
			$rule['action_parameters']['edge_ttl'],
			'override_origin makes Cloudflare ignore the no-store the origin sends for '
			. 'every request it refuses, so it must never be the default.'
		);
	}

	public function test_overriding_the_origin_restricts_the_rule_to_empty_query_strings(): void {
		$rule = ( new RuleCompiler() )->rule( 'example.com', $this->policy(), 86400 );

		self::assertSame( 'override_origin', $rule['action_parameters']['edge_ttl']['mode'] );
		self::assertStringContainsString( '(http.request.uri.query eq "")', $rule['expression'] );
	}

	public function test_respecting_the_origin_still_caches_marketing_query_strings(): void {
		$rule = ( new RuleCompiler() )->rule( 'example.com', $this->policy(), 0 );

		self::assertStringNotContainsString( 'http.request.uri.query eq ""', $rule['expression'] );
	}

	/**
	 * The defect this guards: an unknown query parameter is an origin deny, but the
	 * managed rule matched it anyway and, under override_origin, held it for the full
	 * edge TTL. An unbounded parameter space made that both a private-response leak
	 * and an edge cache-fragmentation vector.
	 *
	 * @dataProvider unsafeRequests
	 */
	public function test_override_mode_never_matches_a_request_the_origin_refuses( RequestContext $request ): void {
		$policy = $this->policy();

		self::assertFalse(
			( new Eligibility() )->decide( $request, $policy )->cacheable,
			'Fixture must be a request the origin refuses.'
		);

		self::assertFalse(
			( new RuleExpression() )->matches( $request, 'example.com', $policy, true ),
			'The overriding rule must not match a request the origin marks private.'
		);
	}

	/**
	 * @return array<string, array{RequestContext}>
	 */
	public static function unsafeRequests(): array {
		return array(
			'unknown query parameter' => array(
				new RequestContext( 'GET', 'https', 'example.com', '/', array( 'x' => '1' ), array(), array(), '' ),
			),
			'bypassed query parameter' => array(
				new RequestContext( 'GET', 'https', 'example.com', '/', array( 'wc-ajax' => 'get_refreshed_fragments' ), array(), array(), '' ),
			),
			'commerce path' => array(
				new RequestContext( 'GET', 'https', 'example.com', '/checkout', array(), array(), array(), '' ),
			),
			'logged-in cookie' => array(
				new RequestContext( 'GET', 'https', 'example.com', '/', array(), array( 'wordpress_logged_in_abc' => '1' ), array(), '' ),
			),
		);
	}

	public function test_matcher_and_origin_agree_on_a_plain_public_request(): void {
		$request = new RequestContext( 'GET', 'https', 'example.com', '/about/', array(), array(), array(), '' );
		$policy  = $this->policy();

		self::assertTrue( ( new Eligibility() )->decide( $request, $policy )->cacheable );
		self::assertTrue( ( new RuleExpression() )->matches( $request, 'example.com', $policy, true ) );
	}
}
