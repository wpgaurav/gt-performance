<?php
/**
 * Page cache module tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\PageCacheModule;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PageCacheModuleTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_filters'] );
	}

	public function testPreviewCaptureRequiresARequestContext(): void {
		$module = new PageCacheModule( new Logger() );

		self::assertSame( '<html>original</html>', $module->capturePreview( '<html>original</html>' ) );
	}

	public function testPreviewCaptureOptimizesWithoutWritingToThePageCache(): void {
		$module  = new PageCacheModule( new Logger() );
		$request = RequestContext::fromUrl( 'https://example.com/?gtperf_css_preview=valid' );
		self::assertNotNull( $request );

		$property = new ReflectionProperty( $module, 'request' );
		$property->setValue( $module, $request );

		$GLOBALS['gtperf_test_filters']['gt_performance_html'][] = static function ( string $html, RequestContext $context ): string {
			return str_replace( '</body>', '<link data-gt-performance="used" href="/used.css"></body>', $html ) . $context->path;
		};

		$result = $module->capturePreview( '<html><body>preview</body></html>' );

		self::assertStringContainsString( 'data-gt-performance="used"', $result );
		self::assertStringEndsWith( '/', $result );
	}

	public function testPreviewCaptureFallsBackWhenThePipelineReturnsEmptyOutput(): void {
		$module  = new PageCacheModule( new Logger() );
		$request = RequestContext::fromUrl( 'https://example.com/?gtperf_css_preview=valid' );
		self::assertNotNull( $request );

		$property = new ReflectionProperty( $module, 'request' );
		$property->setValue( $module, $request );
		$GLOBALS['gtperf_test_filters']['gt_performance_html'][] = static fn(): string => '';

		self::assertSame( '<html>original</html>', $module->capturePreview( '<html>original</html>' ) );
	}

	/**
	 * @return list<string>
	 */
	private function purgeAllActions(): array {
		return array_values(
			array_filter(
				array_map( static fn( array $action ): string => (string) $action['hook'], $GLOBALS['gtperf_test_actions'] ?? array() ),
				static fn( string $hook ): bool => 'gt_performance_purged_all' === $hook
			)
		);
	}

	public function testCoreThemeAndPluginUpdatesPurgeTheCache(): void {
		$module = new PageCacheModule( new Logger() );

		foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
			$GLOBALS['gtperf_test_actions'] = array();
			$module->purgeAfterUpgrade( null, array( 'action' => 'update', 'type' => $type ) );

			self::assertCount( 1, $this->purgeAllActions(), $type );
		}
	}

	public function testTranslationUpdatesAndInstallsLeaveTheCacheAlone(): void {
		$module                          = new PageCacheModule( new Logger() );
		$GLOBALS['gtperf_test_actions'] = array();

		$module->purgeAfterUpgrade( null, array( 'action' => 'update', 'type' => 'translation' ) );
		$module->purgeAfterUpgrade( null, array( 'action' => 'install', 'type' => 'plugin' ) );
		$module->purgeAfterUpgrade( null, 'unexpected' );

		self::assertSame( array(), $this->purgeAllActions() );
	}

	public function testUpgradesAreHooked(): void {
		$GLOBALS['gtperf_test_registered_actions'] = array();
		$module                                    = new PageCacheModule( new Logger() );
		$module->register();

		self::assertContains( array( $module, 'purgeAfterUpgrade' ), $GLOBALS['gtperf_test_registered_actions']['upgrader_process_complete'] ?? array() );
	}
}
