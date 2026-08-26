<?php
/**
 * Pull-CDN URL rewriting module.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\CDN;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\OutputBuffer;
use GTPerformance\Core\Settings;

final class CdnModule implements Module {
	private ?UrlRewriter $rewriter = null;

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'startCapture' ), -10000 );
		add_filter( 'gt_performance_html', array( $this, 'rewriteHtml' ), 60 );
	}

	public function startCapture(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() || null === $this->rewriter() ) {
			return;
		}

		// Start before the page-cache buffer. When origin caching is active, its
		// optimizer pipeline runs first as the inner buffer and this final pass is
		// idempotent. When origin caching is bypassed, CDN delivery still works.
		OutputBuffer::start( array( $this, 'rewriteHtml' ) );
	}

	public function rewriteHtml( string $html ): string {
		$rewriter = $this->rewriter();

		return null === $rewriter ? $html : $rewriter->rewriteHtml( $html );
	}

	private function rewriter(): ?UrlRewriter {
		if ( ! (bool) Settings::get( 'cdn.enabled', false ) ) {
			return null;
		}
		if ( null !== $this->rewriter ) {
			return $this->rewriter;
		}

		$url       = (string) Settings::get( 'cdn.url', '' );
		$fileTypes = array_map( 'strval', (array) Settings::get( 'cdn.file_types', array() ) );
		if ( '' === $url || ! $fileTypes ) {
			return null;
		}

		$this->rewriter = new UrlRewriter(
			$url,
			array( home_url( '/' ), site_url( '/' ), content_url( '/' ) ),
			$fileTypes
		);

		return $this->rewriter;
	}
}
