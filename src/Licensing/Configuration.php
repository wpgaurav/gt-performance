<?php
/**
 * FluentCart licensing configuration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Licensing;

final class Configuration {
	/**
	 * The FluentCart item this package updates from.
	 *
	 * This is the FluentCart product id, not a variation id. Licenses carry both
	 * (product_id 1170147, variation_id 68 or 90) and the endpoint keys on the
	 * product: a variation id answers "Product not found".
	 *
	 * Shipped as a default so the package works on install. Requiring every customer
	 * to define a constant in wp-config.php before updates work is not a product.
	 * Overridable by GTPERF_FLUENTCART_ITEM_ID and by filter for a staging store.
	 */
	private const DEFAULT_ITEM_ID = 1170147;

	public function itemId(): int {
		$itemId = defined( 'GTPERF_FLUENTCART_ITEM_ID' ) ? (int) GTPERF_FLUENTCART_ITEM_ID : self::DEFAULT_ITEM_ID;

		return max( 0, (int) apply_filters( 'gt_performance_fluentcart_item_id', $itemId ) );
	}

	public function serverUrl(): string {
		$url = defined( 'GTPERF_LICENSE_SERVER_URL' ) ? (string) GTPERF_LICENSE_SERVER_URL : 'https://gauravtiwari.org/';
		$url = (string) apply_filters( 'gt_performance_license_server_url', $url );

		return trailingslashit( esc_url_raw( $url ) );
	}

	public function siteUrl(): string {
		$homeUrl = home_url( '/' );
		$siteUrl = self::portSafeSiteUrl( $homeUrl );
		$siteUrl = (string) apply_filters( 'gt_performance_license_site_url', $siteUrl, $homeUrl );

		return esc_url_raw( $siteUrl );
	}

	public static function portSafeSiteUrl( string $url ): string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['port'] ) ) {
			return $url;
		}

		$host = strtolower( (string) $parts['host'] );
		$host = preg_replace( '/[^a-z0-9]+/', '-', $host );
		$host = is_string( $host ) ? trim( $host, '-' ) : '';
		$host = '' !== $host ? substr( $host, 0, 30 ) : 'local';

		$scheme = isset( $parts['scheme'] ) && 'http' === strtolower( (string) $parts['scheme'] ) ? 'http' : 'https';

		return sprintf(
			'%s://%s-p%d-%s.invalid/',
			$scheme,
			$host,
			(int) $parts['port'],
			substr( hash( 'sha256', $url ), 0, 10 )
		);
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
