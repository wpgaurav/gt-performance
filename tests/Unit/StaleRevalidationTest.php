<?php
/**
 * Stale page revalidation tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\DropinRuntime;
use GTPerformance\Cache\FileStore;
use GTPerformance\Core\Paths;
use PHPUnit\Framework\TestCase;

final class StaleRevalidationTest extends TestCase {
	public function test_preload_rebuilds_a_stale_entry(): void {
		self::assertTrue( DropinRuntime::shouldRevalidate( true, array( 'x-gt-preload' => '1' ) ) );
	}

	public function test_preload_still_serves_a_fresh_entry(): void {
		// Preloading current content must stay cheap, or a burst of queued jobs
		// would stampede the origin rebuilding pages that are already good.
		self::assertFalse( DropinRuntime::shouldRevalidate( false, array( 'x-gt-preload' => '1' ) ) );
	}

	public function test_ordinary_visitor_still_gets_the_stale_copy(): void {
		self::assertFalse( DropinRuntime::shouldRevalidate( true, array() ) );
	}

	public function test_blank_preload_header_is_ignored(): void {
		self::assertFalse( DropinRuntime::shouldRevalidate( true, array( 'x-gt-preload' => '  ' ) ) );
	}

	protected function setUp(): void {
		$this->removePages();
	}

	protected function tearDown(): void {
		$this->removePages();
	}

	public function test_stale_entry_is_reported(): void {
		$now = 1700000000;
		$this->writeEntry( 'aa11', 'https://example.com/stale/', $now - 7200, $now - 3600, $now + 3600 );

		self::assertSame(
			array( 'https://example.com/stale/' ),
			( new FileStore() )->staleUrls( $now, 10 )
		);
	}

	public function test_fresh_entry_is_not_reported(): void {
		$now = 1700000000;
		$this->writeEntry( 'bb22', 'https://example.com/fresh/', $now - 60, $now + 3600, $now + 90000 );

		self::assertSame( array(), ( new FileStore() )->staleUrls( $now, 10 ) );
	}

	public function test_expired_entry_is_not_reported(): void {
		// Past stale_until the drop-in already returns EXPIRED and WordPress
		// regenerates on the next hit, so queueing a preload would be wasted work.
		$now = 1700000000;
		$this->writeEntry( 'cc33', 'https://example.com/expired/', $now - 200000, $now - 190000, $now - 100 );

		self::assertSame( array(), ( new FileStore() )->staleUrls( $now, 10 ) );
	}

	public function test_limit_is_respected(): void {
		$now = 1700000000;
		for ( $i = 0; $i < 5; $i++ ) {
			$this->writeEntry( 'dd' . $i . '4', 'https://example.com/page-' . $i . '/', $now - 7200, $now - 3600, $now + 3600 );
		}

		self::assertCount( 2, ( new FileStore() )->staleUrls( $now, 2 ) );
	}

	public function test_zero_limit_scans_nothing(): void {
		$now = 1700000000;
		$this->writeEntry( 'ee55', 'https://example.com/stale/', $now - 7200, $now - 3600, $now + 3600 );

		self::assertSame( array(), ( new FileStore() )->staleUrls( $now, 0 ) );
	}

	public function test_unreadable_metadata_is_skipped(): void {
		$now       = 1700000000;
		$directory = Paths::pages() . '/ff';
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0o777, true );
		}
		file_put_contents( $directory . '/ff66.meta.php', "<?php\nreturn 'not-an-array';\n" );
		$this->writeEntry( 'ff77', 'https://example.com/ok/', $now - 7200, $now - 3600, $now + 3600 );

		self::assertSame(
			array( 'https://example.com/ok/' ),
			( new FileStore() )->staleUrls( $now, 10 )
		);
	}

	private function writeEntry( string $hash, string $url, int $stored, int $fresh, int $stale ): void {
		( new FileStore() )->write(
			$hash,
			'<html><body>cached</body></html>',
			array(
				'stored_at'   => $stored,
				'fresh_until' => $fresh,
				'stale_until' => $stale,
				'url'         => $url,
				'generation'  => 1,
			)
		);
	}

	private function removePages(): void {
		if ( ! is_dir( Paths::pages() ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( Paths::pages(), \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				@unlink( $item->getPathname() );
			} else {
				@rmdir( $item->getPathname() );
			}
		}
	}
}
