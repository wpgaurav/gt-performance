<?php
/**
 * Redis object-cache installer tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Redis\ObjectCacheInstaller;
use PHPUnit\Framework\TestCase;

final class ObjectCacheInstallerTest extends TestCase {
	private ObjectCacheInstaller $installer;

	protected function setUp(): void {
		$this->installer                         = new ObjectCacheInstaller();
		$GLOBALS['gtperf_test_options']             = array();
		$GLOBALS['gtperf_test_cache_deletions']     = array();
		$target                                  = $this->installer->target();
		is_file( $target ) && unlink( $target );
	}

	protected function tearDown(): void {
		$target = $this->installer->target();
		is_file( $target ) && unlink( $target );
	}

	public function testOwnedOutdatedDropinAutomaticallyUpdates(): void {
		file_put_contents(
			$this->installer->target(),
			"<?php\n/** GT Performance Redis object-cache drop-in v1.0.0-old */\n"
		);

		ObjectCacheInstaller::syncVersion();

		self::assertSame( GTPERF_VERSION, $this->installer->installedVersion() );

		// The recorded signature covers the bundled file, not just the version, so a
		// drop-in edited without a version bump is still republished. A version-only
		// signature left a broken object cache installed after the fix had shipped.
		$recorded = (string) $GLOBALS['gtperf_test_options']['gt_performance_object_cache_dropin_version'];
		self::assertStringStartsWith( GTPERF_VERSION . '|', $recorded );
		self::assertSame( 3, substr_count( $recorded, '|' ) + 1, 'version|dir|mtime' );
	}

	public function testAContentChangeWithoutAVersionBumpIsRepublished(): void {
		// Start from an owned drop-in so syncVersion() has something to keep current.
		file_put_contents(
			$this->installer->target(),
			"<?php\n/** GT Performance Redis object-cache drop-in v0.0.0-stale */\n"
		);
		ObjectCacheInstaller::syncVersion();
		$recorded = (string) ( $GLOBALS['gtperf_test_options']['gt_performance_object_cache_dropin_version'] ?? '' );

		self::assertNotSame( '', $recorded, 'The first sync must record a signature.' );

		// Same version, newer bundled file.
		touch( GTPERF_DIR . '/dropins/object-cache.php', time() + 10 );
		// PHP caches stat results per process; production calls syncVersion once per
		// request, but this test calls it twice.
		clearstatcache( true, GTPERF_DIR . '/dropins/object-cache.php' );
		file_put_contents( $this->installer->target(), "<?php\n/** GT Performance Redis object-cache drop-in v0.0.0-stale */\n" );

		ObjectCacheInstaller::syncVersion();

		self::assertNotSame(
			$recorded,
			(string) ( $GLOBALS['gtperf_test_options']['gt_performance_object_cache_dropin_version'] ?? '' ),
			'A changed drop-in must produce a new signature.'
		);
		self::assertSame( GTPERF_VERSION, $this->installer->installedVersion() );

		touch( GTPERF_DIR . '/dropins/object-cache.php' );
		clearstatcache( true, GTPERF_DIR . '/dropins/object-cache.php' );
	}

	public function testForeignDropinRemainsUntouched(): void {
		$foreign = "<?php\n/** Foreign Redis drop-in */\n";
		file_put_contents( $this->installer->target(), $foreign );

		ObjectCacheInstaller::syncVersion();

		self::assertSame( $foreign, file_get_contents( $this->installer->target() ) );
		self::assertSame( array(), $GLOBALS['gtperf_test_cache_deletions'] );
	}

	public function testUpdatingOwnedDropinClearsExactAggregateOptionCaches(): void {
		file_put_contents(
			$this->installer->target(),
			"<?php\n/** GT Performance Redis object-cache drop-in */\n"
		);

		ObjectCacheInstaller::syncVersion();

		self::assertSame(
			array(
				array( 'alloptions', 'options' ),
				array( 'notoptions', 'options' ),
				array( 'cron', 'options' ),
			),
			$GLOBALS['gtperf_test_cache_deletions']
		);
	}
}
