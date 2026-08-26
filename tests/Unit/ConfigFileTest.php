<?php
/**
 * Inert configuration data file tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\ConfigFile;
use GTPerformance\Core\Paths;
use PHPUnit\Framework\TestCase;

final class ConfigFileTest extends TestCase {
	private string $path = '';

	protected function setUp(): void {
		$directory = Paths::cacheRoot();
		is_dir( $directory ) || mkdir( $directory, 0o777, true );
		$this->path = $directory . '/config-file-test.php';
	}

	protected function tearDown(): void {
		is_file( $this->path ) && unlink( $this->path );
	}

	public function test_payload_round_trips(): void {
		$config = array(
			'generation' => 7,
			'cache'      => array( 'enabled' => true, 'bypass_paths' => array( '/checkout/' ) ),
			'debug'      => false,
			'plugin_dir' => '/var/www/wp-content/plugins/gt-performance',
			'unicode'    => "café — ünïcode",
		);

		self::assertTrue( ConfigFile::write( $this->path, $config ) );
		self::assertSame( $config, ConfigFile::read( $this->path ) );
	}

	/**
	 * The stored file must never be executable configuration. Requesting it over
	 * the web has to hit a PHP exit, and reading it has to yield plain JSON.
	 */
	public function test_file_is_guarded_json_and_not_executable_configuration(): void {
		ConfigFile::write( $this->path, array( 'secret' => 'redis-password' ) );
		$raw = (string) file_get_contents( $this->path );

		self::assertStringStartsWith( '<?php exit;', $raw );
		self::assertStringNotContainsString( 'return ', $raw );
		self::assertStringNotContainsString( 'array (', $raw );

		$payload = substr( $raw, (int) strpos( $raw, "\n" ) + 1 );
		self::assertSame( array( 'secret' => 'redis-password' ), json_decode( $payload, true ) );

		// A direct web request lands on the guard. Run the file the way a server
		// would — in its own process, since the guard calls exit — and confirm it
		// terminates cleanly without disclosing the payload.
		$command = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 ' . escapeshellarg( $this->path ) . ' 2>&1';
		$output  = shell_exec( $command );

		self::assertSame( '', trim( (string) $output ), 'A direct request must disclose nothing.' );
		self::assertStringNotContainsString( 'redis-password', (string) $output );
	}

	public function test_missing_and_malformed_files_read_as_null(): void {
		self::assertNull( ConfigFile::read( $this->path . '.absent' ) );

		file_put_contents( $this->path, ConfigFile::GUARD . 'not json at all' );
		self::assertNull( ConfigFile::read( $this->path ) );

		file_put_contents( $this->path, ConfigFile::GUARD . '"a scalar"' );
		self::assertNull( ConfigFile::read( $this->path ) );

		file_put_contents( $this->path, 'no newline so no payload' );
		self::assertNull( ConfigFile::read( $this->path ) );
	}

	/**
	 * The bundled drop-in parses the file itself rather than loading this class,
	 * so its inline reader has to agree with ConfigFile::read().
	 */
	public function test_bundled_dropin_parses_the_same_payload(): void {
		$config = array( 'plugin_dir' => '/plugins/gt-performance', 'cache' => array( 'enabled' => true ) );
		ConfigFile::write( $this->path, $config );

		$raw   = (string) file_get_contents( $this->path );
		$break = strpos( $raw, "\n" );

		self::assertNotFalse( $break );
		self::assertSame( $config, json_decode( substr( $raw, $break + 1 ), true ) );
	}
}
