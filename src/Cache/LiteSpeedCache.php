<?php
/**
 * LiteSpeed's own page cache, driven from WordPress.
 *
 * On LiteSpeed Web Server and OpenLiteSpeed the server can keep a response and
 * answer later requests for the URL without starting PHP. It does so only for a
 * response that says `X-LiteSpeed-Cache-Control: public`, and it forgets what an
 * `X-LiteSpeed-Purge` header names. This class sends those headers from the same
 * decisions the page cache makes: public for a response GT Performance would
 * store, no-cache for everything else, tagged so a purge reaches the same URLs a
 * GT purge does.
 *
 * What the server does not get from PHP, it gets from one cookie. LiteSpeed
 * looks a request up before WordPress runs, so it cannot know that a visitor is
 * signed in or has a cart. Rewrite rules that try to tell it (E=Cache-Control:
 * no-cache on a cookie match) were ignored by OpenLiteSpeed in testing: a signed-
 * in request got the public copy 30 times out of 30. The server does key every
 * entry on `_lscache_vary`, so any visitor holding a bypass cookie also gets that
 * cookie, and their requests miss the public copy every time (0 of 30). No
 * response to a request carrying it is ever marked public, so nothing is stored
 * under that key.
 *
 * Purge headers only work on a response the server sees. A purge in a web request
 * rides on that response; one from WP-CLI or a system cron is queued and sent by
 * a loopback request.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class LiteSpeedCache implements Module {
	public const VARY_COOKIE  = '_lscache_vary';
	public const QUEUE_OPTION = 'gt_performance_litespeed_purge_queue';
	public const TOKEN_OPTION = 'gt_performance_litespeed_purge_token';
	private const ACTION      = 'gtperf_ls_purge';

	/** Bound on queued tags; past it the whole site is purged instead. */
	private const QUEUE_LIMIT = 200;

	/** @var array<string, true> Tags to purge on this response. */
	private static array $pending = array();

	private static bool $emitted = false;

	/** @var list<string>|null */
	private static ?array $bypassCookies = null;

	/**
	 * Whether these settings drive LiteSpeed's cache. A separate mobile copy needs
	 * a User-Agent vary LiteSpeed cannot apply before PHP, so it turns this off.
	 *
	 * @param array<string, mixed>|null $settings Settings; the saved ones by default.
	 */
	public static function enabled( ?array $settings = null ): bool {
		$cache = (array) ( ( $settings ?? Settings::all() )['cache'] ?? array() );

		return (bool) ( $cache['litespeed'] ?? false )
			&& (bool) ( $cache['enabled'] ?? false )
			&& ! (bool) ( $cache['separate_mobile'] ?? false )
			&& ! DropinRuntime::servingDisabled()
			&& ! self::pluginOwnsCache();
	}

	/**
	 * Whether the LiteSpeed Cache plugin is running on this site. It drives the
	 * same headers, vary cookie, and purges, so two owners would contradict each
	 * other on every response; GT Performance steps aside until it is deactivated.
	 * xCloud's LiteSpeed Cache switch installs and activates it.
	 */
	public static function pluginOwnsCache(): bool {
		return defined( 'LSCWP_V' ) || class_exists( '\\LiteSpeed\\Core', false );
	}

	/**
	 * Tag prefix for this site. Every tag carries it, so a site-wide purge never
	 * reaches another site sharing the server, or another site of a network.
	 */
	public static function prefix(): string {
		return 'gtp' . substr( md5( (string) home_url( '/' ) ), 0, 8 );
	}

	/**
	 * The tag of one URL, without its query: a purge addresses a page, and every
	 * query variant of it changes with it. Mirrored by the drop-in.
	 */
	public static function urlTag( string $prefix, string $scheme, string $host, string $path ): string {
		return DropinRuntime::liteSpeedUrlTag( $prefix, $scheme, $host, $path );
	}

	/**
	 * Mark the response public for LiteSpeed. Never for a request carrying the
	 * vary cookie: that key is shared by every signed-in visitor and shopper.
	 */
	public static function sendPublic( int $maxAge, string $prefix, string $urlTag ): void {
		if ( ! headers_sent() ) {
			DropinRuntime::liteSpeedPublic( $maxAge, $prefix, $urlTag );
		}
	}

	public static function sendNoCache(): void {
		if ( self::$active && ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/** Whether this request registered the module, so its headers mean something. */
	private static bool $active = false;

	/**
	 * Mark a WordPress response public, from the request it answers.
	 */
	public static function sendPublicFor( RequestContext $request ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$prefix = self::prefix();
		self::sendPublic( (int) Settings::get( 'cache.fresh_ttl', 3600 ), $prefix, self::urlTag( $prefix, $request->scheme, $request->host, $request->path ) );
	}

	public function register(): void {
		self::$active = true;
		add_action( 'gt_performance_purged_urls', array( self::class, 'purgeUrls' ), 5, 1 );
		add_action( 'gt_performance_purged_all', array( self::class, 'purgeAll' ), 5, 0 );
		add_action( 'init', array( self::class, 'takeQueue' ), 1 );
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'endpoint' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( self::class, 'endpoint' ) );
		add_action( 'shutdown', array( self::class, 'persist' ), 1000 );
		add_action( 'init', array( self::class, 'syncOwner' ), 2 );
		// Runs just before PHP sends the headers, whatever produced the response:
		// a page, a redirect after a save, an AJAX add-to-cart. The CLI has no
		// response for it to run on; purges there go through the queue.
		if ( 'cli' !== PHP_SAPI ) {
			header_register_callback( array( self::class, 'beforeHeaders' ) );
			// PHP keeps one header callback per request, so a plugin registering its
			// own later replaces this one. The page, redirect, and JSON paths run it
			// again from WordPress hooks; it does its work once.
			add_action( 'send_headers', array( self::class, 'beforeHeaders' ), PHP_INT_MAX );
			add_filter( 'wp_redirect', array( self::class, 'beforeRedirect' ), PHP_INT_MAX );
			add_filter( 'wp_die_ajax_handler', array( self::class, 'beforeAjaxDie' ), PHP_INT_MAX );
		}
	}

	/** Option: who drives LiteSpeed's cache, as of the last compile. */
	private const OWNER_OPTION = 'gt_performance_litespeed_owner';

	/**
	 * The drop-in's configuration names LiteSpeed only while GT Performance owns
	 * the cache. When the LiteSpeed Cache plugin is switched on or off, compile
	 * again so drop-in hits follow; a compile made while it was active would
	 * otherwise leave every hit unmarked after it is gone.
	 */
	public static function syncOwner(): void {
		$owner = self::pluginOwnsCache() ? 'plugin' : 'gt';
		if ( get_option( self::OWNER_OPTION, '' ) !== $owner ) {
			Settings::compile();
			update_option( self::OWNER_OPTION, $owner, true );
		}
	}

	/**
	 * @param mixed $location Redirect target, passed through.
	 */
	public static function beforeRedirect( mixed $location ): mixed {
		self::beforeHeaders();

		return $location;
	}

	/**
	 * @param mixed $handler wp_die handler for AJAX, passed through.
	 */
	public static function beforeAjaxDie( mixed $handler ): mixed {
		self::beforeHeaders();

		return $handler;
	}

	/**
	 * @param mixed $urls Purged URLs, from the gt_performance_purged_urls action.
	 */
	public static function purgeUrls( mixed $urls ): void {
		$prefix = self::prefix();
		foreach ( is_array( $urls ) ? $urls : array() as $url ) {
			$request = is_string( $url ) ? RequestContext::fromUrl( $url ) : null;
			if ( null !== $request ) {
				self::$pending[ self::urlTag( $prefix, $request->scheme, $request->host, $request->path ) ] = true;
			}
		}
		self::dispatch();
	}

	/**
	 * Purge every page of the current site. Additive, so a network purge that
	 * switches through its sites keeps each site's tag.
	 */
	public static function purgeAll(): void {
		self::$pending[ self::prefix() ] = true;
		self::dispatch();
	}

	/**
	 * Send now when this response can still carry the header; otherwise queue.
	 */
	private static function dispatch(): void {
		if ( array() === self::$pending ) {
			return;
		}
		if ( ! self::$active || self::$emitted || headers_sent() || ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI ) {
			self::persist();
		}
	}

	/**
	 * Header callback: purge tags, and the vary cookie for visitors who must
	 * never be answered from the public copy.
	 */
	public static function beforeHeaders(): void {
		if ( headers_sent() ) {
			return;
		}
		self::$emitted = true;
		if ( array() !== self::$pending ) {
			header( 'X-LiteSpeed-Purge: ' . implode( ', ', array_map( static fn ( string $tag ): string => 'tag=' . $tag, array_keys( self::$pending ) ) ) );
			self::$pending = array();
		}
		if ( ! self::enabled() ) {
			return;
		}
		self::syncVaryCookie( headers_list() );
		// Default deny: a response nothing marked (admin, REST, feeds, errors) must
		// not be stored by a server configured to cache everything.
		foreach ( headers_list() as $line ) {
			if ( 0 === stripos( $line, 'x-litespeed-cache-control:' ) ) {
				return;
			}
		}
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/**
	 * @param list<string> $headers Response headers about to be sent.
	 */
	public static function syncVaryCookie( array $headers ): void {
		$needs = self::needsVary( array_keys( $_COOKIE ), $headers, self::bypassCookies() );
		$has   = isset( $_COOKIE[ self::VARY_COOKIE ] );
		if ( $needs === $has ) {
			return;
		}
		// Already sent on this response by an earlier pass.
		foreach ( $headers as $line ) {
			if ( 0 === stripos( $line, 'set-cookie: ' . self::VARY_COOKIE . '=' ) ) {
				return;
			}
		}
		$secure = function_exists( 'is_ssl' ) && is_ssl() ? '; Secure' : '';
		$domain = defined( 'COOKIE_DOMAIN' ) && '' !== (string) constant( 'COOKIE_DOMAIN' ) ? '; Domain=' . (string) constant( 'COOKIE_DOMAIN' ) : '';
		$life   = $needs ? '; Max-Age=' . 30 * DAY_IN_SECONDS : '; Max-Age=0';
		header( 'Set-Cookie: ' . self::VARY_COOKIE . '=' . ( $needs ? '1' : 'deleted' ) . '; Path=/' . $domain . $life . $secure . '; HttpOnly; SameSite=Lax', false );
	}

	/**
	 * Whether the visitor holds, or is being given, a cookie that bypasses the
	 * cache. A cookie being deleted does not count.
	 *
	 * @param list<int|string> $requestCookies Names of the request's cookies.
	 * @param list<string>     $headers        Response header lines.
	 * @param list<string>     $prefixes       Bypass cookie prefixes.
	 */
	public static function needsVary( array $requestCookies, array $headers, array $prefixes ): bool {
		$matches = static function ( string $name ) use ( $prefixes ): bool {
			foreach ( $prefixes as $prefix ) {
				if ( '' !== $prefix && str_starts_with( $name, $prefix ) ) {
					return true;
				}
			}
			return false;
		};

		$deleted = array();
		$set     = array();
		foreach ( $headers as $line ) {
			if ( 1 !== preg_match( '/^set-cookie:\s*([^=;\s]+)=([^;]*)(.*)$/i', $line, $parts ) ) {
				continue;
			}
			$gone = 'deleted' === $parts[2] || '' === $parts[2] || 1 === preg_match( '/;\s*max-age=0\b|;\s*expires=[^;]*19(?:70|99)/i', $parts[3] );
			if ( $gone ) {
				$deleted[ $parts[1] ] = true;
			} else {
				$set[ $parts[1] ] = true;
			}
		}

		foreach ( array_keys( $set ) as $name ) {
			if ( $matches( (string) $name ) ) {
				return true;
			}
		}
		foreach ( $requestCookies as $name ) {
			$name = (string) $name;
			if ( ! isset( $deleted[ $name ] ) && $matches( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Bypass cookie prefixes from the full policy, including commerce adapters.
	 *
	 * @return list<string>
	 */
	private static function bypassCookies(): array {
		if ( null === self::$bypassCookies ) {
			$policy              = (array) apply_filters( 'gt_performance_cache_policy', (array) Settings::get( 'cache', array() ) );
			self::$bypassCookies = array_values( array_filter( array_map( 'strval', (array) ( $policy['bypass_cookies'] ?? array() ) ) ) );
		}

		return self::$bypassCookies;
	}

	/**
	 * Purges queued by WP-CLI or cron ride on the next response the server sees.
	 */
	public static function takeQueue(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI ) {
			return;
		}
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || array() === $queue ) {
			return;
		}
		delete_option( self::QUEUE_OPTION );
		foreach ( $queue as $tag ) {
			if ( is_string( $tag ) && 1 === preg_match( '/^[a-z0-9_]+$/', $tag ) ) {
				self::$pending[ $tag ] = true;
			}
		}
	}

	/**
	 * Keep tags no response carried, then ask the server for a response that will.
	 */
	public static function persist(): void {
		if ( array() === self::$pending ) {
			return;
		}
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = array_values( array_unique( array_merge( is_array( $queue ) ? $queue : array(), array_keys( self::$pending ) ) ) );
		if ( count( $queue ) > self::QUEUE_LIMIT ) {
			$queue = array( self::prefix() );
		}
		self::$pending = array();
		update_option( self::QUEUE_OPTION, $queue, true );

		$token = (string) get_option( self::TOKEN_OPTION, '' );
		if ( '' === $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::TOKEN_OPTION, $token, false );
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking'  => false,
				'timeout'   => 1,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter for loopback requests, as wp-cron uses it.
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => self::ACTION,
					'token'  => $token,
				),
			)
		);
	}

	/**
	 * The loopback target: a response that carries the queued purge.
	 */
	public static function endpoint(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated by the stored token; a nonce cannot survive a loopback from WP-CLI.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		$known = (string) get_option( self::TOKEN_OPTION, '' );
		self::sendNoCache();
		if ( '' === $known || ! hash_equals( $known, $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::takeQueue();
		wp_die( 'ok', '', array( 'response' => 200 ) );
	}
}
