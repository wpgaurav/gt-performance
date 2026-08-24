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
	public const ENQUEUE_HOOK = 'gt_performance_enqueue_image_variants';
	public const JOB_HOOK     = 'gt_performance_job_generate_image_variants';
	public const JOB_TYPE     = 'generate_image_variants';

	public function __construct(
		private readonly Logger $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return array<string, mixed>
	 */
	public function enqueue( array $metadata, int $attachmentId ): array {
		if ( ! (bool) Settings::get( 'media.optimize_uploads', false ) ) {
			return $metadata;
		}
		if ( $attachmentId <= 0 ) {
			return $metadata;
		}
		if ( ! (bool) apply_filters( 'gt_performance_generate_image_variants', true, $attachmentId, $metadata ) ) {
			return $metadata;
		}

		foreach ( $this->variantKeys( $metadata ) as $variantKey ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- ENQUEUE_HOOK is the prefixed literal 'gt_performance_enqueue_image_variants'.
			do_action( self::ENQUEUE_HOOK, $attachmentId, $variantKey );
		}

		return $metadata;
	}

	/**
	 * Generate variants for a queued attachment job.
	 *
	 * @param array<string, mixed> $payload Queue payload.
	 */
	public function generateQueued( array $payload ): void {
		$attachmentId = (int) ( $payload['attachment_id'] ?? 0 );
		if ( $attachmentId <= 0 ) {
			throw new \RuntimeException( 'Missing image attachment ID.' );
		}
		if ( ! (bool) Settings::get( 'media.optimize_uploads', false ) ) {
			return;
		}
		if ( 'attachment' !== get_post_type( $attachmentId ) ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachmentId );
		if ( ! is_array( $metadata ) ) {
			throw new \RuntimeException( 'Image attachment metadata is unavailable.' );
		}
		if ( ! (bool) apply_filters( 'gt_performance_generate_image_variants', true, $attachmentId, $metadata ) ) {
			return;
		}

		$source = get_attached_file( $attachmentId );
		if ( ! is_string( $source ) || ! is_readable( $source ) ) {
			$this->logger->log( 'warning', 'Image source unavailable for queued variant generation', array( 'attachment_id' => $attachmentId ) );
			return;
		}

		$variantKey = (string) ( $payload['variant_key'] ?? 'full' );
		$file       = $this->fileForVariant( $source, $metadata, $variantKey );
		if ( null === $file || ! is_readable( $file ) ) {
			$this->logger->log(
				'warning',
				'Image variant source unavailable for queued generation',
				array(
					'attachment_id' => $attachmentId,
					'variant_key'   => $variantKey,
				)
			);
			return;
		}

		$started   = microtime( true );
		$generated = $this->generateFile( $file );

		$this->logger->log(
			'debug',
			'Queued image variant generation completed',
			array(
				'attachment_id' => $attachmentId,
				'variant_key'   => $variantKey,
				'generated'     => $generated ? 1 : 0,
				'duration_ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
			)
		);
	}

	public function rewriteHtml( string $html ): string {
		if (
			! (bool) Settings::get( 'media.rewrite_variants', false )
			|| ! (bool) apply_filters( 'gt_performance_rewrite_image_variants', true )
			|| ! class_exists( '\\WP_HTML_Tag_Processor' )
		) {
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

	private function generateFile( string $file ): bool {
		$mime = wp_get_image_mime( $file );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return false;
		}

		$format = (string) Settings::get( 'media.format', 'webp' );
		$mime   = 'avif' === $format ? 'image/avif' : 'image/webp';
		$target = preg_replace( '/\\.(?:jpe?g|png)$/i', '.' . ( 'image/avif' === $mime ? 'avif' : 'webp' ), $file );
		if ( ! is_string( $target ) || is_file( $target ) ) {
			return false;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			$this->logger->log( 'warning', 'Image editor unavailable', array( 'error' => $editor->get_error_message() ) );
			return false;
		}

		$editor->set_quality( (int) Settings::get( 'media.compression', 82 ) );
		$saved = $editor->save( $target, $mime );
		if ( is_wp_error( $saved ) ) {
			$this->logger->log( 'warning', 'Image variant generation failed', array( 'error' => $saved->get_error_message() ) );
			return false;
		}

		return true;
	}

	/**
	 * Return one queue key per unique source file.
	 *
	 * WordPress can register multiple size names that point to the same physical
	 * file. Deduplicating here avoids duplicate encodes without grouping all
	 * files back into the same long-running request.
	 *
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return list<string>
	 */
	private function variantKeys( array $metadata ): array {
		$keys = array( 'full' );
		$seen = array();

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $key => $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) {
				continue;
			}

			$file = basename( (string) $size['file'] );
			if ( '' === $file || isset( $seen[ $file ] ) ) {
				continue;
			}

			$seen[ $file ] = true;
			$keys[]        = (string) $key;
		}

		return $keys;
	}

	/**
	 * Resolve a queue key against fresh metadata so renamed or regenerated
	 * attachments never trust a stale absolute path stored in the jobs table.
	 *
	 * @param array<string, mixed> $metadata Attachment metadata.
	 */
	private function fileForVariant( string $source, array $metadata, string $variantKey ): ?string {
		if ( 'full' === $variantKey ) {
			return $source;
		}

		$sizes = (array) ( $metadata['sizes'] ?? array() );
		if ( ! isset( $sizes[ $variantKey ] ) || ! is_array( $sizes[ $variantKey ] ) ) {
			return null;
		}

		$size = $sizes[ $variantKey ];
		if ( empty( $size['file'] ) ) {
			return null;
		}

		return dirname( $source ) . '/' . basename( (string) $size['file'] );
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
