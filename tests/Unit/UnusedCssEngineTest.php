<?php
/**
 * The unused-CSS engine, end to end.
 *
 * This is the feature that shipped disabled because it corrupted pages silently.
 * Every assertion here is a defect that reached a cache.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\UnusedCssOptimizer;
use PHPUnit\Framework\TestCase;

final class UnusedCssEngineTest extends TestCase {
	protected function setUp(): void {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			self::markTestSkipped( 'The WordPress HTML API is required.' );
		}

		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array(
			'generation' => 1,
			'css'        => array(
				'enabled'              => true,
				'mode'                 => 'inline',
				'keep_dynamic_states'  => true,
				'rollout_percent'      => 100,
				'safelist'             => array(),
				'excluded_stylesheets' => array(),
				'critical_budget'      => 14336,
			),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_options']['gt_performance_settings'] );
	}

	private function page( string $head, string $body ): string {
		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' . $head . '</head><body>' . $body . '</body></html>';
	}

	/**
	 * Run the engine as the background generator.
	 *
	 * A visitor's request never prunes: it queues a build and goes out unchanged,
	 * because pruning a real page measured 2.8 seconds. Only the generator's own
	 * loopback request, carrying a per-site token, reaches the pruner.
	 */
	private function optimize( string $html ): string {
		$token = new \ReflectionMethod( UnusedCssOptimizer::class, 'generatorToken' );
		$_GET[ UnusedCssOptimizer::GENERATOR_PARAM ] = $token->invoke( null );

		try {
			return ( new UnusedCssOptimizer( new \GTPerformance\Core\Logger() ) )->optimize( $html );
		} finally {
			unset( $_GET[ UnusedCssOptimizer::GENERATOR_PARAM ] );
		}
	}

	/**
	 * What a visitor gets while no artifact exists yet.
	 */
	private function optimizeAsVisitor( string $html ): string {
		return ( new UnusedCssOptimizer( new \GTPerformance\Core\Logger() ) )->optimize( $html );
	}

	/**
	 * The critical defect: the engine parsed the document and serialised it back,
	 * which lowercased inline-SVG camelCase and entity-encoded all non-ASCII, then
	 * wrote the result into the page cache.
	 */
	public function test_it_never_disturbs_markup_it_was_not_aimed_at(): void {
		$html = $this->page(
			'<style>.card{color:red}.unused-xyz{color:#000}</style>',
			'<svg viewBox="0 0 24 24"><clipPath id="c"><rect/></clipPath><feGaussianBlur stdDeviation="2"/></svg>'
			. '<p class="hi">नमस्ते दुनिया</p><div class="card">y</div>'
			. '<script type="application/ld+json">{"n":"</body> in a string"}</script>'
			. '<script>var c = "</body>";</script>'
		);

		$out = $this->optimize( $html );

		foreach ( array( 'viewBox="0 0 24 24"', 'clipPath', 'feGaussianBlur', 'stdDeviation' ) as $name ) {
			self::assertStringContainsString( $name, $out, "SVG {$name} must survive." );
		}
		self::assertStringContainsString( 'नमस्ते दुनिया', $out, 'Non-ASCII must not be entity-encoded.' );
		self::assertStringContainsString( '"</body> in a string"', $out );
		self::assertStringContainsString( 'var c = "</body>";', $out );
		self::assertStringNotContainsString( 'data-gtp-css', $out, 'Internal stamps must not reach the browser.' );
	}

	public function test_it_keeps_used_rules_and_drops_unused_ones(): void {
		$out = $this->optimize(
			$this->page( '<style>.card{color:red}.unused-xyz{color:#000}</style>', '<div class="card">y</div>' )
		);

		self::assertStringContainsString( '.card', $out );
		self::assertStringNotContainsString( 'unused-xyz', $out );
	}

	/**
	 * Escaped utility classes are the shape Tailwind generates for every responsive
	 * variant. They were tokenised before matching, matched nothing, and were pruned
	 * as unused, which emptied the stylesheet of a utility-first theme.
	 */
	public function test_escaped_utility_classes_survive(): void {
		$out = $this->optimize(
			$this->page(
				'<style>.md\:flex{display:flex}.w-1\/2{width:50%}.\32 xl\:block{display:block}</style>',
				'<div class="md:flex w-1/2 2xl:block">y</div>'
			)
		);

		self::assertStringContainsString( 'md\:flex', $out );
		self::assertStringContainsString( 'w-1\/2', $out );
		self::assertStringContainsString( '\\32 xl\\:block', $out, 'The escape must survive verbatim.' );
	}

	/**
	 * The parser drops every nested block and mangles a @layer statement list into
	 * structurally broken CSS. Neither raises an error, so the engine checks the
	 * round trip and passes the stylesheet through untouched instead.
	 *
	 * @dataProvider unparseableConstructs
	 */
	public function test_stylesheets_the_parser_cannot_model_are_passed_through( string $css, string $must ): void {
		$out = $this->optimize( $this->page( '<style>' . $css . '</style>', '<div class="card">y</div>' ) );

		self::assertStringContainsString( $must, $out );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function unparseableConstructs(): array {
		return array(
			'native nesting'   => array( '.card{color:red;&:hover{color:blue}}', '&:hover' ),
			'@layer statement' => array( "@layer base, components;\n.card{color:red}", '@layer base, components' ),
		);
	}

	/**
	 * `rel="alternate stylesheet"` is a theme the visitor has not chosen. It was
	 * collected, merged into the active CSS and removed from the page, which made an
	 * unselected colour scheme the live one.
	 */
	public function test_alternate_and_disabled_stylesheets_are_left_alone(): void {
		$out = $this->optimize(
			$this->page(
				'<link rel="alternate stylesheet" href="/dark.css"><style>.card{color:red}</style>',
				'<div class="card">y</div>'
			)
		);

		self::assertStringContainsString( 'rel="alternate stylesheet"', $out );
		self::assertStringContainsString( '/dark.css', $out );
	}

	/**
	 * The defect this replaces: pruning ran inside the visitor's request and took
	 * seconds on a real page.
	 */
	public function test_a_visitor_never_pays_for_generation(): void {
		// A page shape no other test has generated for, so no artifact can be reused.
		$html = $this->page(
			'<style>.visitor-only-shape{color:red}</style>',
			'<section class="visitor-only-shape">y</section>'
		);

		self::assertSame(
			$html,
			$this->optimizeAsVisitor( $html ),
			'A visitor gets the page unchanged; the build is queued instead.'
		);

		// And once the generator has run, the next visitor reuses its work.
		$this->optimize( $html );
		self::assertNotSame( $html, $this->optimizeAsVisitor( $html ), 'The generated artifact must be reused.' );
	}

	public function test_it_is_off_unless_enabled(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['css']['enabled'] = false;
		$html = $this->page( '<style>.unused-xyz{color:#000}</style>', '<div class="card">y</div>' );

		self::assertSame( $html, $this->optimize( $html ) );
	}
}
