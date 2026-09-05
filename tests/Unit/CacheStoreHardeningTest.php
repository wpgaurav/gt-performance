<?php
/**
 * A full purge must not strip the cache directory's own web guards.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\FileStore;
use GTPerformance\Core\Paths;
use PHPUnit\Framework\TestCase;

final class CacheStoreHardeningTest extends TestCase {
	protected function setUp(): void {
		foreach ( Paths::writableDirectories() as $directory ) {
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0o777, true );
			}
		}

		Paths::harden();
	}

	/**
	 * purgeAll() walks the page tree and unlinked everything in it, including the
	 * .htaccess and index.html that Paths::harden() writes. On Apache that left raw
	 * cached HTML fetchable by hash until something re-hardened the directory.
	 */
	public function test_purge_all_preserves_the_guard_files(): void {
		$store = new FileStore();
		$hash  = hash( 'sha256', 'guard-test' );

		$store->write(
			$hash,
			'<html><body>cached</body></html>',
			array(
				'stored_at'   => time(),
				'fresh_until' => time() + 60,
				'stale_until' => time() + 120,
				'url'         => 'https://example.com/guard-test/',
				'generation'  => 1,
			)
		);

		self::assertFileExists( $store->pagePath( $hash ) );
		self::assertFileExists( Paths::pages() . '/.htaccess' );

		$store->purgeAll();

		self::assertFileDoesNotExist( $store->pagePath( $hash ), 'The cached entry must go.' );
		self::assertFileExists( Paths::pages() . '/.htaccess', 'The Apache guard must survive.' );
		self::assertFileExists( Paths::pages() . '/index.html', 'The listing guard must survive.' );
	}

	public function test_purge_all_reasserts_guards_it_could_not_preserve(): void {
		$store = new FileStore();

		unlink( Paths::pages() . '/.htaccess' );
		self::assertFileDoesNotExist( Paths::pages() . '/.htaccess' );

		$store->purgeAll();

		self::assertFileExists( Paths::pages() . '/.htaccess' );
	}
}
