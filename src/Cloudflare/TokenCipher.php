<?php
/**
 * Cloudflare API token encryption.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

final class TokenCipher {
	public function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$key = $this->key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );

			return 'sodium:' . base64_encode( $nonce . $cipher );
		}

		$nonce     = random_bytes( 12 );
		$tag       = '';
		$encrypted = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $encrypted ) {
			throw new \RuntimeException( 'Unable to encrypt the Cloudflare token.' );
		}

		return 'openssl:' . base64_encode( $nonce . $tag . $encrypted );
	}

	public function decrypt( string $stored ): string {
		if ( defined( 'GTP_CLOUDFLARE_API_TOKEN' ) && is_string( GTP_CLOUDFLARE_API_TOKEN ) ) {
			return GTP_CLOUDFLARE_API_TOKEN;
		}

		if ( '' === $stored ) {
			return '';
		}

		$key = $this->key();

		if ( str_starts_with( $stored, 'sodium:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$decoded = base64_decode( substr( $stored, 7 ), true );
			if ( ! is_string( $decoded ) || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

			return is_string( $plain ) ? $plain : '';
		}

		if ( str_starts_with( $stored, 'openssl:' ) ) {
			$decoded = base64_decode( substr( $stored, 8 ), true );
			if ( ! is_string( $decoded ) || strlen( $decoded ) <= 28 ) {
				return '';
			}
			$nonce  = substr( $decoded, 0, 12 );
			$tag    = substr( $decoded, 12, 16 );
			$cipher = substr( $decoded, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );

			return is_string( $plain ) ? $plain : '';
		}

		return '';
	}

	private function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' .
			( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . '|gt-performance-cloudflare';

		return hash( 'sha256', $material, true );
	}
}
