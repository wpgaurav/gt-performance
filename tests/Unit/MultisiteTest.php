<?php
/**
 * Multisite: per-site resolution, configuration, and bypass rules.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\ConfigFile;
use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Cache\SiteResolver;
use GTPerformance\Core\Network;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class MultisiteTest extends TestCase {
	private string $root = '';

	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_is_multisite'], $GLOBALS['gtperf_test_blog_id'], $GLOBALS['gtperf_test_sites'], $GLOBALS['gtperf_test_site_options'] );
		if ( '' !== $this->root && is_dir( $this->root ) ) {
			$entries = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $entries as $entry ) {
				( $entry->isLink() || ! $entry->isDir() ) ? @unlink( $entry->getPathname() ) : @rmdir( $entry->getPathname() );
			}
			@rmdir( $this->root );
		}
	}

	/** @return list<array<string, mixed>> The demo network: a main site and two subfolder stores. */
	private static function subfolderSites(): array {
		return array(
			array( 'id' => 1, 'domain' => 'demo.test', 'path' => '/', 'active' => true ),
			array( 'id' => 4, 'domain' => 'demo.test', 'path' => '/uranium/', 'active' => true ),
			array( 'id' => 5, 'domain' => 'demo.test', 'path' => '/uranium-fluentcart/', 'active' => true ),
			array( 'id' => 6, 'domain' => 'demo.test', 'path' => '/retired/', 'active' => false ),
		);
	}

	/** @return list<array<string, mixed>> */
	private static function subdomainSites(): array {
		return array(
			array( 'id' => 1, 'domain' => 'demo.test', 'path' => '/', 'active' => true ),
			array( 'id' => 2, 'domain' => 'shop.demo.test', 'path' => '/', 'active' => true ),
			array( 'id' => 3, 'domain' => 'mapped.example', 'path' => '/', 'active' => true ),
		);
	}

	/** @dataProvider subfolderRequests */
	public function test_subfolder_requests_resolve_like_wordpress( string $host, string $path, int $expected ): void {
		self::assertSame( $expected, SiteResolver::resolve( self::subfolderSites(), $host, $path ) );
	}

	/** @return array<string, array{string, string, int}> */
	public static function subfolderRequests(): array {
		return array(
			'main site root'                  => array( 'demo.test', '/', 1 ),
			'main site page'                  => array( 'demo.test', '/about/', 1 ),
			'subsite root'                    => array( 'demo.test', '/uranium/', 4 ),
			'subsite without trailing slash'  => array( 'demo.test', '/uranium', 4 ),
			'subsite deep page'               => array( 'demo.test', '/uranium/product/rod/', 4 ),
			'sibling sharing a prefix'        => array( 'demo.test', '/uranium-fluentcart/checkout/', 5 ),
			'prefix without segment boundary' => array( 'demo.test', '/uraniumx/', 1 ),
			'path case is ignored'            => array( 'demo.test', '/URANIUM/shop/', 4 ),
			'default https port'              => array( 'demo.test:443', '/uranium/', 4 ),
			'www alias'                       => array( 'www.demo.test', '/uranium/', 4 ),
			'other port is another domain'    => array( 'demo.test:8080', '/uranium/', 0 ),
			'unknown host'                    => array( 'evil.test', '/uranium/', 0 ),
			// A suspended site keeps its path; it must not fall through to the main site.
			'inactive site'                   => array( 'demo.test', '/retired/page/', 0 ),
			'empty host'                      => array( '', '/', 0 ),
		);
	}

	/** @dataProvider subdomainRequests */
	public function test_subdomain_and_mapped_requests_resolve_by_host( string $host, string $path, int $expected ): void {
		self::assertSame( $expected, SiteResolver::resolve( self::subdomainSites(), $host, $path ) );
	}

	/** @return array<string, array{string, string, int}> */
	public static function subdomainRequests(): array {
		return array(
			'main domain'        => array( 'demo.test', '/shop/', 1 ),
			'subdomain'          => array( 'shop.demo.test', '/', 2 ),
			'subdomain page'     => array( 'shop.demo.test', '/cart/', 2 ),
			'mapped domain'      => array( 'mapped.example', '/blog/', 3 ),
			'unknown subdomain'  => array( 'nope.demo.test', '/', 0 ),
		);
	}

	public function test_root_bypass_paths_cover_a_subfolder_site(): void {
		$config = array(
			'enabled'      => true,
			'bypass_paths' => array( '/wp-admin/', '/account/', '/uranium/cart/' ),
			'site_path'    => '/uranium/',
		);
		$eligibility = new Eligibility();

		self::assertSame( 'path:/wp-admin/', $eligibility->decide( self::request( '/uranium/wp-admin/' ), $config )->reason );
		self::assertSame( 'path:/account/', $eligibility->decide( self::request( '/uranium/account/orders/' ), $config )->reason );
		self::assertSame( 'path:/uranium/cart/', $eligibility->decide( self::request( '/uranium/cart/' ), $config )->reason );
		self::assertTrue( $eligibility->decide( self::request( '/uranium/shop/' ), $config )->cacheable );
		self::assertTrue( $eligibility->decide( self::request( '/uranium/accounting/' ), $config )->cacheable, 'Bypasses still match on segment boundaries.' );

		unset( $config['site_path'] );
		self::assertTrue(
			$eligibility->decide( self::request( '/uranium/wp-admin/' ), $config )->cacheable,
			'Without the site path a root bypass misses the subfolder, which is why the policy carries it.'
		);
	}

	public function test_each_site_bypasses_on_its_own_cookies_and_query_parameters(): void {
		$woo = array(
			'enabled'             => true,
			'bypass_cookies'      => array( 'wordpress_logged_in_', 'woocommerce_items_in_cart', 'wp_woocommerce_session_' ),
			'bypass_query_params' => array( 'add-to-cart' ),
			'site_path'           => '/uranium/',
		);
		$fluent = array(
			'enabled'             => true,
			'bypass_cookies'      => array( 'wordpress_logged_in_', 'fct_' ),
			'bypass_query_params' => array( 'fluent-cart' ),
			'site_path'           => '/uranium-fluentcart/',
		);
		$eligibility = new Eligibility();

		// Network cookies use the network path "/", so a WooCommerce cart cookie also
		// reaches the FluentCart site. Only the site whose cart it is bypasses on it.
		$cart = array( 'woocommerce_items_in_cart' => '1' );
		self::assertSame( 'cookie:woocommerce_items_in_cart', $eligibility->decide( self::request( '/uranium/shop/', $cart ), $woo )->reason );
		self::assertTrue( $eligibility->decide( self::request( '/uranium-fluentcart/shop/', $cart ), $fluent )->cacheable );

		$fluentCart = array( 'fct_cart_hash' => 'abc' );
		self::assertSame( 'cookie:fct_', $eligibility->decide( self::request( '/uranium-fluentcart/shop/', $fluentCart ), $fluent )->reason );
		self::assertTrue( $eligibility->decide( self::request( '/uranium/shop/', $fluentCart ), $woo )->cacheable );

		self::assertSame( 'query:add-to-cart', $eligibility->decide( self::request( '/uranium/shop/', array(), array( 'add-to-cart' => '12' ) ), $woo )->reason );
		self::assertSame( 'query:fluent-cart', $eligibility->decide( self::request( '/uranium-fluentcart/shop/', array(), array( 'fluent-cart' => 'checkout' ) ), $fluent )->reason );

		$login = array( 'wordpress_logged_in_abc' => 'admin' );
		self::assertSame( 'cookie:wordpress_logged_in_', $eligibility->decide( self::request( '/uranium/shop/', $login ), $woo )->reason );
		self::assertSame( 'cookie:wordpress_logged_in_', $eligibility->decide( self::request( '/uranium-fluentcart/', $login ), $fluent )->reason );
	}

	public function test_single_site_paths_are_unchanged(): void {
		$root = rtrim( WP_CONTENT_DIR, '/' ) . '/cache/gt-performance';

		self::assertSame( $root, Paths::siteRoot() );
		self::assertSame( $root . '/config.json', Paths::config() );
		self::assertSame( $root . '/pages', Paths::pages() );
		self::assertSame( $root . '/assets', Paths::assets() );
		self::assertSame( $root . '/redis-config.json', Paths::redisConfig() );
		self::assertSame( array( $root, $root . '/pages', $root . '/assets', $root . '/locks' ), Paths::writableDirectories() );
		self::assertSame( content_url( '/cache/gt-performance/assets/css/a.css' ), Paths::assetsUrl( 'css/a.css' ) );
		self::assertSame( array( 'hosts' => array( 'x' ) ), Network::scopePolicy( array( 'hosts' => array( 'x' ) ) ) );
	}

	public function test_network_sites_get_their_own_directories(): void {
		$GLOBALS['gtperf_test_is_multisite'] = true;
		$GLOBALS['gtperf_test_blog_id']      = 4;
		$root                                = rtrim( WP_CONTENT_DIR, '/' ) . '/cache/gt-performance';

		self::assertSame( $root . '/sites/4/config.json', Paths::config() );
		self::assertSame( $root . '/sites/4/pages', Paths::pages() );
		self::assertSame( $root . '/sites/4/assets', Paths::assets() );
		self::assertSame( content_url( '/cache/gt-performance/sites/4/assets/fonts/f.woff2' ), Paths::assetsUrl( 'fonts/f.woff2' ) );
		self::assertSame( $root . '/redis-config.json', Paths::redisConfig(), 'There is one object cache per install.' );
		self::assertSame( $root . '/sites.json', Paths::siteMap() );
	}

	public function test_a_subsite_compiles_its_own_configuration_and_leaves_the_network_files_alone(): void {
		$sites = array();
		foreach ( self::subfolderSites() as $entry ) {
			$site          = new \WP_Site();
			$site->blog_id = (string) $entry['id'];
			$site->domain  = $entry['domain'];
			$site->path    = $entry['path'];
			$site->deleted = $entry['active'] ? '0' : '1';
			$sites[ $entry['id'] ] = $site;
		}
		$GLOBALS['gtperf_test_is_multisite'] = true;
		$GLOBALS['gtperf_test_sites']        = $sites;
		$GLOBALS['gtperf_test_blog_id']      = 4;

		$redis = Paths::redisConfig();
		$before = is_file( $redis ) ? (string) file_get_contents( $redis ) : null;

		self::assertTrue( Settings::compile() );
		$compiled = ConfigFile::read( Paths::config() );
		self::assertIsArray( $compiled );
		self::assertSame( '/uranium/', $compiled['cache']['site_path'] ?? null );
		self::assertSame( GTPERF_DIR, $compiled['plugin_dir'] ?? null );
		self::assertSame( $before, is_file( $redis ) ? (string) file_get_contents( $redis ) : null, 'Only the main site writes the network object-cache configuration.' );

		$map = ConfigFile::read( Paths::siteMap() );
		self::assertIsArray( $map );
		self::assertSame( 4, SiteResolver::resolve( $map['sites'], 'demo.test', '/uranium/shop/' ) );
		self::assertSame( 0, SiteResolver::resolve( $map['sites'], 'demo.test', '/retired/' ) );

		@unlink( Paths::config() );
		@unlink( Paths::siteMap() );
	}

	/**
	 * The drop-in, run as it runs in production: before WordPress, from a real
	 * cache directory, choosing the site from the request alone.
	 *
	 * @dataProvider dropinRequests
	 * @param array<string, string> $cookies
	 */
	public function test_the_dropin_serves_each_request_from_its_own_site( array $sites, string $host, string $uri, array $cookies, string $expected, string $extra = '' ): void {
		$this->root = sys_get_temp_dir() . '/gtperf-ms-dropin-' . bin2hex( random_bytes( 6 ) );
		$cache      = $this->root . '/cache/gt-performance';
		mkdir( $cache, 0o777, true );
		self::assertTrue( ConfigFile::write( $cache . '/sites.json', array( 'plugin_dir' => GTPERF_DIR, 'sites' => $sites ) ) );

		$policies = array(
			1 => array( 'bypass_cookies' => array( 'wordpress_logged_in_' ) ),
			2 => array( 'bypass_cookies' => array( 'wordpress_logged_in_', 'woocommerce_items_in_cart' ) ),
			4 => array( 'bypass_cookies' => array( 'wordpress_logged_in_', 'woocommerce_items_in_cart' ), 'bypass_paths' => array( '/wp-admin/' ), 'site_path' => '/uranium/' ),
			5 => array( 'bypass_cookies' => array( 'wordpress_logged_in_', 'fct_' ), 'site_path' => '/uranium-fluentcart/' ),
		);
		foreach ( $policies as $id => $policy ) {
			$siteRoot = $cache . '/sites/' . $id;
			mkdir( $siteRoot . '/pages', 0o777, true );
			$config = array(
				'generation' => 1,
				'cache'      => array( 'enabled' => true, 'hosts' => array( 'demo.test', 'shop.demo.test' ), 'browser_ttl' => 60 ) + $policy,
				'plugin_dir' => GTPERF_DIR,
			);
			self::assertTrue( ConfigFile::write( $siteRoot . '/config.json', $config ) );
			// Every site stores a page for every URL under test, so a wrong choice of
			// site shows up as the wrong body, not merely as a miss.
			foreach ( array( 'https://demo.test/', 'https://demo.test/uranium/', 'https://demo.test/uranium/wp-admin/', 'https://demo.test/uranium-fluentcart/', 'https://shop.demo.test/' ) as $url ) {
				$request = RequestContext::fromUrl( $url );
				$keyed   = $config['cache'] + array( 'generation' => 1 );
				$hash    = ( new CacheKey() )->hash( ( new CacheKey() )->make( $request, $keyed ) );
				is_dir( $siteRoot . '/pages/' . substr( $hash, 0, 2 ) ) || mkdir( $siteRoot . '/pages/' . substr( $hash, 0, 2 ), 0o777, true );
				file_put_contents( $siteRoot . '/pages/' . substr( $hash, 0, 2 ) . '/' . $hash . '.html', 'SITE-' . $id );
				file_put_contents( $siteRoot . '/pages/' . substr( $hash, 0, 2 ) . '/' . $hash . '.meta.json', (string) json_encode( array( 'stored_at' => time() - 5, 'fresh_until' => time() + 600, 'stale_until' => time() + 1200, 'url' => $url ) ) );
			}
		}

		$server = array( 'HTTP_HOST' => $host, 'REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on' );
		$script = '<?php define("AUTH_KEY", "gt-performance-test-auth-key"); define("ABSPATH", ' . var_export( $this->root . '/', true ) . '); define("WP_CONTENT_DIR", ' . var_export( $this->root, true ) . '); define("MULTISITE", true); ' . $extra
			. '$_SERVER = array_merge( $_SERVER, ' . var_export( $server, true ) . ' ); $_COOKIE = ' . var_export( $cookies, true ) . '; '
			. 'require ' . var_export( GTPERF_DIR . '/dropins/advanced-cache.php', true ) . '; echo "WORDPRESS";';
		file_put_contents( $this->root . '/runner.php', $script );

		self::assertSame( $expected, (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 ' . escapeshellarg( $this->root . '/runner.php' ) . ' 2>&1' ) );
	}

	/** @return array<string, array<int, mixed>> */
	public static function dropinRequests(): array {
		$subfolder = self::subfolderSites();
		$subdomain = self::subdomainSites();

		return array(
			'subfolder main site'          => array( $subfolder, 'demo.test', '/', array(), 'SITE-1' ),
			'subfolder store'              => array( $subfolder, 'demo.test', '/uranium/', array(), 'SITE-4' ),
			'subfolder sibling store'      => array( $subfolder, 'demo.test', '/uranium-fluentcart/', array(), 'SITE-5' ),
			'store cart cookie bypasses'   => array( $subfolder, 'demo.test', '/uranium/', array( 'woocommerce_items_in_cart' => '1' ), 'WORDPRESS' ),
			'other store ignores it'       => array( $subfolder, 'demo.test', '/uranium-fluentcart/', array( 'woocommerce_items_in_cart' => '1' ), 'SITE-5' ),
			'fluentcart cookie bypasses'   => array( $subfolder, 'demo.test', '/uranium-fluentcart/', array( 'fct_cart' => 'x' ), 'WORDPRESS' ),
			'main ignores store cookies'   => array( $subfolder, 'demo.test', '/', array( 'fct_cart' => 'x', 'woocommerce_items_in_cart' => '1' ), 'SITE-1' ),
			'logged in bypasses anywhere'  => array( $subfolder, 'demo.test', '/uranium-fluentcart/', array( 'wordpress_logged_in_x' => 'a' ), 'WORDPRESS' ),
			'site-relative admin bypass'   => array( $subfolder, 'demo.test', '/uranium/wp-admin/', array(), 'WORDPRESS' ),
			'subdomain main'               => array( $subdomain, 'demo.test', '/', array(), 'SITE-1' ),
			'subdomain store'              => array( $subdomain, 'shop.demo.test', '/', array(), 'SITE-2' ),
			'subdomain store cookie'       => array( $subdomain, 'shop.demo.test', '/', array( 'woocommerce_items_in_cart' => '1' ), 'WORDPRESS' ),
			'unknown subdomain'            => array( $subdomain, 'nope.demo.test', '/', array(), 'WORDPRESS' ),
			'sunrise routing is not ours'  => array( $subfolder, 'demo.test', '/uranium/', array(), 'WORDPRESS', 'define("SUNRISE", true); ' ),
		);
	}

	/**
	 * @param array<string, string> $cookies
	 * @param array<string, string> $query
	 */
	private static function request( string $path, array $cookies = array(), array $query = array() ): RequestContext {
		return new RequestContext( 'GET', 'https', 'demo.test', $path, $query, $cookies, array(), '' );
	}
}
