<?php
/**
 * The drop-in and the WordPress request path must agree exactly.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\DropinRuntime;
use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * advanced-cache.php builds its request context before WordPress loads, and
 * PageCacheModule builds one after. If the two ever disagree, either every key
 * misses forever or the drop-in serves a cached page to a request WordPress
 * would have bypassed. These tests hold the two implementations together.
 */
final class DropinParityTest extends TestCase {
	/** @var array<string, mixed> */
	private array $server = array();
	/** @var array<string, mixed> */
	private array $cookie = array();

	protected function setUp(): void {
		$this->server = $_SERVER;
		$this->cookie = $_COOKIE;
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		$_COOKIE = $this->cookie;
	}

	/**
	 * Build both contexts from the same request. The WordPress side receives the
	 * slashed superglobals that wp_magic_quotes() would have produced.
	 *
	 * @param array<string, string> $server Raw server values.
	 * @param array<string, string> $cookie Raw cookie values.
	 * @return array{0: RequestContext, 1: RequestContext}
	 */
	private function bothContexts( array $server, array $cookie ): array {
		$_SERVER = $server;
		$_COOKIE = $cookie;

		$reflection = new \ReflectionMethod( DropinRuntime::class, 'request' );
		$early      = $reflection->invoke( null );

		$_SERVER = array_map( 'addslashes', $server );
		$_COOKIE = array_map( 'addslashes', $cookie );
		$late    = RequestContext::fromGlobals();

		return array( $early, $late );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: array<string, string>}>
	 */
	public static function requestProvider(): array {
		return array(
			'plain'                 => array(
				array(
					'REQUEST_METHOD' => 'GET',
					'HTTP_HOST' => 'example.com',
					'REQUEST_URI' => '/about/',
				),
				array(),
			),
			'quote in path'         => array(
				array(
					'REQUEST_METHOD' => 'GET',
					'HTTP_HOST' => 'example.com',
					'REQUEST_URI' => "/it's-a-page/",
				),
				array(),
			),
			'quote in query'        => array(
				array(
					'REQUEST_METHOD' => 'GET',
					'HTTP_HOST' => 'example.com',
					'REQUEST_URI' => "/?s=o'brien",
				),
				array(),
			),
			'quote in cookie'       => array(
				array(
					'REQUEST_METHOD' => 'GET',
					'HTTP_HOST' => 'example.com',
					'REQUEST_URI' => '/',
				),
				array( 'wordpress_logged_in_9f' => "user's-token" ),
			),
			'uppercase host'        => array(
				array(
					'REQUEST_METHOD' => 'get',
					'HTTP_HOST' => 'Example.COM',
					'REQUEST_URI' => '/',
				),
				array(),
			),
			'header injection'      => array(
				array(
					'REQUEST_METHOD'  => "GET\r\nX-Injected: 1",
					'HTTP_HOST'       => "example.com\r\nX-Injected: 1",
					'REQUEST_URI'     => "/page\r\nX-Injected: 1",
					'HTTP_USER_AGENT' => "Mozilla\r\nX-Injected: 1",
				),
				array( "bad\r\nname" => "bad\r\nvalue" ),
			),
			'null bytes'            => array(
				array(
					'REQUEST_METHOD' => "GET\0",
					'HTTP_HOST' => "example.com\0",
					'REQUEST_URI' => "/page\0/",
				),
				array( "n\0ame" => "val\0ue" ),
			),
			'duplicated slashes'    => array(
				array(
					'REQUEST_METHOD' => 'GET',
					'HTTP_HOST' => 'example.com',
					'REQUEST_URI' => '///deep//path//',
				),
				array(),
			),
			'mobile user agent'     => array(
				array(
					'REQUEST_METHOD'  => 'GET',
					'HTTP_HOST'       => 'example.com',
					'REQUEST_URI'     => '/',
					'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile/15E148',
				),
				array(),
			),
			'bypass cookie present' => array(
				array(
					'REQUEST_METHOD' => 'GET',
					'HTTP_HOST' => 'example.com',
					'REQUEST_URI' => '/',
				),
				array(
					'wordpress_logged_in_9f' => 'token',
					'other' => 'value',
				),
			),
		);
	}

	/**
	 * @param array<string, string> $server Raw server values.
	 * @param array<string, string> $cookie Raw cookie values.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'requestProvider' )]
	public function test_cache_key_matches_on_both_sides( array $server, array $cookie ): void {
		list( $early, $late ) = $this->bothContexts( $server, $cookie );

		$config = array(
			'generation' => 1,
			'separate_mobile' => true,
			'ignored_query_params' => array( 's' ),
		);
		$key    = new CacheKey();

		self::assertSame(
			$key->hash( $key->make( $early, $config ) ),
			$key->hash( $key->make( $late, $config ) ),
			'The drop-in and WordPress must hash the same request identically.'
		);
	}

	/**
	 * @param array<string, string> $server Raw server values.
	 * @param array<string, string> $cookie Raw cookie values.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'requestProvider' )]
	public function test_eligibility_matches_on_both_sides( array $server, array $cookie ): void {
		list( $early, $late ) = $this->bothContexts( $server, $cookie );

		$config = array(
			'enabled'              => true,
			'bypass_cookies'       => array( 'wordpress_logged_in_' ),
			'bypass_paths'         => array( '/checkout/' ),
			'ignored_query_params' => array( 's' ),
		);

		$eligibility = new Eligibility();
		$earlyResult = $eligibility->decide( $early, $config );
		$lateResult  = $eligibility->decide( $late, $config );

		self::assertSame( $earlyResult->cacheable, $lateResult->cacheable );
		self::assertSame( $earlyResult->reason, $lateResult->reason );
	}

	/**
	 * @param array<string, string> $server Raw server values.
	 * @param array<string, string> $cookie Raw cookie values.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'requestProvider' )]
	public function test_contexts_carry_no_control_characters( array $server, array $cookie ): void {
		foreach ( $this->bothContexts( $server, $cookie ) as $context ) {
			$values = array_merge(
				array( $context->method, $context->scheme, $context->host, $context->path, $context->userAgent ),
				array_keys( $context->cookies ),
				array_values( $context->cookies ),
				array_keys( $context->query ),
				array_values( $context->query ),
				array_keys( $context->headers ),
				array_values( $context->headers )
			);

			foreach ( $values as $value ) {
				self::assertSame(
					0,
					preg_match( '/[\x00-\x1F\x7F]/', (string) $value ),
					'No untrusted value may reach the gt_performance_html filter with control characters intact.'
				);
			}
		}
	}
}
