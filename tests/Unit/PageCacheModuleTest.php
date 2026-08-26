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
}
