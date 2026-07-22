<?php
/**
 * Lightweight YouTube previews and lazy-render styles.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Settings;

final class EmbedOptimizer {
	public function optimize( string $html ): string {
		$youtube   = (bool) Settings::get( 'media.youtube_previews', false );
		$selectors = array_map( 'strval', (array) Settings::get( 'media.lazy_render_selectors', array() ) );
		if ( ! $youtube && ! $selectors ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$htmlDocument = new HtmlDocument();
			$document     = $htmlDocument->load( $html );
			if ( null === $document ) {
				return $html;
			}

			if ( $youtube ) {
				$this->replaceYoutube( $document );
			}
			if ( $selectors ) {
				$this->appendLazyRender( $document, $selectors );
			}

			$output = $htmlDocument->save( $document );

			return null === $output ? $html : $output;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	private function replaceYoutube( \DOMDocument $document ): void {
		$xpath   = new \DOMXPath( $document );
		$iframes = $xpath->query( '//iframe[@src]' );
		if ( false === $iframes ) {
			return;
		}

		$replace = array();
		foreach ( $iframes as $iframe ) {
			if ( ! $iframe instanceof \DOMElement ) {
				continue;
			}
			$src = $iframe->getAttribute( 'src' );
			if ( ! preg_match( '#(?:youtube(?:-nocookie)?\\.com/embed/|youtu\\.be/)([a-zA-Z0-9_-]{6,})#', $src, $matches ) ) {
				continue;
			}
			$replace[] = array( $iframe, $matches[1] );
		}

		foreach ( $replace as [ $iframe, $videoId ] ) {
			$wrapper = $document->createElement( 'div' );
			$wrapper->setAttribute( 'class', 'gtp-youtube' );
			$wrapper->setAttribute( 'data-video-id', $videoId );
			$wrapper->setAttribute( 'style', 'aspect-ratio:16/9;position:relative;background:#000 url(https://i.ytimg.com/vi/' . rawurlencode( $videoId ) . '/hqdefault.jpg) center/cover no-repeat' );
			$button = $document->createElement( 'button', 'Play video' );
			$button->setAttribute( 'type', 'button' );
			$button->setAttribute( 'aria-label', 'Play YouTube video' );
			$button->setAttribute( 'style', 'position:absolute;inset:0;margin:auto;width:5rem;height:3.5rem' );
			$wrapper->appendChild( $button );
			$iframe->parentNode?->replaceChild( $wrapper, $iframe );
		}

		if ( $replace ) {
			$body = $document->getElementsByTagName( 'body' )->item( 0 );
			if ( $body instanceof \DOMElement ) {
				$script = $document->createElement(
					'script',
					"document.addEventListener('click',function(e){var b=e.target.closest('.gtp-youtube button');if(!b)return;var w=b.parentNode,i=document.createElement('iframe');i.src='https://www.youtube-nocookie.com/embed/'+w.dataset.videoId+'?autoplay=1';i.allow='autoplay; encrypted-media; picture-in-picture';i.allowFullscreen=true;i.style='width:100%;height:100%;border:0';w.replaceChildren(i);});"
				);
				$script->setAttribute( 'data-gt-performance', 'youtube' );
				$body->appendChild( $script );
			}
		}
	}

	/**
	 * @param list<string> $selectors CSS selectors.
	 */
	private function appendLazyRender( \DOMDocument $document, array $selectors ): void {
		$valid = array_filter(
			$selectors,
			static fn ( string $selector ): bool => (bool) preg_match( "/^[a-zA-Z0-9_#.\\-\\s>:(),\\[\\]=\"']+$/", $selector )
		);
		if ( ! $valid ) {
			return;
		}

		$head = $document->getElementsByTagName( 'head' )->item( 0 );
		if ( ! $head instanceof \DOMElement ) {
			return;
		}
		$style = $document->createElement(
			'style',
			implode( ',', $valid ) . '{content-visibility:auto;contain-intrinsic-size:auto 800px}'
		);
		$style->setAttribute( 'data-gt-performance', 'lazy-render' );
		$head->appendChild( $style );
	}
}
