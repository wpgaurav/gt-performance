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

		$GLOBALS['gtperf_test_options']       = array( Settings::OPTION => $settings, 'gt_performance_schema_version' => \GTPerformance\Core\Database::SCHEMA_VERSION );
		$GLOBALS['gtperf_test_http_requests'] = array();
		$GLOBALS['gtperf_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"success":true,"result":{}}',
		);
		\WP_CLI::$successes                = array();
		\WP_CLI::$lines                    = array();
		\WP_CLI::$logs                     = array();
		\WP_CLI::$halted                   = null;
		$GLOBALS['gtperf_test_cli_items']  = array();
	}

	public function testCloudflarePurgeClearsTheEntireZone(): void {
		( new Command() )->cloudflare( array( 'purge' ), array() );

		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		self::assertSame(
			'https://api.cloudflare.com/client/v4/zones/zone-123/purge_cache',
			$GLOBALS['gtperf_test_http_requests'][0]['url']
		);
		self::assertSame(
			array( 'purge_everything' => true ),
			json_decode( (string) $GLOBALS['gtperf_test_http_requests'][0]['args']['body'], true )
		);
		self::assertSame( array( 'Cloudflare full purge completed.' ), \WP_CLI::$successes );
	}

	public function testCloudflarePurgeCanTargetOneExactUrl(): void {
		$url = 'https://example.com/article/?updated=1';

		( new Command() )->cloudflare( array( 'purge' ), array( 'page-url' => $url ) );

		self::assertCount( 1, $GLOBALS['gtperf_test_http_requests'] );
		self::assertSame(
			array( 'files' => array( $url ) ),
			json_decode( (string) $GLOBALS['gtperf_test_http_requests'][0]['args']['body'], true )
		);
		self::assertSame( array( 'Cloudflare URL purge completed.' ), \WP_CLI::$successes );
	}

	public function testCloudflarePurgeReportsApiFailures(): void {
		$GLOBALS['gtperf_test_http_response'] = array(
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

		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}

	public function testPageUrlOnAnotherSiteIsRefusedBeforeAnyRequest(): void {
		foreach ( array( 'cloudflare', 'cache' ) as $command ) {
			try {
				( new Command() )->{$command}( array( 'purge' ), array( 'page-url' => 'https://attacker.example/article/' ) );
				self::fail( $command . ' purge accepted a URL on another site.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringStartsWith( '--page-url must be on this site (example.com)', $exception->getMessage() );
			}
		}

		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}

	public function testPageUrlHostMatchingIgnoresCase(): void {
		( new Command() )->cloudflare( array( 'purge' ), array( 'page-url' => 'https://EXAMPLE.com/article/' ) );

		self::assertSame( array( 'Cloudflare URL purge completed.' ), \WP_CLI::$successes );
	}

	/**
	 * Run database run with a $wpdb that records the revision query.
	 *
	 * @param array<string, string> $assocArgs Named arguments.
	 * @return list<string>
	 */
	private function revisionQueries( array $assocArgs ): array {
		$settings                                   = $GLOBALS['gtperf_test_options'][ Settings::OPTION ];
		$settings['database']['tasks']              = array( 'revisions' );
		$settings['database']['retain_revisions']   = 5;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;

		$original        = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public string $posts = 'wp_posts';

			/** @var list<string> */
			public array $queries = array();

			public function prepare( string $query, mixed ...$arguments ): string {
				return vsprintf( str_replace( '%d', '%s', $query ), array_map( 'strval', $arguments ) );
			}

			/**
			 * @return list<string>
			 */
			public function get_col( string $query ): array {
				$this->queries[] = $query;

				return array();
			}
		};

		try {
			( new Command() )->database( array( 'run' ), $assocArgs );

			return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $query ): bool => str_contains( $query, 'HAVING COUNT(*)' ) ) );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	public function testDatabaseRunKeepsTheRetainedRevisions(): void {
		$queries = $this->revisionQueries( array() );

		self::assertNotEmpty( $queries );
		self::assertStringContainsString( 'HAVING COUNT(*) > 5', $queries[0] );
	}

	public function testDatabaseRunCanDeleteEveryRevisionOnRequest(): void {
		$queries = $this->revisionQueries( array( 'all-revisions' => '' ) );

		self::assertStringContainsString( 'HAVING COUNT(*) > 0', $queries[0] );
	}

	public function testAllRevisionsIsRejectedForAPreview(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '--all-revisions is supported only by database run.' );

		( new Command() )->database( array( 'preview' ), array( 'all-revisions' => '' ) );
	}

	/**
	 * Run a health command with the cache directory writable or not, which is a
	 * check both commands report as a failure.
	 */
	private function runWithCacheDirectory( bool $writable, callable $command ): ?int {
		$root = \GTPerformance\Core\Paths::cacheRoot();
		wp_mkdir_p( $root );
		chmod( $root, $writable ? 0o755 : 0o555 );
		try {
			$command();
		} finally {
			chmod( $root, 0o755 );
		}

		return \WP_CLI::$halted;
	}

	public function testDoctorAndHealthExitNonZeroWhenACheckFails(): void {
		self::assertSame( 1, $this->runWithCacheDirectory( false, static fn() => ( new Command() )->doctor() ) );

		\WP_CLI::$halted = null;
		self::assertSame( 1, $this->runWithCacheDirectory( false, static fn() => ( new Command() )->health( array(), array() ) ) );

		\WP_CLI::$halted = null;
		self::assertSame( 1, $this->runWithCacheDirectory( false, static fn() => ( new Command() )->health( array(), array( 'format' => 'json' ) ) ) );
	}

	public function testDoctorWarningsAloneExitZero(): void {
		// Integrations that are simply not in use report warnings, and monitoring
		// must not page anyone for a site that never connected Cloudflare.
		self::assertNull( $this->runWithCacheDirectory( true, static fn() => ( new Command() )->doctor() ) );
		$statuses = array_column( $GLOBALS['gtperf_test_cli_items'][0]['items'], 'status' );
		self::assertContains( 'warning', $statuses );
		self::assertNotContains( 'fail', $statuses );
	}

	public function testActionSpecificOptionsCannotBeSilentlyIgnored(): void {
		$cases = array(
			static function ( Command $command ): void {
				$command->cache( array( 'status' ), array( 'page-url' => 'https://example.com/' ) );
			},
			static function ( Command $command ): void {
				$command->cloudflare( array( 'sync' ), array( 'page-url' => 'https://example.com/' ) );
			},
		);
		$messages = array(
			'--page-url is supported only by cache purge, explain, and verify.',
			'--page-url is supported only by cloudflare purge.',
		);

		foreach ( $cases as $index => $invoke ) {
			try {
				$invoke( new Command() );
				self::fail( 'An action-specific option should not be ignored.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $messages[ $index ], $exception->getMessage() );
			}
		}

		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}

	public function testQueueRejectsANonNumericLimitBeforeRunningJobs(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Use --limit with a positive whole number.' );

		( new Command() )->queue( array( 'run' ), array( 'limit' => 'many' ) );
	}

	public function testQueueWithoutActionRetainsRunDefault(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Use --limit with a positive whole number.' );
		( new Command() )->queue( array(), array( 'limit' => 'many' ) );
	}

	public function testQueueDoesNotRunBeforeMigrationFinishes(): void {
		delete_option( 'gt_performance_schema_version' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'queue schema upgrade is incomplete' );
		( new Command() )->queue( array( 'run' ), array() );
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

		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
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
				'Unknown cache action. Use status, purge, warm, warm-status, preview, install-dropin, explain, or verify.',
			),
			'queue'      => array(
				static function ( Command $command ): void {
					$command->queue( array( 'typo' ), array() );
				},
				'Unknown queue action. Use status, list, run, pause, resume, retry, or cancel.',
			),
			'cloudflare' => array(
				static function ( Command $command ): void {
					$command->cloudflare( array( 'typo' ), array() );
				},
				'Unknown Cloudflare action. Use status, plan, sync, purge, or disconnect.',
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
		);
	}
}
