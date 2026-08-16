<?php
/**
 * Builds an xCloud API client from saved settings or constants.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\XCloud;

use GTPerformance\Core\SecretCipher;
use GTPerformance\Core\Settings;

final class ClientFactory {
	/**
	 * @param array<string, mixed>|null $settings Plugin settings.
	 * @return ApiClient|\WP_Error
	 */
	public function create( ?array $settings = null ): ApiClient|\WP_Error {
		$settings = $settings ?? Settings::all();
		$xcloud   = isset( $settings['xcloud'] ) && is_array( $settings['xcloud'] )
			? $settings['xcloud']
			: array();
		$token    = ( new SecretCipher( 'xcloud' ) )->decrypt(
			(string) ( $xcloud['api_token'] ?? '' ),
			'GTP_XCLOUD_API_TOKEN'
		);

		if ( '' === trim( $token ) ) {
			return new \WP_Error( 'gtp_xcloud_token', __( 'xCloud API token is unavailable.', 'gt-performance' ) );
		}

		return new ApiClient( trim( $token ), $this->baseUrl() );
	}

	/**
	 * @param array<string, mixed>|null $settings Plugin settings.
	 */
	public function domain( ?array $settings = null ): string {
		$settings = $settings ?? Settings::all();
		$xcloud   = isset( $settings['xcloud'] ) && is_array( $settings['xcloud'] )
			? $settings['xcloud']
			: array();
		$domain   = (string) ( $xcloud['domain'] ?? '' );
		if ( '' === $domain ) {
			$domain = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		}

		$url  = str_contains( $domain, '://' ) ? $domain : 'https://' . $domain;
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return trim( strtolower( is_string( $host ) ? $host : '' ), ". \t\n\r\0\x0B" );
	}

	private function baseUrl(): string {
		if ( ! defined( 'GTP_XCLOUD_API_BASE_URL' ) ) {
			return 'https://app.xcloud.host';
		}

		$value = constant( 'GTP_XCLOUD_API_BASE_URL' );

		return is_string( $value ) && '' !== trim( $value )
			? rtrim( trim( $value ), '/' )
			: 'https://app.xcloud.host';
	}
}
