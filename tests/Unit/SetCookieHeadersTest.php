<?php
/**
 * Selective Set-Cookie header tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Compatibility\SetCookieHeaders;
use PHPUnit\Framework\TestCase;

final class SetCookieHeadersTest extends TestCase {
	public function test_removes_only_the_named_cookie(): void {
		$result = ( new SetCookieHeaders() )->removeCookie(
			array(
				'Content-Type: text/html',
				'Set-Cookie: cf_poll_voter=visitor; Path=/; Secure',
				'Set-Cookie: commerce_session=private; Path=/; HttpOnly',
			),
			'cf_poll_voter'
		);

		self::assertTrue( $result['removed'] );
		self::assertSame(
			array( 'Set-Cookie: commerce_session=private; Path=/; HttpOnly' ),
			$result['kept']
		);
	}

	public function test_leaves_unrelated_cookies_untouched(): void {
		$result = ( new SetCookieHeaders() )->removeCookie(
			array( 'Set-Cookie: commerce_session=private; Path=/; HttpOnly' ),
			'cf_poll_voter'
		);

		self::assertFalse( $result['removed'] );
		self::assertCount( 1, $result['kept'] );
	}
}
