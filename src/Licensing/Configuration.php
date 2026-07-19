<?php
/**
 * FluentCart licensing configuration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Licensing;

final class Configuration {
	public function itemId(): int {
		$itemId = defined( 'GTP_FLUENTCART_ITEM_ID' ) ? (int) GTP_FLUENTCART_ITEM_ID : 0;

		return max( 0, (int) apply_filters( 'gt_performance_fluentcart_item_id', $itemId ) );
	}

	public function serverUrl(): string {
		$url = defined( 'GTP_LICENSE_SERVER_URL' ) ? (string) GTP_LICENSE_SERVER_URL : 'https://gauravtiwari.org/';
		$url = (string) apply_filters( 'gt_performance_license_server_url', $url );

		return trailingslashit( esc_url_raw( $url ) );
	}

	public function productUrl(): string {
		return (string) apply_filters(
			'gt_performance_product_url',
			'https://gauravtiwari.org/product/gt-performance/'
		);
	}

	public function releasesUrl(): string {
		return (string) apply_filters(
			'gt_performance_releases_url',
			'https://github.com/wpgaurav/gt-performance/releases'
		);
	}
}
