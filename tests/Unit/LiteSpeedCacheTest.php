<?php
/**
 * LiteSpeed's own cache: headers, tags, the vary cookie, and queued purges.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\ConfigFile;
use GTPerformance\Cache\LiteSpeedCache;
use GTPerformance\Cache\ServerRules;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class LiteSpeedCacheTest extends TestCase {
	private string $root = '';

	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_options'][ Settings::OPTION ], $GLOBALS['gtperf_test_options'][ LiteSpeedCache::QUEUE_OPTION ], $GLOBALS['gtperf_test_options'][ LiteSpeedCache::TOKEN_OPTION ], $GLOBALS['gtperf_test_remote_posts'] );
		@unlink( Paths::config() );
		if ( '' !== $this->root && is_dir( $this->root ) ) {
			$entries = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $entries as $entry ) {
				( $entry->isLink() || ! $entry->isDir() ) ? @unlink( $entry->getPathname() ) : @rmdir( $entry->getPathname() );
			}
			@rmdir( $this->root );
		}
	}

	/** @dataProvider varyCases */
	public function test_the_vary_cookie_follows_bypass_cookies( array $cookies, array $headers, bool $expected ): void {
		$prefixes = array( 'wordpress_logged_in_', 'woocommerce_items_in_cart', 'fct_', 'comment_author_' );

		self::assertSame( $expected, LiteSpeedCache::needsVary( $cookies, $headers, $prefixes ) );
	}

	/** @return array<string, array{list<string>, list<string>, bool}> */
	public static function varyCases(): array {
		return array(
			'anonymous'                     => array( array( '_ga' ), array(), false ),
			'signed in'                     => array( array( 'wordpress_logged_in_abc' ), array(), true ),
			'being signed in'               => array( array(), array( 'Set-Cookie: wordpress_logged_in_abc=user%7C123; path=/; HttpOnly' ), true ),
			'adding to cart'                => array( array( '_ga' ), array( 'Set-Cookie: woocommerce_items_in_cart=1; path=/' ), true ),
			'fluentcart cart'               => array( array( 'fct_cart_hash' ), array(), true ),
			'signing out (deleted value)'   => array( array( 'wordpress_logged_in_abc' ), array( 'Set-Cookie: wordpress_logged_in_abc=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0; path=/' ), false ),
			'signing out (empty, expired)'  => array( array( 'wordpress_logged_in_abc' ), array( 'Set-Cookie: wordpress_logged_in_abc=+; expires=Mon, 01-Jan-1999 00:00:00 GMT; path=/' ), false ),
			'cart emptied, still signed in' => array( array( 'wordpress_logged_in_a', 'woocommerce_items_in_cart' ), array( 'Set-Cookie: woocommerce_items_in_cart=; Max-Age=0' ), true ),
			'other cookie set'              => array( array(), array( 'Set-Cookie: pll_language=en; path=/' ), false ),
		);
	}

	public function test_tags_name_a_page_without_its_query_and_differ_per_site(): void {
		$tag = LiteSpeedCache::urlTag( 'gtpabc', 'https', 'Example.com', '/post/' );

		self::assertSame( $tag, LiteSpeedCache::urlTag( 'gtpabc', 'https', 'example.com', '/post/' ), 'Host case does not change the tag.' );
		self::assertNotSame( $tag, LiteSpeedCache::urlTag( 'gtpabc', 'https', 'example.com', '/other/' ) );
		self::assertNotSame( $tag, LiteSpeedCache::urlTag( 'gtpdef', 'https', 'example.com', '/post/' ), 'Each site has its own prefix.' );
		self::assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $tag );
		self::assertStringStartsWith( 'gtp', LiteSpeedCache::prefix() );
	}

	public function test_gt_steps_aside_while_the_litespeed_cache_plugin_runs(): void {
		self::assertFalse( LiteSpeedCache::pluginOwnsCache() );
		$probe = sys_get_temp_dir() . '/gtperf-lscwp-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $probe, '<?php require ' . var_export( GTPERF_DIR . '/vendor/autoload.php', true ) . '; require ' . var_export( GTPERF_DIR . '/tests/bootstrap.php', true ) . '; define( "LSCWP_V", "7.9.1" ); var_export( GTPerformance\\Cache\\LiteSpeedCache::enabled( array( "cache" => array( "litespeed" => true, "enabled" => true ) ) ) );' );
		try {
			self::assertSame( 'false', trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probe ) . ' 2>&1' ) ) );
		} finally {
			unlink( $probe );
		}
	}

	public function test_a_compile_without_the_commerce_rules_asks_for_another(): void {
		// Before the modules register (an update republishing the drop-in), the
		// compiled configuration has no store cookies. The site must compile again.
		$GLOBALS['gtperf_test_options'][ \GTPerformance\Commerce\CommerceModule::POLICY_HASH_OPTION ] = 'stale-hash';
		self::assertFalse( \GTPerformance\Commerce\CommerceModule::registered() );
		Settings::compile();

		self::assertArrayNotHasKey( \GTPerformance\Commerce\CommerceModule::POLICY_HASH_OPTION, $GLOBALS['gtperf_test_options'] );
	}

	public function test_a_separate_mobile_cache_keeps_litespeed_out(): void {
		self::assertTrue( LiteSpeedCache::enabled( array( 'cache' => array( 'litespeed' => true, 'enabled' => true ) ) ) );
		self::assertFalse( LiteSpeedCache::enabled( array( 'cache' => array( 'litespeed' => true, 'enabled' => true, 'separate_mobile' => true ) ) ) );
		self::assertFalse( LiteSpeedCache::enabled( array( 'cache' => array( 'litespeed' => true, 'enabled' => false ) ) ) );
		self::assertFalse( LiteSpeedCache::enabled( array( 'cache' => array( 'enabled' => true ) ) ) );
	}

	public function test_a_purge_without_a_response_is_queued_and_sent_by_loopback(): void {
		LiteSpeedCache::purgeUrls( array( 'https://example.com/post/', 'https://example.com/post/?orderby=price', 'not a url' ) );

		$queue = (array) get_option( LiteSpeedCache::QUEUE_OPTION, array() );
		self::assertSame( array( LiteSpeedCache::urlTag( LiteSpeedCache::prefix(), 'https', 'example.com', '/post/' ) ), $queue, 'A query variant purges with its page.' );
		$post = end( $GLOBALS['gtperf_test_remote_posts'] );
		self::assertStringEndsWith( 'admin-ajax.php', (string) $post['url'] );
		self::assertSame( get_option( LiteSpeedCache::TOKEN_OPTION ), $post['args']['body']['token'] );
		self::assertFalse( $post['args']['blocking'] );

		LiteSpeedCache::purgeAll();
		self::assertContains( LiteSpeedCache::prefix(), (array) get_option( LiteSpeedCache::QUEUE_OPTION ) );
	}

	public function test_a_network_purge_purges_every_site_in_litespeed(): void {
		$sites = array();
		foreach ( array( 1 => '/', 4 => '/uranium/' ) as $id => $path ) {
			$site          = new \WP_Site();
			$site->blog_id = (string) $id;
			$site->path    = $path;
			$sites[ $id ]  = $site;
		}
		$GLOBALS['gtperf_test_is_multisite'] = true;
		$GLOBALS['gtperf_test_sites']        = $sites;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'litespeed' => true ) );
		try {
			\GTPerformance\Core\Network::purgeAllSites();
		} finally {
			unset( $GLOBALS['gtperf_test_is_multisite'], $GLOBALS['gtperf_test_sites'] );
		}

		self::assertContains( LiteSpeedCache::prefix(), (array) get_option( LiteSpeedCache::QUEUE_OPTION ) );
		self::assertNotEmpty( $GLOBALS['gtperf_test_remote_posts'] ?? array(), 'A CLI purge reaches LiteSpeed through the loopback.' );
	}

	public function test_the_compiled_configuration_tells_the_dropin_how_to_tag(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'litespeed' => true, 'fresh_ttl' => 900 ) );
		Settings::compile();
		$compiled = (array) ConfigFile::read( Paths::config() );

		self::assertSame( array( 'prefix' => LiteSpeedCache::prefix(), 'max_age' => 900, 'cookie_domain' => '' ), $compiled['litespeed'] ?? null );
		$block = ( new ServerRules( sys_get_temp_dir() . '/gtperf-none/.htaccess' ) )->apacheBlock();
		self::assertStringContainsString( "<IfModule LiteSpeed>\nCacheLookup on\nCacheKeyModify -qs:fbclid\n", $block );
		self::assertStringContainsString( "CacheKeyModify -qs:utm_source\n", $block, 'Ignored parameters leave LiteSpeed\'s key as they leave the drop-in\'s.' );
		self::assertStringNotContainsString( 'CacheKeyModify -qs:s' . "\n", $block, 'A search query is never dropped from the key.' );

		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'litespeed' => false ) );
		Settings::compile();
		self::assertArrayNotHasKey( 'litespeed', (array) ConfigFile::read( Paths::config() ) );
	}
}
