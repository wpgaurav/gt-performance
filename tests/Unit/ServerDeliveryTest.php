<?php
/**
 * Precompressed copies, web-server copies, and the rules that serve them.
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
use GTPerformance\Cache\ServerRules;
use GTPerformance\Cache\StaticStore;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class ServerDeliveryTest extends TestCase {
	private string $root = '';

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/gtperf-delivery-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root, 0o777, true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_options'][ Settings::OPTION ], $GLOBALS['gtperf_test_options'][ ServerRules::OPTION ], $GLOBALS['gtperf_test_is_multisite'], $GLOBALS['gtperf_test_sites'], $GLOBALS['gtperf_test_blog_id'] );
		( new StaticStore() )->purgeAll();
		( new FileStore() )->purgeAll();
		@unlink( Paths::config() );
		foreach ( array( $this->root ) as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}
			$entries = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $entries as $entry ) {
				( $entry->isLink() || ! $entry->isDir() ) ? @unlink( $entry->getPathname() ) : @rmdir( $entry->getPathname() );
			}
			@rmdir( $directory );
		}
	}

	public function test_a_stored_page_keeps_a_gzip_copy_and_its_etag(): void {
		$store = new FileStore();
		$hash  = str_repeat( 'ab', 32 );
		$html  = '<!doctype html><title>t</title>' . str_repeat( '<p>repeat</p>', 200 );

		self::assertTrue( $store->write( $hash, $html, array( 'url' => 'https://example.com/a/' ) ) );
		self::assertSame( $html, gzdecode( (string) file_get_contents( $store->encodedPath( $hash, 'gzip' ) ) ) );
		$meta = $store->metadata( $hash );
		self::assertSame( hash( 'sha256', $html ), $meta['etag'] ?? null );
		self::assertContains( 'gzip', (array) ( $meta['encodings'] ?? array() ) );

		$store->delete( $hash );
		self::assertFileDoesNotExist( $store->encodedPath( $hash, 'gzip' ) );
	}

	public function test_turning_precompression_off_stores_plain_html_only(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'precompress' => false ) );
		$store = new FileStore();
		$hash  = str_repeat( 'cd', 32 );

		self::assertTrue( $store->write( $hash, '<html>x</html>', array() ) );
		self::assertFileDoesNotExist( $store->encodedPath( $hash, 'gzip' ) );
		self::assertArrayNotHasKey( 'encodings', (array) $store->metadata( $hash ) );
	}

	public function test_a_rewritten_page_never_keeps_the_old_compressed_copy(): void {
		$store = new FileStore();
		$hash  = str_repeat( 'ef', 32 );
		$store->write( $hash, '<html>old</html>', array() );
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'precompress' => false ) );
		$store->write( $hash, '<html>new</html>', array() );

		self::assertFileDoesNotExist( $store->encodedPath( $hash, 'gzip' ), 'An old gzip copy would be served for the new page.' );
	}

	/** @dataProvider acceptEncodings */
	public function test_the_encoding_follows_accept_encoding( string $header, array $stored, string $expected ): void {
		$meta = array(
			'etag'      => str_repeat( 'a', 64 ),
			'encodings' => $stored,
		);

		self::assertSame( $expected, DropinRuntime::encodingFor( $header, $meta ) );
	}

	/** @return array<string, array{string, list<string>, string}> */
	public static function acceptEncodings(): array {
		return array(
			'brotli preferred'          => array( 'gzip, deflate, br, zstd', array( 'br', 'gzip' ), 'br' ),
			'gzip when no brotli copy'  => array( 'gzip, deflate, br', array( 'gzip' ), 'gzip' ),
			'gzip only client'          => array( 'gzip', array( 'br', 'gzip' ), 'gzip' ),
			'q of zero refuses'         => array( 'br;q=0, gzip;q=0.8', array( 'br', 'gzip' ), 'gzip' ),
			'everything refused'        => array( 'br;q=0, gzip;q=0', array( 'br', 'gzip' ), '' ),
			'identity only'             => array( 'identity', array( 'br', 'gzip' ), '' ),
			'no header'                 => array( '', array( 'br', 'gzip' ), '' ),
			'wildcard'                  => array( '*', array( 'gzip' ), 'gzip' ),
			'case and spacing'          => array( ' GZip ; q=1 ', array( 'gzip' ), 'gzip' ),
			'nothing stored'            => array( 'gzip, br', array(), '' ),
		);
	}

	public function test_an_entry_without_a_valid_etag_is_sent_plain(): void {
		self::assertSame( '', DropinRuntime::encodingFor( 'gzip', array( 'encodings' => array( 'gzip' ) ) ) );
		self::assertSame( '', DropinRuntime::encodingFor( 'gzip', array( 'etag' => '../x', 'encodings' => array( 'gzip' ) ) ) );
	}

	/**
	 * The drop-in, run before WordPress as in production, sends the stored copy.
	 *
	 * @dataProvider dropinEncodings
	 */
	public function test_the_dropin_sends_the_stored_compressed_copy( string $accept, string $expected ): void {
		$cache = $this->root . '/cache/gt-performance';
		mkdir( $cache . '/pages', 0o777, true );
		$config = array(
			'generation' => 1,
			'cache'      => array( 'enabled' => true, 'hosts' => array( 'example.com' ) ),
			'plugin_dir' => GTPERF_DIR,
		);
		self::assertTrue( ConfigFile::write( $cache . '/config.json', $config ) );
		$html = '<html><body>' . str_repeat( 'cached ', 300 ) . '</body></html>';
		$hash = ( new CacheKey() )->hash( ( new CacheKey() )->make( RequestContext::fromUrl( 'https://example.com/post/' ), $config['cache'] + array( 'generation' => 1 ) ) );
		$dir  = $cache . '/pages/' . substr( $hash, 0, 2 );
		mkdir( $dir );
		file_put_contents( $dir . '/' . $hash . '.html', $html );
		file_put_contents( $dir . '/' . $hash . '.html.gz', (string) gzencode( $html, 9 ) );
		file_put_contents( $dir . '/' . $hash . '.meta.json', (string) json_encode( array( 'stored_at' => time() - 5, 'fresh_until' => time() + 600, 'stale_until' => time() + 1200, 'etag' => hash( 'sha256', $html ), 'encodings' => array( 'gzip' ) ) ) );

		$server = array( 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/post/', 'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on', 'HTTP_ACCEPT_ENCODING' => $accept );
		file_put_contents(
			$this->root . '/runner.php',
			'<?php define("AUTH_KEY", "gt-performance-test-auth-key"); define("ABSPATH", ' . var_export( $this->root . '/', true ) . '); define("WP_CONTENT_DIR", ' . var_export( $this->root, true ) . '); '
			. '$_SERVER = array_merge( $_SERVER, ' . var_export( $server, true ) . ' ); '
			. 'register_shutdown_function( static function () { file_put_contents( ' . var_export( $this->root . '/headers.txt', true ) . ', implode( "\n", headers_list() ) ); } ); '
			. 'require ' . var_export( GTPERF_DIR . '/dropins/advanced-cache.php', true ) . '; echo "WORDPRESS";'
		);
		$output = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d zlib.output_compression=0 ' . escapeshellarg( $this->root . '/runner.php' ) . ' 2>&1' );

		self::assertSame( $html, 'gzip' === $expected ? gzdecode( $output ) : $output );
	}

	/** @return array<string, array{string, string}> */
	public static function dropinEncodings(): array {
		return array(
			'gzip client' => array( 'gzip, deflate', 'gzip' ),
			'plain client' => array( 'identity', '' ),
			'brotli client without a brotli copy' => array( 'br', '' ),
		);
	}

	/** @dataProvider staticUrls */
	public function test_only_urls_a_rule_can_serve_safely_get_a_static_copy( string $url, bool $servable ): void {
		$path = ( new StaticStore() )->pathFor( $url );

		self::assertSame( $servable, null !== $path, $url );
	}

	/** @return array<string, array{string, bool}> */
	public static function staticUrls(): array {
		return array(
			'home'                 => array( 'https://example.com/', true ),
			'post'                 => array( 'https://example.com/2026/10/post-name/', true ),
			'no trailing slash'    => array( 'https://example.com/post-name', false ),
			'query string'         => array( 'https://example.com/shop/?orderby=price', false ),
			'other scheme'         => array( 'http://example.com/post/', false ),
			'foreign host'         => array( 'https://evil.test/post/', false ),
			'port'                 => array( 'https://example.com:8443/post/', false ),
			'dot segment'          => array( 'https://example.com/a/../b/', false ),
			'hidden segment'       => array( 'https://example.com/.git/', false ),
			'encoded character'    => array( 'https://example.com/caf%C3%A9/', false ),
			'credentials'          => array( 'https://user:pw@example.com/post/', false ),
		);
	}

	public function test_a_page_with_php_security_headers_stays_with_php(): void {
		self::assertTrue( StaticStore::headersAllow( array( 'Link: <https://example.com/wp-json/>; rel="https://api.w.org/"' ) ) );
		self::assertFalse( StaticStore::headersAllow( array( 'Content-Security-Policy: default-src \'self\'' ) ) );
		self::assertFalse( StaticStore::headersAllow( array( 'X-Robots-Tag: noindex' ) ) );

		$static = new StaticStore();
		self::assertFalse( $static->write( 'https://example.com/private/', '<html>p</html>', array(), array( 'X-Robots-Tag: noindex' ) ) );
		self::assertFileDoesNotExist( (string) $static->pathFor( 'https://example.com/private/' ) );
	}

	public function test_static_copies_follow_writes_purges_and_the_generation(): void {
		$static = new StaticStore();
		$static->sync( true, 7 );
		$html = '<html>static</html>';
		self::assertTrue( $static->write( 'https://example.com/post/', $html, FileStore::compress( $html ), array() ) );
		$path = (string) $static->pathFor( 'https://example.com/post/' );
		self::assertSame( Paths::siteRoot() . '/static/example.com/post/index.html', $path );
		self::assertSame( $html, file_get_contents( $path ) );
		self::assertSame( $html, gzdecode( (string) file_get_contents( $path . '.gz' ) ) );
		self::assertSame( '0644', substr( sprintf( '%o', fileperms( $path ) ), -4 ), 'The web server user must be able to read the copy.' );
		self::assertStringContainsString( 'AddEncoding gzip .gz', (string) file_get_contents( $static->root() . '/.htaccess' ) );

		$static->sync( true, 7 );
		self::assertFileExists( $path, 'The same generation keeps its copies.' );
		$static->sync( true, 8 );
		self::assertFileDoesNotExist( $path, 'A new generation retires every copy, as it retires every key.' );

		$static->write( 'https://example.com/post/', $html, array(), array() );
		$static->sync( false, 8 );
		self::assertFileDoesNotExist( $path, 'Switching the feature off removes what the rules would serve.' );
	}

	public function test_deleting_an_entry_deletes_its_static_copy(): void {
		$store  = new FileStore();
		$static = new StaticStore();
		$hash   = str_repeat( '12', 32 );
		$store->write( $hash, '<html>e</html>', array( 'url' => 'https://example.com/entry/' ) );
		$static->write( 'https://example.com/entry/', '<html>e</html>', array( 'gzip' => 'x' ), array() );
		$path = (string) $static->pathFor( 'https://example.com/entry/' );
		self::assertFileExists( $path );

		$store->delete( $hash );
		self::assertFileDoesNotExist( $path );
		self::assertFileDoesNotExist( $path . '.gz' );

		$static->write( 'https://example.com/entry/', '<html>e</html>', array(), array() );
		$store->purgeAll();
		self::assertFileDoesNotExist( $path );
	}

	public function test_the_compiled_configuration_carries_static_settings_only_when_on(): void {
		Settings::compile();
		self::assertArrayNotHasKey( 'static', (array) ConfigFile::read( Paths::config() ) );

		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'static' => true ) );
		Settings::compile();
		$static = (array) ( ConfigFile::read( Paths::config() )['static'] ?? array() );
		self::assertSame( 'https', $static['scheme'] ?? null );
		self::assertSame( Paths::siteRoot() . '/static', $static['root'] ?? null );
	}

	public function test_the_apache_block_mirrors_the_drop_in(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'static' => true, 'bypass_cookies' => array( 'wordpress_logged_in_', 'custom_cart' ) ) );
		Settings::compile();
		$block = ( new ServerRules( $this->root . '/.htaccess' ) )->apacheBlock();
		$root  = (string) realpath( Paths::siteRoot() . '/static' );

		self::assertStringStartsWith( '# BEGIN GT Performance', $block );
		self::assertStringContainsString( 'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$', $block );
		self::assertStringContainsString( 'RewriteCond %{QUERY_STRING} ^$', $block );
		self::assertStringContainsString( 'RewriteCond %{HTTP:X-GT-Preload} ^$', $block );
		self::assertStringContainsString( 'custom_cart', $block, 'Compiled bypass cookies are carried into the rules.' );
		self::assertStringContainsString( 'woocommerce_items_in_cart', $block, 'Store cookies bypass even before a store plugin is active.' );
		self::assertStringContainsString( 'RewriteCond %{HTTP_HOST} ^(example\.com)$', $block );
		self::assertStringContainsString( '"' . $root . '/%{HTTP_HOST}%{REQUEST_URI}index.html" -f', $block );
		// OpenLiteSpeed ignored IfModule and served copies past the bypasses; every
		// rule has to exclude LiteSpeed by name.
		self::assertSame( 3, substr_count( $block, 'RewriteCond %{SERVER_SOFTWARE} !LiteSpeed [NC]' ) );
		self::assertSame( 3, substr_count( $block, 'RewriteRule ^' ) );
		self::assertStringNotContainsString( 'IfModule !LiteSpeed', $block );
		self::assertMatchesRegularExpression( '#RewriteRule \^ "/[^"]*/static/%\{HTTP_HOST\}%\{REQUEST_URI\}index\.html" \[L\]#', $block );
	}

	public function test_the_block_goes_above_wordpress_and_comes_out_cleanly(): void {
		$htaccess  = $this->root . '/.htaccess';
		$wordpress = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";
		file_put_contents( $htaccess, $wordpress );
		$rules = new ServerRules( $htaccess );
		$block = "# BEGIN GT Performance\nRewriteRule ^ - [L]\n# END GT Performance\n";

		self::assertTrue( $rules->write( $block ) );
		$content = (string) file_get_contents( $htaccess );
		self::assertLessThan( strpos( $content, '# BEGIN WordPress' ), strpos( $content, '# BEGIN GT Performance' ), 'Below WordPress, index.php would take every request first.' );
		self::assertSame( $block, $rules->installed() );

		self::assertTrue( $rules->write( $block ) );
		self::assertSame( 1, substr_count( (string) file_get_contents( $htaccess ), '# BEGIN GT Performance' ), 'Rewriting replaces the block.' );

		self::assertTrue( $rules->remove() );
		self::assertSame( $wordpress, file_get_contents( $htaccess ) );
	}

	public function test_network_rules_keep_each_site_to_its_own_paths(): void {
		$sites = array();
		foreach ( array( 1 => '/', 4 => '/uranium/', 5 => '/uranium-fluentcart/', 6 => '/retired/' ) as $id => $path ) {
			$site          = new \WP_Site();
			$site->blog_id = (string) $id;
			$site->domain  = 'example.com';
			$site->path    = $path;
			$site->deleted = 6 === $id ? '1' : '0';
			$sites[ $id ]  = $site;
		}
		$GLOBALS['gtperf_test_is_multisite'] = true;
		$GLOBALS['gtperf_test_sites']        = $sites;
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'static' => true ) );
		foreach ( array( 1, 4, 5 ) as $id ) {
			$GLOBALS['gtperf_test_blog_id'] = $id;
			Settings::compile();
		}
		$GLOBALS['gtperf_test_blog_id'] = 1;

		$block = ( new ServerRules( $this->root . '/.htaccess' ) )->apacheBlock();
		$main  = substr( $block, (int) strpos( $block, '# Site 1:' ) );

		self::assertLessThan( strpos( $block, '# Site 1:' ), strpos( $block, '# Site 4:' ), 'The more specific site is tried first.' );
		self::assertStringContainsString( 'RewriteCond %{REQUEST_URI} ^/uranium/(?:', $block );
		foreach ( array( '/uranium/', '/uranium\-fluentcart/', '/retired/' ) as $child ) {
			self::assertStringContainsString( 'RewriteCond %{REQUEST_URI} !^' . $child . ' [NC]', $main, 'The main site never answers for ' . $child );
		}
		self::assertStringNotContainsString( '# Site 6:', $block, 'A suspended site gets no rules.' );
		self::assertStringContainsString( '/sites/4/static/', $block );

		foreach ( array( 1, 4, 5 ) as $id ) {
			$GLOBALS['gtperf_test_blog_id'] = $id;
			@unlink( Paths::config() );
			( new StaticStore() )->sync( false, 0 );
		}
		$GLOBALS['gtperf_test_blog_id'] = 1;
		@unlink( Paths::siteMap() );
	}

	public function test_the_nginx_snippet_ties_host_and_path_to_one_site(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = array( 'cache' => array( 'static' => true ) );
		Settings::compile();
		$snippet = ( new ServerRules( $this->root . '/.htaccess' ) )->nginxSnippet();

		self::assertStringContainsString( 'set $gtperf_key "$gtperf_scheme://$host$uri";', $snippet );
		self::assertStringContainsString( 'if ($gtperf_key ~ "^https://(example\.com)(/(?:', $snippet );
		self::assertStringContainsString( 'try_files $gtperf_file $uri $uri/ /index.php?$args;', $snippet );
		self::assertStringContainsString( 'if ($args != "")', $snippet );
		self::assertStringContainsString( 'gzip_static on;', $snippet );
	}
}
