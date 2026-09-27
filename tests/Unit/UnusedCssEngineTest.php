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
		unset( $GLOBALS['wp_styles'] );
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

	/**
	 * A table of contents that a script fills after load is empty in the HTML the
	 * generator reads, so its rules were pruned until a browser scan learned them.
	 */
	public function test_classes_scripts_add_on_that_post_type_are_kept(): void {
		$html = $this->page(
			'<style>.sp-toc{display:block}.sp-toc .level-3{padding-left:1em}.sp-toc .level-30{color:red}.sp-toc li{margin:0}.sp-toc a:hover{color:blue}</style>',
			'<aside class="sp-toc"><ol id="sp-toc-list"></ol></aside>'
		);
		( new \GTPerformance\Optimization\Css\ScriptClasses() )->save( array( 'post' => array( 'classes' => array( 'level-3' ), 'selectors' => array( '.sp-toc li' ) ) ), array( 'post', 'page' ) );

		try {
			$GLOBALS['gtperf_test_singular'] = 'page';
			self::assertStringNotContainsString( 'level-3', $this->optimize( $html ), 'Another post type learned nothing.' );

			$GLOBALS['gtperf_test_singular'] = 'post';
			$out = $this->optimize( $html );
			self::assertStringContainsString( '.sp-toc .level-3', $out );
			self::assertStringContainsString( '.sp-toc li{', $out, 'A learned selector keeps a rule that names no learned class.' );
			self::assertStringNotContainsString( 'level-30', $out );
			self::assertStringNotContainsString( 'a:hover', $out, 'A selector the scan did not see is still pruned.' );
		} finally {
			unset( $GLOBALS['gtperf_test_singular'], $GLOBALS['gtperf_test_options'][ \GTPerformance\Optimization\Css\ScriptClasses::OPTION ] );
		}
	}

	/**
	 * A comment form prints the current URL. With the token still in it, a build
	 * never matched the page a visitor gets and was never served.
	 */
	public function test_the_build_request_renders_under_the_visitors_url(): void {
		$claimed = new \ReflectionProperty( UnusedCssOptimizer::class, 'claimed' );
		$token   = ( new \ReflectionMethod( UnusedCssOptimizer::class, 'generatorToken' ) )->invoke( null );
		$server  = $_SERVER;

		try {
			$_GET                    = array( 'replytocom' => '4', UnusedCssOptimizer::GENERATOR_PARAM => 'wrong' );
			$_SERVER['REQUEST_URI']  = '/post-1/?replytocom=4&' . UnusedCssOptimizer::GENERATOR_PARAM . '=wrong';
			$_SERVER['QUERY_STRING'] = 'replytocom=4&' . UnusedCssOptimizer::GENERATOR_PARAM . '=wrong';
			UnusedCssOptimizer::claimGeneratorRequest();
			self::assertFalse( UnusedCssOptimizer::isGeneratorRequest() );
			self::assertStringContainsString( 'wrong', $_SERVER['REQUEST_URI'], 'A wrong token changes nothing.' );

			$_GET                    = array( UnusedCssOptimizer::GENERATOR_PARAM => $token, 'replytocom' => '4', UnusedCssOptimizer::GENERATOR_RUN_PARAM => 'a1b2c3' );
			$_SERVER['REQUEST_URI']  = '/post-1/?' . UnusedCssOptimizer::GENERATOR_PARAM . '=' . $token . '&replytocom=4&' . UnusedCssOptimizer::GENERATOR_RUN_PARAM . '=a1b2c3';
			$_SERVER['QUERY_STRING'] = UnusedCssOptimizer::GENERATOR_PARAM . '=' . $token . '&replytocom=4&' . UnusedCssOptimizer::GENERATOR_RUN_PARAM . '=a1b2c3';
			UnusedCssOptimizer::claimGeneratorRequest();

			self::assertTrue( UnusedCssOptimizer::isGeneratorRequest(), 'The request stays a build after the token is gone.' );
			self::assertSame( '/post-1/?replytocom=4', $_SERVER['REQUEST_URI'] );
			self::assertSame( 'replytocom=4', $_SERVER['QUERY_STRING'] );
			self::assertSame( array( 'replytocom' => '4' ), $_GET );

			$_SERVER['REQUEST_URI'] = '/post-1/?' . UnusedCssOptimizer::GENERATOR_PARAM . '=' . $token;
			$_GET                   = array( UnusedCssOptimizer::GENERATOR_PARAM => $token );
			$claimed->setValue( null, false );
			UnusedCssOptimizer::claimGeneratorRequest();
			self::assertSame( '/post-1/', $_SERVER['REQUEST_URI'] );
		} finally {
			$claimed->setValue( null, false );
			$_GET    = array();
			$_SERVER = $server;
		}
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

	/** @dataProvider deliveryModes */
	public function test_core_delivery_and_reuse_in_each_mode( string $mode, int $budget, bool $inline, bool $file ): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['css']['mode'] = $mode;
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['css']['critical_budget'] = $budget;
		$html = $this->page( '<style>.early{color:red}.late{color:blue}.unused-delivery{color:black}</style>', '<div class="early">top</div>' . str_repeat( '<p>filler</p>', 170 ) . '<div class="late">bottom</div>' );
		$output = $this->optimize( $html );
		self::assertNotSame( $html, $output );
		self::assertSame( $inline, str_contains( $output, '<style ' ) );
		self::assertSame( $file, str_contains( $output, '<link ' ) );
		self::assertStringNotContainsString( 'unused-delivery', $output );
		self::assertSame( $output, $this->optimizeAsVisitor( $html ) );
		if ( $inline ) {
			self::assertStringContainsString( '.early', $output );
		}
		if ( $file ) {
			$processor = new \WP_HTML_Tag_Processor( $output );
			self::assertTrue( $processor->next_tag( 'LINK' ) );
			$path = WP_CONTENT_DIR . str_replace( '/wp-content', '', (string) wp_parse_url( (string) $processor->get_attribute( 'href' ), PHP_URL_PATH ) );
			$css = file_get_contents( $path );
			self::assertStringContainsString( '.late', $css );
			self::assertStringNotContainsString( 'unused-delivery', $css );
			unlink( $path );
			self::assertSame( $html, $this->optimizeAsVisitor( $html ), 'Missing artifacts must preserve original styles.' );
		}
	}

	/**
	 * Akismet puts a random number in every comment form. While a build was keyed on
	 * the exact page, a visitor's copy never matched it: posts with comments were
	 * never served their CSS, and every uncached visit queued another build.
	 */
	public function test_a_visitor_gets_the_build_despite_tokens_that_change_every_render(): void {
		$page = fn( string $value ): string => $this->page(
			'<style nonce="n' . $value . '">.card{color:red}.unused-xyz{color:#000}</style>',
			'<div class="card">y</div><form><input type="hidden" name="ak_js" value="' . $value . '"/><input type="text" name="q" value="find"></form>'
			. '<script nonce="n' . $value . '">var rendered = ' . $value . ';</script><!-- built in 0.' . $value . 's -->'
		);
		$this->optimize( $page( '28' ) );

		$out = $this->optimizeAsVisitor( $page( '20' ) );
		self::assertStringNotContainsString( 'unused-xyz', $out, 'The visitor is served the build.' );
		self::assertStringContainsString( 'value="20"', $out, "The visitor's own markup goes out unchanged." );

		$changed = $this->optimizeAsVisitor( str_replace( 'class="card"', 'class="card featured"', $page( '20' ) ) );
		self::assertStringContainsString( 'unused-xyz', $changed, 'A page whose elements changed waits for its own build.' );
	}

	/**
	 * gatilab.com: the theme's stylesheets are excluded and stay in place, while
	 * WordPress's global styles before them and Additional CSS after them are
	 * consolidated. With every generated block at the end of <head>, global styles
	 * moved behind the theme and overrode its fonts and link colours.
	 */
	public function test_stylesheets_left_in_place_keep_their_position_in_the_cascade(): void {
		$html = $this->page(
			'<style id="global-styles-inline-css">a{color:orange}.unused-global{color:red}</style>'
			. '<style disabled>.ignored{color:blue}</style>'
			. '<style id="more-global-css">.card{margin:0}</style>'
			. '<link rel="stylesheet" id="theme-css" href="https://theme.example.net/theme.css">'
			. '<style id="wp-custom-css">.card{font-weight:700}.unused-custom{color:red}</style>',
			'<a href="#">x</a><div class="card">y</div>'
		);

		$out   = $this->optimize( $html );
		$theme = strpos( $out, 'theme.example.net/theme.css' );
		self::assertIsInt( $theme );
		self::assertLessThan( $theme, strpos( $out, 'color:orange' ), 'Global styles stay before the theme.' );
		self::assertLessThan( $theme, strpos( $out, 'margin:0' ), 'A disabled stylesheet does not split a group.' );
		self::assertGreaterThan( $theme, strpos( $out, 'font-weight:700' ), 'Additional CSS stays after the theme.' );
		self::assertSame( 2, substr_count( $out, 'data-gt-performance=' ), 'One generated block per group.' );
		self::assertStringContainsString( '<style disabled>', $out, 'A disabled stylesheet is left alone.' );
		self::assertStringNotContainsString( 'unused-', $out );
		self::assertSame( $out, $this->optimizeAsVisitor( $html ), 'A visitor is served the same placement.' );
	}

	public function test_a_hybrid_build_over_the_limit_records_how_far_over_it_was(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['css']['mode']            = 'hybrid';
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['css']['critical_budget'] = 8;
		$saved           = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			/** @var list<array<string, mixed>> */
			public array $writes = array();
			public function update( string $table, array $data ): int {
				unset( $table );
				$this->writes[] = $data;
				return 1;
			}
			public function prepare( string $query, mixed ...$arguments ): string {
				unset( $arguments );
				return $query;
			}
			public function __call( string $name, array $arguments ): mixed {
				unset( $name, $arguments );
				return null;
			}
		};

		try {
			$this->optimize( $this->page( '<style>.early{color:red}.late{color:blue}</style>', '<div class="early">top</div>' . str_repeat( '<p>filler</p>', 170 ) . '<div class="late">bottom</div>' ) );
			$last = json_decode( (string) end( $GLOBALS['wpdb']->writes )['metadata'], true );
		} finally {
			$GLOBALS['wpdb'] = $saved;
		}

		self::assertSame( 'critical_budget_exceeded', $last['fallback'] );
		self::assertSame( 8, $last['critical_budget'] );
		self::assertGreaterThan( 8, $last['critical_bytes'] );
	}

	/** @return array<string, array{string, int, bool, bool}> */
	public static function deliveryModes(): array {
		return array(
			'inline' => array( 'inline', 14336, true, false ),
			'file' => array( 'file', 14336, false, true ),
			'hybrid' => array( 'hybrid', 14336, true, true ),
			'hybrid budget fallback' => array( 'hybrid', 1, false, true ),
		);
	}
}
