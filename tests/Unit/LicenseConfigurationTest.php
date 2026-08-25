<?php
/**
 * FluentCart license-site identity tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Licensing\Configuration;
use PHPUnit\Framework\TestCase;

final class LicenseConfigurationTest extends TestCase {
	public function test_standard_site_url_is_unchanged(): void {
		$url = 'https://example.com/wordpress/';

		self::assertSame( $url, Configuration::portSafeSiteUrl( $url ) );
	}

	public function test_port_site_url_uses_stable_colon_safe_identity(): void {
		$identity = Configuration::portSafeSiteUrl( 'http://localhost:8887/' );

		self::assertMatchesRegularExpression(
			'/^http:\/\/localhost-p8887-[a-f0-9]{10}\.invalid\/$/',
			$identity
		);
		self::assertNull( parse_url( $identity, PHP_URL_PORT ) );
	}

	public function test_different_ports_have_different_identities(): void {
		$first  = Configuration::portSafeSiteUrl( 'http://localhost:8885/' );
		$second = Configuration::portSafeSiteUrl( 'http://localhost:8887/' );

		self::assertNotSame( $first, $second );
	}
}
