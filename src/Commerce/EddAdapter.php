<?php
/**
 * Easy Digital Downloads cache-safety adapter.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

final class EddAdapter extends AbstractAdapter {
	public function id(): string {
		return 'edd';
	}

	public function active(): bool {
		return defined( 'EDD_VERSION' ) || function_exists( 'edd_get_option' );
	}

	public function bypassPaths(): array {
		$pageIds = array();
		if ( function_exists( 'edd_get_option' ) ) {
			foreach ( array( 'purchase_page', 'success_page', 'failure_page', 'purchase_history_page' ) as $key ) {
				$pageIds[] = (int) edd_get_option( $key, 0 );
			}
		}

		return array_values(
			array_unique(
				array_merge(
					array( '/checkout/', '/purchase-confirmation/', '/purchase-history/' ),
					$this->pathsFromPageIds( $pageIds )
				)
			)
		);
	}

	public function bypassCookies(): array {
		return array(
			'edd_items_in_cart',
			'edd_session_',
			'edd_cart_messages',
			'edd_purchase',
			'edd_cart_fees',
			'edd_resume_payment',
			'edd_cart',
			'cart_discounts',
			'preset_discount',
			'edd_cart_token',
			'edd_saved_cart',
		);
	}

	public function bypassQueryParameters(): array {
		return array( 'edd_action', 'discount', 'payment-confirmation', 'purchase_key' );
	}

	public function isProduct( int $postId, \WP_Post $post ): bool {
		return 'download' === $post->post_type;
	}

	public function relatedUrls( int $postId ): array {
		return $this->commonRelatedUrls( $postId, 'download' );
	}
}
