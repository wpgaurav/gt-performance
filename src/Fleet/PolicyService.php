<?php
/**
 * Licensed fleet policy export, verification, and application.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Fleet;

use GTPerformance\Core\Settings;
use GTPerformance\Licensing\LicenseRepository;

final class PolicyService {
	public function __construct(
		private readonly LicenseRepository $license = new LicenseRepository(),
		private readonly FleetRepository $repository = new FleetRepository(),
	) {
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create(): array|\WP_Error {
		if ( ! (bool) Settings::get( 'fleet.enabled', false ) ) {
			return new \WP_Error( 'gtp_fleet_disabled', __( 'Fleet policy exports are disabled on this site.', 'gt-performance' ) );
		}

		$bundle = $this->bundler();
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		return $bundle->create(
			Settings::all(),
			array_map( 'strval', (array) Settings::get( 'fleet.policy_modules', array() ) ),
			$this->repository->siteId(),
			time(),
			wp_generate_uuid4()
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function applyJson( string $json ): array|\WP_Error {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'gtp_fleet_json', __( 'The fleet policy is not valid JSON.', 'gt-performance' ) );
		}

		return $this->apply( $decoded );
	}

	/**
	 * @param array<string, mixed> $bundle Signed bundle.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply( array $bundle ): array|\WP_Error {
		if ( ! (bool) Settings::get( 'fleet.enabled', false ) || ! (bool) Settings::get( 'fleet.allow_imports', true ) ) {
			return new \WP_Error( 'gtp_fleet_disabled', __( 'Fleet policy imports are disabled on this site.', 'gt-performance' ) );
		}

		$bundler = $this->bundler();
		if ( is_wp_error( $bundler ) ) {
			return $bundler;
		}

		$bundleId = sanitize_text_field( (string) ( $bundle['bundle_id'] ?? '' ) );
		if ( $this->repository->used( $bundleId ) ) {
			return new \WP_Error( 'gtp_fleet_replay', __( 'This fleet policy was already applied.', 'gt-performance' ) );
		}
		if ( ! $bundler->verify( $bundle, time() ) ) {
			return new \WP_Error( 'gtp_fleet_signature', __( 'The fleet policy signature or timestamp is invalid.', 'gt-performance' ) );
		}

		$settings = array_replace_recursive( Settings::all(), $bundler->policy( $bundle ) );
		Settings::save( $settings );
		$this->repository->record( $bundleId, (string) ( $bundle['source_id'] ?? '' ), 'applied' );

		return array(
			'bundle_id' => $bundleId,
			'modules'   => array_keys( $bundler->policy( $bundle ) ),
		);
	}

	/**
	 * @return PolicyBundle|\WP_Error
	 */
	private function bundler(): PolicyBundle|\WP_Error {
		$state = $this->license->state();
		$key   = $this->license->licenseKey();
		if ( 'valid' !== (string) $state['status'] || '' === $key ) {
			return new \WP_Error( 'gtp_fleet_license', __( 'A valid GT Performance license is required for fleet policies.', 'gt-performance' ) );
		}

		return new PolicyBundle( hash( 'sha256', 'gt-performance-fleet|' . $key, true ) );
	}
}
