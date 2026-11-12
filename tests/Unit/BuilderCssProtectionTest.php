<?php
/**
 * Page builder state styles survive unused CSS removal.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Compatibility\PluginDetector;
use GTPerformance\Optimization\Css\CssPruner;
use GTPerformance\Optimization\Css\SelectorSafelist;
use PHPUnit\Framework\TestCase;

final class BuilderCssProtectionTest extends TestCase {
	private function document( string $body ): \DOMDocument {
		$document = new \DOMDocument();
		$document->loadHTML( '<!doctype html><html><body>' . $body . '</body></html>', LIBXML_NOERROR );

		return $document;
	}

	public function test_builders_are_detected_by_plugin_or_theme_and_libraries_are_added_once(): void {
		$detector = new PluginDetector();

		self::assertSame( array(), $detector->builderCssSafelist( array( 'akismet/akismet.php' ), array(), 'twentytwentyfive' ), 'No builder, no extra safelist.' );

		$bricks = $detector->builderCssSafelist( array(), array(), 'bricks' );
		self::assertContains( 'brx-open', $bricks );
		self::assertContains( 'swiper-', $bricks );

		$both = $detector->builderCssSafelist( array( 'elementor/elementor.php', 'generateblocks-pro/plugin.php' ), array(), 'Divi' );
		self::assertContains( 'elementor-active', $both );
		self::assertContains( 'et_pb_toggle_open', $both, 'Theme names match case-insensitively.' );
		self::assertContains( 'gblocks-action-message--show', $both );
		self::assertSame( 1, count( array_keys( $both, 'swiper-', true ) ) );

		self::assertSame( array( 'gt-page-block', '/plugins/page-blocks-builder/' ), $detector->builderStylesheetExclusions( array( 'page-blocks-builder/page-blocks-builder.php' ), array(), '' ) );
	}

	public function test_every_builder_pattern_is_a_valid_safelist_entry(): void {
		foreach ( PluginDetector::builders() as $id => $builder ) {
			$validation = ( new SelectorSafelist() )->validate( array_merge( $builder['css_safelist'], PluginDetector::RUNTIME_LIBRARY_SAFELIST ) );
			self::assertSame( array(), $validation['invalid'], $id );
		}
	}

	public function test_runtime_state_rules_survive_pruning_while_unused_css_does_not(): void {
		$css = '.brxe-nav-menu .brx-open .sub-menu{display:block}'
			. '.brx-nav-nested-items.active{opacity:1}'
			. '.interactive-map{color:red}'
			. '.elementor-tab-title.elementor-active{color:blue}'
			. '.gb-accordion__item-open .gb-accordion__content{display:block}'
			. '.swiper-slide-active{transform:none}'
			. '.never-used-anywhere{color:green}';
		$html = '<nav class="brxe-nav-menu"><ul class="brx-nav-nested-items"></ul></nav>';
		$safelist = ( new PluginDetector() )->builderCssSafelist( array( 'elementor/elementor.php', 'generateblocks/plugin.php' ), array(), 'bricks' );

		$output = ( new CssPruner() )->prune( $css, $this->document( $html ), 'used', $safelist );

		foreach ( array( '.brx-open', '.brx-nav-nested-items.active', '.elementor-active', '.gb-accordion__item-open', '.swiper-slide-active' ) as $kept ) {
			self::assertStringContainsString( $kept, $output, $kept );
		}
		self::assertStringNotContainsString( 'never-used-anywhere', $output );
		self::assertStringNotContainsString( 'interactive-map', $output, 'Whole-word state patterns do not keep ".interactive-…".' );
	}

	public function test_core_support_portal_styles_are_never_collected(): void {
		// The portal's image previews are built by app.js, so the server HTML holds an
		// empty list. Pruned against that HTML, the rule capping screenshot size goes.
		$css      = '.csp-previews img{max-width:min(320px,100%);max-height:240px}.csp-avatar{width:32px}';
		$document = $this->document( '<div id="csp-detail"></div>' );
		self::assertStringNotContainsString( 'csp-previews', ( new CssPruner() )->prune( $css, $document, 'used' ), 'Unprotected, the pruner drops the rules app.js needs.' );

		$detector   = new PluginDetector();
		$exclusions = $detector->stylesheetExclusions( array( 'core-support/core-support.php' ), array(), 'twentytwentyfive' );
		$collector  = new \GTPerformance\Optimization\Css\StylesheetCollector();

		self::assertFalse( $collector->reads( home_url( '/wp-content/plugins/core-support/assets/app.css?ver=1.2.0' ), $exclusions ) );
		self::assertFalse( $collector->reads( home_url( '/wp-content/plugins/core-support/assets/icons.css' ), $exclusions ) );
		self::assertTrue( $collector->reads( home_url( '/wp-content/themes/twentytwentyfive/style.css' ), $exclusions ), 'Theme CSS is still optimized.' );

		$collected = $collector->collect( $this->document( '<style id="core-support-custom-inline-css">.csp-shell{max-width:960px}</style><style id="theme-inline-css">.theme-only{color:red}</style>' ), $exclusions );
		$collected = implode( '', array_map( static fn ( $sheet ): string => $sheet->css, $collected['stylesheets'] ) );
		self::assertStringNotContainsString( 'csp-shell', $collected, 'Core Support custom CSS is left whole.' );
		self::assertStringContainsString( '.theme-only', $collected );

		self::assertSame( array(), $detector->stylesheetExclusions( array( 'akismet/akismet.php' ), array(), 'twentytwentyfive' ), 'Nothing is excluded while Core Support is inactive.' );
	}

	public function test_own_plugins_are_left_whole_and_kept_out_of_script_delay(): void {
		$detector = new PluginDetector();
		$plugins  = array(
			'core-support/core-support.php'             => '/plugins/core-support/',
			'core-forms/core-forms.php'                 => '/plugins/core-forms/',
			'core-bookings/core-bookings.php'           => '/plugins/core-bookings/',
			'core-approvals/core-approvals.php'         => '/plugins/core-approvals/',
			'core-tables/core-tables.php'               => '/plugins/core-tables/',
			'stackless-edd/stackless-edd.php'           => '/plugins/stackless-edd/',
			'stackless-lite-edd/stackless-lite-edd.php' => '/plugins/stackless-lite-edd/',
			'stackless/stackless.php'                   => '/plugins/stackless/',
			'stackless-lite/stackless-lite.php'         => '/plugins/stackless-lite/',
		);

		foreach ( $plugins as $file => $fragment ) {
			self::assertContains( $fragment, $detector->stylesheetExclusions( array( $file ), array(), '' ), $file );
			self::assertContains( $fragment, $detector->javascriptExclusionsForPlugins( array( $file ) ), $file );
			self::assertContains( $fragment, $detector->stylesheetExclusions( array(), array( $file ), '' ), $file . ' network-active' );
		}

		// `/plugins/stackless/` must not swallow a plugin whose slug merely starts with it.
		$collector = new \GTPerformance\Optimization\Css\StylesheetCollector();
		self::assertTrue( $collector->reads( home_url( '/wp-content/plugins/stackless-reviews/style.css' ), $detector->stylesheetExclusions( array( 'stackless/stackless.php' ), array(), '' ) ) );
	}

	public function test_stackless_theme_states_survive_pruning(): void {
		$detector = new PluginDetector();
		$safelist = $detector->cssSafelist( array(), array(), 'stackless-theme' );

		self::assertSame( array(), ( new SelectorSafelist() )->validate( $safelist )['invalid'] );
		self::assertSame( array(), $detector->cssSafelist( array(), array(), 'twentytwentyfive' ) );
		self::assertNotContains( 'swiper-', $safelist, 'A theme alone does not pull in the builder runtime libraries.' );

		$css    = '.js .site-nav{display:none}.nav-open .site-nav{display:block}.search-open .search-panel{opacity:1}'
			. '.site-header.is-scrolled{box-shadow:0 1px 0 #ddd}.is-loading .results{opacity:.5}.json-viewer{color:red}';
		$output = ( new CssPruner() )->prune( $css, $this->document( '<header class="site-header"></header>' ), 'used', $safelist );

		foreach ( array( '.js .site-nav', '.nav-open', '.search-open', '.is-scrolled', '.is-loading' ) as $kept ) {
			self::assertStringContainsString( $kept, $output, $kept );
		}
		self::assertStringNotContainsString( 'json-viewer', $output );
	}

	public function test_own_plugins_appear_only_when_active(): void {
		foreach ( PluginDetector::apps() as $id => $app ) {
			self::assertSame( 'app', $app['group'], $id );
			self::assertArrayHasKey( $id, ( new PluginDetector() )->catalog(), $id );
		}
	}

	public function test_gt_page_blocks_inline_styles_are_left_whole(): void {
		$document = $this->document( '<style id="gt-page-block-css">.pbb-hero.visible{opacity:1}[data-theme=dark] .pbb-card{color:#fff}</style><style id="theme-inline-css">.theme-only{color:red}</style>' );
		$exclusions = ( new PluginDetector() )->builderStylesheetExclusions( array( 'page-blocks-builder/page-blocks-builder.php' ), array(), '' );

		$collected = ( new \GTPerformance\Optimization\Css\StylesheetCollector() )->collect( $document, $exclusions );
		$css       = implode( '', array_map( static fn ( $sheet ): string => $sheet->css, $collected['stylesheets'] ) );

		self::assertStringContainsString( '.theme-only', $css, 'Other inline styles are still optimized.' );
		self::assertStringNotContainsString( 'pbb-hero', $css, 'Page block CSS is never collected, so it is never pruned.' );
	}
}
