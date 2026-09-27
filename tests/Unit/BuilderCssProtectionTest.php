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

	public function test_gt_page_blocks_inline_styles_are_left_whole(): void {
		$document = $this->document( '<style id="gt-page-block-css">.pbb-hero.visible{opacity:1}[data-theme=dark] .pbb-card{color:#fff}</style><style id="theme-inline-css">.theme-only{color:red}</style>' );
		$exclusions = ( new PluginDetector() )->builderStylesheetExclusions( array( 'page-blocks-builder/page-blocks-builder.php' ), array(), '' );

		$collected = ( new \GTPerformance\Optimization\Css\StylesheetCollector() )->collect( $document, $exclusions );
		$css       = implode( '', array_map( static fn ( $sheet ): string => $sheet->css, $collected['stylesheets'] ) );

		self::assertStringContainsString( '.theme-only', $css, 'Other inline styles are still optimized.' );
		self::assertStringNotContainsString( 'pbb-hero', $css, 'Page block CSS is never collected, so it is never pruned.' );
	}
}
