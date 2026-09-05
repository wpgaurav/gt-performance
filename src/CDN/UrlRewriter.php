<?php
/**
 * Same-origin static asset URL rewriting for a pull CDN.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\CDN;

final class UrlRewriter {
	private string $cdnBase;

	/** @var list<string> */
	private array $sourceHosts;

	/** @var list<string> */
	private array $fileTypes;

	/**
	 * @param list<string> $sourceUrls Source site URLs.
	 * @param list<string> $fileTypes Allowed extensions without dots.
	 */
	public function __construct( string $cdnUrl, array $sourceUrls, array $fileTypes ) {
		$this->cdnBase    = self::normalizeBase( $cdnUrl );
		$this->sourceHosts = self::hosts( $sourceUrls );
		$this->fileTypes  = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $type ): string => strtolower( ltrim( trim( (string) $type ), '.' ) ),
						$fileTypes
					)
				)
			)
		);
	}

	public function rewrite( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || '' === $this->cdnBase || str_starts_with( $url, '#' ) ) {
			return $url;
		}

		$lower = strtolower( $url );
		foreach ( array( 'data:', 'blob:', 'mailto:', 'tel:', 'javascript:' ) as $scheme ) {
			if ( str_starts_with( $lower, $scheme ) ) {
				return $url;
			}
		}

		if ( str_starts_with( $url, $this->cdnBase . '/' ) || $url === $this->cdnBase ) {
			return $url;
		}

		$isRootRelative = str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' );
		$parseTarget    = str_starts_with( $url, '//' ) ? 'https:' . $url : $url;
		$parts          = $isRootRelative ? wp_parse_url( 'https://gtperf.invalid' . $url ) : wp_parse_url( $parseTarget );
		if ( ! is_array( $parts ) ) {
			return $url;
		}

		if ( ! $isRootRelative ) {
			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! in_array( $host, $this->sourceHosts, true ) ) {
				return $url;
			}
		}

		$path      = (string) ( $parts['path'] ?? '' );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $path || '' === $extension || ! in_array( $extension, $this->fileTypes, true ) ) {
			return $url;
		}

		$rewritten = $this->cdnBase . '/' . ltrim( $path, '/' );
		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			$rewritten .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) && '' !== (string) $parts['fragment'] ) {
			$rewritten .= '#' . $parts['fragment'];
		}

		return $rewritten;
	}

	public function rewriteSrcset( string $srcset ): string {
		if ( '' === trim( $srcset ) || str_contains( strtolower( $srcset ), 'data:' ) ) {
			return $srcset;
		}

		$candidates = explode( ',', $srcset );
		foreach ( $candidates as &$candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}

			$parts     = preg_split( '/\s+/', $candidate, 2 );
			$url       = (string) ( $parts[0] ?? '' );
			$descriptor = (string) ( $parts[1] ?? '' );
			$candidate = $this->rewrite( $url ) . ( '' !== $descriptor ? ' ' . $descriptor : '' );
		}
		unset( $candidate );

		return implode( ', ', $candidates );
	}

	public function rewriteCss( string $css ): string {
		$rewritten = preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
			fn( array $match ): string => 'url(' . $match[1] . $this->rewrite( trim( (string) $match[2] ) ) . $match[1] . ')',
			$css
		);

		return is_string( $rewritten ) ? $rewritten : $css;
	}

	/**
	 * Rewrite same-site asset URLs onto the CDN host.
	 *
	 * This used to round-trip the whole document through DOMDocument, which
	 * lowercased inline-SVG camelCase attributes and entity-encoded all non-ASCII
	 * text on every page it touched. WP_HTML_Tag_Processor walks the same tags
	 * without reserialising anything it was not asked to change.
	 */
	public function rewriteHtml( string $html ): string {
		if ( '' === trim( $html ) || ! str_contains( $html, '<' ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$changed   = false;

		while ( $processor->next_tag() ) {
			$tag = (string) $processor->get_tag();

			// An SVG sprite reference resolves against the SVG document, and browsers
			// refuse to resolve one across origins. Moving it to the CDN host makes every
			// icon disappear.
			if ( 'USE' === $tag ) {
				continue;
			}

			// Subresource Integrity binds a hash to a same-origin fetch. Moving the URL
			// cross-origin without CORS makes the browser drop the resource entirely.
			if ( null !== $processor->get_attribute( 'integrity' ) ) {
				continue;
			}

			foreach ( array( 'src', 'href', 'poster', 'data-src', 'data-lazy-src', 'data-original', 'data-bg' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$rewritten = $this->rewrite( $value );
				if ( $rewritten !== $value ) {
					$processor->set_attribute( $attribute, $rewritten );
					$changed = true;

					// A font fetched from another origin needs CORS on both sides; without
					// the attribute the browser discards the preload and fetches twice.
					if ( 'LINK' === $tag && 'font' === strtolower( (string) $processor->get_attribute( 'as' ) ) ) {
						$processor->set_attribute( 'crossorigin', 'anonymous' );
					}
				}
			}

			foreach ( array( 'srcset', 'data-srcset' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$rewritten = $this->rewriteSrcset( $value );
				if ( $rewritten !== $value ) {
					$processor->set_attribute( $attribute, $rewritten );
					$changed = true;
				}
			}

			$style = $processor->get_attribute( 'style' );
			if ( is_string( $style ) && '' !== $style ) {
				$rewritten = $this->rewriteCss( $style );
				if ( $rewritten !== $style ) {
					$processor->set_attribute( 'style', $rewritten );
					$changed = true;
				}
			}
		}

		$html = $changed ? $processor->get_updated_html() : $html;

		// <style> element content is text, not attributes, so the tag processor cannot
		// reach it. Match the element itself rather than reparsing the document.
		$out = preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
			fn ( array $m ): string => $m[1] . $this->rewriteCss( $m[2] ) . $m[3],
			$html
		);

		return is_string( $out ) ? $out : $html;
	}

	private static function normalizeBase( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return '';
		}

		$base = 'https://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$base .= ':' . (int) $parts['port'];
		}
		$path = trim( (string) ( $parts['path'] ?? '' ), '/' );

		return $base . ( '' !== $path ? '/' . $path : '' );
	}

	/**
	 * @param list<string> $urls Source URLs.
	 * @return list<string>
	 */
	private static function hosts( array $urls ): array {
		$hosts = array();
		foreach ( $urls as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}

		return array_values( array_unique( $hosts ) );
	}
}
