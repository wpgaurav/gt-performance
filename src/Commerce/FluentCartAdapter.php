<?php
/**
 * FluentCart cache-safety adapter.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

final class FluentCartAdapter extends AbstractAdapter {
	public function id(): string {
		return 'fluentcart';
	}

	public function active(): bool {
		return defined( 'FLUENTCART_VERSION' )
			|| class_exists( '\\FluentCart\\App\\App' )
			|| class_exists( '\\FluentCart\\App\\Models\\Cart' );
	}

	public function bypassPaths(): array {
		$defaults = array( '/cart/', '/checkout/', '/account/', '/receipt/' );
		$settings = get_option( 'fluent_cart_settings', array() );
		$pageIds  = array();

		if ( is_array( $settings ) ) {
			foreach ( array( 'cart_page_id', 'checkout_page_id', 'customer_profile_page_id', 'receipt_page_id' ) as $key ) {
				if ( isset( $settings[ $key ] ) ) {
					$pageIds[] = (int) $settings[ $key ];
				}
			}
		}

		return array_values(
			array_unique(
				array_merge(
					$defaults,
					$this->pathsFromPageIds( $pageIds ),
					array( '/wp-json/fluent-cart/' )
				)
			)
		);
	}

	public function bypassCookies(): array {
		return array( 'fct_', 'fct_cart_hash' );
	}

	public function bypassQueryParameters(): array {
		return array( 'fluent-cart', 'fct', 'coupons' );
	}

	public function isProduct( int $postId, \WP_Post $post ): bool {
		return in_array( $post->post_type, array( 'fluent-products', 'fluent-product', 'fct_product' ), true );
	}

	public function relatedUrls( int $postId ): array {
		return $this->commonRelatedUrls( $postId, (string) get_post_type( $postId ) );
	}
}
