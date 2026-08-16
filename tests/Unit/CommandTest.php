<?php
/**
 * WP-CLI command routing tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\CLI\Command;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommandTest extends TestCase {
	protected function setUp(): void {
		$settings                           = Settings::defaults();
		$settings['cloudflare']['enabled']  = true;
		$settings['cloudflare']['zone_id']  = 'zone-123';
		$settings['cloudflare']['api_token'] = ( new TokenCipher() )->encrypt( 'test-token' );

		$GLOBALS['gtp_test_options']       = array( Settings::OPTION => $settings );
		$GLOBALS['gtp_test_http_requests'] = array();
		$GLOBALS['gtp_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"success":true,"result":{}}',
		);
		\WP_CLI::$successes                = array();
		\WP_CLI::$lines                    = array();
		\WP_CLI::$logs                     = array();
	}

	public function testCloudflarePurgeClearsTheEntireZone(): void {
		( new Command() )->cloudflare( array( 'purge' ), array() );

		self::assertCount( 1, $GLOBALS['gtp_test_http_requests'] );
		self::assertSame(
			'https://api.cloudflare.com/client/v4/zones/zone-123/purge_cache',
			$GLOBALS['gtp_test_http_requests'][0]['url']
		);
		self::assertSame(
			array( 'purge_everything' => true ),
			json_decode( (string) $GLOBALS['gtp_test_http_requests'][0]['args']['body'], true )
		);
		self::assertSame( array( 'Cloudflare full purge completed.' ), \WP_CLI::$successes );
	}

	public function testCloudflarePurgeCanTargetOneExactUrl(): void {
		$url = 'https://example.com/article/?updated=1';

		( new Command() )->cloudflare( array( 'purge' ), array( 'page-url' => $url ) );

		self::assertCount( 1, $GLOBALS['gtp_test_http_requests'] );
		self::assertSame(
			array( 'files' => array( $url ) ),
			json_decode( (string) $GLOBALS['gtp_test_http_requests'][0]['args']['body'], true )
		);
		self::assertSame( array( 'Cloudflare URL purge completed.' ), \WP_CLI::$successes );
	}

	public function testCloudflarePurgeReportsApiFailures(): void {
		$GLOBALS['gtp_test_http_response'] = array(
			'response' => array( 'code' => 403 ),
			'body'     => '{"success":false,"errors":[{"message":"Missing purge permission"}]}',
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Missing purge permission' );

		( new Command() )->cloudflare( array( 'purge' ), array() );
	}

	public function testInvalidExplicitPageUrlCannotBecomeAFullPurge(): void {
		try {
			( new Command() )->cloudflare( array( 'purge' ), array( 'page-url' => '' ) );
			self::fail( 'An empty explicit target should fail.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Use --page-url with a complete HTTP or HTTPS URL.', $exception->getMessage() );
		}

		self::assertSame( array(), $GLOBALS['gtp_test_http_requests'] );
	}

	public function testActionSpecificOptionsCannotBeSilentlyIgnored(): void {
		$cases = array(
			static function ( Command $command ): void {
				$command->cache( array( 'status' ), array( 'page-url' => 'https://example.com/' ) );
			},
			static function ( Command $command ): void {
				$command->cloudflare( array( 'sync' ), array( 'page-url' => 'https://example.com/' ) );
			},
			static function ( Command $command ): void {
				$command->fleet( array( 'export' ), array( 'file' => '/tmp/ignored.json' ) );
			},
		);
		$messages = array(
			'--page-url is supported only by cache purge, explain, and verify.',
			'--page-url is supported only by cloudflare purge.',
			'--file is supported only by fleet import.',
		);

		foreach ( $cases as $index => $invoke ) {
			try {
				$invoke( new Command() );
				self::fail( 'An action-specific option should not be ignored.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $messages[ $index ], $exception->getMessage() );
			}
		}

		self::assertSame( array(), $GLOBALS['gtp_test_http_requests'] );
	}

	public function testQueueRejectsANonNumericLimitBeforeRunningJobs(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Use --limit with a positive whole number.' );

		( new Command() )->queue( array( 'run' ), array( 'limit' => 'many' ) );
	}

	/**
	 * @dataProvider unknownActionProvider
	 *
	 * @param callable(Command): void $invoke Command invocation.
	 */
	public function testUnknownActionsFailBeforeDoingWork( callable $invoke, string $message ): void {
		try {
			$invoke( new Command() );
			self::fail( 'An unknown action should fail.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( $message, $exception->getMessage() );
		}

		self::assertSame( array(), $GLOBALS['gtp_test_http_requests'] );
	}

	/**
	 * @return array<string, array{callable(Command): void, string}>
	 */
	public static function unknownActionProvider(): array {
		return array(
			'cache'      => array(
				static function ( Command $command ): void {
					$command->cache( array( 'typo' ), array() );
				},
				'Unknown cache action. Use status, purge, warm, install-dropin, explain, or verify.',
			),
			'queue'      => array(
				static function ( Command $command ): void {
					$command->queue( array( 'typo' ), array() );
				},
				'Unknown queue action. Use run.',
			),
			'cloudflare' => array(
				static function ( Command $command ): void {
					$command->cloudflare( array( 'typo' ), array() );
				},
				'Unknown Cloudflare action. Use status, plan, sync, or purge.',
			),
			'xcloud' => array(
				static function ( Command $command ): void {
					$command->xcloud( array( 'typo' ) );
				},
				'Unknown xCloud action. Use status, refresh, or purge.',
			),
			'database'   => array(
				static function ( Command $command ): void {
					$command->database( array( 'typo' ) );
				},
				'Unknown database action. Use preview or run.',
			),
			'fleet'      => array(
				static function ( Command $command ): void {
					$command->fleet( array( 'typo' ), array() );
				},
				'Unknown fleet action. Use export or import.',
			),
		);
	}
}
