<?php
/**
 * Cloudflare authentication header tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cloudflare\ApiCredentials;
use PHPUnit\Framework\TestCase;

final class ApiCredentialsTest extends TestCase {
	public function test_scoped_token_uses_bearer_authorization(): void {
		$credentials = ApiCredentials::apiToken( 'scoped-token' );

		self::assertSame( 'token', $credentials->mode() );
		self::assertSame(
			array( 'Authorization' => 'Bearer scoped-token' ),
			$credentials->headers()
		);
	}

	public function test_global_key_uses_email_and_key_headers(): void {
		$credentials = ApiCredentials::globalKey( 'owner@example.com', 'global-key' );

		self::assertSame( 'global', $credentials->mode() );
		self::assertSame(
			array(
				'X-Auth-Email' => 'owner@example.com',
				'X-Auth-Key'   => 'global-key',
			),
			$credentials->headers()
		);
	}
}
