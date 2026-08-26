<?php
/**
 * Cache-safe public shells with privately rendered registered islands.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\PrivateFragments;

use GTPerformance\Cache\SharedCacheHeaders;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class PrivateFragmentsModule implements Module {
	public function __construct(
		private readonly FragmentRegistry $fragments = new FragmentRegistry(),
	) {
	}

	public function register(): void {
		add_shortcode( 'gtperf_private_island', array( $this, 'shortcode' ) );
		add_filter( 'gt_performance_html', array( $this, 'prepareHtml' ), 90 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), PHP_INT_MAX );
		add_action( 'wp_ajax_gtp_private_fragments', array( $this, 'respond' ) );
		add_action( 'wp_ajax_nopriv_gtp_private_fragments', array( $this, 'respond' ) );
	}

	/**
	 * @param array<string, string> $attributes Shortcode attributes.
	 */
	public function shortcode( array $attributes = array() ): string {
		if ( ! (bool) Settings::get( 'private_fragments.enabled', false ) ) {
			return '';
		}

		$attributes = shortcode_atts( array( 'id' => '' ), $attributes, 'gtperf_private_island' );
		$fragmentId = sanitize_key( (string) $attributes['id'] );
		if ( ! $this->fragments->has( $fragmentId ) ) {
			return '';
		}

		return $this->placeholder( $fragmentId );
	}

	public function prepareHtml( string $html ): string {
		if ( ! (bool) Settings::get( 'private_fragments.enabled', false ) || ! str_contains( $html, 'data-gtp-private-island' ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$document = new \DOMDocument( '1.0', 'UTF-8' );
			if ( ! $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
				return $html;
			}
			$nodes = ( new \DOMXPath( $document ) )->query( '//*[@data-gtp-private-island]' );
			if ( false === $nodes ) {
				return $html;
			}

			foreach ( $nodes as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}
				$fragmentId = sanitize_key( $node->getAttribute( 'data-gtp-private-island' ) );
				if ( ! $this->fragments->has( $fragmentId ) ) {
					$node->removeAttribute( 'data-gtp-private-island' );
					continue;
				}

				while ( $node->firstChild ) {
					$node->removeChild( $node->firstChild );
				}
				$node->appendChild( $document->createTextNode( wp_strip_all_tags( $this->fragments->fallback( $fragmentId ) ) ) );
				$node->setAttribute( 'data-gtp-signature', Signer::forSite()->sign( $fragmentId ) );
				$node->setAttribute( 'aria-live', 'polite' );
			}

			$output = $document->saveHTML();

			return is_string( $output ) ? ( preg_replace( '/^<\?xml[^>]+>\s*/', '', $output ) ?? $output ) : $html;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	public function enqueue(): void {
		if ( ! (bool) Settings::get( 'private_fragments.enabled', false ) ) {
			return;
		}

		wp_enqueue_script(
			'gt-performance-private-islands',
			plugins_url( 'assets/private-islands.js', GTPERF_FILE ),
			array(),
			GTPERF_VERSION,
			true
		);
		wp_localize_script(
			'gt-performance-private-islands',
			'gtPerformancePrivateIslands',
			array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) )
		);
	}

	public function respond(): void {
		nocache_headers();
		SharedCacheHeaders::noStore();
		header( 'X-GT-Private-Fragments: BYPASS' );

		// This read-only public endpoint verifies each purpose-bound fragment signature.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['fragments'] ) && is_string( $_POST['fragments'] )
			? sanitize_textarea_field( wp_unslash( $_POST['fragments'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$requested = json_decode( is_string( $raw ) ? $raw : '', true );
		$requested = is_array( $requested ) ? array_slice( $requested, 0, 10 ) : array();
		$result    = array();
		$signer    = Signer::forSite();

		foreach ( $requested as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id        = sanitize_key( (string) ( $item['id'] ?? '' ) );
			$signature = sanitize_text_field( (string) ( $item['signature'] ?? '' ) );
			if ( ! $this->fragments->has( $id ) || ! $signer->verify( $id, $signature ) ) {
				continue;
			}

			$result[ $id ] = $this->fragments->render( $id );
		}

		wp_send_json_success( array( 'fragments' => $result ) );
	}

	private function placeholder( string $fragmentId ): string {
		return sprintf(
			'<span data-gtp-private-island="%1$s" data-gtp-signature="%2$s" aria-live="polite">%3$s</span>',
			esc_attr( $fragmentId ),
			esc_attr( Signer::forSite()->sign( $fragmentId ) ),
			$this->fragments->fallback( $fragmentId )
		);
	}
}
