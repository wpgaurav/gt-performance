<?php
/**
 * Drop-in serving guards: safe mode, header replay, and authenticated preloads.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\ConfigFile;
use GTPerformance\Cache\DropinRuntime;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Paths;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DropinServingTest extends TestCase {
	public function test_serving_is_enabled_without_the_safe_mode_constant(): void {
		self::assertFalse( DropinRuntime::servingDisabled() );
	}

	#[RunInSeparateProcess]
	public function test_safe_mode_constant_disables_serving(): void {
		define( 'GTPERF_SAFE_MODE', true );

		self::assertTrue( DropinRuntime::servingDisabled() );
	}

	#[RunInSeparateProcess]
	public function test_a_false_safe_mode_constant_leaves_serving_on(): void {
		define( 'GTPERF_SAFE_MODE', false );

		self::assertFalse( DropinRuntime::servingDisabled() );
	}

	#[RunInSeparateProcess]
	public function test_safe_mode_falls_through_instead_of_serving_a_stored_page(): void {
		// Without the guard, serve() finds this fresh entry, prints it, and calls
		// exit, which ends this process before the assertions run.
		define( 'GTPERF_SAFE_MODE', true );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/safe-mode/';
		$_COOKIE                   = array();

		$cache  = array(
			'enabled'   => true,
			'hosts'     => array( 'example.com' ),
			'fresh_ttl' => 3600,
			'stale_ttl' => 3600,
		);
		$config = Paths::cacheRoot() . '/serve-test-config.json';
		wp_mkdir_p( Paths::pages() );
		self::assertTrue(
			ConfigFile::write(
				$config,
				array(
					'cache' => $cache,
					'generation' => 1,
				)
			)
		);

		$request = new RequestContext( 'GET', 'http', 'example.com', '/safe-mode/', array(), array(), array(), '' );
		$hash    = ( new CacheKey() )->hash( ( new CacheKey() )->make( $request, $cache + array( 'generation' => 1 ) ) );
		$now     = time();
		( new FileStore() )->write(
			$hash,
			'<html><body>cached</body></html>',
			array(
				'stored_at'   => $now,
				'fresh_until' => $now + 3600,
				'stale_until' => $now + 7200,
				'url'         => 'http://example.com/safe-mode/',
				'generation'  => 1,
			)
		);

		ob_start();
		DropinRuntime::serve( $config, Paths::pages() );
		$output = (string) ob_get_clean();
		( new FileStore() )->delete( $hash );
		@unlink( $config );

		self::assertSame( '', $output );
	}

	public function test_security_and_indexing_headers_are_kept_for_replay(): void {
		$replay = DropinRuntime::replayableHeaders(
			array(
				'Content-Type: text/html; charset=UTF-8',
				"Content-Security-Policy: default-src 'self'",
				'Strict-Transport-Security: max-age=31536000; includeSubDomains',
				'X-Frame-Options: SAMEORIGIN',
				'X-Content-Type-Options: nosniff',
				'Referrer-Policy: strict-origin-when-cross-origin',
				'Permissions-Policy: camera=()',
				'X-Robots-Tag: noindex',
				'Link: <https://example.com/wp-json/>; rel="https://api.w.org/"',
				'Link: <https://example.com/?p=1>; rel=shortlink',
				'Set-Cookie: session=secret',
				'Cache-Control: no-cache',
				'X-Powered-By: PHP/8.3',
			)
		);

		self::assertSame(
			array(
				"Content-Security-Policy: default-src 'self'",
				'Strict-Transport-Security: max-age=31536000; includeSubDomains',
				'X-Frame-Options: SAMEORIGIN',
				'X-Content-Type-Options: nosniff',
				'Referrer-Policy: strict-origin-when-cross-origin',
				'Permissions-Policy: camera=()',
				'X-Robots-Tag: noindex',
				'Link: <https://example.com/wp-json/>; rel="https://api.w.org/"',
				'Link: <https://example.com/?p=1>; rel=shortlink',
			),
			$replay
		);
	}

	public function test_replay_rejects_injection_and_malformed_lines(): void {
		self::assertSame(
			array(),
			DropinRuntime::replayableHeaders(
				array(
					"X-Frame-Options: DENY\r\nSet-Cookie: injected=1",
					"X-Frame-Options: DENY\nLocation: https://attacker.example/",
					"X-Frame-Options: DENY\0",
					'X-Frame-Options:',
					'X-Frame-Options:    ',
					'Not A Header',
					'Bad Name: value',
					array( 'X-Frame-Options: DENY' ),
					42,
					'X-Frame-Options: ' . str_repeat( 'a', 9000 ),
				)
			)
		);
	}

	public function test_replay_is_bounded(): void {
		$lines = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$lines[] = 'Link: <https://example.com/' . $i . '>; rel=preload';
		}

		self::assertCount( 20, DropinRuntime::replayableHeaders( $lines ) );
	}

	public function test_a_valid_preload_token_rebuilds_a_stale_entry(): void {
		$now = 1700000000;

		self::assertTrue( DropinRuntime::shouldRevalidate( true, array( 'x-gt-preload' => DropinRuntime::preloadToken( $now ) ), $now + 30 ) );
	}

	public function test_a_preload_token_still_serves_a_fresh_entry(): void {
		// Preloading current content must stay cheap, or a burst of queued jobs
		// would stampede the origin rebuilding pages that are already good.
		$now = 1700000000;

		self::assertFalse( DropinRuntime::shouldRevalidate( false, array( 'x-gt-preload' => DropinRuntime::preloadToken( $now ) ), $now ) );
	}

	public function test_an_arbitrary_preload_header_cannot_force_a_rebuild(): void {
		// Before tokens, any client could send X-GT-Preload: 1 and turn every
		// stale page into a full WordPress render.
		foreach ( array( '1', 'yes', '  ', '1700000600.' . str_repeat( 'a', 64 ) ) as $value ) {
			self::assertFalse( DropinRuntime::shouldRevalidate( true, array( 'x-gt-preload' => $value ), 1700000000 ), $value );
		}
	}

	public function test_an_expired_preload_token_is_refused(): void {
		$issued = 1700000000;

		self::assertFalse( DropinRuntime::shouldRevalidate( true, array( 'x-gt-preload' => DropinRuntime::preloadToken( $issued ) ), $issued + 3600 ) );
	}

	public function test_a_preload_token_from_the_far_future_is_refused(): void {
		$token = DropinRuntime::preloadToken( 1800000000 );

		self::assertFalse( DropinRuntime::shouldRevalidate( true, array( 'x-gt-preload' => $token ), 1700000000 ) );
	}

	public function test_bypass_reasons_keep_their_paths(): void {
		self::assertSame( 'path:/cart/', DropinRuntime::reasonHeader( 'path:/cart/' ) );
		self::assertSame( 'cookie:wordpress_logged_in_', DropinRuntime::reasonHeader( 'cookie:wordpress_logged_in_' ) );
		self::assertSame( 'path:/shop/*', DropinRuntime::reasonHeader( 'path:/shop/*' ) );
	}

	public function test_bypass_reasons_cannot_inject_headers(): void {
		self::assertSame( 'unknown_query:aX-Evil:1', DropinRuntime::reasonHeader( "unknown_query:a\r\nX-Evil: 1" ) );
		self::assertSame( 200, strlen( DropinRuntime::reasonHeader( 'unknown_query:' . str_repeat( 'a', 500 ) ) ) );
	}

	public function test_ordinary_visitor_still_gets_the_stale_copy(): void {
		self::assertFalse( DropinRuntime::shouldRevalidate( true, array(), 1700000000 ) );
	}
}
