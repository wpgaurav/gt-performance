<?php
/**
 * CSS operations must report real work and preserve page-specific selector inputs.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Optimization\Css\Maintenance;
use GTPerformance\Optimization\Css\ReportRepository;
use GTPerformance\Optimization\Css\UnusedCssOptimizer;
use PHPUnit\Framework\TestCase;

final class CssMaintenanceTest extends TestCase {
	private mixed $database;

	protected function setUp(): void {
		$this->database = $GLOBALS['wpdb'];
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = Settings::defaults();
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['css']['enabled'] = true;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['css']['mode'] = 'inline';
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['cache']['bypass_paths'][] = '/cart/';
		$store = &gtperf_test_transients();
		$store = array();
		$_SERVER['REQUEST_URI'] = '/css-test/';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->database;
		unset( $GLOBALS['gtperf_test_options'][ Settings::OPTION ], $GLOBALS['gtperf_test_options']['gtperf_css_revision'], $GLOBALS['gtperf_test_http_response'], $_SERVER['REQUEST_URI'], $_GET[ UnusedCssOptimizer::GENERATOR_PARAM ] );
	}

	public function test_only_eligible_same_origin_public_urls_can_be_regenerated(): void {
		self::assertTrue( Maintenance::eligible( 'https://example.com/article/' ) );
		foreach ( array( 'https://foreign.test/', 'https://example.com:8443/', 'https://user:pass@example.com/', 'http://example.com/', 'https://example.com/cart/', 'https://example.com/?gtperf_css_build=secret', 'https://example.com/?preview=true' ) as $url ) {
			self::assertFalse( Maintenance::eligible( $url ), $url );
		}
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ]['css']['rollout_percent'] = 0;
		self::assertFalse( Maintenance::enabled() );
	}

	public function test_signed_builds_preserve_commerce_and_query_protections(): void {
		$engine = new UnusedCssOptimizer( new Logger() );
		$token = ( new \ReflectionMethod( $engine, 'generatorToken' ) )->invoke( null );
		$_GET[ UnusedCssOptimizer::GENERATOR_PARAM ] = $token;
		$config = Settings::get( 'cache' );
		$eligibility = new \GTPerformance\Cache\Eligibility();
		$request = \GTPerformance\Cache\RequestContext::fromUrl( 'https://example.com/?gtperf_css_build=' . $token );
		self::assertFalse( $eligibility->decide( $request, $config )->cacheable );
		self::assertTrue( $eligibility->decide( UnusedCssOptimizer::publicRequest( $request ), $config )->cacheable );
		foreach ( array( 'https://example.com/cart/?gtperf_css_build=' . $token, 'https://example.com/?gtperf_css_build=' . $token . '&preview=true' ) as $url ) {
			$protected = \GTPerformance\Cache\RequestContext::fromUrl( $url );
			self::assertFalse( $eligibility->decide( UnusedCssOptimizer::publicRequest( $protected ), $config )->cacheable );
		}
		$private = \GTPerformance\Cache\RequestContext::fromUrl( 'https://example.com/?gtperf_css_build=' . $token, array( 'wordpress_logged_in_123' => 'session' ) );
		self::assertFalse( $eligibility->decide( UnusedCssOptimizer::publicRequest( $private ), $config )->cacheable );
		$_GET[ UnusedCssOptimizer::GENERATOR_PARAM ] = 'invalid';
		self::assertSame( $request, UnusedCssOptimizer::publicRequest( $request ) );
	}

	public function test_url_invalidation_changes_the_reuse_key_even_without_a_report(): void {
		$engine = new UnusedCssOptimizer( new Logger() );
		$key = new \ReflectionMethod( $engine, 'reuseKey' );
		$before = $key->invoke( $engine, '<html>same</html>', 'inline' );
		( new ReportRepository() )->invalidateUrl( 'https://example.com/css-test/' );
		self::assertNotSame( $before, $key->invoke( $engine, '<html>same</html>', 'inline' ) );
	}

	public function test_failed_queue_inserts_do_not_create_queued_reports(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 0;
			public array $tables = array();
			public function prepare( string $sql, mixed ...$args ): string { return $sql; }
			public function get_var( string $sql ): mixed { return null; }
			public function insert( string $table, array $values, array $formats ): bool {
				$this->tables[] = $table;
				return false;
			}
		};
		self::assertFalse( ( new Maintenance() )->enqueue( 'https://example.com/css-test/' ) );
		self::assertSame( array( 'wp_gtperf_jobs' ), $GLOBALS['wpdb']->tables );
	}

	public function test_generator_token_does_not_split_report_identity_or_reuse(): void {
		$engine = new UnusedCssOptimizer( new Logger() );
		$token = ( new \ReflectionMethod( $engine, 'generatorToken' ) )->invoke( null );
		$_GET[ UnusedCssOptimizer::GENERATOR_PARAM ] = $token;
		$_SERVER['REQUEST_URI'] = '/css-test/?gtperf_css_build=' . $token;
		$html = '<html><head><style>.present{color:red}.unused{color:blue}</style></head><body><p class="present">Test</p></body></html>';
		$generated = $engine->optimize( $html );
		unset( $_GET[ UnusedCssOptimizer::GENERATOR_PARAM ] );
		$_SERVER['REQUEST_URI'] = '/css-test/';
		self::assertSame( $generated, $engine->optimize( $html ) );
		self::assertStringNotContainsString( '.unused', $generated );
	}

	public function test_reuse_changes_with_ids_attributes_and_dom_relationships(): void {
		$engine = new UnusedCssOptimizer( new Logger() );
		$key = new \ReflectionMethod( $engine, 'reuseKey' );
		$a = $key->invoke( $engine, '<div id="a"><p data-state="open">Text</p></div>', 'inline' );
		foreach ( array( '<div id="b"><p data-state="open">Text</p></div>', '<div id="a"><p data-state="closed">Text</p></div>', '<div id="a"></div><p data-state="open">Text</p>' ) as $html ) {
			self::assertNotSame( $a, $key->invoke( $engine, $html, 'inline' ) );
		}
	}

	public function test_force_build_does_not_reuse_existing_output(): void {
		$engine = new UnusedCssOptimizer( new Logger() );
		$html = '<html><head><style>.present{color:red}</style></head><body><p class="present">Test</p></body></html>';
		$key = ( new \ReflectionMethod( $engine, 'reuseKey' ) )->invoke( $engine, $html, 'inline' );
		set_transient( 'gtperf_css_reuse_' . $key, array( 'markup' => '<style>stale-forced-result</style>', 'markers' => array() ) );
		$_GET[ UnusedCssOptimizer::GENERATOR_PARAM ] = ( new \ReflectionMethod( $engine, 'generatorToken' ) )->invoke( null );
		self::assertStringNotContainsString( 'stale-forced-result', $engine->optimize( $html ) );
	}

	public function test_http_success_without_a_report_is_a_queue_failure(): void {
		$GLOBALS['gtperf_test_http_response'] = array( 'response' => array( 'code' => 200 ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'without a completed CSS report' );
		( new UnusedCssOptimizer( new Logger() ) )->generateQueued( array( 'url' => 'https://example.com/css-test/' ) );
	}

	public function test_http_errors_fail_the_worker_so_the_queue_can_retry(): void {
		$GLOBALS['gtperf_test_http_response'] = array( 'response' => array( 'code' => 503 ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'CSS generation request failed' );
		( new UnusedCssOptimizer( new Logger() ) )->generateQueued( array( 'url' => 'https://example.com/css-test/' ) );
	}

	public function test_statistics_include_more_than_the_latest_fifty_and_exclude_stale_savings(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public int $offset = 0;
			public function prepare( string $sql, mixed ...$args ): string {
				$this->offset = (int) end( $args );
				return $sql;
			}
			public function get_results( string $sql, string $format ): array {
				unset( $sql, $format );
				$rows = array();
				for ( $i = $this->offset; $i < min( 205, $this->offset + 200 ); ++$i ) {
					$rows[] = array( 'status' => 'ready', 'metadata' => json_encode( array( 'generation' => 1, 'revision' => $i < 203 ? 2 : 1, 'original_bytes' => 100, 'generated_bytes' => 40 ) ) );
				}
				return $rows;
			}
		};
		update_option( 'gtperf_css_revision', 2 );
		$stats = ( new ReportRepository() )->statistics();
		self::assertSame( 205, $stats['total'] );
		self::assertSame( 203, $stats['ready'] );
		self::assertSame( 2, $stats['stale'] );
		self::assertSame( 20300, $stats['original_bytes'] );
		self::assertSame( 8120, $stats['generated_bytes'] );
	}
}
