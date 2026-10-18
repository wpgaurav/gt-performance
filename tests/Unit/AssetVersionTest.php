<?php
/**
 * The core-version rewrite tolerates asset filters called without a URL.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\Database\DatabaseModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssetVersionTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_options'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function nonStringSources(): array {
		return array(
			'null'  => array( null ),
			'false' => array( false ),
		);
	}

	#[DataProvider( 'nonStringSources' )]
	public function test_a_source_that_is_not_a_url_passes_through_unchanged( mixed $src ): void {
		// Query Monitor applies script_loader_src and style_loader_src to
		// dependency-only handles, whose src is false or null. A string-typed
		// callback threw a TypeError on null during shutdown.
		$settings                             = Settings::defaults();
		$settings['bloat']['hide_wp_version'] = true;
		$GLOBALS['gtperf_test_options']       = array( Settings::OPTION => $settings );

		self::assertSame( $src, ( new DatabaseModule() )->assetVersion( $src ) );
	}
}
