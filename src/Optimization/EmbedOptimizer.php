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

	/**
	 * YouTube's own large play button: the rounded red plate and the white triangle.
	 * Decorative, because the button's aria-label already names the action.
	 */
	private const PLAY_ICON = '<svg viewBox="0 0 68 48" width="68" height="48" aria-hidden="true" focusable="false">'
		. '<path class="gtp-youtube-plate" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55c-2.93.78-4.63 3.26-5.42 6.19C.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z"/>'
		. '<path fill="#fff" d="M45 24 27 14v20z"/></svg>';

	/**
	 * The preview carries its own 16:9 box because a bare iframe does. Inside a
	 * responsive embed block, core already reserves that box with a padding
	 * `::before` on the wrapper and pins the iframe over it, so the preview has to
	 * be pinned the same way. Keeping its 16:9 there stacked a second box under the
	 * reserved one, leaving an empty band the height of the video above it. The
	 * wrapper selector mirrors core's `.wp-has-aspect-ratio iframe` rule exactly, so
	 * the two agree on every theme.
	 *
	 * Nothing here is inline, so a theme's own embed wrapper can still position it.
	 */
	private const STYLES = '.gtp-youtube{position:relative;width:100%;aspect-ratio:16/9;overflow:hidden;background:#000 center/cover no-repeat}'
		. '.wp-embed-responsive .wp-has-aspect-ratio .gtp-youtube{position:absolute;inset:0;height:100%;aspect-ratio:auto}'
		. '.gtp-youtube>button{position:absolute;inset:0;width:100%;height:100%;margin:0;padding:0;border:0;border-radius:0;background:none;box-shadow:none;cursor:pointer}'
		. '.gtp-youtube>button svg{position:absolute;top:50%;left:50%;width:68px;height:48px;transform:translate(-50%,-50%)}'
		. '.gtp-youtube-plate{fill:#f00;transition:fill .1s}'
		. '.gtp-youtube>button:hover .gtp-youtube-plate,.gtp-youtube>button:focus-visible .gtp-youtube-plate{fill:#c00}'
		. '.gtp-youtube>button:focus-visible{outline:3px solid #fff;outline-offset:-6px}'
		. '.gtp-youtube iframe{position:absolute;inset:0;width:100%;height:100%;border:0}';

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

				// The button covers the whole thumbnail, so a click anywhere plays, as
				// it does on YouTube's own embed.
				return sprintf(
					'<div class="gtp-youtube" data-video-id="%1$s" data-video-query="%2$s" style="background-image:url(https://i.ytimg.com/vi/%1$s/hqdefault.jpg)">'
					. '<button type="button" aria-label="%3$s">%4$s</button></div>',
					esc_attr( rawurlencode( $videoId ) ),
					esc_attr( $query ),
					esc_attr( $label ),
					self::PLAY_ICON
				);
			},
			$html
		);

		if ( ! is_string( $out ) || 0 === $replaced ) {
			return $html;
		}

		$out = $this->injectBeforeLast( $out, '</head>', BufferedAssets::style( 'youtube', self::STYLES ) );

		return $this->injectBeforeLast( $out, '</body>', $this->playerScript() );
	}

	private function playerScript(): string {
		return BufferedAssets::script( 'youtube' );
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

		$style = BufferedAssets::style(
			'lazy-render',
			implode( ',', $valid ) . '{content-visibility:auto;contain-intrinsic-size:auto 800px}'
		);

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
