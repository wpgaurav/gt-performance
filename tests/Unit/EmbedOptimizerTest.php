<?php
/**
 * YouTube preview markup and the styles it depends on.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\EmbedOptimizer;
use PHPUnit\Framework\TestCase;

final class EmbedOptimizerTest extends TestCase {
	/** What the core YouTube embed block renders in a theme with responsive embeds. */
	private const BLOCK_PAGE = '<!doctype html><html><head><title>t</title></head><body class="wp-embed-responsive">'
		. '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio">'
		. '<div class="wp-block-embed__wrapper"><iframe title="Checkout layouts" width="500" height="281" src="https://www.youtube.com/embed/IVtqxDp0EX8?feature=oembed" frameborder="0" allowfullscreen></iframe></div>'
		. '<figcaption class="wp-element-caption">Caption</figcaption></figure></body></html>';

	protected function setUp(): void {
		unset( $GLOBALS['wp_styles'], $GLOBALS['wp_scripts'] );
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array( 'media' => array( 'youtube_previews' => true ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_styles'], $GLOBALS['wp_scripts'], $GLOBALS['gtperf_test_options']['gt_performance_settings'] );
	}

	/**
	 * Core reserves the 16:9 box with a padding `::before` on the wrapper and pins
	 * the iframe over it. The preview used to keep an inline 16:9 box of its own,
	 * which an inline style guarantees no stylesheet can undo, so it stacked under
	 * the reserved box and left an empty band the height of the video above it.
	 */
	public function test_preview_inside_a_responsive_embed_block_is_pinned_over_the_reserved_box(): void {
		$output = ( new EmbedOptimizer() )->optimize( self::BLOCK_PAGE );

		self::assertStringNotContainsString( '<iframe', $output );
		self::assertSame( 1, preg_match( '#<div class="gtp-youtube"[^>]*\bstyle="([^"]*)"#', $output, $inline ) );
		self::assertSame( 'background-image:url(https://i.ytimg.com/vi/IVtqxDp0EX8/hqdefault.jpg)', $inline[1], 'Sizing must not be inline, or nothing can override it.' );

		$head = substr( $output, 0, (int) strpos( $output, '</head>' ) );
		self::assertSame( 1, preg_match( '#<style[^>]*data-gt-performance="youtube"[^>]*>(.*?)</style>#s', $head, $style ), 'The preview styles belong in the head, before the preview renders.' );
		self::assertSame( 1, preg_match( '#\.wp-embed-responsive \.wp-has-aspect-ratio \.gtp-youtube\{([^}]*)\}#', $style[1], $pinned ), 'The wrapper rule must mirror core\'s selector.' );
		self::assertStringContainsString( 'position:absolute', $pinned[1] );
		self::assertStringContainsString( 'inset:0', $pinned[1] );
		self::assertStringContainsString( 'aspect-ratio:auto', $pinned[1] );

		self::assertStringContainsString( 'feature=oembed', $output, 'Embed parameters must be preserved.' );
		self::assertStringContainsString( '/assets/youtube.js', substr( $output, (int) strpos( $output, '</head>' ) ) );
	}

	public function test_play_button_is_youtubes_icon_covering_the_whole_thumbnail(): void {
		$output = ( new EmbedOptimizer() )->optimize( self::BLOCK_PAGE );

		self::assertSame( 1, preg_match( '#<button type="button" aria-label="Play video: Checkout layouts">(.*?)</button>#s', $output, $button ) );
		self::assertStringStartsWith( '<svg viewBox="0 0 68 48"', $button[1], 'The SVG keeps its camelCase viewBox.' );
		self::assertStringContainsString( 'aria-hidden="true"', $button[1], 'The label is on the button; the icon is decorative.' );
		self::assertSame( '', trim( strip_tags( $button[1] ) ), 'No visible text next to the icon.' );

		self::assertSame( 1, preg_match( '#\.gtp-youtube>button\{([^}]*)\}#', $output, $rule ) );
		self::assertStringContainsString( 'inset:0', $rule[1], 'A click anywhere on the thumbnail plays.' );
		self::assertStringContainsString( 'background:none', $rule[1], 'Theme button styling must not show through.' );
	}

	public function test_a_page_without_a_replaceable_embed_gets_no_preview_assets(): void {
		$html = '<!doctype html><html><head></head><body><iframe src="https://www.youtube.com/embed/videoseries?list=PL123456"></iframe></body></html>';

		self::assertSame( $html, ( new EmbedOptimizer() )->optimize( $html ) );
	}
}
