<?php
/** Settings history, restore, import, and write exclusion against real WordPress options. */
declare(strict_types=1);
namespace GTPerformance\Tests\Integration;

use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Configuration\ConfigurationService;
use GTPerformance\Configuration\RevisionRepository;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase {
	private mixed $saved;

	protected function setUp(): void {
		$this->saved = get_option( Settings::OPTION );
		delete_option( RevisionRepository::OPTION );
		delete_option( Settings::CONFIG_ERROR );
		( new RevisionRepository() )->register();
	}

	protected function tearDown(): void {
		remove_all_actions( 'update_option_' . Settings::OPTION );
		update_option( Settings::OPTION, $this->saved );
		delete_option( RevisionRepository::OPTION );
		delete_option( Settings::CONFIG_ERROR );
	}

	private function saveWith( int $maxUrls, string $token ): void {
		$settings                              = Settings::all();
		$settings['cache']['preload_max_urls'] = $maxUrls;
		$settings['cache']['bypass_paths']     = array( '/wp-admin/', '/rev-' . $maxUrls . '/' );
		$settings['cloudflare']['api_token']   = ( new TokenCipher() )->encrypt( $token );
		self::assertTrue( Settings::save( $settings ) );
	}

	public function test_restore_brings_back_exact_prior_values_and_keeps_current_credentials(): void {
		$this->saveWith( 111, 'token-A' );
		$this->saveWith( 222, 'token-B' );
		$revisions = ( new RevisionRepository() )->all();
		self::assertSame( 111, $revisions[0]['settings']['cache']['preload_max_urls'] );
		self::assertStringNotContainsString( 'token-A', (string) wp_json_encode( $revisions ) );

		$service = new ConfigurationService();
		$result  = $service->restore( $revisions[0]['id'], $service->currentHash() );

		self::assertTrue( $result['applied'] );
		self::assertSame( 111, Settings::get( 'cache.preload_max_urls' ) );
		self::assertSame( array( '/wp-admin/', '/rev-111/' ), Settings::get( 'cache.bypass_paths' ) );
		self::assertSame( 'token-B', ( new TokenCipher() )->decrypt( (string) Settings::get( 'cloudflare.api_token' ) ) );
		self::assertSame( 'restore', ( new RevisionRepository() )->all()[0]['source'] );
		self::assertSame( 222, ( new RevisionRepository() )->all()[0]['settings']['cache']['preload_max_urls'], 'Restoring records what it replaced.' );
	}

	public function test_a_stale_preview_hash_changes_nothing(): void {
		$this->saveWith( 111, 'token-A' );
		$this->saveWith( 222, 'token-B' );
		$service = new ConfigurationService();
		$hash    = $service->currentHash();
		$id      = ( new RevisionRepository() )->all()[0]['id'];
		$this->saveWith( 333, 'token-B' );

		$result = $service->restore( $id, $hash );
		self::assertSame( 'gtperf_stale_settings', $result->get_error_code() );
		self::assertSame( 333, Settings::get( 'cache.preload_max_urls' ) );
	}

	public function test_failed_runtime_publication_leaves_settings_and_history_unchanged(): void {
		$this->saveWith( 111, 'token-A' );
		$this->saveWith( 222, 'token-B' );
		$service  = new ConfigurationService();
		$id       = ( new RevisionRepository() )->all()[0]['id'];
		$count    = count( ( new RevisionRepository() )->all() );
		$config   = Paths::config();
		$root     = Paths::cacheRoot();
		$previous = fileperms( $root ) & 0777;
		chmod( $root, 0555 );
		try {
			$result = $service->restore( $id, $service->currentHash() );
		} finally {
			chmod( $root, $previous );
			Settings::compile();
		}

		self::assertSame( 'gtperf_config_write', $result->get_error_code() );
		self::assertSame( 222, Settings::get( 'cache.preload_max_urls' ) );
		self::assertCount( $count, ( new RevisionRepository() )->all() );
		self::assertFileExists( $config );
	}

	public function test_import_applies_portable_values_and_refuses_foreign_keys(): void {
		$this->saveWith( 111, 'token-A' );
		$service = new ConfigurationService();
		$export  = $service->export();
		$export['settings']['cache']['preload_max_urls'] = 55;
		$portable = $service->parseImport( json_decode( (string) wp_json_encode( $export ), true ) );
		self::assertIsArray( $portable );
		$preview = $service->preview( $portable );
		self::assertSame( array( 'cache.preload_max_urls' ), array_column( $preview['changes'], 'path' ) );

		self::assertTrue( $service->apply( $portable, $preview['settings_hash'], 'import' )['applied'] );
		self::assertSame( 55, Settings::get( 'cache.preload_max_urls' ) );
		self::assertSame( 'token-A', ( new TokenCipher() )->decrypt( (string) Settings::get( 'cloudflare.api_token' ) ) );

		$export['settings']['cloudflare']['zone_id'] = 'someone-elses-zone';
		self::assertSame( 'gtperf_import_keys', $service->parseImport( $export )->get_error_code() );
	}

	public function test_a_writer_waits_for_the_lock_and_keeps_the_other_writers_change(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			self::markTestSkipped( 'proc_open is required.' );
		}
		$this->saveWith( 111, 'token-A' );
		$barrier = sys_get_temp_dir() . '/gtperf-settings-' . bin2hex( random_bytes( 6 ) );
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/settings-lock-holder.php' ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'a' ), 2 => array( 'file', $barrier . '.err', 'a' ) ), $pipes, null, array_merge( getenv(), array( 'GTPERF_BARRIER' => $barrier ) ) );
		try {
			$deadline = microtime( true ) + 10;
			while ( ! is_file( $barrier . '.held' ) && microtime( true ) < $deadline ) {
				usleep( 20000 );
			}
			self::assertFileExists( $barrier . '.held', (string) @file_get_contents( $barrier . '.err' ) );

			$started = microtime( true );
			$service = new ConfigurationService();
			$result  = $service->apply( array( 'cache' => array( 'preload_max_urls' => 99 ) ), '', 'import' );
			$waited  = microtime( true ) - $started;
			self::assertSame( 0, proc_close( $process ), (string) @file_get_contents( $barrier . '.err' ) );
			$process = null;

			self::assertGreaterThan( 0.5, $waited, 'The second writer waited for the first.' );
			self::assertTrue( $result['applied'] );
			wp_cache_delete( Settings::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			self::assertSame( 4321, Settings::get( 'cache.entry_budget' ), 'The first writer\'s change survived.' );
			self::assertSame( 99, Settings::get( 'cache.preload_max_urls' ) );
		} finally {
			if ( is_resource( $process ) ) {
				proc_terminate( $process );
				proc_close( $process );
			}
			foreach ( glob( $barrier . '.*' ) ?: array() as $path ) {
				unlink( $path );
			}
		}
	}
}
