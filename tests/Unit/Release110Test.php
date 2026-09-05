<?php
/**
 * Behaviour removed or corrected in 1.1.0.
 *
 * Each test names the defect it guards, so a later change that quietly restores
 * one fails here rather than in a support thread.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class Release110Test extends TestCase {
	/**
	 * The header's reason code claimed it was signed. Nothing ever computed or
	 * verified a signature, so any client could force a full uncached render on
	 * every request and bypass the cache entirely.
	 */
	public function test_the_unsigned_bypass_header_no_longer_disables_the_cache(): void {
		$request = new RequestContext(
			'GET',
			'https',
			'example.com',
			'/',
			array(),
			array(),
			array( 'x-gt-performance-bypass' => '1' ),
			''
		);

		$decision = ( new Eligibility() )->decide(
			$request,
			array( 'enabled' => true, 'ignored_query_params' => array() )
		);

		self::assertTrue( $decision->cacheable );
	}

	/**
	 * The drop-in must stop collecting the header too, or the two sides of the
	 * cache would disagree about what a request means.
	 */
	public function test_the_dropin_no_longer_collects_the_bypass_header(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Cache/DropinRuntime.php' );

		self::assertIsString( $source );
		self::assertStringNotContainsString( 'HTTP_X_GT_PERFORMANCE_BYPASS', $source );
	}

	/**
	 * The filter was registered unconditionally, so activating the plugin capped
	 * revisions at 5 on every site regardless of the database module's own setting,
	 * irreversibly discarding revision history on the next save.
	 */
	public function test_the_revision_limit_setting_and_filter_are_gone(): void {
		$defaults = Settings::defaults();

		self::assertArrayNotHasKey( 'limit_revisions', $defaults['bloat'] );

		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Database/DatabaseModule.php' );
		self::assertIsString( $source );
		self::assertStringNotContainsString( 'wp_revisions_to_keep', $source );
	}

	/**
	 * Six corruption defects sat behind this one checkbox, four of them silent and
	 * written straight into the cache. The engine stays reachable through a
	 * wp-config constant, but never through a settings screen.
	 */
	public function test_the_unused_css_toggle_is_gone(): void {
		$defaults = Settings::defaults();

		self::assertArrayNotHasKey( 'enabled', $defaults['css'] );
		self::assertArrayHasKey( 'mode', $defaults['css'], 'Delivery options survive for when the engine returns.' );
	}

	/**
	 * A saved setting bumps `generation`, which is part of the cache key, so every
	 * save orphans the entire store. Nothing collects orphans, so the purge has to
	 * happen at the moment the generation changes.
	 */
	public function test_saving_settings_always_bumps_the_generation(): void {
		$stored    = Settings::all();
		$sanitized = Settings::sanitize( Settings::defaults() );

		// The increment is relative to the stored generation, not the submitted one,
		// so every save produces a key nothing already on disk can match.
		self::assertSame(
			(int) $stored['generation'] + 1,
			(int) $sanitized['generation'],
			'AdminModule::afterSettingsUpdate() purges on this change; if it stops moving, the purge stops firing.'
		);
	}

	/**
	 * The purge is what stops a settings save from orphaning the entire store, so
	 * the condition that triggers it has to keep naming the generation.
	 */
	public function test_the_settings_update_handler_purges_on_a_generation_change(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/AdminModule.php' );

		self::assertIsString( $source );
		self::assertStringContainsString( "\$generationChanged", $source );
		self::assertMatchesRegularExpression(
			'/if \( \$generationChanged \|\|.*\) \{\s*\( new Purger\(\) \)->purgeAll\(\);/s',
			$source
		);
	}
}
