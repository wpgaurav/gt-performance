<?php
/**
 * Speculative loading exclusions and modes.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\Optimization\SpeculationPolicy;
use PHPUnit\Framework\TestCase;

final class SpeculationPolicyTest extends TestCase {
	public function test_bypass_paths_become_patterns_and_core_paths_are_left_to_core(): void {
		$patterns = SpeculationPolicy::patterns( array( '/cart/', 'checkout', '/my-account/', '/wp-admin/', '/wp-json/', '/', '/odd/*' ), true );

		self::assertSame( array( '/cart', '/cart/*', '/checkout', '/checkout/*', '/my-account', '/my-account/*' ), $patterns );
	}

	public function test_plain_permalinks_exclude_action_download_and_signed_parameters_only(): void {
		$patterns = SpeculationPolicy::patterns( array(), false );

		self::assertContains( '/*\\?*(^|&)add-to-cart=*', $patterns );
		self::assertContains( '/*\\?*(^|&)eddfile=*', $patterns );
		self::assertContains( '/*\\?*(^|&)signature=*', $patterns );
		self::assertNotContains( '/*\\?(.+)', $patterns, 'Content itself uses query strings under plain permalinks.' );
	}

	public function test_modes_leave_core_alone_pin_conservative_or_disable(): void {
		$core   = array( 'mode' => 'prerender', 'eagerness' => 'moderate' );
		$policy = new SpeculationPolicy();
		foreach ( array( 'core' => $core, 'conservative' => array( 'mode' => 'prefetch', 'eagerness' => 'conservative' ), 'off' => null ) as $mode => $expected ) {
			$settings                        = Settings::defaults();
			$settings['speculation']['mode'] = $mode;
			$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;
			self::assertSame( $expected, $policy->configuration( $core ), $mode );
		}
		self::assertNull( $policy->configuration( null ), 'A context core already disabled stays disabled.' );
		self::assertSame( 'core', Settings::sanitize( array( 'speculation' => array( 'mode' => 'eager' ) ) )['speculation']['mode'] );
	}
}
