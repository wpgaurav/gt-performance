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
		$GLOBALS['gtp_test_options']             = array();
		$GLOBALS['gtp_test_cache_deletions']     = array();
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

		self::assertSame( GTP_VERSION, $this->installer->installedVersion() );
		self::assertSame( GTP_VERSION, $GLOBALS['gtp_test_options']['gt_performance_object_cache_dropin_version'] );
	}

	public function testForeignDropinRemainsUntouched(): void {
		$foreign = "<?php\n/** Foreign Redis drop-in */\n";
		file_put_contents( $this->installer->target(), $foreign );

		ObjectCacheInstaller::syncVersion();

		self::assertSame( $foreign, file_get_contents( $this->installer->target() ) );
		self::assertSame( array(), $GLOBALS['gtp_test_cache_deletions'] );
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
			$GLOBALS['gtp_test_cache_deletions']
		);
	}
}
