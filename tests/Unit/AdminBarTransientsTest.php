<?php
/**
 * Admin-bar transient clearing.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Admin\AdminModule;
use PHPUnit\Framework\TestCase;

final class AdminBarTransientsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_ext_object_cache'] );
	}

	/**
	 * A menu item whose command has no case falls through to "invalid action".
	 */
	public function test_every_admin_bar_command_has_a_handler(): void {
		$source = (string) file_get_contents( GTPERF_DIR . '/src/Admin/AdminBarModule.php' );
		preg_match_all( "/actionNode\(\s*\\\$bar,\s*'[^']+',\s*__\([^)]*\),\s*'([a-z-]+)'/", $source, $commands );
		preg_match_all( "/case '([a-z-]+)':/", $source, $cases );

		self::assertContains( 'clear-expired-transients', $commands[1] );
		self::assertContains( 'clear-transients', $commands[1] );
		self::assertNotContains( 'test-redis', $cases[1], 'Redis is tested from the Object Cache tab, not the admin bar.' );
		self::assertStringNotContainsString( 'gtp-object-cache', $source );
		foreach ( $commands[1] as $command ) {
			self::assertContains( $command, $cases[1], "Admin-bar item '{$command}' has no handler." );
		}
	}

	public function test_transient_notices_report_the_count(): void {
		self::assertSame(
			array( 'message' => 'Cleared 12 expired transients from the database.', 'type' => 'success' ),
			$this->notice( 'transients-expired-12' )
		);
		self::assertSame( 'Cleared 1 transient from the database.', $this->notice( 'transients-all-1' )['message'] );
	}

	public function test_transient_notices_mention_the_object_cache_when_it_holds_transients(): void {
		$GLOBALS['gtperf_test_ext_object_cache'] = true;

		self::assertStringContainsString( 'object cache', $this->notice( 'transients-all-0' )['message'] );
		self::assertStringContainsString( 'expire on their own', $this->notice( 'transients-expired-0' )['message'] );
	}

	public function test_a_malformed_transient_notice_is_not_treated_as_one(): void {
		self::assertStringNotContainsString( 'transient', $this->notice( 'transients-some-3' )['message'] ?? '' );
	}

	/**
	 * @return array{message?:string,type?:string}
	 */
	private function notice( string $notice ): array {
		$module = ( new \ReflectionClass( AdminModule::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( AdminModule::class, 'noticeDetails' );

		return (array) $method->invoke( $module, $notice );
	}
}
