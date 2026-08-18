<?php
/**
 * Builds a Cloudflare API client from saved settings or constants.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

use GTPerformance\Core\Settings;

final class ClientFactory {
	/**
	 * @param array<string, mixed>|null $settings Plugin settings.
	 * @return ApiClient|\WP_Error
	 */
	public function create( ?array $settings = null ): ApiClient|\WP_Error {
		$settings   = $settings ?? Settings::all();
		$cloudflare = isset( $settings['cloudflare'] ) && is_array( $settings['cloudflare'] )
			? $settings['cloudflare']
			: array();
		$mode       = $this->authMode( $cloudflare );
		$cipher     = new TokenCipher();

		if ( 'global' === $mode ) {
			return $this->createGlobal( $settings );
		}

		$token = $cipher->decrypt( (string) ( $cloudflare['api_token'] ?? '' ) );
		if ( '' === $token ) {
			return new \WP_Error( 'gtp_cloudflare_token', __( 'Cloudflare API token is unavailable.', 'gt-performance' ) );
		}

		return new ApiClient( ApiCredentials::apiToken( $token ) );
	}

	/**
	 * Build a Global API Key client regardless of the configured mode.
	 *
	 * Minting a scoped token is the one operation that needs account-wide
	 * credentials even when the site is already authenticating with a token.
	 *
	 * @param array<string, mixed>|null $settings Plugin settings.
	 * @return ApiClient|\WP_Error
	 */
	public function createGlobal( ?array $settings = null ): ApiClient|\WP_Error {
		$settings   = $settings ?? Settings::all();
		$cloudflare = isset( $settings['cloudflare'] ) && is_array( $settings['cloudflare'] )
			? $settings['cloudflare']
			: array();

		$email = $this->constant( 'GTP_CLOUDFLARE_EMAIL' );
		if ( '' === $email ) {
			$email = trim( (string) ( $cloudflare['email'] ?? '' ) );
		}

		$key = ( new TokenCipher() )->decrypt(
			(string) ( $cloudflare['global_api_key'] ?? '' ),
			'GTP_CLOUDFLARE_GLOBAL_API_KEY'
		);

		if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return new \WP_Error( 'gtp_cloudflare_email', __( 'A valid Cloudflare account email is required for Global API Key authentication.', 'gt-performance' ) );
		}
		if ( '' === $key ) {
			return new \WP_Error( 'gtp_cloudflare_global_key', __( 'Cloudflare Global API Key is unavailable.', 'gt-performance' ) );
		}

		return new ApiClient( ApiCredentials::globalKey( $email, $key ) );
	}

	/**
	 * @param array<string, mixed>|null $settings Plugin settings.
	 */
	public function domain( ?array $settings = null ): string {
		$settings   = $settings ?? Settings::all();
		$cloudflare = isset( $settings['cloudflare'] ) && is_array( $settings['cloudflare'] )
			? $settings['cloudflare']
			: array();
		$domain     = $this->constant( 'GTP_CLOUDFLARE_DOMAIN' );
		if ( '' === $domain ) {
			$domain = (string) ( $cloudflare['domain'] ?? '' );
		}
		if ( '' === $domain ) {
			$domain = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		}

		return $this->normalizeDomain( $domain );
	}

	/**
	 * @param array<string, mixed> $cloudflare Cloudflare settings.
	 */
	private function authMode( array $cloudflare ): string {
		if ( '' !== $this->constant( 'GTP_CLOUDFLARE_API_TOKEN' ) ) {
			return 'token';
		}
		if (
			'' !== $this->constant( 'GTP_CLOUDFLARE_GLOBAL_API_KEY' )
			&& '' !== $this->constant( 'GTP_CLOUDFLARE_EMAIL' )
		) {
			return 'global';
		}

		return 'global' === ( $cloudflare['auth_mode'] ?? 'token' ) ? 'global' : 'token';
	}

	private function normalizeDomain( string $domain ): string {
		$domain = trim( strtolower( $domain ) );
		$url    = str_contains( $domain, '://' ) ? $domain : 'https://' . $domain;
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		return trim( strtolower( is_string( $host ) ? $host : '' ), ". \t\n\r\0\x0B" );
	}

	private function constant( string $name ): string {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}
}
