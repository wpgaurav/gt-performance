<?php
/**
 * Self-hosted Google Fonts delivery.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;

final class FontOptimizer {
	public const JOB_TYPE = 'localize_fonts';
	public const JOB_HOOK = 'gt_performance_job_localize_fonts';

	/**
	 * Only this host is localized, matched on the parsed host and never on a
	 * substring: `str_contains( $href, 'fonts.googleapis.com' )` also matches
	 * `https://evil.example/?x=fonts.googleapis.com`, which made the server fetch
	 * an attacker-chosen URL and republish the bytes from the site's own origin.
	 */
	private const SOURCE_HOST = 'fonts.googleapis.com';
	private const FONT_ORIGIN = 'https://fonts.gstatic.com/';

	/** Bound the work one job may do, so a pathological stylesheet cannot run forever. */
	private const MAX_FONT_FILES = 40;

	public function __construct(
		private readonly Logger $logger,
	) {
	}

	public function optimize( string $html ): string {
		if ( ! (bool) Settings::get( 'fonts.self_host_google', false ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$changed   = false;
		$queued    = array();

		while ( $processor->next_tag( array( 'tag_name' => 'LINK' ) ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) || ! $this->isGoogleFontsUrl( $href ) ) {
				continue;
			}

			$local = $this->localUrl( $href );
			if ( null !== $local ) {
				$processor->set_attribute( 'href', $local );
				$changed = true;
				continue;
			}

			// Nothing is fetched during a visitor's request. Localizing means one
			// blocking call for the stylesheet plus one per font file, with no bound on
			// how many, inside the output buffer. The first request that sees an
			// un-localized stylesheet queues the work and leaves the original link in
			// place; the next request serves the local copy.
			$queued[ $href ] = true;
		}

		foreach ( array_keys( $queued ) as $href ) {
			$this->enqueue( (string) $href );
		}

		return $changed ? $processor->get_updated_html() : $html;
	}

	/**
	 * Resolve an already-localized stylesheet, or null when it has not been built.
	 *
	 * The filename is derived from the source URL rather than the response body, so
	 * this lookup is a single is_file() with no manifest and no network access.
	 */
	private function localUrl( string $href ): ?string {
		$file = $this->fileName( $href );

		return is_file( Paths::assets() . '/fonts/' . $file )
			? content_url( '/cache/gt-performance/assets/fonts/' . $file )
			: null;
	}

	private function fileName( string $href ): string {
		return hash( 'sha256', $this->normalize( $href ) ) . '.css';
	}

	private function normalize( string $href ): string {
		return html_entity_decode( $href, ENT_QUOTES | ENT_HTML5 );
	}

	private function isGoogleFontsUrl( string $href ): bool {
		$host = wp_parse_url( $this->normalize( $href ), PHP_URL_HOST );

		return is_string( $host ) && self::SOURCE_HOST === strtolower( $host );
	}

	private function enqueue( string $href ): void {
		/**
		 * Hand the URL to the background queue.
		 *
		 * @param string $href Google Fonts stylesheet URL.
		 */
		do_action( 'gt_performance_enqueue_font_localization', $this->normalize( $href ) );
	}

	/**
	 * Background worker: fetch the stylesheet, mirror every font file it names, and
	 * write the rewritten CSS under a name derived from the source URL.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function localizeQueued( array $payload ): void {
		$url = (string) ( $payload['url'] ?? '' );
		if ( '' === $url || ! $this->isGoogleFontsUrl( $url ) ) {
			return;
		}

		$directory = Paths::assets() . '/fonts';
		$target    = $directory . '/' . $this->fileName( $url );
		if ( is_file( $target ) ) {
			return;
		}

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return;
		}

		// Google serves woff2 only to user agents it recognises as supporting it. A
		// custom agent gets the legacy TTF payload, which is several times larger.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => MB_IN_BYTES,
				'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->logger->log( 'warning', 'Google Fonts stylesheet could not be fetched', array( 'url' => $url ) );
			return;
		}

		$css     = (string) wp_remote_retrieve_body( $response );
		$fetched = 0;

		$css = preg_replace_callback(
			'#url\((' . preg_quote( self::FONT_ORIGIN, '#' ) . '[^)]+)\)#i',
			function ( array $matches ) use ( $directory, &$fetched ): string {
				if ( $fetched >= self::MAX_FONT_FILES ) {
					return $matches[0];
				}
				++$fetched;

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

				$body = (string) wp_remote_retrieve_body( $font );
				$ext  = pathinfo( (string) wp_parse_url( $fontUrl, PHP_URL_PATH ), PATHINFO_EXTENSION );
				$ext  = '' === $ext ? 'woff2' : $ext;
				$file = hash( 'sha256', $body ) . '.' . sanitize_key( $ext );
				if ( ! is_file( $directory . '/' . $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic asset write; WP_Filesystem offers no LOCK_EX equivalent.
					file_put_contents( $directory . '/' . $file, $body, LOCK_EX );
				}

				return 'url("' . content_url( '/cache/gt-performance/assets/fonts/' . $file ) . '")';
			},
			$css
		) ?? $css;

		$display = (string) Settings::get( 'fonts.font_display', 'swap' );
		if ( ! str_contains( $css, 'font-display:' ) ) {
			$css = preg_replace( '/(@font-face\s*\{)/i', '$1font-display:' . sanitize_key( $display ) . ';', $css ) ?? $css;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic asset write; WP_Filesystem offers no LOCK_EX equivalent.
		if ( false === file_put_contents( $target, $css, LOCK_EX ) ) {
			$this->logger->log( 'warning', 'Unable to write self-hosted font CSS', array( 'url' => $url ) );
		}
	}
}
