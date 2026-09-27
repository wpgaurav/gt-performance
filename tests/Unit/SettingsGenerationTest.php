<?php
/**
 * Which settings saves retire the page cache.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Admin\AdminModule;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsGenerationTest extends TestCase {
	protected function setUp(): void {
		$stored               = Settings::defaults();
		$stored['generation'] = 4;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $stored;
		$GLOBALS['gtperf_test_actions']                     = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_options'][ Settings::OPTION ], $GLOBALS['gtperf_test_options']['gt_performance_remove_data_on_uninstall'] );
		$GLOBALS['gtperf_test_actions'] = array();
	}

	/**
	 * @param array<string, mixed> $changes Section => key => value.
	 * @return array<string, mixed>
	 */
	private function submit( array $changes ): array {
		$input = Settings::all();
		foreach ( $changes as $section => $values ) {
			$input[ $section ] = is_array( $values ) ? array_merge( $input[ $section ], $values ) : $values;
		}
		return Settings::sanitize( $input );
	}

	public function test_a_change_visitors_see_advances_the_generation_once(): void {
		foreach ( array(
			array( 'javascript' => array( 'defer' => true ) ),
			array( 'media' => array( 'youtube_previews' => true ) ),
			array( 'cache' => array( 'separate_mobile' => true ) ),
			array( 'cdn' => array( 'url' => 'https://cdn.example.com' ) ),
			array( 'bloat' => array( 'disable_emojis' => true ) ),
			array( 'cloudflare' => array( 'edge_ttl' => 600 ) ),
		) as $change ) {
			self::assertSame( 5, $this->submit( $change )['generation'], wp_json_encode( $change ) . ' changes cached pages.' );
		}
	}

	public function test_credentials_status_and_background_settings_keep_the_cache(): void {
		foreach ( array(
			array(),
			array( 'cloudflare' => array( 'api_token' => 'new-token', 'zone_id' => 'abc123' ) ),
			array( 'xcloud' => array( 'checked_at' => '2026-09-27 10:00:00', 'enterprise_requests' => 12 ) ),
			array( 'database' => array( 'schedule' => 'daily', 'retain_revisions' => 9 ) ),
			array( 'redis' => array( 'port' => 6380 ) ),
			array( 'cache' => array( 'preload_max_urls' => 500, 'entry_budget' => 9000 ) ),
			array( 'bloat' => array( 'heartbeat_seconds' => 90 ) ),
			array( 'agents' => array( 'mode' => 'read' ) ),
			array( 'remove_data_on_uninstall' => true ),
		) as $change ) {
			self::assertSame( 4, $this->submit( $change )['generation'], wp_json_encode( $change ) . ' does not change cached pages.' );
		}
	}

	public function test_the_save_handler_purges_exactly_when_the_generation_moves(): void {
		$admin = ( new \ReflectionClass( AdminModule::class ) )->newInstanceWithoutConstructor();
		$old   = Settings::all();

		$admin->afterSettingsUpdate( $old, $this->submit( array( 'database' => array( 'schedule' => 'daily' ) ) ) );
		self::assertNotContains( 'gt_performance_purged_all', array_column( $GLOBALS['gtperf_test_actions'], 'hook' ), 'A cleanup schedule must not purge the site or the Cloudflare zone.' );

		$admin->afterSettingsUpdate( $old, $this->submit( array( 'javascript' => array( 'delay' => true ) ) ) );
		self::assertContains( 'gt_performance_purged_all', array_column( $GLOBALS['gtperf_test_actions'], 'hook' ), 'A retired generation must be purged, or its pages stay on disk.' );
	}
}
