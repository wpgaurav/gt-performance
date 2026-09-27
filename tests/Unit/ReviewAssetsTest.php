<?php
/** WordPress.org asset remediation regression coverage. */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\OutputBuffer;
use GTPerformance\Optimization\BufferedAssets;
use GTPerformance\Optimization\EmbedOptimizer;
use GTPerformance\Optimization\JavaScriptOptimizer;
use PHPUnit\Framework\TestCase;

final class ReviewAssetsTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['wp_styles'], $GLOBALS['wp_scripts'] );
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_styles'], $GLOBALS['wp_scripts'], $GLOBALS['gtperf_test_options']['gt_performance_settings'], $GLOBALS['gtperf_test_filters']['style_loader_tag'], $GLOBALS['gtperf_test_filters']['script_loader_tag'] );
	}

	public function test_core_styles_keep_inline_escapes_and_leave_unrelated_queue_alone(): void {
		wp_enqueue_style( 'unrelated', 'https://example.com/other.css' );
		$css = '.icon:before{content:"\\e800"}.hi{font-family:"नमस्ते"}';
		$output = BufferedAssets::style( 'used', $css );
		self::assertStringContainsString( $css, $output );
		self::assertStringContainsString( 'inline-css', $output );
		self::assertStringNotContainsString( 'other.css', $output );
		self::assertTrue( wp_style_is( 'unrelated', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'unrelated', 'done' ) );
		self::assertSame( $output, BufferedAssets::style( 'used', $css ) );
	}

	public function test_file_styles_and_bundled_scripts_use_core_loader_filters(): void {
		$GLOBALS['gtperf_test_filters']['style_loader_tag'][] = static fn ( string $tag ): string => str_replace( '<link ', '<link data-core-filter="yes" ', $tag );
		$GLOBALS['gtperf_test_filters']['script_loader_tag'][] = static fn ( string $tag ): string => str_replace( '<script ', '<script data-core-filter="yes" ', $tag );
		self::assertStringContainsString( 'data-core-filter="yes"', BufferedAssets::style( 'used', '', 'https://example.com/used.css' ) );
		$script = BufferedAssets::script( 'youtube' );
		self::assertStringContainsString( 'data-core-filter="yes"', $script );
		self::assertStringContainsString( '/assets/youtube.js', $script );
		self::assertStringNotContainsString( 'document.addEventListener', $script );
	}

	public function test_inline_css_cannot_close_the_style_element(): void {
		$css = '.example::before{content:"</StYlE><script>alert(1)</script> & < >"}'
			. '/* </style ><img src=x onerror=alert(2)> */'
			. '.icon{background:url("data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\'></svg>")}'
			. '.unicode::after{content:"नमस्ते \\e800"}';
		$output = BufferedAssets::style( 'used', $css );
		$document = new \DOMDocument();
		@$document->loadHTML( '<html><head>' . $output . '</head><body></body></html>' );
		self::assertCount( 1, $document->getElementsByTagName( 'style' ) );
		self::assertCount( 0, $document->getElementsByTagName( 'script' ) );
		self::assertCount( 0, $document->getElementsByTagName( 'img' ) );
		self::assertStringContainsString( '\\3C /StYlE>', $output );
		self::assertStringContainsString( ' & \\3C  >', $output );
		self::assertStringContainsString( 'नमस्ते \\e800', $output );
		self::assertStringNotContainsString( '&lt;', $output );
		self::assertStringNotContainsString( '&amp;', $output );
	}

	public function test_response_transport_preserves_complete_markup_and_exception_fallback(): void {
		$html = '<!doctype html><html><head><script>window.fixture="<&>";</script></head><body>'
			. '<form><input name="token" value="a&amp;b"></form>'
			. '<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>नमस्ते</body></html>';
		foreach ( array(
			static fn( string $response ): string => $response,
			static fn( string $response ): bool => false,
			static function ( string $response ): never { throw new \RuntimeException( 'Fixture failure' ); },
		) as $callback ) {
			ob_start();
			$level = ob_get_level() + 1;
			OutputBuffer::start( $callback );
			echo $html;
			OutputBuffer::close( $level );
			self::assertSame( $html, ob_get_clean() );
		}
	}

	public function test_nested_buffers_transform_in_order_and_can_capture_core_assets(): void {
		ob_start();
		$level = ob_get_level() + 1;
		OutputBuffer::start( static fn ( string $html ): string => '[' . $html . ']' );
		echo 'outer';
		OutputBuffer::start( static fn ( string $html ): string => $html . BufferedAssets::style( 'lazy-render', '.late{content-visibility:auto}' ) );
		echo 'inner';
		OutputBuffer::close( $level );
		$output = ob_get_clean();
		self::assertStringStartsWith( '[outerinner<style ', $output );
		self::assertStringContainsString( '.late{content-visibility:auto}', $output );
		self::assertStringEndsWith( ']', $output );
	}

	public function test_minification_keeps_missing_sources_without_creating_files(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array( 'javascript' => array( 'minify' => true ) );
		$html = '<script src="https://example.com/wp-content/custom.js"></script>';
		self::assertSame( $html, ( new JavaScriptOptimizer() )->optimize( $html ) );
		self::assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/cache/gt-performance/assets/js' );
	}

	public function test_defer_delay_exclusions_and_loader_position(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array( 'javascript' => array( 'defer' => true, 'delay' => true, 'delay_patterns' => array( 'widget.js' ), 'exclusions' => array( 'protected.js' ) ) );
		$html = '<html><head></head><body><script src="/widget.js"></script><script src="/plain.js"></script><script src="/protected.js"></script><script src="/checkout.js"></script><script>var html="</body>";</script></body></html>';
		$output = ( new JavaScriptOptimizer() )->optimize( $html );
		self::assertStringContainsString( 'type="text/gtp-delayed"', $output );
		self::assertStringContainsString( 'data-gtp-src="/widget.js"', $output );
		self::assertStringContainsString( '<script src="/plain.js">', $output, 'A script WordPress did not register has unknown ordering, so it is not deferred.' );
		self::assertStringContainsString( '<script src="/protected.js">', $output );
		self::assertStringContainsString( '<script src="/checkout.js">', $output );
		self::assertStringContainsString( 'var html="</body>";', $output );
		self::assertStringContainsString( '/assets/delay.js', $output );
		self::assertGreaterThan( strpos( $output, 'var html=' ), strpos( $output, '/assets/delay.js' ) );
	}

	public function test_lazy_render_styles_are_printed_by_wordpress(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array( 'media' => array( 'lazy_render_selectors' => array( '.below', 'script{}' ) ) );
		$output = ( new EmbedOptimizer() )->optimize( '<html><head></head><body></body></html>' );
		self::assertStringContainsString( 'inline-css', $output );
		self::assertStringContainsString( '.below{content-visibility:auto;contain-intrinsic-size:auto 800px}', $output );
		self::assertStringNotContainsString( 'script{}', $output );
	}
}
