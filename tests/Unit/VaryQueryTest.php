<?php
/**
 * "Cache each value separately" query parameters.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\PageCacheModule;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class VaryQueryTest extends TestCase {
	private const PAGE = '<!doctype html><html><head></head><body>shop</body></html>';

	protected function setUp(): void {
		$settings                                = Settings::defaults();
		$settings['cache']['vary_query_params']  = array( 'orderby', 'lang' );
		$GLOBALS['gtperf_test_options']          = array( Settings::OPTION => $settings );
		$GLOBALS['gtperf_test_actions']          = array();
		( new FileStore() )->purgeAll();
		http_response_code( 200 );
	}

	protected function tearDown(): void {
		( new FileStore() )->purgeAll();
		unset( $GLOBALS['gtperf_test_options'] );
		$GLOBALS['gtperf_test_actions'] = array();
	}

	/** @return array<string, mixed> */
	private function policy(): array {
		return array( 'generation' => 1 ) + Settings::all()['cache'];
	}

	private function decide( string $url ): string {
		$decision = ( new Eligibility() )->decide( RequestContext::fromUrl( $url ), $this->policy() );

		return $decision->cacheable ? 'cache' : $decision->reason;
	}

	private function capture( string $url ): string {
		$module = new PageCacheModule( new Logger() );
		( new \ReflectionProperty( $module, 'request' ) )->setValue( $module, RequestContext::fromUrl( $url ) );

		return $module->capture( self::PAGE );
	}

	private function stored( string $url ): bool {
		$key = new CacheKey();

		return is_file( ( new FileStore() )->pagePath( $key->hash( $key->make( RequestContext::fromUrl( $url ), $this->policy() ) ) ) );
	}

	public function test_listed_parameters_are_cached_and_everything_else_keeps_its_rule(): void {
		self::assertSame( 'cache', $this->decide( 'https://example.com/shop/?orderby=price' ) );
		self::assertSame( 'cache', $this->decide( 'https://example.com/shop/?lang=de&utm_source=news' ) );
		self::assertSame( 'unknown_query:color', $this->decide( 'https://example.com/shop/?orderby=price&color=red' ) );
		self::assertSame( 'query:s', $this->decide( 'https://example.com/?s=shoes&orderby=price' ), 'Never-cache parameters still win.' );
	}

	public function test_long_values_that_mint_keys_are_not_cached(): void {
		self::assertSame( 'query_value:orderby', $this->decide( 'https://example.com/shop/?orderby=' . str_repeat( 'a', 101 ) ) );
	}

	public function test_purging_a_page_purges_its_variants_at_the_origin_and_the_edge(): void {
		$this->capture( 'https://example.com/shop/' );
		$this->capture( 'https://example.com/shop/?orderby=price' );
		$this->capture( 'https://example.com/shop/?lang=de&orderby=date' );
		$this->capture( 'https://example.com/blog/?orderby=date' );
		self::assertTrue( $this->stored( 'https://example.com/shop/?orderby=price' ) );

		( new Purger() )->purgeUrl( 'https://example.com/shop/' );

		self::assertFalse( $this->stored( 'https://example.com/shop/' ) );
		self::assertFalse( $this->stored( 'https://example.com/shop/?orderby=price' ) );
		self::assertFalse( $this->stored( 'https://example.com/shop/?orderby=date&lang=de' ), 'Parameter order does not matter.' );
		self::assertTrue( $this->stored( 'https://example.com/blog/?orderby=date' ), 'Another page\'s variants stay.' );

		$edge = array();
		foreach ( $GLOBALS['gtperf_test_actions'] as $action ) {
			if ( 'gt_performance_purged_urls' === $action['hook'] ) {
				$edge = $action['args'][0];
			}
		}
		self::assertEqualsCanonicalizing(
			array( 'https://example.com/shop/', 'https://example.com/shop/?orderby=price', 'https://example.com/shop/?lang=de&orderby=date' ),
			$edge
		);
	}

	public function test_a_page_at_the_variant_limit_serves_new_values_uncached(): void {
		$store = new FileStore();
		for ( $i = 0; $i < FileStore::MAX_VARIANTS; $i++ ) {
			self::assertTrue( $store->addVariant( 'https://example.com/shop/', hash( 'sha256', (string) $i ), 'https://example.com/shop/?orderby=' . $i ) );
		}

		self::assertSame( self::PAGE, $this->capture( 'https://example.com/shop/?orderby=one-too-many' ) );
		self::assertFalse( $this->stored( 'https://example.com/shop/?orderby=one-too-many' ) );

		( new Purger() )->purgeUrl( 'https://example.com/shop/' );
		$this->capture( 'https://example.com/shop/?orderby=one-too-many' );
		self::assertTrue( $this->stored( 'https://example.com/shop/?orderby=one-too-many' ), 'A purge frees the page\'s variant budget.' );
	}
}
