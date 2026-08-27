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
		'HTTP_X_GT_PERFORMANCE_BYPASS'   => 'x-gt-performance-bypass',
		'HTTP_X_GT_PRELOAD'              => 'x-gt-preload',
	);

	public static function serve( string $configFile, string $pagesRoot ): void {
		// A drop-in published by an older release requires a fixed list of runtime
		// files that predates ConfigFile. After an update that drop-in is still on
		// disk and loads this newer class, so serve() must not assume its own
		// dependencies were loaded for it: a missing class here is a fatal raised
		// from wp-settings.php, before WordPress exists to catch it, which takes
		// the front end and wp-admin down together until the drop-in is replaced.
		if ( ! class_exists( ConfigFile::class, false ) ) {
			$dependency = __DIR__ . '/ConfigFile.php';
			if ( ! is_readable( $dependency ) ) {
				return;
			}
			require_once $dependency;
		}

		$config = ConfigFile::read( $configFile );
		if ( null === $config || ! isset( $config['cache'] ) || ! is_array( $config['cache'] ) ) {
			return;
		}

		$request  = self::request();
		$decision = ( new Eligibility() )->decide( $request, $config['cache'] );
		if ( ! $decision->cacheable ) {
			if ( (bool) ( $config['debug'] ?? false ) ) {
				header( 'X-GT-Cache: BYPASS' );
				header( 'X-GT-Cache-Reason: ' . preg_replace( '/[^a-z0-9_:\\-\\.]/i', '', $decision->reason ) );
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
		$rawMeta = @file_get_contents( $metaFile ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- Runs before WordPress loads; a concurrent purge is expected.
		$meta    = is_string( $rawMeta ) ? json_decode( $rawMeta, true ) : null;
		if ( ! is_array( $meta ) ) {
			header( 'X-GT-Cache: MISS' );
			return;
		}

		$html = @file_get_contents( $page ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- Runs before WordPress loads; a concurrent purge is expected.
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
		header( 'X-GT-Cache-Key: ' . substr( $hash, 0, 12 ) );

		if ( 'HEAD' !== $request->method ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Previously validated cached HTML.
		}
		exit;
	}

	/**
	 * Whether a cached entry should be rebuilt instead of served.
	 *
	 * A preload request exists to refresh content, so handing it the stale copy
	 * makes it a no-op — which is why stale pages never recovered on their own.
	 * Returning true here falls through to WordPress, and
	 * PageCacheModule::capture() stores the new entry.
	 *
	 * Fresh entries are still served from cache, so preloading current content
	 * stays cheap and a burst of preload jobs cannot stampede the origin.
	 *
	 * @param array<string, string> $headers Request headers, lower-cased keys.
	 */
	public static function shouldRevalidate( bool $isStale, array $headers ): bool {
		if ( ! $isStale ) {
			return false;
		}

		return '' !== trim( (string) ( $headers['x-gt-preload'] ?? '' ) );
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
