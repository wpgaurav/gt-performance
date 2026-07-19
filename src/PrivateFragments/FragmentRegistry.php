<?php
/**
 * Explicit private-fragment registry.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\PrivateFragments;

use GTPerformance\Core\Settings;

final class FragmentRegistry {
	/**
	 * @return array<string, array{fallback:string,render:callable():string}>
	 */
	public function all(): array {
		$fragments = array();
		if ( (bool) Settings::get( 'private_fragments.cart_count', true ) ) {
			$fragments['commerce_cart_count'] = array(
				'fallback' => '0',
				'render'   => array( $this, 'cartCount' ),
			);
		}
		if ( (bool) Settings::get( 'private_fragments.account_link', true ) ) {
			$fragments['commerce_account_link'] = array(
				'fallback' => __( 'Account', 'gt-performance' ),
				'render'   => array( $this, 'accountLink' ),
			);
		}

		$filtered = apply_filters( 'gt_performance_private_fragments', $fragments );

		return is_array( $filtered ) ? $filtered : $fragments;
	}

	public function has( string $fragmentId ): bool {
		return isset( $this->all()[ $fragmentId ] );
	}

	public function fallback( string $fragmentId ): string {
		$fragment = $this->all()[ $fragmentId ] ?? null;

		return is_array( $fragment ) ? wp_kses_post( (string) ( $fragment['fallback'] ?? '' ) ) : '';
	}

	public function render( string $fragmentId ): string {
		$fragment = $this->all()[ $fragmentId ] ?? null;
		if ( ! is_array( $fragment ) || ! is_callable( $fragment['render'] ?? null ) ) {
			return '';
		}

		return wp_kses_post( (string) call_user_func( $fragment['render'] ) );
	}

	public function cartCount(): string {
		$count = 0;
		if ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) ) {
			$count = (int) WC()->cart->get_cart_contents_count();
		} elseif ( function_exists( 'edd_get_cart_quantity' ) ) {
			$count = (int) edd_get_cart_quantity();
		}

		$count = max( 0, (int) apply_filters( 'gt_performance_private_cart_count', $count ) );

		return number_format_i18n( $count );
	}

	public function accountLink(): string {
		if ( is_user_logged_in() ) {
			$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : get_edit_profile_url();
			$url = (string) apply_filters( 'gt_performance_private_account_url', $url, true );

			return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'My account', 'gt-performance' ) );
		}

		$url = (string) apply_filters( 'gt_performance_private_account_url', wp_login_url(), false );

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Sign in', 'gt-performance' ) );
	}
}
