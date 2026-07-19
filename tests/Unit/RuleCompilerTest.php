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
}
