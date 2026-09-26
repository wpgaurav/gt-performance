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

	$cacheRoot  = realpath( rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/gt-performance' );
	$configFile = false === $cacheRoot ? false : realpath( $cacheRoot . '/config.json' );
	if ( false === $configFile || ! str_starts_with( $configFile, $cacheRoot . DIRECTORY_SEPARATOR ) || ! is_file( $configFile ) || ! is_readable( $configFile ) ) {
		return;
	}

	// Local encrypted JSON only; WordPress's HTTP API is not loaded at this stage.
	$raw = @file_get_contents( $configFile );
	if ( ! is_string( $raw ) ) {
		return;
	}

	$config = ( static function ( string $raw ): ?array {
		if ( ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) || strlen( AUTH_KEY ) < 16 || 'put your unique phrase here' === AUTH_KEY ) {
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
		return is_array( $decoded ) ? $decoded : null;
	} )( $raw );
	if ( ! is_array( $config ) ) {
		return;
	}

	$pluginDir = isset( $config['plugin_dir'] ) ? (string) $config['plugin_dir'] : '';
	if ( '' === $pluginDir || ! is_dir( $pluginDir ) ) {
		return;
	}

	$runtime = array(
		'/src/Cache/ConfigFile.php',
		'/src/Cache/Decision.php',
		'/src/Cache/RequestContext.php',
		'/src/Cache/Eligibility.php',
		'/src/Cache/CacheKey.php',
		'/src/Cache/DropinRuntime.php',
	);

	foreach ( $runtime as $relative ) {
		if ( ! is_readable( $pluginDir . $relative ) ) {
			return;
		}
	}

	foreach ( $runtime as $relative ) {
		require_once $pluginDir . $relative;
	}

	\GTPerformance\Cache\DropinRuntime::serve( $configFile, $cacheRoot . '/pages' );
} )();
