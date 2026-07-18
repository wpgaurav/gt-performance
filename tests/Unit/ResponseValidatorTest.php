<?php
/**
 * Response validator tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\ResponseValidator;
use PHPUnit\Framework\TestCase;

final class ResponseValidatorTest extends TestCase {
	public function test_safe_html_is_accepted(): void {
		$decision = ( new ResponseValidator() )->validate(
			'<!doctype html><html><body>Safe</body></html>',
			200,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		self::assertTrue( $decision->cacheable );
	}

	/**
	 * @dataProvider unsafeHeaders
	 */
	public function test_private_headers_are_rejected( string $header, string $reason ): void {
		$decision = ( new ResponseValidator() )->validate(
			'<!doctype html><html><body>Private</body></html>',
			200,
			array( $header )
		);

		self::assertFalse( $decision->cacheable );
		self::assertSame( $reason, $decision->reason );
	}

	/**
	 * @return iterable<string, array{string,string}>
	 */
	public static function unsafeHeaders(): iterable {
		yield 'cookie' => array( 'Set-Cookie: secret=value', 'set_cookie' );
		yield 'private' => array( 'Cache-Control: private, max-age=0', 'private_cache_control' );
		yield 'JSON' => array( 'Content-Type: application/json', 'content_type' );
	}
}
