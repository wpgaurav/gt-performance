<?php
/**
 * Fleet policy bundle tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Fleet\PolicyBundle;
use PHPUnit\Framework\TestCase;

final class PolicyBundleTest extends TestCase {
	public function testBundleIsSignedPortableAndSecretFree(): void {
		$bundler = new PolicyBundle( 'shared-license-derived-key' );
		$bundle  = $bundler->create(
			array(
				'cache'      => array( 'enabled' => true ),
				'cloudflare' => array(
					'enabled'   => true,
					'api_token' => 'secret',
					'zone_id'   => 'zone',
					'nested'    => array( 'password' => 'also-secret' ),
				),
			),
			array( 'cache', 'cloudflare' ),
			'site-a',
			1000,
			'bundle-a'
		);

		self::assertTrue( $bundler->verify( $bundle, 1100 ) );
		self::assertArrayNotHasKey( 'api_token', $bundle['policy']['cloudflare'] );
		self::assertArrayNotHasKey( 'password', $bundle['policy']['cloudflare']['nested'] );
		self::assertSame( 'zone', $bundle['policy']['cloudflare']['zone_id'] );
	}

	public function testTamperedOrExpiredBundleIsRejected(): void {
		$bundler = new PolicyBundle( 'shared-key' );
		$bundle  = $bundler->create( array( 'cache' => array( 'enabled' => true ) ), array( 'cache' ), 'a', 1000, 'one' );
		$bundle['policy']['cache']['enabled'] = false;

		self::assertFalse( $bundler->verify( $bundle, 1100 ) );
		$valid = $bundler->create( array( 'cache' => array( 'enabled' => true ) ), array( 'cache' ), 'a', 1000, 'two' );
		self::assertFalse( $bundler->verify( $valid, 1400 ) );
	}
}
