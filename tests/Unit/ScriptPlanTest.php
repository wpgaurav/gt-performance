<?php
/**
 * Dependency-aware defer and delay decisions.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\Optimization\JavaScriptOptimizer;
use GTPerformance\Optimization\ScriptPlan;
use PHPUnit\Framework\TestCase;

final class ScriptPlanTest extends TestCase {
	/**
	 * @param list<string> $deps Dependencies.
	 * @return array{src:string,deps:list<string>,after:bool}
	 */
	private function script( string $src, array $deps = array(), bool $after = false ): array {
		return array( 'src' => $src, 'deps' => $deps, 'after' => $after );
	}

	private function none(): \Closure {
		return static fn ( string $src ): bool => false;
	}

	public function test_inline_after_code_keeps_a_library_and_everything_it_supports_blocking(): void {
		$plan = ScriptPlan::build(
			array(
				'jquery'  => $this->script( '/jquery.js', array(), true ),
				'slider'  => $this->script( '/slider.js', array( 'jquery' ) ),
				'footer'  => $this->script( '/footer.js' ),
			),
			true,
			false,
			$this->none(),
			$this->none()
		);

		self::assertSame( 'keep', $plan['jquery']['action'] );
		self::assertStringContainsString( 'inline code', $plan['jquery']['reason'] );
		self::assertSame( 'defer', $plan['slider']['action'], 'A dependent may defer; its dependency still runs first.' );
		self::assertSame( 'defer', $plan['footer']['action'] );
	}

	public function test_a_dependency_is_not_deferred_when_a_dependent_must_run_in_place(): void {
		$plan = ScriptPlan::build(
			array(
				'lib'    => $this->script( '/lib.js' ),
				'widget' => $this->script( '/widget.js', array( 'lib' ), true ),
			),
			true,
			false,
			$this->none(),
			$this->none()
		);

		self::assertSame( 'keep', $plan['widget']['action'] );
		self::assertSame( 'keep', $plan['lib']['action'] );
		self::assertStringContainsString( 'dependent widget', $plan['lib']['reason'] );
	}

	public function test_delay_takes_the_whole_dependent_chain_or_none_of_it(): void {
		$selected = static fn ( string $src ): bool => str_contains( $src, 'analytics' );
		$safe     = ScriptPlan::build(
			array(
				'analytics' => $this->script( '/analytics.js' ),
				'ab-test'   => $this->script( '/ab.js', array( 'analytics' ) ),
				'other'     => $this->script( '/other.js' ),
			),
			true,
			true,
			$this->none(),
			$selected
		);
		self::assertSame( 'delay', $safe['analytics']['action'] );
		self::assertSame( 'delay', $safe['ab-test']['action'] );
		self::assertSame( 'depends on delayed analytics', $safe['ab-test']['reason'] );
		self::assertSame( 'defer', $safe['other']['action'] );

		$unsafe = ScriptPlan::build(
			array(
				'analytics' => $this->script( '/analytics.js' ),
				'consent'   => $this->script( '/consent.js', array( 'analytics' ), true ),
			),
			false,
			true,
			$this->none(),
			$selected
		);
		self::assertSame( 'keep', $unsafe['analytics']['action'] );
		self::assertSame( 'keep', $unsafe['consent']['action'] );
		self::assertStringContainsString( 'consent has inline code', $unsafe['analytics']['reason'] );
	}

	public function test_exclusions_stop_both_defer_and_delay_through_the_chain(): void {
		$excluded = static fn ( string $src ): bool => str_contains( $src, 'checkout' );
		$plan     = ScriptPlan::build(
			array(
				'tracker'  => $this->script( '/tracker.js' ),
				'checkout' => $this->script( '/checkout.js', array( 'tracker' ) ),
			),
			true,
			true,
			$excluded,
			static fn ( string $src ): bool => str_contains( $src, 'tracker' )
		);

		self::assertSame( 'keep', $plan['tracker']['action'] );
		self::assertSame( 'keep', $plan['checkout']['action'] );
	}

	public function test_optimizer_applies_the_plan_to_registered_script_tags(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'javascript' => array( 'defer' => true ) );
		wp_register_script( 'gtp-lib', 'https://example.com/lib.js', array(), null );
		wp_register_script( 'gtp-app', 'https://example.com/app.js', array( 'gtp-lib' ), null );
		wp_add_inline_script( 'gtp-app', 'app.start();' );
		wp_scripts()->done = array_merge( wp_scripts()->done, array( 'gtp-lib', 'gtp-app' ) );
		try {
			$html   = '<script src="https://example.com/lib.js" id="gtp-lib-js"></script><script src="https://example.com/app.js" id="gtp-app-js"></script><script id="gtp-app-js-after">app.start();</script>';
			$output = ( new JavaScriptOptimizer() )->optimize( $html );
		} finally {
			wp_scripts()->done = array_values( array_diff( wp_scripts()->done, array( 'gtp-lib', 'gtp-app' ) ) );
			wp_deregister_script( 'gtp-app' );
			wp_deregister_script( 'gtp-lib' );
		}

		self::assertSame( $html, $output, 'app has inline "after" code, so neither it nor its dependency is deferred.' );
	}
}
