<?php
/**
 * GT Performance advanced-cache drop-in.
 *
 * This file is copied verbatim from the plugin's dropins directory. It is not
 * generated: the only value stamped into it at install time is the version
 * appended to the signature on the line above. Every path is resolved at
 * runtime, so a renamed or relocated plugin directory keeps working.
 *
 * WordPress is not loaded at this point, so this file uses no WordPress
 * functions and reads its configuration as inert JSON.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.NoSilencedErrors
 *
 * @package GTPerformance
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		return;
	}

	$cacheRoot = realpath( rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/gt-performance' );
	if ( false === $cacheRoot ) {
		return;
	}

	// Local encrypted JSON only; WordPress's HTTP API is not loaded at this stage.
	$read = static function ( string $path, string $root ): ?array {
		$file = realpath( $path );
		if ( false === $file || ! str_starts_with( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$raw = @file_get_contents( $file );
		if ( ! is_string( $raw ) || ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) || strlen( AUTH_KEY ) < 16 || 'put your unique phrase here' === AUTH_KEY ) {
			return null;
		}
		$envelope = json_decode( $raw, true );
		if ( ! is_array( $envelope ) || 1 !== ( $envelope['version'] ?? null ) || ! is_string( $envelope['data'] ?? null ) ) {
			return null;
		}
		$bytes = base64_decode( $envelope['data'], true );
		if ( false === $bytes || strlen( $bytes ) <= 28 ) {
			return null;
		}
		$key = hash( 'sha256', 'gt-performance-runtime-v1|' . AUTH_KEY, true );
		$json = openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ), 'gt-performance-runtime-v1' );
		$decoded = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $decoded ) ? array(
			'file' => $file,
			'data' => $decoded,
		) : null;
	};

	$runtime = array(
		'/src/Cache/ConfigFile.php',
		'/src/Cache/Decision.php',
		'/src/Cache/RequestContext.php',
		'/src/Cache/Eligibility.php',
		'/src/Cache/CacheKey.php',
		'/src/Cache/DropinRuntime.php',
	);
	$load = static function ( string $pluginDir, array $files ): bool {
		if ( '' === $pluginDir || ! is_dir( $pluginDir ) ) {
			return false;
		}
		foreach ( $files as $relative ) {
			if ( ! is_readable( $pluginDir . $relative ) ) {
				return false;
			}
		}
		foreach ( $files as $relative ) {
			require_once $pluginDir . $relative;
		}
		return true;
	};

	if ( defined( 'MULTISITE' ) && MULTISITE ) {
		// A sunrise.php can route requests in ways the site map cannot see, and it
		// runs after this file, so leave every request to WordPress.
		if ( defined( 'SUNRISE' ) && SUNRISE ) {
			return;
		}

		// Every site has its own configuration and page store. Choose the site the
		// way WordPress will, from the host and path, before reading either.
		$map = $read( $cacheRoot . '/sites.json', $cacheRoot );
		if ( null === $map || ! is_array( $map['data']['sites'] ?? null ) ) {
			return;
		}
		$runtime[] = '/src/Cache/SiteResolver.php';
		if ( ! $load( (string) ( $map['data']['plugin_dir'] ?? '' ), $runtime ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by RequestContext, as DropinRuntime does; wp_unslash() does not exist yet.
		$uri  = \GTPerformance\Cache\RequestContext::sanitizeValue( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), 2048 );
		$path = parse_url( $uri, PHP_URL_PATH );
		$blog = \GTPerformance\Cache\SiteResolver::resolve(
			$map['data']['sites'],
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by RequestContext::sanitizeHost().
			\GTPerformance\Cache\RequestContext::sanitizeHost( (string) ( $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '' ) ),
			\GTPerformance\Cache\RequestContext::normalizePath( false === $path || null === $path ? '/' : (string) $path )
		);
		$siteRoot = $blog > 0 ? realpath( $cacheRoot . '/sites/' . $blog ) : false;
		if ( false === $siteRoot || ! str_starts_with( $siteRoot, $cacheRoot . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		$config = $read( $siteRoot . '/config.json', $siteRoot );
		if ( null === $config ) {
			return;
		}

		\GTPerformance\Cache\DropinRuntime::serve( $config['file'], $siteRoot . '/pages' );
		return;
	}

	$config = $read( $cacheRoot . '/config.json', $cacheRoot );
	if ( null === $config || ! $load( (string) ( $config['data']['plugin_dir'] ?? '' ), $runtime ) ) {
		return;
	}
	$configFile = $config['file'];

	\GTPerformance\Cache\DropinRuntime::serve( $configFile, $cacheRoot . '/pages' );
} )();
