<?php
/**
 * Web-server rules that answer cache hits without starting PHP.
 *
 * Apache and LiteSpeed get a marked block at the top of the site's .htaccess,
 * ahead of WordPress's own rules; Nginx gets a snippet to paste, because nothing
 * can reload Nginx from PHP. Rules exist only while a site owner has chosen them
 * (one option per install, or per network), they are regenerated whenever a
 * site's compiled configuration changes, and they are removed on deactivation.
 *
 * The rules mirror the drop-in's eligibility where a rule can, and serve nothing
 * where it cannot: GET or HEAD only, no query string, no Authorization header,
 * no preload request (those must reach PHP to rebuild), the site's own scheme and
 * hosts, no bypass cookie, and only a file StaticStore wrote. A request any rule
 * cannot decide falls through to WordPress and the PHP drop-in as before.
 *
 * LiteSpeed is excluded on purpose. On OpenLiteSpeed (gatilab.com, 2026-10-08)
 * these rules served the stored copy to requests carrying a login cookie or a
 * query string in 23 to 49 of 60 tries, depending on the rule shape, while
 * Apache honored every condition; OLS also ignores <IfModule !LiteSpeed> and
 * sends a .br or .gz file without Content-Encoding. Every rule therefore checks
 * that the server is not LiteSpeed, which OLS does evaluate. LiteSpeed sites get
 * the PHP drop-in, which sends the stored compressed copy itself.
 *
 * Rules are built from each site's compiled configuration file, never from the
 * live settings of whichever site is compiling, so a network site's block always
 * carries that site's own bypass cookies.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\AtomicFile;
use GTPerformance\Core\Network;
use GTPerformance\Core\Paths;

final class ServerRules {
	/** Install-wide (network-wide on multisite): whether the owner chose server rules. */
	public const OPTION = 'gt_performance_server_rules';

	/** The last failure to write the rules, for the admin screen. */
	public const ERROR_OPTION = 'gt_performance_server_rules_error';

	private const BEGIN = '# BEGIN GT Performance';
	private const END   = '# END GT Performance';

	/**
	 * Cookies that always skip the rules, whatever is active today. A rule written
	 * before a store plugin is switched on would otherwise serve its cart pages to
	 * a shopper until the rules are next regenerated; on OpenLiteSpeed that waits
	 * for a server restart.
	 */
	private const ALWAYS_BYPASS = array(
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wp-postpass_',
		'comment_author_',
		'wordpress_no_cache',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'edd_items_in_cart',
		'edd_session_',
		'fct_',
	);

	/**
	 * @param string|null $htaccess The .htaccess to manage; defaults to the site's own.
	 */
	public function __construct( private readonly ?string $htaccess = null ) {
	}

	public static function enabled(): bool {
		return 'on' === Network::getOption( self::OPTION, '' );
	}

	/**
	 * The web server answering this request: apache, litespeed, nginx, or unknown.
	 */
	public static function server( ?string $software = null ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against fixed names only.
		$software = strtolower( $software ?? (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
		foreach ( array( 'litespeed', 'apache', 'nginx' ) as $name ) {
			if ( str_contains( $software, $name ) ) {
				return $name;
			}
		}

		return 'unknown';
	}

	/**
	 * Turn the rules on. Callers check that the user may change server files.
	 */
	public static function enable(): bool|\WP_Error {
		Network::updateOption( self::OPTION, 'on' );

		return self::sync();
	}

	public static function disable(): bool|\WP_Error {
		Network::deleteOption( self::OPTION );
		Network::deleteOption( self::ERROR_OPTION );

		return ( new self() )->remove();
	}

	/**
	 * Bring the installed block in line with the compiled configurations.
	 *
	 * When the rules cannot be rewritten, the ones in place may still name an old
	 * set of bypass cookies, so this site's copies are deleted: with no file to
	 * find, every rule falls through to PHP.
	 */
	public static function sync(): bool|\WP_Error {
		if ( ! self::enabled() ) {
			return true;
		}
		$rules  = new self();
		$result = $rules->write( $rules->apacheBlock() );
		if ( is_wp_error( $result ) ) {
			( new StaticStore() )->purgeAll();
			Network::updateOption( self::ERROR_OPTION, $result->get_error_message() );
			return $result;
		}
		Network::deleteOption( self::ERROR_OPTION );

		return true;
	}

	public function htaccessPath(): string {
		return $this->htaccess ?? $this->basePath() . '.htaccess';
	}

	/**
	 * Filesystem directory the .htaccess lives in, with a trailing slash.
	 */
	public function basePath(): string {
		if ( ! function_exists( 'get_home_path' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			return rtrim( ABSPATH, '/\\' ) . '/';
		}
		if ( is_multisite() && ! is_main_site() ) {
			switch_to_blog( (int) get_main_site_id() );
			try {
				return rtrim( get_home_path(), '/\\' ) . '/';
			} finally {
				restore_current_blog();
			}
		}

		return rtrim( get_home_path(), '/\\' ) . '/';
	}

	/**
	 * URL path the .htaccess directory answers under, with a trailing slash.
	 */
	public function baseUrlPath(): string {
		$path = is_multisite() ? (string) get_network()->path : (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return '/' . trim( $path, '/' ) . ( '' === trim( $path, '/' ) ? '' : '/' );
	}

	/**
	 * One entry per site whose compiled configuration asks for static copies.
	 *
	 * @return list<array{id:int,hosts:list<string>,scheme:string,path:string,cookies:list<string>,mobile:bool,root:string,children:list<string>}>
	 */
	public function sites(): array {
		if ( ! is_multisite() ) {
			$site = self::entry( 0, $this->configs()[0] ?? null );
			return null === $site ? array() : array( $site );
		}

		$map     = $this->siteMapData();
		$known   = is_array( $map['sites'] ?? null ) ? $map['sites'] : array();
		$configs = $this->configs();
		$sites = array();
		foreach ( $known as $site ) {
			if ( ! is_array( $site ) || true !== ( $site['active'] ?? false ) ) {
				continue;
			}
			$entry = self::entry( (int) $site['id'], $configs[ (int) $site['id'] ] ?? null );
			if ( null === $entry ) {
				continue;
			}
			// A site below this one on the same domain owns its own paths, even when
			// it is suspended or keeps no static copies.
			foreach ( $known as $other ) {
				$path = strtolower( (string) ( $other['path'] ?? '' ) );
				if ( is_array( $other ) && (int) $other['id'] !== $entry['id'] && strtolower( (string) $other['domain'] ) === strtolower( (string) $site['domain'] )
					&& strlen( $path ) > strlen( $entry['path'] ) && str_starts_with( $path, $entry['path'] ) ) {
					$entry['children'][] = $path;
				}
			}
			$sites[] = $entry;
		}

		// Most specific first, so the longest site path is tried before its parent.
		usort( $sites, static fn ( array $a, array $b ): int => strlen( $b['path'] ) <=> strlen( $a['path'] ) );

		return $sites;
	}

	/**
	 * @param array<string, mixed>|null $config Compiled configuration.
	 * @return array{id:int,hosts:list<string>,scheme:string,path:string,cookies:list<string>,mobile:bool,root:string,children:list<string>}|null
	 */
	private static function entry( int $id, ?array $config ): ?array {
		$static = is_array( $config['static'] ?? null ) ? $config['static'] : null;
		$cache  = is_array( $config['cache'] ?? null ) ? $config['cache'] : array();
		if ( null === $static || true !== ( $cache['enabled'] ?? false ) ) {
			return null;
		}
		$hosts = array_values( array_filter( array_map( 'strval', (array) ( $cache['hosts'] ?? array() ) ), static fn ( string $host ): bool => 1 === preg_match( '/^[a-z0-9.-]+$/', $host ) ) );
		$root  = (string) ( $static['root'] ?? '' );
		if ( array() === $hosts || '' === $root ) {
			return null;
		}
		$cookies = array_values( array_unique( array_merge( self::ALWAYS_BYPASS, array_filter( array_map( 'strval', (array) ( $cache['bypass_cookies'] ?? array() ) ) ) ) ) );

		return array(
			'id'      => $id,
			'hosts'   => $hosts,
			'scheme'  => 'http' === ( $static['scheme'] ?? 'https' ) ? 'http' : 'https',
			'path'    => '/' . ltrim( strtolower( rtrim( (string) ( $cache['site_path'] ?? '/' ), '/' ) . '/' ), '/' ),
			'cookies' => $cookies,
			'mobile'  => (bool) ( $cache['separate_mobile'] ?? false ),
			'root'    => rtrim( $root, '/\\' ),
			'children' => array(),
		);
	}

	/**
	 * The .htaccess block, or '' when no site keeps static copies.
	 */
	public function apacheBlock(): string {
		$base    = realpath( $this->basePath() );
		$baseUrl = $this->baseUrlPath();
		$lines   = array();
		foreach ( $this->sites() as $site ) {
			$root = realpath( $site['root'] );
			// The rewrite target is a URL path, so the store must sit below the
			// directory this .htaccess serves.
			if ( false === $base || false === $root || ! str_starts_with( $root, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			$url  = $baseUrl . str_replace( DIRECTORY_SEPARATOR, '/', substr( $root, strlen( rtrim( $base, '/\\' ) ) + 1 ) );
			$file = '%{HTTP_HOST}%{REQUEST_URI}index.html';

			$conditions = $this->conditions( $site );
			$lines[]    = '# Site ' . $site['id'] . ': ' . $site['scheme'] . '://' . $site['hosts'][0] . $site['path'];
			// The stored compressed copy first, then the plain page.
			foreach ( array(
				'br' => 'br',
				'gzip' => 'gz',
			) as $token => $extension ) {
				array_push( $lines, ...$conditions );
				$lines[] = 'RewriteCond %{HTTP:Accept-Encoding} ' . $token . ' [NC]';
				$lines[] = 'RewriteCond "' . $root . '/' . $file . '.' . $extension . '" -f';
				$lines[] = 'RewriteCond "' . $root . '/' . $file . '" -f';
				$lines[] = 'RewriteRule ^ "' . $url . '/' . $file . '.' . $extension . '" [L]';
			}
			array_push( $lines, ...$conditions );
			$lines[] = 'RewriteCond "' . $root . '/' . $file . '" -f';
			$lines[] = 'RewriteRule ^ "' . $url . '/' . $file . '" [L]';
		}

		// LiteSpeed's own cache: the server looks pages up itself, and the vary cookie,
		// not a rewrite condition, keeps signed-in visitors and shoppers out.
		$lookup = '';
		if ( $this->liteSpeedSites() > 0 ) {
			// Parameters every such site ignores leave LiteSpeed's key too, so a
			// campaign link is answered from the page's one stored copy, as the
			// drop-in answers it. OpenLiteSpeed reads these at startup.
			$trim   = array_map( static fn ( string $parameter ): string => 'CacheKeyModify -qs:' . $parameter, $this->liteSpeedIgnoredParameters() );
			$lookup = implode( "\n", array_merge( array( '<IfModule LiteSpeed>', 'CacheLookup on' ), $trim, array( '</IfModule>' ) ) ) . "\n";
		}
		if ( array() === $lines && '' === $lookup ) {
			return '';
		}
		$rewrite = array() === $lines ? '' : "<IfModule mod_rewrite.c>\nRewriteEngine On\n" . implode( "\n", $lines ) . "\n</IfModule>\n";

		return self::BEGIN . "\n"
			. "# Written by GT Performance from each site's settings; edits are\n"
			. "# overwritten. Removed when the plugin is deactivated.\n"
			. $lookup
			. $rewrite
			. self::END . "\n";
	}

	/**
	 * How many sites ask LiteSpeed to cache their pages.
	 */
	public function liteSpeedSites(): int {
		return count( $this->liteSpeedConfigs() );
	}

	/**
	 * Query parameters every LiteSpeed-cached site ignores. One .htaccess serves
	 * the whole network, so a parameter only one site ignores stays in the key.
	 *
	 * @return list<string>
	 */
	public function liteSpeedIgnoredParameters(): array {
		$common = null;
		foreach ( $this->liteSpeedConfigs() as $config ) {
			$ignored = array_values(
				array_filter(
					array_map( 'strval', (array) ( $config['cache']['ignored_query_params'] ?? array() ) ),
					static fn ( string $parameter ): bool => 1 === preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $parameter )
				)
			);
			$common = null === $common ? $ignored : array_values( array_intersect( $common, $ignored ) );
		}

		$common = array_values( array_unique( $common ?? array() ) );
		sort( $common );

		return $common;
	}

	/**
	 * Compiled configurations of the sites that hand pages to LiteSpeed.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function liteSpeedConfigs(): array {
		return array_values( array_filter( $this->configs(), static fn ( array $config ): bool => is_array( $config['litespeed'] ?? null ) ) );
	}

	/** @var array<int, array<string, mixed>>|null */
	private ?array $configs = null;

	/** @var array<string, mixed>|null|false */
	private array|null|false $map = false;

	/**
	 * Compiled configuration of every active site, read and decrypted once per
	 * rules build: keyed by blog id on a network, [0] on a single site.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function configs(): array {
		if ( null !== $this->configs ) {
			return $this->configs;
		}
		$this->configs = array();
		if ( ! is_multisite() ) {
			$config = ConfigFile::read( Paths::config() );
			if ( null !== $config ) {
				$this->configs[0] = $config;
			}
			return $this->configs;
		}
		$map = $this->siteMapData();
		foreach ( is_array( $map['sites'] ?? null ) ? $map['sites'] : array() as $site ) {
			if ( is_array( $site ) && true === ( $site['active'] ?? false ) ) {
				$config = ConfigFile::read( Paths::siteRootFor( (int) $site['id'] ) . '/config.json' );
				if ( null !== $config ) {
					$this->configs[ (int) $site['id'] ] = $config;
				}
			}
		}

		return $this->configs;
	}

	/** @return array<string, mixed>|null */
	private function siteMapData(): ?array {
		if ( false === $this->map ) {
			$this->map = ConfigFile::read( Paths::siteMap() );
		}

		return $this->map;
	}

	/**
	 * @param array{id:int,hosts:list<string>,scheme:string,path:string,cookies:list<string>,mobile:bool,root:string,children:list<string>} $site Site entry.
	 * @return list<string>
	 */
	private function conditions( array $site ): array {
		$quote = static fn ( string $value ): string => preg_quote( $value, '#' );
		$lines = array(
			// See the class comment: LiteSpeed does not honor the conditions below.
			'RewriteCond %{SERVER_SOFTWARE} !LiteSpeed [NC]',
			'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$',
			'RewriteCond %{QUERY_STRING} ^$',
			'RewriteCond %{HTTP:Authorization} ^$',
			'RewriteCond %{HTTP:X-GT-Preload} ^$',
			'RewriteCond %{HTTP_COOKIE} !(' . implode( '|', array_map( $quote, $site['cookies'] ) ) . ') [NC]',
		);
		if ( 'https' === $site['scheme'] ) {
			$lines[] = 'RewriteCond %{HTTPS} =on [OR]';
			$lines[] = 'RewriteCond %{HTTP:X-Forwarded-Proto} =https [NC]';
		} else {
			$lines[] = 'RewriteCond %{HTTPS} !=on';
			$lines[] = 'RewriteCond %{HTTP:X-Forwarded-Proto} !=https [NC]';
		}
		$lines[] = 'RewriteCond %{HTTP_HOST} ^(' . implode( '|', array_map( $quote, $site['hosts'] ) ) . ')$';
		// The same character set StaticStore::PATH_PATTERN writes, below this site.
		$lines[] = 'RewriteCond %{REQUEST_URI} ^' . $quote( $site['path'] ) . '(?:[A-Za-z0-9_~-][A-Za-z0-9_.~-]*/)*$';
		foreach ( $site['children'] as $child ) {
			$lines[] = 'RewriteCond %{REQUEST_URI} !^' . $quote( $child ) . ' [NC]';
		}
		if ( $site['mobile'] ) {
			$lines[] = 'RewriteCond %{HTTP_USER_AGENT} !(' . CacheKey::MOBILE_AGENTS . ') [NC]';
		}

		return $lines;
	}

	/**
	 * An Nginx snippet for the server {} block, for the owner to paste and reload.
	 */
	public function nginxSnippet(): string {
		$base    = realpath( $this->basePath() );
		$baseUrl = $this->baseUrlPath();
		$sites   = $this->sites();
		if ( array() === $sites || false === $base ) {
			return '';
		}

		$none    = 'set $gtperf_file "/gtperf-no-file";';
		$cookies = array();
		$mobile  = false;
		$lines   = array(
			'# GT Performance: serve stored pages without PHP.',
			'# Paste inside the server {} block, replace the try_files line of your',
			'# "location / { ... }" with the one below, and reload Nginx.',
			'# gzip_static needs ngx_http_gzip_static_module; brotli_static needs ngx_brotli.',
			$none,
			// Behind a proxy that terminates TLS, as the drop-in and the Apache rules do.
			'set $gtperf_scheme $scheme;',
			'if ($http_x_forwarded_proto = "https") { set $gtperf_scheme https; }',
			'set $gtperf_key "$gtperf_scheme://$host$uri";',
		);
		// Least specific first: a later match overrides, so the longest site path wins.
		foreach ( array_reverse( $sites ) as $site ) {
			$root = realpath( $site['root'] );
			if ( false === $root || ! str_starts_with( $root, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			$url     = $baseUrl . str_replace( DIRECTORY_SEPARATOR, '/', substr( $root, strlen( rtrim( $base, '/\\' ) ) + 1 ) );
			$hosts   = implode( '|', array_map( static fn ( string $host ): string => preg_quote( $host, '#' ), $site['hosts'] ) );
			$cookies = array_merge( $cookies, $site['cookies'] );
			$mobile  = $mobile || $site['mobile'];
			$lines[] = '# Site ' . $site['id'];
			$lines[] = 'if ($gtperf_key ~ "^' . $site['scheme'] . '://(' . $hosts . ')(' . preg_quote( $site['path'], '#' ) . '(?:[A-Za-z0-9_~-][A-Za-z0-9_.~-]*/)*)$") { set $gtperf_file "' . $url . '/$1$2index.html"; }';
			foreach ( $site['children'] as $child ) {
				$lines[] = 'if ($gtperf_key ~* "^' . $site['scheme'] . '://(' . $hosts . ')' . preg_quote( $child, '#' ) . '") { ' . $none . ' }';
			}
		}
		if ( $mobile ) {
			$lines[] = 'if ($http_user_agent ~* "(' . CacheKey::MOBILE_AGENTS . ')") { ' . $none . ' }';
		}
		$cookies = array_values( array_unique( $cookies ) );
		array_push(
			$lines,
			'if ($request_method !~ ^(GET|HEAD)$) { ' . $none . ' }',
			'if ($args != "") { ' . $none . ' }',
			'if ($http_authorization != "") { ' . $none . ' }',
			'if ($http_x_gt_preload != "") { ' . $none . ' }',
			'if ($http_cookie ~* "(' . implode( '|', array_map( static fn ( string $cookie ): string => preg_quote( $cookie, '#' ), $cookies ) ) . ')") { ' . $none . ' }',
			'',
			'location / {',
			'    gzip_static on;',
			'    # brotli_static on;',
			'    try_files $gtperf_file $uri $uri/ /index.php?$args;',
			'}'
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The block currently in .htaccess, or '' when there is none.
	 */
	public function installed(): string {
		$path    = $this->htaccessPath();
		$content = is_file( $path ) ? (string) file_get_contents( $path ) : '';

		return 1 === preg_match( '/' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '\n?/s', $content, $match ) ? $match[0] : '';
	}

	/**
	 * Put a block at the top of .htaccess, replacing any earlier one. An empty
	 * block removes it. WordPress's insert_with_markers() would append after the
	 * WordPress block, where the front controller has already taken the request.
	 */
	public function write( string $block ): bool|\WP_Error {
		$path    = $this->htaccessPath();
		if ( is_link( $path ) ) {
			return new \WP_Error( 'gtperf_htaccess_link', __( '.htaccess is a symbolic link, so GT Performance leaves it alone.', 'gt-performance' ) );
		}
		$exists  = is_file( $path );
		$content = $exists ? file_get_contents( $path ) : '';
		if ( ! is_string( $content ) ) {
			return new \WP_Error( 'gtperf_htaccess_read', __( 'GT Performance could not read .htaccess.', 'gt-performance' ) );
		}
		$without = (string) preg_replace( '/' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '\n*/s', '', $content );
		$updated = '' === $block ? $without : $block . "\n" . ltrim( $without, "\n" );
		if ( $updated === $content || ( ! $exists && '' === $block ) ) {
			return true;
		}
		if ( ( $exists && ! wp_is_writable( $path ) ) || ( ! $exists && ! wp_is_writable( dirname( $path ) ) ) ) {
			return new \WP_Error( 'gtperf_htaccess_write', __( '.htaccess is not writable, so the server rules could not be updated.', 'gt-performance' ) );
		}
		$mode = $exists ? ( fileperms( $path ) & 0777 ) : 0644;
		if ( ! AtomicFile::write( $path, $updated, $mode ) ) {
			return new \WP_Error( 'gtperf_htaccess_write', __( '.htaccess could not be updated. The previous file was preserved.', 'gt-performance' ) );
		}

		return true;
	}

	public function remove(): bool|\WP_Error {
		return '' === $this->installed() ? true : $this->write( '' );
	}
}
