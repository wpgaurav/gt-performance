<?php
/**
 * WooCommerce cache-safety adapter.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

final class WooCommerceAdapter extends AbstractAdapter {
	public function id(): string {
		return 'woocommerce';
	}

	public function active(): bool {
		return defined( 'WC_VERSION' ) || class_exists( '\\WooCommerce' );
	}

	public function bypassPaths(): array {
		$urls = array();
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$urls[] = wc_get_page_permalink( $page );
			}
		}

		return array_values(
			array_unique(
				array_merge(
					array( '/cart/', '/checkout/', '/my-account/', '/wp-json/wc/store/cart', '/wp-json/wc/store/checkout' ),
					$this->pathsFromUrls( $urls )
				)
			)
		);
	}

	public function bypassCookies(): array {
		return array(
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'wp_woocommerce_session_',
			'woocommerce_recently_viewed',
			'store_notice',
		);
	}

	public function bypassQueryParameters(): array {
		return array( 'wc-ajax', 'add-to-cart', 'order-pay', 'order-received', 'key' );
	}

	public function isProduct( int $postId, \WP_Post $post ): bool {
		return in_array( $post->post_type, array( 'product', 'product_variation' ), true );
	}

	public function relatedUrls( int $postId ): array {
		$parent = wp_get_post_parent_id( $postId );
		if ( $parent > 0 ) {
			$postId = $parent;
		}

		$urls = $this->commonRelatedUrls( $postId, 'product' );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			if ( is_string( $shop ) ) {
				$urls[] = $shop;
			}
		}

		return array_values( array_unique( $urls ) );
	}
}
