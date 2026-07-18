<?php
/**
 * Cloudflare token cipher tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cloudflare\TokenCipher;
use PHPUnit\Framework\TestCase;

final class TokenCipherTest extends TestCase {
	public function test_token_round_trip(): void {
		$cipher    = new TokenCipher();
		$encrypted = $cipher->encrypt( 'test-cloudflare-token' );

		self::assertNotSame( 'test-cloudflare-token', $encrypted );
		self::assertSame( 'test-cloudflare-token', $cipher->decrypt( $encrypted ) );
	}

	public function test_global_key_round_trip_uses_its_own_constant_name(): void {
		$cipher    = new TokenCipher();
		$encrypted = $cipher->encrypt( 'test-cloudflare-global-key' );

		self::assertSame(
			'test-cloudflare-global-key',
			$cipher->decrypt( $encrypted, 'GTP_TEST_CLOUDFLARE_GLOBAL_API_KEY' )
		);
	}
}
