<?php
/**
 * Same-origin stylesheet collection.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

final class StylesheetCollector {
	/**
	 * @param list<string> $exclusions Excluded URL fragments.
	 * @return array{stylesheets:list<Stylesheet>,nodes:list<\DOMNode>}
	 */
	public function collect( \DOMDocument $document, array $exclusions = array() ): array {
		$xpath       = new \DOMXPath( $document );
		$stylesheets = array();
		$nodes       = array();
		$siteHost    = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		$links = $xpath->query( '//link[contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")][@href]' );
		if ( false !== $links ) {
			foreach ( $links as $link ) {
				if ( ! $link instanceof \DOMElement ) {
					continue;
				}
				$href = html_entity_decode( $link->getAttribute( 'href' ), ENT_QUOTES | ENT_HTML5 );
				$url  = $this->absoluteUrl( $href );
				if ( '' === $url || $this->excluded( $url, $exclusions ) ) {
					continue;
				}

				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( '' !== $host && $host !== $siteHost ) {
					continue;
				}

				$css = $this->fetch( $url );
				if ( is_wp_error( $css ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; never rendered.
					throw new \RuntimeException( $css->get_error_message() );
				}

				$media = $link->getAttribute( 'media' );
				$stylesheets[] = new Stylesheet(
					$url,
					$this->rebaseUrls( $css, $url ),
					'' === $media ? 'all' : $media
				);
				$nodes[]       = $link;
			}
		}

		$inline = $xpath->query( '//style[not(@data-gt-performance)]' );
		if ( false !== $inline ) {
			foreach ( $inline as $style ) {
				if ( ! $style instanceof \DOMElement || '' === trim( $style->textContent ) ) {
					continue;
				}
				$media         = $style->getAttribute( 'media' );
				$stylesheets[] = new Stylesheet( 'inline', $style->textContent, '' === $media ? 'all' : $media );
				$nodes[]       = $style;
			}
		}

		return array(
			'stylesheets' => $stylesheets,
			'nodes'       => $nodes,
		);
	}

	private function absoluteUrl( string $url ): string {
		if ( str_starts_with( $url, 'data:' ) ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return esc_url_raw( $url );
		}

		return esc_url_raw( home_url( '/' . ltrim( $url, '/' ) ) );
	}

	/**
	 * @return string|\WP_Error
	 */
	private function fetch( string $url ): string|\WP_Error {
		$local = $this->localPath( $url );
		if ( null !== $local && is_readable( $local ) ) {
			$size = filesize( $local );
			if ( is_int( $size ) && $size > 2 * MB_IN_BYTES ) {
				return new \WP_Error( 'gtp_css_size', __( 'A stylesheet exceeded the 2 MB safety limit.', 'gt-performance' ) );
			}
			$contents = file_get_contents( $local );

			return is_string( $contents ) ? $contents : new \WP_Error( 'gtp_css_read', __( 'A local stylesheet could not be read.', 'gt-performance' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 2,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => 'GT-Performance-CSS/' . GTP_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'gtp_css_http', __( 'A stylesheet returned a non-200 response.', 'gt-performance' ) );
		}

		$contentType = strtolower( wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' !== $contentType && ! str_contains( $contentType, 'text/css' ) ) {
			return new \WP_Error( 'gtp_css_type', __( 'A stylesheet returned an unexpected content type.', 'gt-performance' ) );
		}

		return wp_remote_retrieve_body( $response );
	}

	private function localPath( string $url ): ?string {
		$contentUrl = content_url( '/' );
		if ( str_starts_with( $url, $contentUrl ) ) {
			$relative = ltrim( substr( $url, strlen( $contentUrl ) ), '/' );
			$relative = (string) wp_parse_url( $relative, PHP_URL_PATH );
			$path     = realpath( WP_CONTENT_DIR . '/' . $relative );
			$root     = realpath( WP_CONTENT_DIR );

			if ( is_string( $path ) && is_string( $root ) && str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * @param list<string> $exclusions Exclusions.
	 */
	private function excluded( string $url, array $exclusions ): bool {
		foreach ( $exclusions as $exclusion ) {
			if ( '' !== $exclusion && str_contains( $url, $exclusion ) ) {
				return true;
			}
		}

		return false;
	}

	private function rebaseUrls( string $css, string $sourceUrl ): string {
		$base = trailingslashit( dirname( $sourceUrl ) );

		return preg_replace_callback(
			"/url\\(\\s*([\"']?)(?!data:|https?:|\\/\\/|#)([^\"')]+)\\1\\s*\\)/i",
			static function ( array $matches ) use ( $base ): string {
				$absolute = $base . ltrim( trim( $matches[2] ), '/' );

				return 'url("' . esc_url_raw( $absolute ) . '")';
			},
			$css
		) ?? $css;
	}
}
