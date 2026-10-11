<?php
/**
 * Alternate-format cache isolation, including the pre-WordPress runtime.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\DropinRuntime;
use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Cloudflare\RuleExpression;
use PHPUnit\Framework\TestCase;

final class RepresentationCacheTest extends TestCase {
	/** @dataProvider representations */
	public function test_early_and_wordpress_caches_agree_on_representation( string $accept, string $query, bool $bypass ): void {
		$server = $_SERVER;
		$cookie = $_COOKIE;
		try {
			$_SERVER = array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_HOST'      => 'example.com',
				'REQUEST_URI'    => '/snippet/' . $query,
				'HTTP_ACCEPT'    => $accept,
			);
			$_COOKIE = array();
			$early = ( new \ReflectionMethod( DropinRuntime::class, 'request' ) )->invoke( null );
			$late  = RequestContext::fromGlobals();
			$config = array(
				'enabled' => true,
				'ignored_query_params' => array( 'format' ),
			);
			foreach ( array( $early, $late ) as $request ) {
				$decision = ( new Eligibility() )->decide( $request, $config );
				self::assertSame( ! $bypass, $decision->cacheable );
				if ( $bypass ) {
					self::assertSame( 'alternate_representation', $decision->reason );
				}
				self::assertSame( ! $bypass, ( new RuleExpression() )->matches( $request, 'example.com', $config ) );
			}
		} finally {
			$_SERVER = $server;
			$_COOKIE = $cookie;
		}
	}

	/** @return iterable<string, array{string,string,bool}> */
	public static function representations(): iterable {
		yield 'browser HTML' => array( 'text/html,application/xhtml+xml,*/*;q=0.8', '', false );
		yield 'wildcard' => array( '*/*', '', false );
		yield 'absent Accept' => array( '', '', false );
		yield 'Markdown' => array( 'text/markdown', '', true );
		yield 'case and spacing' => array( 'TEXT/MARKDOWN ; q=1', '', true );
		yield 'lower-priority Markdown' => array( 'text/html;q=1,text/markdown;q=0.1', '', true );
		yield 'rejected Markdown still negotiates' => array( 'text/markdown;q=0,text/html', '', true );
		yield 'legacy Markdown type' => array( 'text/x-markdown', '', true );
		yield 'conservative Markdown prefix' => array( 'text/markdownish', '', true );
		yield 'different media type' => array( 'application/json', '', false );
		yield 'explicit Markdown format' => array( 'text/html', '?format=md', true );
		yield 'explicit HTML format cannot be ignored' => array( 'text/html', '?format=html', true );
	}

	public function test_edge_rule_keeps_negotiation_and_authorization_out_of_html_cache(): void {
		$expression = ( new RuleExpression() )->compile( 'example.com', array(), true );
		self::assertStringContainsString( 'lower(http.request.headers["accept"][0])', $expression );
		self::assertStringContainsString( 'http.request.headers["authorization"]', $expression );
		self::assertStringContainsString( 'http.request.uri.query eq ""', $expression );
	}

	public function test_preload_reaches_origin_while_origin_policy_still_allows_its_public_page(): void {
		$request = RequestContext::fromUrl( 'https://example.com/post/', array(), array( 'X-GT-Preload' => 'timestamp.signature' ) );
		self::assertNotNull( $request );
		self::assertTrue( ( new Eligibility() )->decide( $request, array( 'enabled' => true ) )->cacheable );
		self::assertFalse( ( new RuleExpression() )->matches( $request, 'example.com', array() ) );
		self::assertStringContainsString( 'http.request.headers["x-gt-preload"]', ( new RuleExpression() )->compile( 'example.com', array() ) );
	}
}
