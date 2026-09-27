<?php
/**
 * Uninstall behaviour.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Paths;
use PHPUnit\Framework\TestCase;

/**
 * uninstall.php runs as a script with WordPress loaded and the plugin not
 * loaded, so it is exercised here in a subprocess with the test stubs, rather
 * than included into this process where its globals would leak.
 */
final class UninstallTest extends TestCase {
	private string $root = '';

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/gtperf-uninstall-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/wp-content/cache/gt-performance/pages/ab', 0o777, true );
		mkdir( $this->root . '/wp-content/cache/gt-performance/logs', 0o777, true );
	}

	protected function tearDown(): void {
		if ( ! is_dir( $this->root ) ) {
			return;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $entries as $entry ) {
			( $entry->isLink() || ! $entry->isDir() ) ? @unlink( $entry->getPathname() ) : @rmdir( $entry->getPathname() );
		}
		@rmdir( $this->root );
	}

	private function seedCache(): string {
		$cache = $this->root . '/wp-content/cache/gt-performance';
		file_put_contents( $cache . '/config.json.php', "<?php exit; ?>\n{}" );
		file_put_contents( $cache . '/redis-config.json.php', "<?php exit; ?>\n{\"password\":\"s3cret\"}" );
		file_put_contents( $cache . '/index.html', '' );
		file_put_contents( $cache . '/pages/ab/abcd.html', '<html>' );
		file_put_contents( $cache . '/pages/ab/abcd.meta.json', '{}' );
		file_put_contents( $cache . '/logs/gt-performance.log', 'log' );

		return $cache;
	}

	private function runUninstall( bool $removeData ): string {
		$script = $this->root . '/runner.php';
		$body   = "<?php\n"
			. 'define( \'WP_UNINSTALL_PLUGIN\', true );' . "\n"
			. 'define( \'WP_CONTENT_DIR\', ' . var_export( $this->root . '/wp-content', true ) . " );\n"
			. 'define( \'ABSPATH\', ' . var_export( $this->root . '/', true ) . " );\n"
			. '$GLOBALS[\'gtperf_remove_data\'] = ' . var_export( $removeData, true ) . ";\n"
			. 'function get_option( $name, $default = false ) { return \'gt_performance_remove_data_on_uninstall\' === $name ? $GLOBALS[\'gtperf_remove_data\'] : $default; }' . "\n"
			. 'function delete_option( $name ) { return true; }' . "\n"
			. 'function delete_transient( $name ) { return true; }' . "\n"
			. 'function wp_unschedule_hook( $name ) { return 0; }' . "\n"
			. 'function wp_delete_file( $file ) { @unlink( $file ); }' . "\n"
			. 'class gtperf_Fake_Wpdb { public $prefix = \'wp_\'; public $options = \'wp_options\'; public function query( $q ) { return 1; } public function prepare( $q, ...$a ) { return $q; } public function esc_like( $t ) { return $t; } }' . "\n"
			. '$GLOBALS[\'wpdb\'] = new gtperf_Fake_Wpdb();' . "\n"
			. 'require ' . var_export( GTPERF_DIR . '/uninstall.php', true ) . ";\n"
			. "echo 'OK';\n";
		file_put_contents( $script, $body );

		return (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 ' . escapeshellarg( $script ) . ' 2>&1' );
	}

	public function test_opting_in_removes_the_cache_directory(): void {
		$cache  = $this->seedCache();
		$output = $this->runUninstall( true );

		self::assertStringNotContainsString( 'Fatal error', $output );
		self::assertStringEndsWith( 'OK', trim( $output ) );
		self::assertDirectoryDoesNotExist(
			$cache,
			'Opting in to data removal must not leave Redis credentials and cached content on disk.'
		);
	}

	public function test_opting_in_leaves_the_shared_cache_directory_for_other_plugins(): void {
		$this->seedCache();
		$this->runUninstall( true );

		self::assertDirectoryExists( $this->root . '/wp-content/cache' );
	}

	public function test_not_opting_in_keeps_everything(): void {
		$cache  = $this->seedCache();
		$output = $this->runUninstall( false );

		self::assertStringEndsWith( 'OK', trim( $output ) );
		self::assertFileExists( $cache . '/config.json.php' );
		self::assertFileExists( $cache . '/pages/ab/abcd.html' );
	}

	public function test_missing_cache_directory_is_not_an_error(): void {
		$output = $this->runUninstall( true );

		self::assertStringNotContainsString( 'Fatal error', $output );
		self::assertStringNotContainsString( 'Warning', $output );
		self::assertStringEndsWith( 'OK', trim( $output ) );
	}

	/** @dataProvider aliases */
	public function test_root_and_ancestor_symlinks_never_delete_foreign_files( string $part ): void {
		$foreign = $this->root . '/wp-content/plugins/foreign';
		mkdir( $foreign . '/gt-performance', 0777, true );
		file_put_contents( $foreign . '/keep.txt', 'keep' );
		file_put_contents( $foreign . '/gt-performance/keep.txt', 'keep' );
		$alias = $this->root . '/wp-content/' . $part;
		rename( $alias, $this->root . '/original-cache' );
		symlink( $foreign, $alias );
		self::assertStringEndsWith( 'OK', trim( $this->runUninstall( true ) ) );
		self::assertFileExists( $foreign . '/keep.txt' );
		self::assertFileExists( $foreign . '/gt-performance/keep.txt' );
	}
	public static function aliases(): array { return array( array( 'cache/gt-performance' ), array( 'cache' ) ); }

	public function test_descendant_links_are_removed_without_traversing_their_targets(): void {
		$foreign = $this->root . '/foreign';
		mkdir( $foreign );
		file_put_contents( $foreign . '/keep.txt', 'keep' );
		symlink( $foreign, $this->root . '/wp-content/cache/gt-performance/foreign' );
		self::assertStringEndsWith( 'OK', trim( $this->runUninstall( true ) ) );
		self::assertFileExists( $foreign . '/keep.txt' );
		self::assertDirectoryDoesNotExist( $this->root . '/wp-content/cache/gt-performance' );
	}

	/**
	 * The current cache root must stay the one uninstall targets. A rename that
	 * missed this file would silently leave every install's data behind.
	 */
	public function test_uninstall_targets_the_path_the_plugin_actually_uses(): void {
		$source = (string) file_get_contents( GTPERF_DIR . '/uninstall.php' );

		self::assertStringContainsString( "/cache/gt-performance", $source );
		self::assertStringEndsWith( '/cache/gt-performance', Paths::cacheRoot() );
	}
}
