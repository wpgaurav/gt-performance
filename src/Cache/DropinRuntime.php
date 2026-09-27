<?php
/**
 * Dependency-free early cache runtime loaded by advanced-cache.php.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class DropinRuntime {
	/**
	 * The only request headers that change a cache decision. Collecting just
	 * these keeps the drop-in cheap and keeps untrusted header values out of
	 * the context entirely.
	 */
	private const ELIGIBILITY_HEADERS = array(
		'HTTP_AUTHORIZATION'             => 'authorization',
		'HTTP_X_GT_PRELOAD'              => 'x-gt-preload',
	);

	/**
	 * Response headers a stored page keeps for its hits, lower-cased.
	 *
	 * A hit is sent by this drop-in, not by the code that set these headers, so
	 * without replay a cached page silently loses its CSP, HSTS, framing policy,
	 * and a noindex X-Robots-Tag. Caching and transport headers stay out: this
	 * drop-in sets those itself, and Set-Cookie never reaches a cacheable page.
	 */
	private const REPLAY_HEADERS = array(
		'content-security-policy',
		'content-security-policy-report-only',
		'strict-transport-security',
		'x-frame-options',
		'x-content-type-options',
		'referrer-policy',
		'permissions-policy',
		'cross-origin-opener-policy',
		'cross-origin-embedder-policy',
		'cross-origin-resource-policy',
		'x-robots-tag',
		'link',
		'content-language',
	);

	private const REPLAY_LIMIT = 20;

	private const REPLAY_LINE_BYTES = 8192;

	/**
	 * How long a preload token stays valid. Preload requests are sent straight
	 * after the token is made, so this only has to cover a slow loopback.
	 */
	private const PRELOAD_TOKEN_TTL = 600;

	public static function serve( string $configFile, string $pagesRoot ): void {
		// Safe mode promises that no page is served from the cache. WordPress
		// reports SAFE-MODE on the response once it loads.
		if ( self::servingDisabled() ) {
			return;
		}

		// realpath accepts filesystem paths only, never HTTP or stream-wrapper URLs.
		$localRoot = realpath( $pagesRoot );
		if ( false === $localRoot || ! is_dir( $localRoot ) ) {
			return;
		}
		$pagesRoot = $localRoot;
		$config = ConfigFile::read( $configFile );
		if ( null === $config || ! isset( $config['cache'] ) || ! is_array( $config['cache'] ) ) {
			return;
		}

		$request  = self::request();
		$decision = ( new Eligibility() )->decide( $request, $config['cache'] );
		if ( ! $decision->cacheable ) {
			if ( (bool) ( $config['debug'] ?? false ) ) {
				header( 'X-GT-Cache: BYPASS' );
				header( 'X-GT-Cache-Reason: ' . self::reasonHeader( $decision->reason ) );
			}
			return;
		}

		$cacheConfig               = $config['cache'];
		$cacheConfig['generation'] = $config['generation'] ?? 1;
		$hash                      = ( new CacheKey() )->hash( ( new CacheKey() )->make( $request, $cacheConfig ) );
		$directory                 = rtrim( $pagesRoot, '/\\' ) . '/' . substr( $hash, 0, 2 );
		$page                      = $directory . '/' . $hash . '.html';
		$metaFile                  = $directory . '/' . $hash . '.meta.json';

		clearstatcache( true, $page );
		clearstatcache( true, $metaFile );
		if ( ! is_readable( $page ) || ! is_readable( $metaFile ) ) {
			header( 'X-GT-Cache: MISS' );
			return;
		}

		// Reads are intentionally non-fatal because another worker may purge
		// between the stat and the read. Metadata is inert JSON, never executed.
		$rawMeta = self::readLocalCacheFile( $metaFile, $pagesRoot );
		$meta    = is_string( $rawMeta ) ? json_decode( $rawMeta, true ) : null;
		if ( ! is_array( $meta ) ) {
			header( 'X-GT-Cache: MISS' );
			return;
		}

		$html = self::readLocalCacheFile( $page, $pagesRoot );
		if ( ! is_string( $html ) ) {
			header( 'X-GT-Cache: MISS' );
			return;
		}

		$stored  = (int) ( $meta['stored_at'] ?? 0 );
		$fresh   = (int) ( $meta['fresh_until'] ?? 0 );
		$stale   = (int) ( $meta['stale_until'] ?? 0 );
		$now     = time();
		$isStale = $now > $fresh;

		if ( $now > $stale || $stored <= 0 ) {
			header( 'X-GT-Cache: EXPIRED' );
			return;
		}

		if ( self::shouldRevalidate( $isStale, $request->headers ) ) {
			header( 'X-GT-Cache: REVALIDATE' );
			return;
		}

		$etag = '"' . hash( 'sha256', $html ) . '"';
		// WordPress is not loaded here, so the shared RequestContext helper does
		// the sanitizing that wp_unslash()/sanitize_text_field() would.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on the same line; wp_unslash() does not exist yet.
		$ifNoneMatch = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( RequestContext::sanitizeValue( (string) $_SERVER['HTTP_IF_NONE_MATCH'], 256 ) ) : '';
		if ( hash_equals( $etag, $ifNoneMatch ) ) {
			http_response_code( 304 );
			header( 'ETag: ' . $etag );
			header( 'X-GT-Cache: ' . ( $isStale ? 'STALE' : 'HIT' ) );
			exit;
		}

		$browserTtl = max( 0, (int) ( $cacheConfig['browser_ttl'] ?? 300 ) );
		$staleTtl   = max( 0, (int) ( $cacheConfig['stale_ttl'] ?? 0 ) );
		$ifErrorTtl = max( 0, (int) ( $cacheConfig['stale_if_error'] ?? 0 ) );

		$cacheControl = 'public, max-age=' . $browserTtl . ', s-maxage=' . max( 0, $fresh - $stored ) . ', stale-while-revalidate=' . $staleTtl;
		if ( $ifErrorTtl > 0 ) {
			$cacheControl .= ', stale-if-error=' . $ifErrorTtl;
		}

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: ' . $cacheControl );
		header( 'Age: ' . max( 0, $now - $stored ) );
		header( 'ETag: ' . $etag );
		header( 'Vary: ' . ( (bool) ( $cacheConfig['separate_mobile'] ?? false ) ? 'Accept-Encoding, User-Agent' : 'Accept-Encoding' ) );
		header( 'X-GT-Cache: ' . ( $isStale ? 'STALE' : 'HIT' ) );
		// A cache-key fingerprint on every public response tells an attacker when two
		// requests collide, which is the reconnaissance step for a poisoning attempt.
		// It is a debugging aid, so gate it like one.
		if ( ! empty( $config['debug'] ) ) {
			header( 'X-GT-Cache-Key: ' . substr( $hash, 0, 12 ) );
		}
		// Re-checked here as well as at capture: the metadata file is only data.
		foreach ( self::replayableHeaders( is_array( $meta['headers'] ?? null ) ? $meta['headers'] : array() ) as $line ) {
			header( $line, false );
		}

		if ( 'HEAD' !== $request->method ) {
			// Replay the validated complete response through an output handler.
			// Escaping it as a fragment would strip the site's scripts/forms/SVG.
			ob_start( static fn( string $buffer ): string => $html );
			ob_end_flush();
		}
		exit;
	}

	/**
	 * Read only a regular local file contained in the resolved page-cache root.
	 *
	 * These are cached HTML and JSON on disk, not remote requests. The HTTP API
	 * cannot read them and is not loaded yet in advanced-cache.php. Network fetches
	 * elsewhere in the plugin use the WordPress HTTP API.
	 */
	private static function readLocalCacheFile( string $path, string $root ): ?string {
		$local = realpath( $path );
		if ( false === $local || ! str_starts_with( $local, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) || ! is_file( $local ) || ! is_readable( $local ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validated local cache file; before WordPress loads; concurrent purges can remove it.
		$content = @file_get_contents( $local );
		return is_string( $content ) ? $content : null;
	}

	/**
	 * A bypass reason made safe for a response header, identical in the drop-in
	 * and in WordPress so both report `path:/cart/` the same way. Part of a reason
	 * can come from the request (an unknown query parameter's name), so only a
	 * narrow character set survives.
	 */
	public static function reasonHeader( string $reason ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_:\/.*-]/', '', $reason ), 0, 200 );
	}

	/**
	 * Whether GTPERF_SAFE_MODE forbids serving anything from the page cache.
	 */
	public static function servingDisabled(): bool {
		return defined( 'GTPERF_SAFE_MODE' ) && (bool) constant( 'GTPERF_SAFE_MODE' );
	}

	/**
	 * The response header lines a stored page replays on each hit.
	 *
	 * Used at capture time on headers_list() and again when serving, on the
	 * stored copy. Only allowlisted names survive, and a line carrying CR, LF,
	 * or NUL is dropped whole so nothing can be smuggled into the response.
	 *
	 * @param array<mixed> $lines "Name: value" header lines.
	 * @return list<string>
	 */
	public static function replayableHeaders( array $lines ): array {
		$replay = array();
		foreach ( $lines as $line ) {
			if ( ! is_string( $line ) || strlen( $line ) > self::REPLAY_LINE_BYTES ) {
				continue;
			}
			if ( 1 !== preg_match( '/^([A-Za-z0-9-]+):[ \t]*([^\r\n\0]*)\z/', $line, $parts ) ) {
				continue;
			}
			$value = trim( $parts[2] );
			if ( '' === $value || ! in_array( strtolower( $parts[1] ), self::REPLAY_HEADERS, true ) ) {
				continue;
			}
			$replay[] = $parts[1] . ': ' . $value;
			if ( count( $replay ) >= self::REPLAY_LIMIT ) {
				break;
			}
		}

		return $replay;
	}

	/**
	 * A short-lived token that lets the plugin's own preload requests rebuild
	 * a stale page. Empty when the site has no usable AUTH_KEY, in which case the
	 * drop-in cannot decrypt its configuration and never runs anyway.
	 */
	public static function preloadToken( int $now ): string {
		$key = self::preloadKey();
		if ( '' === $key ) {
			return '';
		}
		$expires = (string) ( $now + self::PRELOAD_TOKEN_TTL );

		return $expires . '.' . hash_hmac( 'sha256', $expires, $key );
	}

	/**
	 * Whether a cached entry should be rebuilt instead of served.
	 *
	 * A preload request exists to refresh content, so handing it the stale copy
	 * makes it a no-op, which is why stale pages never recovered on their own.
	 * Returning true here falls through to WordPress, and
	 * PageCacheModule::capture() stores the new entry.
	 *
	 * Only a valid preload token counts. Any client can send the header, and an
	 * unauthenticated value would let anyone turn every stale page into a full
	 * WordPress render. Fresh entries are still served from cache, so preloading
	 * current content stays cheap and a burst of preload jobs cannot stampede
	 * the origin.
	 *
	 * @param array<string, string> $headers Request headers, lower-cased keys.
	 */
	public static function shouldRevalidate( bool $isStale, array $headers, ?int $now = null ): bool {
		if ( ! $isStale ) {
			return false;
		}

		return self::validPreloadToken( trim( (string) ( $headers['x-gt-preload'] ?? '' ) ), $now ?? time() );
	}

	private static function validPreloadToken( string $token, int $now ): bool {
		$key = self::preloadKey();
		if ( '' === $key || 1 !== preg_match( '/^(\d{1,12})\.([a-f0-9]{64})\z/', $token, $parts ) ) {
			return false;
		}
		$expires = (int) $parts[1];
		if ( $expires < $now || $expires > $now + self::PRELOAD_TOKEN_TTL ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $parts[1], $key ), $parts[2] );
	}

	/**
	 * Derived from AUTH_KEY the same way ConfigFile derives the runtime key, with
	 * its own label, because the drop-in has no database to hold a shared secret.
	 */
	private static function preloadKey(): string {
		if ( ! defined( 'AUTH_KEY' ) ) {
			return '';
		}
		$authKey = (string) constant( 'AUTH_KEY' );
		if ( strlen( $authKey ) < 16 || 'put your unique phrase here' === $authKey ) {
			return '';
		}

		return hash( 'sha256', 'gt-performance-preload-v1|' . $authKey, true );
	}

	/**
	 * Build the request context before WordPress loads.
	 *
	 * Every untrusted value goes through the same RequestContext helpers that
	 * RequestContext::fromGlobals() uses once WordPress is available. The two
	 * must stay byte-identical: a divergence would make cache keys miss forever,
	 * or let this drop-in reach a different bypass decision than WordPress and
	 * serve a cached page to a signed-in visitor. WordPress has not yet added
	 * slashes at this point, so nothing is unslashed here.
	 */
	private static function request(): RequestContext {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized below through the shared RequestContext helpers; wp_unslash() does not exist yet.
		$server = array_filter( $_SERVER, 'is_scalar' );
		$https  = isset( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] );
		$proto  = isset( $server['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( RequestContext::sanitizeValue( (string) $server['HTTP_X_FORWARDED_PROTO'] ) ) : '';
		$scheme = $https || 'https' === $proto ? 'https' : 'http';
		$uri    = RequestContext::sanitizeValue( (string) ( $server['REQUEST_URI'] ?? '/' ), 2048 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs inside advanced-cache.php before wp_parse_url() exists.
		$parsedPath = parse_url( $uri, PHP_URL_PATH );
		$path       = false === $parsedPath || null === $parsedPath ? '/' : (string) $parsedPath;

		$query = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs inside advanced-cache.php before wp_parse_url() exists.
		$parsedQuery = parse_url( $uri, PHP_URL_QUERY );
		parse_str( false === $parsedQuery || null === $parsedQuery ? '' : (string) $parsedQuery, $query );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitizeMap() strips control characters and bounds every name and value.
		$cookies = RequestContext::sanitizeMap( array_filter( $_COOKIE, 'is_scalar' ) );

		$headers = array();
		foreach ( self::ELIGIBILITY_HEADERS as $key => $name ) {
			if ( isset( $server[ $key ] ) ) {
				$headers[ $name ] = RequestContext::sanitizeValue( (string) $server[ $key ] );
			}
		}

		return new RequestContext(
			RequestContext::sanitizeMethod( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ),
			$scheme,
			RequestContext::sanitizeHost( (string) ( $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '' ) ),
			RequestContext::normalizePath( RequestContext::sanitizeValue( $path, 2048 ) ),
			RequestContext::sanitizeMap( $query ),
			$cookies,
			$headers,
			RequestContext::sanitizeUserAgent( (string) ( $server['HTTP_USER_AGENT'] ?? '' ) ),
		);
	}
}
