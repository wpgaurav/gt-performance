<?php
/**
 * Recommended integration defaults tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Integrations\RecommendedDefaults;
use PHPUnit\Framework\TestCase;

final class RecommendedIntegrationDefaultsTest extends TestCase {
	public function testProfilesUseTheCurrentSiteAndSafeProviders(): void {
		$profiles = RecommendedDefaults::profiles( 'https://Example.com/path/' );

		self::assertSame( 'example.com', $profiles['cloudflare']['cloudflare']['domain'] );
		self::assertSame( 'token', $profiles['cloudflare']['cloudflare']['auth_mode'] );
		self::assertSame( 86400, $profiles['cloudflare']['cloudflare']['edge_ttl'] );
		self::assertSame( 'example.com', $profiles['xcloud']['xcloud']['domain'] );
		self::assertArrayNotHasKey( 'api_token', $profiles['cloudflare']['cloudflare'] );
		self::assertArrayNotHasKey( 'api_token', $profiles['xcloud']['xcloud'] );
	}

	public function testProfilesArmOnlySafeDependentFeatures(): void {
		$profiles = RecommendedDefaults::profiles( 'https://example.com/' );

		self::assertTrue( $profiles['compatibility']['integrations']['akismet'] );
		self::assertTrue( $profiles['compatibility']['integrations']['jetpack'] );
		self::assertSame( 'automatic', $profiles['compatibility']['integrations']['perfmatters_owner'] );
		self::assertTrue( $profiles['private_fragments']['private_fragments']['cart_count'] );
		self::assertTrue( $profiles['private_fragments']['private_fragments']['account_link'] );
		self::assertContains( 'css', $profiles['cdn']['cdn']['file_types'] );
		self::assertNotContains( 'html', $profiles['cdn']['cdn']['file_types'] );
	}

	public function testRedisProfileContainsNoCredentialOrDestructiveValue(): void {
		$redis = RecommendedDefaults::profiles( 'https://example.com/' )['redis']['redis'];

		self::assertSame( '127.0.0.1', $redis['host'] );
		self::assertSame( 6379, $redis['port'] );
		self::assertSame( 0, $redis['database'] );
		self::assertArrayNotHasKey( 'password', $redis );
		self::assertArrayNotHasKey( 'enabled', $redis );
	}
}
