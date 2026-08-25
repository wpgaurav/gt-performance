<?php
/**
 * Encrypted local license state.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Licensing;

use GTPerformance\Core\SecretCipher;

final class LicenseRepository {
	public const OPTION = 'gt_performance_license';

	private SecretCipher $keyCipher;

	private SecretCipher $hashCipher;

	public function __construct() {
		$this->keyCipher  = new SecretCipher( 'license-key' );
		$this->hashCipher = new SecretCipher( 'license-activation' );
	}

	/**
	 * @return array<string, int|string>
	 */
	public function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array_merge(
			array(
				'status'            => 'inactive',
				'license_key'       => '',
				'activation_hash'   => '',
				'expiration_date'   => '',
				'variation_id'      => 0,
				'variation_title'   => '',
				'activations_count' => 0,
				'activation_limit'  => 0,
				'activated_at'      => '',
				'last_checked_at'   => '',
			),
			$saved
		);
	}

	public function licenseKey(): string {
		$state = $this->state();

		return $this->keyCipher->decrypt( (string) $state['license_key'], 'GTP_LICENSE_KEY' );
	}

	public function activationHash(): string {
		$state = $this->state();

		return $this->hashCipher->decrypt( (string) $state['activation_hash'] );
	}

	public function hasCredentials(): bool {
		return '' !== $this->licenseKey() || '' !== $this->activationHash();
	}

	public function isConstantManaged(): bool {
		return defined( 'GTP_LICENSE_KEY' ) && is_string( GTP_LICENSE_KEY ) && '' !== trim( GTP_LICENSE_KEY );
	}

	public function maskedKey(): string {
		$key = $this->licenseKey();
		if ( '' === $key ) {
			return '';
		}

		$visible = min( 4, strlen( $key ) );

		return str_repeat( '•', max( 8, strlen( $key ) - $visible ) ) . substr( $key, -$visible );
	}

	/**
	 * @param array<string, mixed> $response Activation response.
	 */
	public function saveActivation( string $licenseKey, array $response ): void {
		$state                    = $this->state();
		$state['license_key']     = $this->keyCipher->encrypt( $licenseKey );
		$activationHash           = trim( (string) ( $response['activation_hash'] ?? '' ) );
		$state['activation_hash'] = '' !== $activationHash
			? $this->hashCipher->encrypt( $activationHash )
			: (string) $state['activation_hash'];
		$this->applyPublicState( $state, $response );
		$state['status']       = 'valid';
		$state['activated_at'] = gmdate( 'Y-m-d H:i:s' );

		update_option( self::OPTION, $state, false );
	}

	/**
	 * @param array<string, mixed> $response Verification response.
	 */
	public function saveVerification( array $response ): void {
		$state = $this->state();
		$this->applyPublicState( $state, $response );
		$state['status']          = $this->normalizeStatus( (string) ( $response['status'] ?? 'invalid' ) );
		$state['last_checked_at'] = gmdate( 'Y-m-d H:i:s' );

		update_option( self::OPTION, $state, false );
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @param array<string, int|string> $state    Saved state.
	 * @param array<string, mixed>      $response FluentCart response.
	 */
	private function applyPublicState( array &$state, array $response ): void {
		$state['expiration_date']   = sanitize_text_field( (string) ( $response['expiration_date'] ?? '' ) );
		$state['variation_id']      = absint( $response['variation_id'] ?? 0 );
		$state['variation_title']   = sanitize_text_field( (string) ( $response['variation_title'] ?? '' ) );
		$state['activations_count'] = absint( $response['activations_count'] ?? 0 );
		$state['activation_limit']  = absint( $response['activation_limit'] ?? 0 );
	}

	private function normalizeStatus( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, array( 'valid', 'active' ), true ) ? 'valid' : 'invalid';
	}
}
