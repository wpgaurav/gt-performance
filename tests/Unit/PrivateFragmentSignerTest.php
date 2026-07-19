<?php
/**
 * Private fragment signature tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\PrivateFragments\Signer;
use PHPUnit\Framework\TestCase;

final class PrivateFragmentSignerTest extends TestCase {
	public function testSignatureIsPurposeBoundToTheFragment(): void {
		$signer    = new Signer( 'test-key' );
		$signature = $signer->sign( 'commerce_cart_count' );

		self::assertTrue( $signer->verify( 'commerce_cart_count', $signature ) );
		self::assertFalse( $signer->verify( 'commerce_account_link', $signature ) );
		self::assertFalse( $signer->verify( 'commerce_cart_count', 'tampered' ) );
	}
}
