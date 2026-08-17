<?php
/**
 * Update-cache re-entry guard tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Licensing\Updater;
use PHPUnit\Framework\TestCase;

final class UpdaterCacheReentryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['gtp_test_transient_deletions']             = array();
		$GLOBALS['gtp_test_deleted_site_transient_listener'] = null;
	}

	protected function tearDown(): void {
		$GLOBALS['gtp_test_deleted_site_transient_listener'] = null;
	}

	public function test_clear_cache_deletes_the_update_transient(): void {
		Updater::clearCache();

		self::assertCount( 1, $GLOBALS['gtp_test_transient_deletions'] );
		self::assertStringStartsWith( 'gtp_fluentcart_update_', $GLOBALS['gtp_test_transient_deletions'][0] );
	}

	/**
	 * clearCache() runs on delete_site_transient_update_plugins and then deletes
	 * a transient itself. A listener elsewhere that refreshes the plugin update
	 * cache in response re-enters clearCache(), and before the guard the two
	 * hooks recursed until PHP exhausted the VM stack — reported as
	 * "Allowed memory size of 536870912 bytes exhausted".
	 */
	public function test_clear_cache_does_not_recurse_when_deletion_re_enters_it(): void {
		$GLOBALS['gtp_test_deleted_site_transient_listener'] = static function (): void {
			Updater::clearCache();
		};

		Updater::clearCache();

		self::assertCount( 1, $GLOBALS['gtp_test_transient_deletions'] );
	}

	public function test_guard_is_released_for_later_calls(): void {
		$GLOBALS['gtp_test_deleted_site_transient_listener'] = static function (): void {
			Updater::clearCache();
		};

		Updater::clearCache();
		Updater::clearCache();

		self::assertCount( 2, $GLOBALS['gtp_test_transient_deletions'] );
	}
}
