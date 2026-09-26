<?php
/** Drop-ins must not follow config symlinks outside their own cache directory. */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\ConfigFile;
use PHPUnit\Framework\TestCase;

final class EarlyConfigPathTest extends TestCase {
	/** @dataProvider dropins */
	public function test_early_readers_accept_local_files_and_reject_outside_symlinks( string $dropin, string $configName ): void {
		$root = sys_get_temp_dir() . '/gtperf-early-path-' . uniqid();
		$cache = $root . '/cache/gt-performance';
		mkdir( $cache, 0777, true );
		ConfigFile::write( $cache . '/' . $configName, array( 'plugin_dir' => GTPERF_DIR, 'cache' => array( 'enabled' => false ), 'host' => 'review-config-host', 'enabled' => false ) );
		$config = file_get_contents( $cache . '/' . $configName );
		file_put_contents( $root . '/outside.php', $config );
		file_put_contents( $cache . '/' . $configName, $config );
		$script = '<?php define("AUTH_KEY", "gt-performance-test-auth-key"); define("ABSPATH", ' . var_export( $root . '/', true ) . '); define("WP_CONTENT_DIR", ' . var_export( $root, true ) . '); require ' . var_export( GTPERF_DIR . '/dropins/' . $dropin, true ) . ';';
		if ( 'advanced-cache.php' === $dropin ) {
			$script .= 'echo class_exists("GTPerformance\\\\Cache\\\\DropinRuntime", false) ? "loaded" : "skipped";';
		} else {
			$script .= '$cache=(new ReflectionClass("WP_Object_Cache"))->newInstanceWithoutConstructor(); $method=new ReflectionMethod("WP_Object_Cache","configuration"); $config=$method->invoke($cache); echo ($config["host"] ?? "") === "review-config-host" ? "loaded" : "skipped";';
		}
		file_put_contents( $root . '/runner.php', $script );
		$command = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 ' . escapeshellarg( $root . '/runner.php' ) . ' 2>&1';
		try {
			self::assertSame( 'loaded', shell_exec( $command ) );
			file_put_contents( $root . '/runner.php', str_replace( 'gt-performance-test-auth-key', 'a-different-auth-key-for-test', $script ) );
			self::assertSame( 'skipped', shell_exec( $command ) );
			file_put_contents( $root . '/runner.php', $script );
			unlink( $cache . '/' . $configName );
			symlink( $root . '/outside.php', $cache . '/' . $configName );
			self::assertSame( 'skipped', shell_exec( $command ) );
		} finally {
			unlink( $cache . '/' . $configName );
			unlink( $root . '/outside.php' );
			unlink( $root . '/runner.php' );
			rmdir( $cache );
			rmdir( $root . '/cache' );
			rmdir( $root );
		}
	}

	/** @return array<string, array{string,string}> */
	public static function dropins(): array {
		return array(
			'page cache' => array( 'advanced-cache.php', 'config.json' ),
			'object cache' => array( 'object-cache.php', 'redis-config.json' ),
		);
	}
}
