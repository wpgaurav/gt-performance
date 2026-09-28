<?php
/**
 * Query parameters sent as arrays (`name[]=`) must bypass the cache.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\ConfigFile;
use GTPerformance\Cache\DropinRuntime;
use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Cloudflare\RuleExpression;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * parse_str() turns `name[]=x` into an array and the request context keeps only
 * scalar values, so these requests used to be judged and keyed as the page with
 * no query: `?preview[]=1` or `?s[]=x` could be stored as the plain page.
 */
final class ArrayQueryTest extends TestCase {
	/** @return array<string, mixed> */
	private function policy(): array {
		return array(
			'generation'        => 1,
			'hosts'             => array( 'example.com' ),
			'vary_query_params' => array( 'orderby' ),
		) + Settings::defaults()['cache'];
	}

	private function decide( string $url ): string {
		$decision = ( new Eligibility() )->decide( RequestContext::fromUrl( $url ), $this->policy() );

		return $decision->cacheable ? 'cache' : $decision->reason;
	}

	public function test_the_plain_page_and_scalar_parameters_keep_their_decisions(): void {
		self::assertSame( 'cache', $this->decide( 'https://example.com/' ) );
		self::assertSame( 'cache', $this->decide( 'https://example.com/?utm_source=news' ) );
		self::assertSame( 'query:s', $this->decide( 'https://example.com/?s=shoes' ) );
		self::assertSame( 'unknown_query:anything', $this->decide( 'https://example.com/shop/?anything=x' ) );
	}

	public function test_every_parameter_sent_as_an_array_bypasses(): void {
		self::assertSame( 'query_array:preview', $this->decide( 'https://example.com/?preview[]=1' ), 'A bypass parameter.' );
		self::assertSame( 'query_array:s', $this->decide( 'https://example.com/?s[]=x' ) );
		self::assertSame( 'query_array:add-to-cart', $this->decide( 'https://example.com/?add-to-cart[]=5' ) );
		self::assertSame( 'query_array:anything', $this->decide( 'https://example.com/shop/?anything[]=x' ), 'An unknown parameter.' );
		self::assertSame( 'query_array:utm_source', $this->decide( 'https://example.com/?utm_source[]=x' ), 'Even an ignored one: WordPress sees the array.' );
		self::assertSame( 'query_array:orderby', $this->decide( 'https://example.com/shop/?orderby[x]=price' ), 'And a cache-separately one.' );
		self::assertSame( 'query_array:filter', $this->decide( 'https://example.com/shop/?utm_source=a&filter[color]=red' ) );
	}

	public function test_the_drop_in_and_wordpress_see_the_same_array_parameters(): void {
		$server = $_SERVER;
		try {
			$_SERVER = array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_HOST'      => 'example.com',
				'REQUEST_URI'    => '/?utm_source=x&preview[]=1',
			);
			$early = ( new \ReflectionMethod( DropinRuntime::class, 'request' ) )->invoke( null );
			$late  = RequestContext::fromGlobals();
		} finally {
			$_SERVER = $server;
		}

		self::assertSame( array( 'preview' ), $early->arrayQuery );
		self::assertSame( $early->arrayQuery, $late->arrayQuery );
		self::assertSame( ( new Eligibility() )->decide( $early, $this->policy() )->reason, ( new Eligibility() )->decide( $late, $this->policy() )->reason );
	}

	public function test_the_edge_never_counts_an_array_parameter_as_an_empty_query(): void {
		$rule = new RuleExpression();
		$plain = RequestContext::fromUrl( 'https://example.com/' );
		$array = RequestContext::fromUrl( 'https://example.com/?s[]=x' );

		self::assertTrue( $rule->matches( $plain, 'example.com', $this->policy(), true ) );
		self::assertFalse( $rule->matches( $array, 'example.com', $this->policy(), true ), 'Cloudflare sees `s%5B%5D=x`, so the empty-query term fails there too.' );
	}

	#[RunInSeparateProcess]
	public function test_the_drop_in_does_not_serve_the_plain_page_for_an_array_parameter(): void {
		// Without the bypass, serve() finds the stored home page, prints it, and
		// calls exit, which ends this process before the assertion runs.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/?preview[]=1';
		$_COOKIE                   = array();

		$cache  = array(
			'enabled'   => true,
			'hosts'     => array( 'example.com' ),
			'fresh_ttl' => 3600,
			'stale_ttl' => 3600,
		);
		$config = Paths::cacheRoot() . '/array-query-config.json';
		wp_mkdir_p( Paths::pages() );
		self::assertTrue( ConfigFile::write( $config, array( 'cache' => $cache, 'generation' => 1 ) ) );

		$home = new RequestContext( 'GET', 'http', 'example.com', '/', array(), array(), array(), '' );
		$hash = ( new CacheKey() )->hash( ( new CacheKey() )->make( $home, $cache + array( 'generation' => 1 ) ) );
		$now  = time();
		( new FileStore() )->write(
			$hash,
			'<html><body>cached home</body></html>',
			array(
				'stored_at'   => $now,
				'fresh_until' => $now + 3600,
				'stale_until' => $now + 7200,
				'url'         => 'http://example.com/',
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
}
