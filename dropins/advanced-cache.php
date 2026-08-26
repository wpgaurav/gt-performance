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

	$cacheRoot  = rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/gt-performance';
	$configFile = $cacheRoot . '/config.php';
	if ( ! is_readable( $configFile ) ) {
		return;
	}

	$raw = @file_get_contents( $configFile );
	if ( ! is_string( $raw ) ) {
		return;
	}

	// The first line is a fixed guard that terminates direct web requests.
	// Everything after it is JSON data and is never executed.
	$break = strpos( $raw, "\n" );
	if ( false === $break ) {
		return;
	}

	$config = json_decode( substr( $raw, $break + 1 ), true );
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
