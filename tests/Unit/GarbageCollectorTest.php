<?php
/**
 * Reclamation of everything the plugin writes to disk.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\GarbageCollector;
use GTPerformance\Core\Paths;
use PHPUnit\Framework\TestCase;

final class GarbageCollectorTest extends TestCase {
	private FileStore $store;

	protected function setUp(): void {
		$this->store = new FileStore();
		foreach ( Paths::writableDirectories() as $directory ) {
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0o777, true );
			}
		}
		$this->store->purgeAll();
	}

	/**
	 * @param array<string, int|string> $overrides Metadata overrides.
	 */
	private function seed( string $hash, array $overrides = array() ): void {
		$now = time();
		$this->store->write(
			$hash,
			'<html><body>x</body></html>',
			$overrides + array(
				'stored_at'   => $now,
				'fresh_until' => $now + 3600,
				'stale_until' => $now + 86400,
				'url'         => 'https://example.com/' . $hash . '/',
				'generation'  => 1,
			)
		);
	}

	public function test_entries_past_their_stale_window_are_removed(): void {
		$this->seed( str_repeat( 'a', 64 ), array( 'stale_until' => time() - 1 ) );
		$this->seed( str_repeat( 'b', 64 ) );

		$stats = ( new GarbageCollector() )->collect();

		self::assertSame( 1, $stats['expired'] );
		self::assertFileDoesNotExist( $this->store->pagePath( str_repeat( 'a', 64 ) ) );
		self::assertFileExists( $this->store->pagePath( str_repeat( 'b', 64 ) ) );
	}

	/**
	 * The generation is part of the cache key, so an entry written under an older one
	 * can never be recomputed and is dead weight forever. This is what turned 33
	 * reachable entries into 2,162 files on a real site.
	 */
	public function test_entries_from_an_earlier_generation_are_removed(): void {
		$this->seed( str_repeat( 'c', 64 ), array( 'generation' => 0 ) );
		$this->seed( str_repeat( 'd', 64 ), array( 'generation' => 1 ) );

		$stats = ( new GarbageCollector() )->collect();

		self::assertSame( 1, $stats['orphaned'] );
		self::assertFileDoesNotExist( $this->store->pagePath( str_repeat( 'c', 64 ) ) );
		self::assertFileExists( $this->store->pagePath( str_repeat( 'd', 64 ) ) );
	}

	public function test_the_guard_files_are_never_collected(): void {
		$this->seed( str_repeat( 'e', 64 ), array( 'stale_until' => time() - 1 ) );

		( new GarbageCollector() )->collect();

		self::assertFileExists( Paths::pages() . '/.htaccess' );
		self::assertFileExists( Paths::pages() . '/index.html' );
	}

	/**
	 * Shared hosting bills inodes. The store writes two files per variant, so an
	 * unbounded cache can suspend the account it was installed to speed up.
	 */
	public function test_the_oldest_entries_are_evicted_once_the_budget_is_exceeded(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array(
			'generation' => 1,
			'cache'      => array( 'entry_budget' => 2 ),
		);

		foreach ( array( 'f', '0', '1' ) as $index => $prefix ) {
			$this->seed( str_repeat( $prefix, 64 ), array( 'stored_at' => time() - ( 100 - $index ) ) );
		}

		$stats = ( new GarbageCollector() )->collect();

		self::assertSame( 1, $stats['evicted'], 'Three entries against a budget of two evicts one.' );
		self::assertSame( 2, $stats['remaining'] );
		self::assertFileDoesNotExist( $this->store->pagePath( str_repeat( 'f', 64 ) ), 'The oldest entry goes first.' );

		unset( $GLOBALS['gtperf_test_options']['gt_performance_settings'] );
	}

	public function test_abandoned_temporary_files_are_cleaned_up(): void {
		$temp = Paths::pages() . '/aa/orphan.html.abandoned.tmp';
		if ( ! is_dir( dirname( $temp ) ) ) {
			mkdir( dirname( $temp ), 0o777, true );
		}
		file_put_contents( $temp, 'half a page' );
		touch( $temp, time() - 7200 );

		( new GarbageCollector() )->collect();

		self::assertFileDoesNotExist( $temp, 'A crashed write must not leave a permanent orphan.' );
	}
}
