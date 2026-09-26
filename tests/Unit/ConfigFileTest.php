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
		$this->path = $directory . '/config-file-test.json';
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

	public function test_file_contains_only_encrypted_json(): void {
		ConfigFile::write( $this->path, array( 'secret' => 'redis-password', 'php' => '<?php exit;' ) );
		$raw = (string) file_get_contents( $this->path );
		self::assertIsArray( json_decode( $raw, true ) );
		self::assertStringNotContainsString( '<?', $raw );
		self::assertStringNotContainsString( 'redis-password', $raw );
		$envelope = json_decode( $raw, true );
		$bytes = base64_decode( $envelope['data'] );
		$bytes[28] = chr( ord( $bytes[28] ) ^ 1 );
		$envelope['data'] = base64_encode( $bytes );
		self::assertNull( ConfigFile::decode( json_encode( $envelope ) ) );
		$envelope['data'] = base64_encode( substr( $bytes, 0, 20 ) );
		self::assertNull( ConfigFile::decode( json_encode( $envelope ) ) );
	}

	public function test_missing_and_malformed_files_read_as_null(): void {
		self::assertNull( ConfigFile::read( $this->path . '.absent' ) );

		file_put_contents( $this->path, 'not json at all' );
		self::assertNull( ConfigFile::read( $this->path ) );

		file_put_contents( $this->path, '"a scalar"' );
		self::assertNull( ConfigFile::read( $this->path ) );

		file_put_contents( $this->path, 'no newline so no payload' );
		self::assertNull( ConfigFile::read( $this->path ) );
	}

	public function test_temporary_files_are_protected_before_credentials_are_written(): void {
		$report = $this->writeScenario( 'success' );
		self::assertTrue( $report['result'] );
		$before = $report['events']['before_write'];
		self::assertStringEndsWith( '.json', $before['name'] );
		self::assertSame( 0600, $before['permissions'] );
		self::assertSame( 0, $before['bytes'] );
		self::assertStringNotContainsString( '<?', $report['events']['before_rename']['payload'] );
		self::assertStringNotContainsString( 'fixture-secret', $report['events']['before_rename']['payload'] );
		self::assertSame( array( 'password' => 'fixture-secret' ), ConfigFile::read( $this->path ) );
		self::assertSame( array(), glob( dirname( $this->path ) . '/gtperf-config-*.json' ) );
	}

	public function test_failed_writes_preserve_the_previous_configuration_and_clean_up(): void {
		$original = '{"password":"original"}';
		foreach ( array( 'permissions-fail', 'short-write', 'rename-fail' ) as $mode ) {
			file_put_contents( $this->path, $original );
			$report = $this->writeScenario( $mode );
			self::assertFalse( $report['result'], $mode );
			self::assertSame( $original, file_get_contents( $this->path ), $mode );
			self::assertSame( array(), glob( dirname( $this->path ) . '/gtperf-config-*.json' ), $mode );
			if ( 'permissions-fail' === $mode ) {
				self::assertArrayNotHasKey( 'before_write', $report['events'] );
			}
		}
	}

	public function test_compilation_removes_legacy_php_files(): void {
		$legacy = Paths::cacheRoot() . '/config.json.php';
		file_put_contents( $legacy, '<?php exit; ?>' );
		self::assertTrue( \GTPerformance\Core\Settings::compile() );
		self::assertFileDoesNotExist( $legacy );
		self::assertNotNull( ConfigFile::read( Paths::config() ) );
		self::assertNotNull( ConfigFile::read( Paths::redisConfig() ) );
	}

	private function writeScenario( string $mode ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' '
			. escapeshellarg( dirname( __DIR__ ) . '/Fixtures/config-file-write.php' ) . ' '
			. escapeshellarg( $mode ) . ' ' . escapeshellarg( $this->path );
		return json_decode( (string) shell_exec( $command ), true, 512, JSON_THROW_ON_ERROR );
	}

}
