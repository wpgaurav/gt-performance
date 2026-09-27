<?php
/**
 * Per-page "Don't cache this page" and "Use original CSS".
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\PageCacheModule;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\CacheInspector;
use GTPerformance\Optimization\Css\UnusedCssOptimizer;
use GTPerformance\Optimization\PageOverrides;
use PHPUnit\Framework\TestCase;

final class PageOptionsTest extends TestCase {
	private const PAGE = '<!doctype html><html><head></head><body>page</body></html>';

	protected function setUp(): void {
		$GLOBALS['gtperf_test_singular']  = 'page';
		$GLOBALS['gtperf_test_post_meta'] = array();
		$GLOBALS['gtperf_test_filters']['gt_performance_html'][] = static fn ( string $html ): string => $html . '<!-- optimized -->';
		http_response_code( 200 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_singular'], $GLOBALS['gtperf_test_post_meta'], $GLOBALS['gtperf_test_filters'], $GLOBALS['gtperf_test_url_posts'], $GLOBALS['gtperf_test_options'][ Settings::OPTION ] );
	}

	private function capture(): string {
		$module = new PageCacheModule( new Logger() );
		( new \ReflectionProperty( $module, 'request' ) )->setValue( $module, RequestContext::fromUrl( 'https://example.com/pricing/' ) );

		return $module->capture( self::PAGE );
	}

	public function test_an_opted_out_page_is_neither_optimized_nor_stored(): void {
		self::assertStringEndsWith( '<!-- optimized -->', $this->capture(), 'Control: an ordinary page goes through the pipeline and is stored.' );

		update_post_meta( 1, PageOverrides::CACHE_META, '1' );
		self::assertSame( self::PAGE, $this->capture() );
	}

	public function test_explain_reports_the_page_option(): void {
		$GLOBALS['gtperf_test_url_posts']['https://example.com/pricing/'] = 7;
		$inspector = new CacheInspector();
		self::assertTrue( $inspector->inspect( 'https://example.com/pricing/' )['cacheable'] );

		update_post_meta( 7, PageOverrides::CACHE_META, '1' );
		$report = $inspector->inspect( 'https://example.com/pricing/' );
		self::assertFalse( $report['cacheable'] );
		self::assertSame( 'page-option', $report['reason'] );
	}

	public function test_original_css_leaves_the_stylesheets_alone(): void {
		$settings                   = Settings::defaults();
		$settings['css']['enabled'] = true;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = $settings;
		$html     = '<!doctype html><html><head><link rel="stylesheet" href="https://example.com/style.css"></head><body></body></html>';
		$enqueued = static fn (): array => array_filter( $GLOBALS['gtperf_test_actions'] ?? array(), static fn ( array $action ): bool => 'gt_performance_enqueue_css' === $action['hook'] );

		$GLOBALS['gtperf_test_actions'] = array();
		( new UnusedCssOptimizer( new Logger() ) )->optimize( $html );
		self::assertNotEmpty( $enqueued(), 'Control: with the engine on, a page without a build queues one.' );

		$GLOBALS['gtperf_test_actions'] = array();
		$locks = &\gtperf_test_transients();
		$locks = array(); // The first run's build lock would hide a second request.
		update_post_meta( 1, PageOverrides::CSS_META, '1' );
		self::assertSame( $html, ( new UnusedCssOptimizer( new Logger() ) )->optimize( $html ) );
		self::assertEmpty( $enqueued(), 'A page on its original CSS never asks for a build.' );
	}
}
