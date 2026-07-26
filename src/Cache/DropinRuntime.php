<?php
/**
 * Dependency-free early cache runtime loaded by advanced-cache.php.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class DropinRuntime {
	public static function serve( string $configFile, string $pagesRoot ): void {
		if ( ! is_readable( $configFile ) ) {
			return;
		}

		$config = require $configFile;
		if ( ! is_array( $config ) || ! isset( $config['cache'] ) || ! is_array( $config['cache'] ) ) {
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
		$metaFile                  = $directory . '/' . $hash . '.meta.php';

		clearstatcache( true, $page );
		clearstatcache( true, $metaFile );
		if ( ! is_readable( $page ) || ! is_readable( $metaFile ) ) {
			header( 'X-GT-Cache: MISS' );
			return;
		}

		// Include is intentionally non-fatal because another worker may purge between the stat and read.
		$meta = @include $metaFile;
		if ( ! is_array( $meta ) ) {
			header( 'X-GT-Cache: MISS' );
			return;
		}

		$html = file_get_contents( $page );
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
		// WordPress is not loaded in advanced-cache.php, so wp_unslash()/sanitize_text_field() are unavailable.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ifNoneMatch = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
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

	private static function request(): RequestContext {
		$server = $_SERVER;
		$https  = isset( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] );
		$proto  = isset( $server['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( (string) $server['HTTP_X_FORWARDED_PROTO'] ) : '';
		$scheme = $https || 'https' === $proto ? 'https' : 'http';
		$uri    = (string) ( $server['REQUEST_URI'] ?? '/' );
		$parsedPath = parse_url( $uri, PHP_URL_PATH );
		$path       = false === $parsedPath || null === $parsedPath ? '/' : (string) $parsedPath;
		$query  = array();
		$parsedQuery = parse_url( $uri, PHP_URL_QUERY );
		parse_str( false === $parsedQuery || null === $parsedQuery ? '' : (string) $parsedQuery, $query );

		$scalarQuery = array();
		foreach ( $query as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$scalarQuery[ (string) $key ] = (string) $value;
			}
		}

		$cookies = array();
		foreach ( $_COOKIE as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$cookies[ (string) $key ] = (string) $value;
			}
		}

		$headers = array();
		if ( isset( $server['HTTP_AUTHORIZATION'] ) ) {
			$headers['authorization'] = (string) $server['HTTP_AUTHORIZATION'];
		}
		if ( isset( $server['HTTP_X_GT_PERFORMANCE_BYPASS'] ) ) {
			$headers['x-gt-performance-bypass'] = (string) $server['HTTP_X_GT_PERFORMANCE_BYPASS'];
		}
		if ( isset( $server['HTTP_X_GT_PRELOAD'] ) ) {
			$headers['x-gt-preload'] = (string) $server['HTTP_X_GT_PRELOAD'];
		}

		return new RequestContext(
			strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ),
			$scheme,
			strtolower( (string) ( $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '' ) ),
			'/' . ltrim( preg_replace( '#/+#', '/', $path ) ?? '/', '/' ),
			$scalarQuery,
			$cookies,
			$headers,
			(string) ( $server['HTTP_USER_AGENT'] ?? '' ),
		);
	}
}
