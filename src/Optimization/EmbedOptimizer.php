<?php
/**
 * Lightweight YouTube previews and lazy-render styles.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Settings;

/**
 * This class used to round-trip the whole document through DOMDocument to swap a
 * handful of iframes. That reparse lowercased every camelCase name in inline SVG
 * (`viewBox` became `viewbox`, silently breaking every inline icon) and
 * entity-encoded all non-ASCII text. An iframe cannot nest and its element content
 * is ignored by browsers, so matching the iframe tag itself is both sufficient and
 * incapable of touching markup it was not aimed at.
 */
final class EmbedOptimizer {
	/**
	 * Paths that look like a video id but are not one. `/embed/videoseries?list=…`
	 * and `/embed/live_stream?channel=…` were previously treated as ids, producing a
	 * facade that linked to a video that does not exist and discarding the playlist
	 * or channel entirely.
	 */
	private const RESERVED_PATHS = array( 'videoseries', 'live_stream' );

	public function optimize( string $html ): string {
		$youtube   = (bool) Settings::get( 'media.youtube_previews', false );
		$selectors = array_map( 'strval', (array) Settings::get( 'media.lazy_render_selectors', array() ) );
		if ( ! $youtube && ! $selectors ) {
			return $html;
		}

		if ( $youtube ) {
			$html = $this->replaceYoutube( $html );
		}
		if ( $selectors ) {
			$html = $this->appendLazyRender( $html, $selectors );
		}

		return $html;
	}

	private function replaceYoutube( string $html ): string {
		$replaced = 0;

		$out = preg_replace_callback(
			'#<iframe\b([^>]*)>(?:\s*</iframe\s*>)?#i',
			function ( array $matches ) use ( &$replaced ): string {
				$attributes = $matches[1];
				if ( ! preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#is', $attributes, $src ) ) {
					return $matches[0];
				}

				$url = html_entity_decode( $src[2], ENT_QUOTES | ENT_HTML5 );
				if ( ! preg_match( '~^(?:https?:)?//(?:www\.)?(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([^/?\#]+)~i', $url, $id ) ) {
					return $matches[0];
				}

				$videoId = $id[1];
				if ( in_array( strtolower( $videoId ), self::RESERVED_PATHS, true ) || ! preg_match( '/^[A-Za-z0-9_-]{6,}$/', $videoId ) ) {
					// A playlist, a live stream, or something that is not an id. There is no
					// single thumbnail to stand in for it, so leave the real embed alone.
					return $matches[0];
				}

				$title = preg_match( '#\btitle\s*=\s*(["\'])(.*?)\1#is', $attributes, $t ) ? $t[2] : '';
				$label = '' !== $title
					? sprintf( /* translators: %s: video title. */ __( 'Play video: %s', 'gt-performance' ), html_entity_decode( $title, ENT_QUOTES | ENT_HTML5 ) )
					: __( 'Play YouTube video', 'gt-performance' );

				// Everything after the id is the embed's own configuration (start time,
				// captions, playlist). Discarding it changed what the visitor gets.
				$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

				++$replaced;

				return sprintf(
					'<div class="gtp-youtube" data-video-id="%1$s" data-video-query="%2$s" style="aspect-ratio:16/9;position:relative;background:#000 url(https://i.ytimg.com/vi/%1$s/hqdefault.jpg) center/cover no-repeat">'
					. '<button type="button" aria-label="%3$s" style="position:absolute;inset:0;margin:auto;width:5rem;height:3.5rem;cursor:pointer">%4$s</button></div>',
					esc_attr( rawurlencode( $videoId ) ),
					esc_attr( $query ),
					esc_attr( $label ),
					esc_html__( 'Play', 'gt-performance' )
				);
			},
			$html
		);

		if ( ! is_string( $out ) || 0 === $replaced ) {
			return $html;
		}

		return $this->injectBeforeLast( $out, '</body>', $this->playerScript() );
	}

	private function playerScript(): string {
		return "<script data-gt-performance=\"youtube\">document.addEventListener('click',function(e){var b=e.target.closest('.gtp-youtube button');if(!b)return;var w=b.parentNode,q=w.dataset.videoQuery||'',i=document.createElement('iframe');i.src='https://www.youtube-nocookie.com/embed/'+w.dataset.videoId+'?'+(q?q+'&':'')+'autoplay=1';i.allow='autoplay; encrypted-media; picture-in-picture';i.allowFullscreen=true;i.title=b.getAttribute('aria-label')||'';i.style='width:100%;height:100%;border:0';w.replaceChildren(i);i.focus();});</script>";
	}

	/**
	 * @param list<string> $selectors CSS selectors.
	 */
	private function appendLazyRender( string $html, array $selectors ): string {
		$valid = array_filter(
			$selectors,
			static fn ( string $selector ): bool => (bool) preg_match( "/^[a-zA-Z0-9_#.\-\s>:(),\[\]=\"']+$/", $selector )
		);
		if ( ! $valid ) {
			return $html;
		}

		$style = '<style data-gt-performance="lazy-render">'
			. implode( ',', $valid )
			. '{content-visibility:auto;contain-intrinsic-size:auto 800px}</style>';

		return $this->injectBeforeLast( $html, '</head>', $style );
	}

	/**
	 * Insert before the LAST occurrence of a closing tag.
	 *
	 * A document can contain the literal string `</body>` inside an inline script or
	 * a JSON-LD block; injecting at the first match would land the markup inside that
	 * string. The real closing tag is always the last one.
	 */
	private function injectBeforeLast( string $html, string $tag, string $markup ): string {
		$position = strripos( $html, $tag );

		return false === $position
			? $html . $markup
			: substr( $html, 0, $position ) . $markup . substr( $html, $position );
	}
}
