<?php
/**
 * Advanced-cache drop-in installation tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\ConfigFile;
use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class DropinInstallerTest extends TestCase {
	private DropinInstaller $installer;

	protected function setUp(): void {
		$this->installer                = new DropinInstaller();
		$GLOBALS['gtperf_test_options'] = array();

		// install() also enables WP_CACHE, which needs a writable wp-config.php.
		is_dir( ABSPATH ) || mkdir( ABSPATH, 0o777, true );
		file_put_contents( ABSPATH . 'wp-config.php', "<?php\n/* Test config. */\n" );

		$target = $this->installer->target();
		is_file( $target ) && unlink( $target );
	}

	protected function tearDown(): void {
		$target = $this->installer->target();
		is_file( $target ) && unlink( $target );
		is_file( ABSPATH . 'wp-config.php' ) && unlink( ABSPATH . 'wp-config.php' );
	}

	/**
	 * The published drop-in must be the bundled file, byte for byte, apart from
	 * the version stamped into its signature. Anything else means the installer
	 * has started generating PHP source again.
	 */
	public function test_published_dropin_is_the_bundled_file_with_only_a_version_stamp(): void {
		self::assertTrue( $this->installer->install() );

		$published = (string) file_get_contents( $this->installer->target() );
		$bundled   = (string) file_get_contents( GTPERF_DIR . '/dropins/advanced-cache.php' );

		self::assertSame(
			$bundled,
			str_replace( ' v' . GTPERF_VERSION, '', $published ),
			'The drop-in must be copied, not generated.'
		);
		self::assertSame( GTPERF_VERSION, $this->installer->installedVersion() );
		self::assertSame( 'owned', $this->installer->status() );
	}

	public function test_published_dropin_contains_no_generated_paths(): void {
		$this->installer->install();
		$published = (string) file_get_contents( $this->installer->target() );

		self::assertStringNotContainsString( GTPERF_DIR, $published );
		self::assertStringNotContainsString( Paths::pages(), $published );
		self::assertStringNotContainsString( 'var_export', $published );
	}

	/**
	 * The drop-in resolves the plugin directory from the compiled configuration,
	 * so installing has to leave that configuration in place or the drop-in
	 * silently loads nothing on the very next request.
	 */
	public function test_install_leaves_a_configuration_the_dropin_can_resolve(): void {
		$this->installer->install();

		$config = ConfigFile::read( Paths::config() );

		self::assertIsArray( $config );
		self::assertSame( GTPERF_DIR, $config['plugin_dir'] ?? null );
		self::assertIsArray( $config['cache'] ?? null );

		foreach (
			array(
				'/src/Cache/ConfigFile.php',
				'/src/Cache/Decision.php',
				'/src/Cache/RequestContext.php',
				'/src/Cache/Eligibility.php',
				'/src/Cache/CacheKey.php',
				'/src/Cache/DropinRuntime.php',
			) as $relative
		) {
			self::assertFileIsReadable(
				$config['plugin_dir'] . $relative,
				'The drop-in requires this file before it will serve anything.'
			);
		}
	}

	/**
	 * A 1.0.0 drop-in reads `config.php` with `require`. If the new guarded file
	 * were written to that same path, any ordering that left the old drop-in in
	 * place would execute its exit guard and blank every front-end response.
	 * Compiling must therefore write elsewhere and clear the old path.
	 */
	public function test_compiling_leaves_nothing_a_pre_1_0_1_dropin_would_execute(): void {
		foreach ( Paths::legacyConfigFiles() as $legacy ) {
			is_dir( dirname( $legacy ) ) || mkdir( dirname( $legacy ), 0o777, true );
			file_put_contents( $legacy, "<?php\nreturn array( 'cache' => array() );\n" );
		}

		Settings::compile();

		foreach ( Paths::legacyConfigFiles() as $legacy ) {
			self::assertFileDoesNotExist( $legacy, 'A legacy drop-in must find no configuration at all.' );
		}

		self::assertStringEndsWith( '.json.php', Paths::config() );
		self::assertFileExists( Paths::config() );
	}

	public function test_foreign_dropin_is_never_overwritten(): void {
		$foreign = "<?php\n/** Another cache plugin */\n";
		file_put_contents( $this->installer->target(), $foreign );

		$result = $this->installer->install();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $foreign, file_get_contents( $this->installer->target() ) );
	}
}
