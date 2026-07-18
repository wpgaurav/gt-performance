<?php
/**
 * Local WebP/AVIF generation and HTML rewriting.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;

final class ImageVariantGenerator {
	public function __construct(
		private readonly Logger $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return array<string, mixed>
	 */
	public function generate( array $metadata, int $attachmentId ): array {
		if ( ! (bool) Settings::get( 'media.optimize_uploads', false ) ) {
			return $metadata;
		}

		$source = get_attached_file( $attachmentId );
		if ( ! is_string( $source ) || ! is_readable( $source ) ) {
			return $metadata;
		}

		$files = array( $source );
		$base  = dirname( $source );
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) ) {
				$files[] = $base . '/' . basename( (string) $size['file'] );
			}
		}

		foreach ( array_unique( $files ) as $file ) {
			$this->generateFile( $file );
		}

		return $metadata;
	}

	public function rewriteHtml( string $html ): string {
		if ( ! (bool) Settings::get( 'media.rewrite_variants', false ) || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			$src = (string) $processor->get_attribute( 'src' );
			$new = $this->variantUrl( $src );
			if ( null !== $new ) {
				$processor->set_attribute( 'src', $new );
			}

			$srcset = (string) $processor->get_attribute( 'srcset' );
			if ( '' !== $srcset ) {
				$items = array();
				foreach ( explode( ',', $srcset ) as $item ) {
					$parts   = preg_split( '/\\s+/', trim( $item ), 2 );
					$variant = $this->variantUrl( (string) ( $parts[0] ?? '' ) );
					$items[] = ( $variant ?? (string) ( $parts[0] ?? '' ) ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
				}
				$processor->set_attribute( 'srcset', implode( ', ', $items ) );
			}
		}

		return $processor->get_updated_html();
	}

	private function generateFile( string $file ): void {
		$mime = wp_get_image_mime( $file );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return;
		}

		$format = (string) Settings::get( 'media.format', 'webp' );
		$mime   = 'avif' === $format ? 'image/avif' : 'image/webp';
		$target = preg_replace( '/\\.(?:jpe?g|png)$/i', '.' . ( 'image/avif' === $mime ? 'avif' : 'webp' ), $file );
		if ( ! is_string( $target ) || is_file( $target ) ) {
			return;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			$this->logger->log( 'warning', 'Image editor unavailable', array( 'error' => $editor->get_error_message() ) );
			return;
		}

		$editor->set_quality( (int) Settings::get( 'media.compression', 82 ) );
		$saved = $editor->save( $target, $mime );
		if ( is_wp_error( $saved ) ) {
			$this->logger->log( 'warning', 'Image variant generation failed', array( 'error' => $saved->get_error_message() ) );
		}
	}

	private function variantUrl( string $url ): ?string {
		$uploads = wp_get_upload_dir();
		$baseUrl = (string) ( $uploads['baseurl'] ?? '' );
		$baseDir = (string) ( $uploads['basedir'] ?? '' );
		if ( '' === $url || '' === $baseUrl || ! str_starts_with( $url, $baseUrl ) ) {
			return null;
		}

		$relative = (string) wp_parse_url( substr( $url, strlen( $baseUrl ) ), PHP_URL_PATH );
		$format   = (string) Settings::get( 'media.format', 'webp' );
		$relative = preg_replace( '/\\.(?:jpe?g|png)$/i', '.' . ( 'avif' === $format ? 'avif' : 'webp' ), $relative );
		if ( ! is_string( $relative ) || ! is_file( $baseDir . $relative ) ) {
			return null;
		}

		return $baseUrl . $relative;
	}
}
