<?php
/**
 * Purge response evidence tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Diagnostics\ResponseSnapshot;
use PHPUnit\Framework\TestCase;

final class ResponseSnapshotTest extends TestCase {
	public function testSnapshotRedactsBodyAndFlagsPrivateResponses(): void {
		$snapshot = ( new ResponseSnapshot() )->analyze(
			200,
			array(
				'Cache-Control'   => 'private, no-store',
				'Set-Cookie'      => 'session=private',
				'CF-Cache-Status' => 'DYNAMIC',
			),
			'<html>customer@example.com</html>'
		);

		self::assertTrue( $snapshot['private'] );
		self::assertTrue( $snapshot['set_cookie'] );
		self::assertSame( 'DYNAMIC', $snapshot['cf_cache_status'] );
		self::assertArrayNotHasKey( 'body', $snapshot );
		self::assertSame( 16, strlen( (string) $snapshot['body_fingerprint'] ) );
	}
}
