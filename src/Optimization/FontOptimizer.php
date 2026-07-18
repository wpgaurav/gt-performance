<?php
/**
 * Optional local Google Fonts hosting.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;

final class FontOptimizer {
	public function __construct(
		private readonly Logger $logger,
	) {
	}

	public function optimize( string $html ): string {
		if ( ! (bool) Settings::get( 'fonts.self_host_google', false ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$document = new \DOMDocument( '1.0', 'UTF-8' );
			if ( ! $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
				return $html;
			}
			$xpath = new \DOMXPath( $document );
			$links = $xpath->query( '//link[@href]' );
			if ( false === $links ) {
				return $html;
			}
			foreach ( $links as $link ) {
				if ( ! $link instanceof \DOMElement ) {
					continue;
				}
				$href = html_entity_decode( $link->getAttribute( 'href' ), ENT_QUOTES | ENT_HTML5 );
				if ( ! str_contains( $href, 'fonts.googleapis.com' ) ) {
					continue;
				}
				$local = $this->localize( $href );
				if ( null !== $local ) {
					$link->setAttribute( 'href', $local );
				}
			}

			$output = $document->saveHTML();

			return is_string( $output ) ? ( preg_replace( '/^<\\?xml[^>]+>\\s*/', '', $output ) ?? $output ) : $html;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	private function localize( string $url ): ?string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Mozilla/5.0 GT-Performance/' . GTP_VERSION,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$css       = wp_remote_retrieve_body( $response );
		$directory = Paths::assets() . '/fonts';
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return null;
		}

		$css = preg_replace_callback(
			'#url\\((https://fonts\\.gstatic\\.com/[^)]+)\\)#i',
			function ( array $matches ) use ( $directory ): string {
				$fontUrl = esc_url_raw( $matches[1] );
				$font    = wp_safe_remote_get(
					$fontUrl,
					array(
						'timeout'             => 15,
						'limit_response_size' => 2 * MB_IN_BYTES,
					)
				);
				if ( is_wp_error( $font ) || 200 !== wp_remote_retrieve_response_code( $font ) ) {
					return $matches[0];
				}
				$body = wp_remote_retrieve_body( $font );
				$ext  = pathinfo( (string) wp_parse_url( $fontUrl, PHP_URL_PATH ), PATHINFO_EXTENSION );
				$ext  = '' === $ext ? 'woff2' : $ext;
				$file = hash( 'sha256', $body ) . '.' . sanitize_key( $ext );
				if ( ! is_file( $directory . '/' . $file ) ) {
					file_put_contents( $directory . '/' . $file, $body, LOCK_EX );
				}

				return 'url("' . content_url( '/cache/gt-performance/assets/fonts/' . $file ) . '")';
			},
			$css
		) ?? $css;

		$display = (string) Settings::get( 'fonts.font_display', 'swap' );
		if ( ! str_contains( $css, 'font-display:' ) ) {
			$css = preg_replace( '/(@font-face\\s*\\{)/i', '$1font-display:' . sanitize_key( $display ) . ';', $css ) ?? $css;
		}

		$file = hash( 'sha256', $css ) . '.css';
		if ( ! is_file( $directory . '/' . $file ) && false === file_put_contents( $directory . '/' . $file, $css, LOCK_EX ) ) {
			$this->logger->log( 'warning', 'Unable to write self-hosted font CSS' );
			return null;
		}

		return content_url( '/cache/gt-performance/assets/fonts/' . $file );
	}
}
