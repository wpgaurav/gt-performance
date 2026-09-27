<?php
/**
 * The autosave interval setting reaches WordPress.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\Database\DatabaseModule;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AutosaveIntervalTest extends TestCase {
	private function useInterval( int $seconds ): void {
		$settings                               = Settings::defaults();
		$settings['bloat']['autosave_interval'] = $seconds;
		$GLOBALS['gtperf_test_options']         = array( Settings::OPTION => $settings );
	}

	#[RunInSeparateProcess]
	public function test_registering_the_module_defines_the_interval_before_wordpress_does(): void {
		// WordPress defines AUTOSAVE_INTERVAL right after plugins_loaded, and the
		// module registers during plugins_loaded. Defining it later, on init, meant
		// the setting never took effect.
		$this->useInterval( 300 );

		( new DatabaseModule() )->register();

		self::assertTrue( defined( 'AUTOSAVE_INTERVAL' ) );
		self::assertSame( 300, constant( 'AUTOSAVE_INTERVAL' ) );
	}

	#[RunInSeparateProcess]
	public function test_the_default_is_left_to_wordpress(): void {
		$this->useInterval( 60 );

		( new DatabaseModule() )->register();

		self::assertFalse( defined( 'AUTOSAVE_INTERVAL' ) );
	}

	#[RunInSeparateProcess]
	public function test_a_value_in_wp_config_wins(): void {
		define( 'AUTOSAVE_INTERVAL', 120 );
		$this->useInterval( 300 );

		( new DatabaseModule() )->register();

		self::assertSame( 120, constant( 'AUTOSAVE_INTERVAL' ) );
	}
}
