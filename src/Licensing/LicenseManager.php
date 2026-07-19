<?php
/**
 * License activation and verification service.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Licensing;

final class LicenseManager {
	public function __construct(
		private readonly LicenseRepository $repository = new LicenseRepository(),
		private readonly FluentCartClient $client = new FluentCartClient(),
	) {
	}

	public function repository(): LicenseRepository {
		return $this->repository;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function activate( string $licenseKey ): bool|\WP_Error {
		$licenseKey = trim( sanitize_text_field( $licenseKey ) );
		if ( '' === $licenseKey ) {
			return new \WP_Error( 'gtp_license_key', __( 'Enter a GT Performance license key.', 'gt-performance' ) );
		}

		$response = $this->client->request(
			'activate_license',
			array( 'license_key' => $licenseKey )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = sanitize_key( (string) ( $response['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'valid', 'active' ), true ) ) {
			return new \WP_Error( 'gtp_license_invalid', __( 'FluentCart did not accept this license for the current site.', 'gt-performance' ) );
		}

		try {
			$this->repository->saveActivation( $licenseKey, $response );
		} catch ( \Throwable ) {
			return new \WP_Error( 'gtp_license_save', __( 'GT Performance could not encrypt and save the license.', 'gt-performance' ) );
		}

		Updater::clearCache();

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function verify(): bool|\WP_Error {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$response = $this->client->request( 'check_license', $credentials );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->repository->saveVerification( $response );
		Updater::clearCache();

		$status = sanitize_key( (string) ( $response['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'valid', 'active' ), true ) ) {
			return new \WP_Error( 'gtp_license_invalid', __( 'The saved GT Performance license is not valid for this site.', 'gt-performance' ) );
		}

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function deactivate(): bool|\WP_Error {
		$key = $this->repository->licenseKey();
		if ( '' === $key ) {
			return new \WP_Error( 'gtp_license_key', __( 'No saved license key is available to deactivate.', 'gt-performance' ) );
		}

		$response = $this->client->request(
			'deactivate_license',
			array( 'license_key' => $key )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = sanitize_key( (string) ( $response['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'deactivated', 'inactive' ), true ) ) {
			return new \WP_Error( 'gtp_license_deactivate', __( 'FluentCart did not confirm license deactivation.', 'gt-performance' ) );
		}

		$this->repository->clear();
		Updater::clearCache();

		return true;
	}

	/**
	 * @return array<string, scalar>|\WP_Error
	 */
	public function credentials(): array|\WP_Error {
		$key  = $this->repository->licenseKey();
		$hash = $this->repository->activationHash();
		if ( '' === $key && '' === $hash ) {
			return new \WP_Error( 'gtp_license_missing', __( 'Activate a GT Performance license before checking for updates.', 'gt-performance' ) );
		}

		$credentials = array();
		if ( '' !== $hash ) {
			$credentials['activation_hash'] = $hash;
		} elseif ( '' !== $key ) {
			$credentials['license_key'] = $key;
		}

		return $credentials;
	}
}
