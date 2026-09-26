<?php
/** Local-only cache reads must work without WordPress HTTP functions. */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\ConfigFile;
use GTPerformance\Cache\DropinRuntime;
use PHPUnit\Framework\TestCase;

final class LocalCacheReadTest extends TestCase {
	public function test_cache_reader_rejects_wrappers_and_symlinks_outside_its_root(): void {
		$root = sys_get_temp_dir() . '/gtperf-read-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/page.html', '<p>Cached page</p>' );
		file_put_contents( $root . '-outside.html', 'outside' );
		symlink( $root . '-outside.html', $root . '/escape.html' );
		$read = new \ReflectionMethod( DropinRuntime::class, 'readLocalCacheFile' );
		try {
			self::assertSame( '<p>Cached page</p>', $read->invoke( null, $root . '/page.html', realpath( $root ) ) );
			foreach ( array( 'https://example.com/page.html', 'file://' . $root . '/page.html', 'php://memory', $root . '/escape.html', $root . '/missing.html' ) as $path ) {
				self::assertNull( $read->invoke( null, $path, realpath( $root ) ) );
			}
		} finally {
			unlink( $root . '/page.html' );
			unlink( $root . '/escape.html' );
			unlink( $root . '-outside.html' );
			rmdir( $root );
		}
	}

	public function test_configuration_reader_rejects_stream_urls(): void {
		self::assertNull( ConfigFile::read( 'https://example.com/config.php' ) );
		self::assertNull( ConfigFile::read( 'data://text/plain,' . rawurlencode( '{"cache":{}}' ) ) );
	}
}
