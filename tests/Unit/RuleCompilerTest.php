<?php
/**
 * Cloudflare rule compiler tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cloudflare\RuleCompiler;
use PHPUnit\Framework\TestCase;

final class RuleCompilerTest extends TestCase {
	private function cachePolicy(): array {
		return array(
			'ignored_query_params' => array( 'utm_source' ),
			'bypass_paths'         => array( '/checkout/' ),
			'bypass_cookies'       => array( 'session_' ),
			'bypass_query_params'  => array( 'add-to-cart' ),
			'separate_mobile'      => false,
		);
	}

	public function testPlanProtectsTheFreeRuleBudget(): void {
		$existing = array_fill(
			0,
			RuleCompiler::FREE_RULE_LIMIT,
			array(
				'id'         => 'other',
				'action'     => 'set_cache_settings',
				'expression' => 'true',
				'enabled'    => true,
			)
		);
		$plan = ( new RuleCompiler() )->plan( 'example.com', $this->cachePolicy(), $existing );

		self::assertSame( 'create', $plan['operation'] );
		self::assertFalse( $plan['within_budget'] );
		self::assertSame( 0, $plan['available'] );
	}

	public function testPlanUpdatesAnExistingManagedRuleWithoutConsumingBudget(): void {
		$compiler = new RuleCompiler();
		$managed  = $compiler->rule( 'example.com', $this->cachePolicy() );
		$managed['expression'] = 'false';

		$plan = $compiler->plan( 'example.com', $this->cachePolicy(), array( $managed ), 1 );

		self::assertSame( 'update', $plan['operation'] );
		self::assertTrue( $plan['within_budget'] );
		self::assertTrue( $plan['drift'] );
	}

	public function testPlanReportsNoChangeForTheExpectedRule(): void {
		$compiler = new RuleCompiler();
		$managed  = $compiler->rule( 'example.com', $this->cachePolicy() );
		$plan     = $compiler->plan( 'example.com', $this->cachePolicy(), array( $managed ) );

		self::assertSame( 'none', $plan['operation'] );
		self::assertFalse( $plan['drift'] );
	}

	public function testPlanIgnoresCloudflareKeyOrderingWhenDetectingDrift(): void {
		$compiler = new RuleCompiler();
		$managed  = $compiler->rule( 'example.com', $this->cachePolicy() );

		// Cloudflare echoes the stored rule with its own key ordering; a re-ordered
		// but otherwise identical rule must not be misread as drift.
		$managed['action_parameters'] = self::reverseKeysRecursive( $managed['action_parameters'] );

		$plan = $compiler->plan( 'example.com', $this->cachePolicy(), array( $managed ) );

		self::assertSame( 'none', $plan['operation'] );
		self::assertFalse( $plan['drift'] );
	}

	/**
	 * @param array<string, mixed> $value Rule fragment.
	 * @return array<string, mixed>
	 */
	private static function reverseKeysRecursive( array $value ): array {
		$reversed = array_reverse( $value, true );
		foreach ( $reversed as $key => $item ) {
			if ( is_array( $item ) ) {
				$reversed[ $key ] = self::reverseKeysRecursive( $item );
			}
		}

		return $reversed;
	}

	public function testPlanReportsAnOverlappingCacheRule(): void {
		$plan = ( new RuleCompiler() )->plan(
			'example.com',
			$this->cachePolicy(),
			array(
				array(
					'id'          => 'other-rule',
					'description' => 'Other HTML cache',
					'action'      => 'set_cache_settings',
					'expression'  => '(http.host eq "example.com")',
					'enabled'     => true,
				),
			)
		);

		self::assertCount( 1, $plan['conflicts'] );
		self::assertSame( 'other-rule', $plan['conflicts'][0]['id'] );
	}

	public function testPositiveEdgeTtlOverridesOriginWhileZeroRespectsIt(): void {
		$compiler = new RuleCompiler();

		$override = $compiler->rule( 'example.com', $this->cachePolicy(), 86400 );
		$respect  = $compiler->rule( 'example.com', $this->cachePolicy(), 0 );

		self::assertSame(
			array(
				'mode'    => 'override_origin',
				'default' => 86400,
			),
			$override['action_parameters']['edge_ttl']
		);
		self::assertSame( array( 'mode' => 'respect_origin' ), $respect['action_parameters']['edge_ttl'] );
	}

	public function testDroppingTheCustomCacheKeyRemovesQueryStringExclusions(): void {
		$compiler = new RuleCompiler();

		$ideal    = $compiler->rule( 'example.com', $this->cachePolicy(), 0, true );
		$degraded = $compiler->rule( 'example.com', $this->cachePolicy(), 0, false );

		self::assertArrayHasKey( 'custom_key', $ideal['action_parameters']['cache_key'] );
		self::assertArrayNotHasKey( 'custom_key', $degraded['action_parameters']['cache_key'] );
	}

	public function testDriftClearsWhenComparedAgainstTheRuleShapeThePlanCanStore(): void {
		$compiler = new RuleCompiler();

		// What Cloudflare accepted on a plan that rejects a custom cache key.
		$stored = $compiler->rule( 'example.com', $this->cachePolicy(), 0, false );

		// Comparing that against the ideal rule reports drift no sync can ever clear.
		$optimistic = $compiler->plan( 'example.com', $this->cachePolicy(), array( $stored ), RuleCompiler::FREE_RULE_LIMIT, 0, true );
		self::assertTrue( $optimistic['drift'] );

		$accurate = $compiler->plan( 'example.com', $this->cachePolicy(), array( $stored ), RuleCompiler::FREE_RULE_LIMIT, 0, false );
		self::assertFalse( $accurate['drift'] );
		self::assertSame( 'none', $accurate['operation'] );
		self::assertFalse( $accurate['custom_key'] );
	}

	public function testPlanReportsACatchAllRuleThatNeverNamesTheHost(): void {
		$plan = ( new RuleCompiler() )->plan(
			'example.com',
			$this->cachePolicy(),
			array(
				array(
					'id'                => 'bypass-everything',
					'description'       => 'Bypass Cache for Everything',
					'action'            => 'set_cache_settings',
					'expression'        => 'true',
					'enabled'           => true,
					'action_parameters' => array( 'cache' => false ),
				),
			)
		);

		self::assertCount( 1, $plan['conflicts'] );
		self::assertSame( 'every-host', $plan['conflicts'][0]['scope'] );
		self::assertTrue( $plan['conflicts'][0]['bypasses'] );
	}

	public function testPlanIgnoresRulesScopedToADifferentHost(): void {
		$plan = ( new RuleCompiler() )->plan(
			'example.com',
			$this->cachePolicy(),
			array(
				array(
					'id'          => 'other-site',
					'description' => 'Another site in the same zone',
					'action'      => 'set_cache_settings',
					'expression'  => '(http.host eq "shop.example.net")',
					'enabled'     => true,
				),
			)
		);

		self::assertSame( array(), $plan['conflicts'] );
	}

	public function testPlanIgnoresDisabledOverlappingRules(): void {
		$plan = ( new RuleCompiler() )->plan(
			'example.com',
			$this->cachePolicy(),
			array(
				array(
					'id'          => 'switched-off',
					'description' => 'Disabled catch-all',
					'action'      => 'set_cache_settings',
					'expression'  => 'true',
					'enabled'     => false,
				),
			)
		);

		self::assertSame( array(), $plan['conflicts'] );
	}
}
