<?php
/**
 * Optimize-only mode: optimize what a host's page cache will store, store nothing.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\ConfigFile;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\PageCacheModule;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\HealthReport;
use GTPerformance\Optimization\PageOverrides;
use PHPUnit\Framework\TestCase;

final class OptimizeOnlyTest extends TestCase {
	private const PAGE = '<!doctype html><html><head></head><body>page</body></html>';

	/** @var array<string, mixed> */
	private array $server = array();

	protected function setUp(): void {
		$this->server                    = $_SERVER;
		$settings                        = Settings::defaults();
		$settings['cache']['mode']       = 'optimize';
		$GLOBALS['gtperf_test_options']  = array( Settings::OPTION => $settings );
		$GLOBALS['gtperf_test_actions']  = array();
		$GLOBALS['gtperf_test_post_meta'] = array();
		$GLOBALS['gtperf_test_filters']['gt_performance_html'][] = static fn ( string $html ): string => $html . '<!-- optimized -->';
		( new FileStore() )->purgeAll();
		http_response_code( 200 );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		( new FileStore() )->purgeAll();
		unset( $GLOBALS['gtperf_test_options'], $GLOBALS['gtperf_test_filters'], $GLOBALS['gtperf_test_post_meta'], $GLOBALS['gtperf_test_singular'] );
		$GLOBALS['gtperf_test_actions'] = array();
	}

	/**
	 * Run startCapture() for a request and report whether it buffered the response.
	 */
	private function buffers( string $uri ): bool {
		$_SERVER = array(
			'REQUEST_METHOD' => 'GET',
			'HTTP_HOST'      => 'example.com',
			'REQUEST_URI'    => $uri,
		);
		$level  = ob_get_level();
		$module = new PageCacheModule( new Logger() );
		$module->startCapture();
		$buffered = ob_get_level() > $level;
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}

		return $buffered;
	}

	public function test_the_setting_is_normalized(): void {
		self::assertSame( 'store', Settings::defaults()['cache']['mode'] );
		self::assertSame( 'store', Settings::sanitize( array( 'cache' => array( 'mode' => 'serve-everything' ) ) )['cache']['mode'] );
		self::assertTrue( Settings::optimizeOnly() );
	}

	public function test_eligible_pages_are_captured_without_the_drop_in_and_bypassed_pages_are_not(): void {
		self::assertTrue( $this->buffers( '/about/' ), 'No drop-in is installed, and optimize-only mode needs none.' );
		self::assertFalse( $this->buffers( '/?s=shoes' ), 'A request the cache would bypass pays for no buffer at all.' );
		self::assertFalse( $this->buffers( '/wp-login.php' ) );

		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cache']['mode'] = 'store';
		self::assertFalse( $this->buffers( '/about/' ), 'Store mode still waits for the drop-in before doing any work.' );
	}

	public function test_a_captured_page_is_optimized_and_nothing_is_stored(): void {
		$module = new PageCacheModule( new Logger() );
		( new \ReflectionProperty( $module, 'request' ) )->setValue( $module, \GTPerformance\Cache\RequestContext::fromUrl( 'https://example.com/about/' ) );

		self::assertSame( self::PAGE . '<!-- optimized -->', $module->captureOptimizeOnly( self::PAGE ) );
		self::assertNotContains( 'gt_performance_cache_stored', array_column( $GLOBALS['gtperf_test_actions'], 'hook' ) );
		self::assertSame( array(), glob( Paths::pages() . '/*/*.html' ) ?: array() );

		self::assertSame( '{"not":"html"}', $module->captureOptimizeOnly( '{"not":"html"}' ), 'A response that fails the stored-page checks is not transformed.' );

		$GLOBALS['gtperf_test_singular'] = 'page';
		update_post_meta( 1, PageOverrides::CACHE_META, '1' );
		self::assertSame( self::PAGE, $module->captureOptimizeOnly( self::PAGE ), 'A page that opted out of caching is left alone too.' );
	}

	public function test_the_drop_in_never_serves_in_optimize_only_mode(): void {
		self::assertTrue( Settings::compile() );
		$compiled = ConfigFile::read( Paths::config() );

		self::assertIsArray( $compiled );
		self::assertFalse( $compiled['cache']['enabled'], 'An entry left over from store mode must not be served.' );
	}

	public function test_warming_is_not_queued(): void {
		self::assertSame( 0, ( new CacheWarmer( new Logger() ) )->queue() );
	}

	public function test_health_does_not_ask_for_the_drop_in(): void {
		$reflection = new \ReflectionMethod( HealthReport::class, 'evaluate' );
		$evidence   = array(
			'queue'            => array( 'ready' => true, 'paused' => false, 'pending' => 0, 'running' => 0, 'failed' => 0, 'oldest_due_age' => 0 ),
			'heartbeat'        => time(),
			'cron'             => array( 'status' => 'pass', 'value' => '' ),
			'storage_writable' => true,
			'cache_enabled'    => true,
			'optimize_only'    => true,
			'page_dropin'      => 'missing',
			'wp_cache'         => 'disabled',
			'redis_enabled'    => false,
			'redis_dropin'     => 'missing',
			'edge_conflict'    => false,
			'config_error'     => false,
			'purges'           => array(),
			'css_enabled'      => false,
			'css'              => array(),
			'warm'             => null,
		);
		$checks = array_column( $reflection->invoke( null, $evidence, time() ), null, 'id' );

		self::assertSame( 'info', $checks['page_dropin']['status'] );
		self::assertStringContainsString( 'Optimize-only mode', $checks['page_dropin']['value'] );
	}
}
