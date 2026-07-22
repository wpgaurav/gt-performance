<?php
/**
 * CDN URL rewriting tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\CDN\UrlRewriter;
use PHPUnit\Framework\TestCase;

final class CdnUrlRewriterTest extends TestCase {
	private function rewriter(): UrlRewriter {
		return new UrlRewriter(
			'https://cdn.example.net/static',
			array( 'https://example.com/', 'https://www.example.com/wp/' ),
			array( 'css', 'js', 'jpg', 'webp', 'woff2' )
		);
	}

	public function test_rewrites_same_origin_allowed_file_and_preserves_query(): void {
		$url = 'https://example.com/wp-content/app.css?ver=12#theme';

		self::assertSame(
			'https://cdn.example.net/static/wp-content/app.css?ver=12#theme',
			$this->rewriter()->rewrite( $url )
		);
	}

	public function test_rewrites_root_relative_allowed_file(): void {
		self::assertSame(
			'https://cdn.example.net/static/wp-content/image.webp',
			$this->rewriter()->rewrite( '/wp-content/image.webp' )
		);
	}

	public function test_leaves_html_external_and_disallowed_files_unchanged(): void {
		$rewriter = $this->rewriter();

		self::assertSame( 'https://example.com/account/', $rewriter->rewrite( 'https://example.com/account/' ) );
		self::assertSame( 'https://example.com/data.json', $rewriter->rewrite( 'https://example.com/data.json' ) );
		self::assertSame( 'https://third-party.test/app.js', $rewriter->rewrite( 'https://third-party.test/app.js' ) );
		self::assertSame( 'data:image/png;base64,abc', $rewriter->rewrite( 'data:image/png;base64,abc' ) );
	}

	public function test_rewrites_srcset_and_inline_css(): void {
		$rewriter = $this->rewriter();

		self::assertSame(
			'https://cdn.example.net/static/a.jpg 1x, https://cdn.example.net/static/a@2x.jpg 2x',
			$rewriter->rewriteSrcset( '/a.jpg 1x, https://example.com/a@2x.jpg 2x' )
		);
		self::assertSame(
			'background:url("https://cdn.example.net/static/wp-content/hero.webp") center/cover',
			$rewriter->rewriteCss( 'background:url("/wp-content/hero.webp") center/cover' )
		);
	}

	public function test_rewrites_html_attributes_without_changing_script_content(): void {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Deliberate HTML rewriting fixture.
		$html = '<link rel="stylesheet" href="https://example.com/app.css"><img src="/image.jpg" srcset="/image.jpg 1x, /image@2x.jpg 2x"><script>const value = "</b>";</script>';
		$output = $this->rewriter()->rewriteHtml( $html );

		self::assertStringContainsString( 'href="https://cdn.example.net/static/app.css"', $output );
		self::assertStringContainsString( 'src="https://cdn.example.net/static/image.jpg"', $output );
		self::assertStringContainsString( 'https://cdn.example.net/static/image@2x.jpg 2x', $output );
		self::assertStringContainsString( 'const value = "</b>";', $output );
	}

	public function test_html_rewriting_is_idempotent_for_nested_response_buffers(): void {
		$html = '<img src="https://example.com/image.jpg">';

		$once = $this->rewriter()->rewriteHtml( $html );

		self::assertSame( $once, $this->rewriter()->rewriteHtml( $once ) );
	}
}
