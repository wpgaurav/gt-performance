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
	 * @param list<string> $exclusions Excluded URL or inline style ID fragments.
	 * @return array{stylesheets:list<Stylesheet>,nodes:list<\DOMNode>}
	 */
	public function collect( \DOMDocument $document, array $exclusions = array() ): array {
		$xpath       = new \DOMXPath( $document );
		$stylesheets = array();
		$nodes       = array();
		$siteHost    = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		// A stylesheet's position is part of the cascade. Query links and inline
		// styles together so consolidating them never moves every inline block
		// behind every external file.
		$nodesInOrder = $xpath->query(
			'//link[not(ancestor::noscript)][contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")][@href]'
			. ' | //style[not(ancestor::noscript)][not(@data-gt-performance)]'
		);
		if ( false !== $nodesInOrder ) {
			foreach ( $nodesInOrder as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}

				if ( 'style' === strtolower( $node->tagName ) ) {
					if ( '' === trim( $node->textContent ) ) {
						continue;
					}
					$styleId = $node->getAttribute( 'id' );
					if ( '' !== $styleId && $this->excluded( $styleId, $exclusions ) ) {
						continue;
					}
					$media          = $node->getAttribute( 'media' );
					$stylesheets[] = new Stylesheet( 'inline', $node->textContent, '' === $media ? 'all' : $media );
					$nodes[]       = $node;
					continue;
				}

				$href = html_entity_decode( $node->getAttribute( 'href' ), ENT_QUOTES | ENT_HTML5 );
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

				$media = $this->effectiveMedia( $node );
				$stylesheets[] = new Stylesheet(
					$url,
					$this->rebaseUrls( $css, $url ),
					'' === $media ? 'all' : $media
				);
				$nodes[]       = $node;
			}
		}

		return array(
			'stylesheets' => $stylesheets,
			'nodes'       => $nodes,
		);
	}

	private function effectiveMedia( \DOMElement $link ): string {
		$media  = trim( $link->getAttribute( 'media' ) );
		$onload = $link->getAttribute( 'onload' );

		// Async-CSS loaders commonly set media="print" to avoid blocking and
		// promote the stylesheet to all media after it loads. Preserve the
		// browser's effective media rather than turning that CSS into print-only
		// rules inside the generated artifact.
		if (
			'print' === strtolower( $media )
			&& preg_match( '/\\.media\\s*=\\s*([\'\"])all\\1/i', $onload )
		) {
			return 'all';
		}

		return '' === $media ? 'all' : $media;
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
	 * @param list<string> $exclusions URL or inline style ID fragments.
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
		$base   = trailingslashit( dirname( $sourceUrl ) );
		$origin = $this->origin( $sourceUrl );

		return preg_replace_callback(
			"/url\\(\\s*([\"']?)(?!data:|https?:|\\/\\/|#)([^\"')]+)\\1\\s*\\)/i",
			static function ( array $matches ) use ( $base, $origin ): string {
				$reference = trim( $matches[2] );
				if ( '' === $reference ) {
					return $matches[0];
				}

				// A root-relative reference resolves against the site origin; only a
				// document-relative reference is joined to the stylesheet's directory.
				$absolute = str_starts_with( $reference, '/' )
					? $origin . $reference
					: $base . ltrim( $reference, '/' );

				return 'url("' . esc_url_raw( $absolute ) . '")';
			},
			$css
		) ?? $css;
	}

	private function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin;
	}
}
