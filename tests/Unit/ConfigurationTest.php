<?php
/**
 * Settings diff, portability, import validation, and history bounds.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Configuration\ConfigurationService;
use GTPerformance\Configuration\Diff;
use GTPerformance\Configuration\RevisionRepository;
use GTPerformance\Core\PublicSettings;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['gtperf_test_options'] = array( Settings::OPTION => Settings::defaults() );
	}

	public function test_diff_compares_leaf_settings_and_whole_lists(): void {
		$from = array(
			'generation' => 1,
			'cache'      => array( 'fresh_ttl' => 3600, 'preload' => true, 'bypass_paths' => array( '/a/', '/b/' ) ),
		);
		$to   = array(
			'generation' => 9,
			'cache'      => array( 'fresh_ttl' => 3600.0, 'preload' => false, 'bypass_paths' => array( '/a/' ), 'new' => 1 ),
		);

		self::assertSame(
			array(
				array( 'path' => 'cache.bypass_paths', 'from' => array( '/a/', '/b/' ), 'to' => array( '/a/' ) ),
				array( 'path' => 'cache.new', 'from' => null, 'to' => 1 ),
				array( 'path' => 'cache.preload', 'from' => true, 'to' => false ),
			),
			Diff::between( $from, $to )
		);
		self::assertTrue( Diff::ignored( 'cloudflare.zone_id', array( 'cloudflare.zone_id' ) ) );
		self::assertTrue( Diff::ignored( 'redis.port', array( 'redis' ) ) );
		self::assertFalse( Diff::ignored( 'redistribute.x', array( 'redis' ) ) );
	}

	public function test_portable_values_leave_out_identity_ownership_and_agent_access(): void {
		$portable = ConfigurationService::portable( PublicSettings::view() );

		foreach ( array( 'generation', 'agents', 'redis', 'xcloud' ) as $key ) {
			self::assertArrayNotHasKey( $key, $portable );
		}
		self::assertSame( array( 'edge_ttl' ), array_keys( $portable['cloudflare'] ) );
		self::assertArrayHasKey( 'fresh_ttl', $portable['cache'] );
	}

	public function test_import_rejects_foreign_formats_versions_unknown_protected_and_mistyped_keys(): void {
		$service = new ConfigurationService();
		$valid   = $service->export();

		self::assertIsArray( $service->parseImport( json_decode( (string) wp_json_encode( $valid ), true ) ) );
		self::assertSame( 'gtperf_import_format', $service->parseImport( 'not json' )->get_error_code() );
		self::assertSame( 'gtperf_import_format', $service->parseImport( array( 'format' => 'other', 'settings' => array() ) )->get_error_code() );
		self::assertSame( 'gtperf_import_schema', $service->parseImport( array( 'schema_version' => 2 ) + $valid )->get_error_code() );

		foreach ( array(
			array( 'cache' => array( 'unknown_key' => 1 ) ),
			array( 'cloudflare' => array( 'api_token' => 'x' ) ),
			array( 'cloudflare' => array( 'zone_id' => 'other-zone' ) ),
			array( 'agents' => array( 'mode' => 'read' ) ),
			array( 'generation' => 99 ),
			array( 'cache' => array( 'bypass_paths' => '/scalar/' ) ),
		) as $settings ) {
			$error = $service->parseImport( array( 'settings' => $settings ) + $valid );
			self::assertInstanceOf( \WP_Error::class, $error, (string) wp_json_encode( $settings ) );
			self::assertSame( 'gtperf_import_keys', $error->get_error_code() );
		}
	}

	public function test_export_carries_versions_and_hash_but_no_secrets(): void {
		$settings                            = Settings::defaults();
		$settings['cloudflare']['api_token'] = 'unit-secret-token';
		$settings['redis']['password']       = 'unit-redis-password';
		$settings['cloudflare']['email']     = 'owner@example.com';
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;

		$export = ( new ConfigurationService() )->export();
		$json   = (string) wp_json_encode( $export );

		self::assertSame( ConfigurationService::EXPORT_FORMAT, $export['format'] );
		self::assertSame( 1, $export['schema_version'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $export['settings_hash'] );
		foreach ( array( 'unit-secret-token', 'unit-redis-password', 'owner@example.com' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $json );
		}
	}

	public function test_a_setting_named_like_a_credential_but_holding_none_is_exported(): void {
		// The credential-name guard matched "password" in this toggle, so an
		// export, a read-only ability, and a proposal all silently lost it.
		$settings                                           = Settings::defaults();
		$settings['bloat']['disable_password_strength_meter'] = true;
		$settings['redis']['password']                      = 'unit-redis-password';
		$settings['redis']['username']                      = 'unit-redis-user';
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;

		$export = ( new ConfigurationService() )->export();

		self::assertTrue( $export['settings']['bloat']['disable_password_strength_meter'] );
		self::assertArrayNotHasKey( 'password', $export['settings']['redis'] ?? array() );
		self::assertStringNotContainsString( 'unit-redis', (string) wp_json_encode( $export ) );
	}

	public function test_save_changes_keeps_a_concurrent_edit_it_did_not_make(): void {
		$before = Settings::all();

		// Another administrator saves while this caller waits on a remote API.
		$other                          = $before;
		$other['cache']['entry_budget'] = 7777;
		self::assertTrue( Settings::save( $other ) );

		$after                             = $before;
		$after['cloudflare']['zone_id']    = 'zone-from-remote';
		$after['cloudflare']['drift_hash'] = 'abc';
		self::assertTrue( Settings::saveChanges( $before, $after ) );

		self::assertSame( 7777, Settings::get( 'cache.entry_budget' ) );
		self::assertSame( 'zone-from-remote', Settings::get( 'cloudflare.zone_id' ) );
	}

	public function test_history_records_real_changes_only_and_stays_bounded(): void {
		$repo   = new RevisionRepository();
		$before = Settings::defaults();

		$secretOnly                            = $before;
		$secretOnly['generation']              = 2;
		$secretOnly['cloudflare']['api_token'] = 'rotated';
		$repo->record( $before, $secretOnly );
		self::assertSame( array(), $repo->all(), 'A credential or generation change is not a settings revision.' );

		for ( $i = 1; $i <= 25; ++$i ) {
			$after                              = $before;
			$after['cache']['preload_max_urls'] = $i;
			$repo->record( $before, $after );
		}
		self::assertCount( RevisionRepository::MAX_REVISIONS, $repo->all() );
		self::assertSame( 200, $repo->all()[0]['settings']['cache']['preload_max_urls'], 'A revision holds the replaced values.' );

		$saved = $repo->all();
		for ( $i = 5; $i < count( $saved ); ++$i ) {
			$saved[ $i ]['at'] = time() - RevisionRepository::MAX_AGE - $i;
		}
		update_option( RevisionRepository::OPTION, $saved );
		$after                              = $before;
		$after['cache']['preload_max_urls'] = 7;
		$repo->record( $before, $after );
		self::assertCount( 6, $repo->all(), 'Revisions past 90 days are dropped.' );

		$large                     = $repo->all();
		$large[0]['settings']['cache']['bypass_paths'] = array_fill( 0, 30000, '/very-long-path-segment/' );
		update_option( RevisionRepository::OPTION, $large );
		$repo->record( $before, $after );
		self::assertLessThanOrEqual( RevisionRepository::MAX_BYTES, strlen( (string) wp_json_encode( $repo->all() ) ) );
		self::assertNotEmpty( $repo->all() );
	}
}
