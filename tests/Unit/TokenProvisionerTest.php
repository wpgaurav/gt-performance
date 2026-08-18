<?php
/**
 * Cloudflare token provisioning tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cloudflare\TokenProvisioner;
use PHPUnit\Framework\TestCase;

final class TokenProvisionerTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private function settings( string $zoneId = '' ): array {
		return array(
			'cloudflare' => array(
				'zone_id' => $zoneId,
				'domain'  => 'example.com',
			),
		);
	}

	public function testTemplateUrlCarriesEveryRequiredPermissionKey(): void {
		$url = ( new TokenProvisioner() )->templateUrl( $this->settings() );

		self::assertStringStartsWith( 'https://dash.cloudflare.com/profile/api-tokens?', $url );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$keys = json_decode( rawurldecode( (string) $query['permissionGroupKeys'] ), true );

		self::assertSame(
			array(
				array(
					'key'  => 'zone',
					'type' => 'read',
				),
				array(
					'key'  => 'cache_purge',
					'type' => 'purge',
				),
				array(
					'key'  => 'cache_settings',
					'type' => 'edit',
				),
			),
			$keys
		);
	}

	public function testTemplateUrlScopesToTheConfiguredZone(): void {
		$provisioner = new TokenProvisioner();

		parse_str( (string) wp_parse_url( $provisioner->templateUrl( $this->settings( 'abc123' ) ), PHP_URL_QUERY ), $scoped );
		parse_str( (string) wp_parse_url( $provisioner->templateUrl( $this->settings() ), PHP_URL_QUERY ), $unscoped );

		self::assertSame( 'abc123', $scoped['zoneId'] );
		self::assertSame( 'all', $unscoped['zoneId'] );
	}

	public function testRequiredPermissionsNamesCacheRulesEdit(): void {
		$permissions = ( new TokenProvisioner() )->requiredPermissions();

		// Cache Settings Write is the permission whose absence silently breaks rule
		// syncing while every read-only check still passes, so it must be listed.
		self::assertCount( 3, $permissions );
		self::assertStringContainsString( 'Cache Settings Write', implode( ' ', $permissions ) );
	}
}
