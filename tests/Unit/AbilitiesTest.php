<?php
/**
 * Ability registration shape and the non-secret settings projection.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Abilities\AbilitiesModule;
use GTPerformance\Abilities\ReadAbilities;
use GTPerformance\Core\PublicSettings;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class AbilitiesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = Settings::defaults();
	}

	private function mode( string $mode ): void {
		$settings                   = Settings::defaults();
		$settings['agents']['mode'] = $mode;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;
	}

	public function test_abilities_are_hidden_from_mcp_and_rest_while_access_is_off(): void {
		$this->mode( 'off' );
		foreach ( AbilitiesModule::arguments() as $args ) {
			self::assertFalse( $args['meta']['mcp']['public'] );
			self::assertFalse( $args['meta']['show_in_rest'] );
		}

		$this->mode( 'read' );
		$operations = array( 'gt-performance/purge-urls', 'gt-performance/preload-urls', 'gt-performance/regenerate-css', 'gt-performance/retry-job', 'gt-performance/propose-settings' );
		foreach ( AbilitiesModule::arguments() as $name => $args ) {
			$operate = in_array( $name, $operations, true );
			self::assertStringStartsWith( 'gt-performance/', $name );
			self::assertSame( ! $operate, $args['meta']['mcp']['public'], $name . ': operations stay hidden in read mode.' );
			self::assertSame( ! $operate, $args['meta']['show_in_rest'], $name );
			self::assertSame( 'tool', $args['meta']['mcp']['type'] );
			self::assertSame( array( 'readonly' => ! $operate, 'destructive' => false, 'idempotent' => true ), $args['meta']['annotations'], $name );
			self::assertSame( array( \GTPerformance\Abilities\Permissions::class, $operate ? 'operate' : 'read' ), $args['permission_callback'], $name );
			self::assertSame( AbilitiesModule::CATEGORY, $args['category'] );
			self::assertIsCallable( $args['execute_callback'] );
			self::assertArrayNotHasKey( 'gtperf_mode', $args );
		}

		$this->mode( 'operate' );
		foreach ( AbilitiesModule::arguments() as $args ) {
			self::assertTrue( $args['meta']['mcp']['public'] );
		}
	}

	public function test_mutations_require_a_request_id_and_proposals_only_allowlisted_fields(): void {
		$definitions = \GTPerformance\Abilities\OperateAbilities::definitions();
		foreach ( array( 'purge-urls', 'preload-urls', 'regenerate-css', 'retry-job', 'propose-settings' ) as $name ) {
			self::assertContains( 'request_id', $definitions[ 'gt-performance/' . $name ]['input_schema']['required'], $name );
		}
		$changes = $definitions['gt-performance/propose-settings']['input_schema']['properties']['changes'];
		self::assertFalse( $changes['additionalProperties'] );
		self::assertSame( array( 0, 10, 25, 50, 100 ), $changes['properties']['css.rollout_percent']['enum'] );
		foreach ( array_keys( $changes['properties'] ) as $path ) {
			list( $section, $key ) = explode( '.', $path, 2 );
			self::assertArrayHasKey( $key, Settings::defaults()[ $section ], $path . ' exists in the live settings schema.' );
		}
		self::assertSame( 20, $definitions['gt-performance/purge-urls']['input_schema']['properties']['urls']['maxItems'] );
	}

	public function test_every_input_schema_is_a_closed_object_that_accepts_omitted_arguments(): void {
		foreach ( ReadAbilities::definitions() as $name => $args ) {
			$schema = $args['input_schema'];
			self::assertSame( 'object', $schema['type'], $name );
			self::assertFalse( $schema['additionalProperties'], $name );
			self::assertTrue( isset( $schema['required'] ) || array() === ( $schema['default'] ?? null ), $name );
			self::assertFalse( isset( $schema['properties'] ) && array() === $schema['properties'], $name . ' must omit empty properties.' );
			self::assertSame( 'object', $args['output_schema']['type'], $name );
		}
		$limit = ReadAbilities::definitions()['gt-performance/list-jobs']['input_schema']['properties']['limit'];
		self::assertSame( 100, $limit['maximum'] );
		self::assertSame( 20, $limit['default'] );
	}

	public function test_unknown_agent_mode_saves_as_off(): void {
		self::assertSame( 'off', Settings::sanitize( array( 'agents' => array( 'mode' => 'admin' ) ) )['agents']['mode'] );
		self::assertSame( 'operate', Settings::sanitize( array( 'agents' => array( 'mode' => 'operate' ) ) )['agents']['mode'] );
		self::assertSame( 'read', Settings::sanitize( array( 'agents' => array( 'mode' => 'read' ) ) )['agents']['mode'] );
	}

	public function test_settings_view_never_carries_credentials_or_identifiers(): void {
		$settings                               = Settings::defaults();
		$settings['cloudflare']['api_token']    = 'cf-token-value';
		$settings['cloudflare']['global_api_key'] = 'cf-global-value';
		$settings['cloudflare']['email']        = 'owner@example.com';
		$settings['xcloud']['api_token']        = 'xc-token-value';
		$settings['xcloud']['site_uuid']        = '11111111-2222-4333-8444-555555555555';
		$settings['redis']['password']          = 'redis-secret-value';
		$settings['redis']['username']          = 'redis-user';
		$settings['redis']['host']              = '10.0.0.5';
		$settings['cache']['future_api_token']  = 'leak-if-denylist-fails';
		$json = (string) wp_json_encode( PublicSettings::view( $settings ) );

		foreach ( array( 'cf-token-value', 'cf-global-value', 'owner@example.com', 'xc-token-value', '11111111-2222', 'redis-secret-value', 'redis-user', '10.0.0.5', 'leak-if-denylist-fails' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $json );
		}
		$view = PublicSettings::view( $settings );
		self::assertEqualsCanonicalizing( array( 'enabled', 'auth_mode', 'domain', 'zone_id', 'edge_ttl' ), array_keys( $view['cloudflare'] ) );
		self::assertArrayHasKey( 'preload_max_urls', $view['cache'] );
		self::assertSame( 'off', $view['agents']['mode'] );
	}

	public function test_settings_hash_follows_exposed_values_and_generation_only(): void {
		$settings = Settings::defaults();
		$hash     = PublicSettings::hash( PublicSettings::view( $settings ) );

		$secretChanged                            = $settings;
		$secretChanged['cloudflare']['api_token'] = 'different';
		self::assertSame( $hash, PublicSettings::hash( PublicSettings::view( $secretChanged ) ) );

		$saved               = $settings;
		$saved['generation'] = 2;
		self::assertNotSame( $hash, PublicSettings::hash( PublicSettings::view( $saved ) ) );

		$changed                               = $settings;
		$changed['cache']['preload_max_urls']  = 50;
		self::assertNotSame( $hash, PublicSettings::hash( PublicSettings::view( $changed ) ) );
	}
}
