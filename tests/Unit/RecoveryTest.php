<?php
/**
 * A way back from anything the plugin does.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class RecoveryTest extends TestCase {
	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	/**
	 * Safe mode must stop every transformation, not merely the optimizers, or the
	 * page an administrator inspects is not the page WordPress would render.
	 */
	public function test_safe_mode_disables_every_transformation_and_the_cache(): void {
		self::assertStringContainsString( 'SafeMode::active()', $this->source( 'src/Optimization/OptimizationModule.php' ) );
		self::assertStringContainsString( 'SafeMode::active()', $this->source( 'src/CDN/CdnModule.php' ) );

		$cache = $this->source( 'src/Cache/PageCacheModule.php' );
		self::assertStringContainsString( 'SafeMode::active()', $cache );
		self::assertStringContainsString( 'X-GT-Cache: SAFE-MODE', $cache );
		self::assertStringContainsString( 'SharedCacheHeaders::noStore()', $cache );
	}

	/**
	 * A wp-config constant survives a broken admin screen, which is the situation
	 * safe mode exists for.
	 */
	public function test_safe_mode_has_a_switch_that_does_not_need_the_admin(): void {
		self::assertStringContainsString( "defined( 'GTPERF_SAFE_MODE' )", $this->source( 'src/Core/SafeMode.php' ) );
	}

	/**
	 * The request-scoped switch must be useless to anyone who is not already an
	 * administrator, and must not be triggerable by a link someone else sends.
	 */
	public function test_the_request_switch_is_capability_and_nonce_checked(): void {
		$source = $this->source( 'src/Core/SafeMode.php' );

		self::assertStringContainsString( "current_user_can( 'manage_options' )", $source );
		self::assertStringContainsString( 'wp_verify_nonce(', $source );
	}

	/**
	 * The parameter belongs in the bypass list, not the ignore list: a safe-mode
	 * request must not be served from cache and must not be stored. Being a declared
	 * bypass parameter also stops it registering as an unknown one.
	 */
	public function test_the_safe_mode_parameter_bypasses_rather_than_splits_the_cache(): void {
		$cache = Settings::defaults()['cache'];

		self::assertContains( 'gtperf_safe_mode', $cache['bypass_query_params'] );
		self::assertNotContains( 'gtperf_safe_mode', $cache['ignored_query_params'] );
	}

	/**
	 * uninstall.php gates on an option that no code path wrote, so deleting the
	 * plugin left 16 options, four tables, both drop-ins and a plaintext Redis
	 * credentials file on disk, whatever the user chose.
	 */
	public function test_the_uninstall_gate_is_actually_written(): void {
		self::assertArrayHasKey( 'remove_data_on_uninstall', Settings::defaults() );
		self::assertFalse( Settings::defaults()['remove_data_on_uninstall'], 'Destructive by default would be wrong.' );

		$gate = 'gt_performance_remove_data_on_uninstall';
		self::assertStringContainsString( $gate, $this->source( 'uninstall.php' ) );
		self::assertStringContainsString(
			"update_option(\n\t\t\t'" . $gate . "'",
			$this->source( 'src/Admin/AdminModule.php' ),
			'The option uninstall.php reads must be written when settings are saved.'
		);
	}
}
