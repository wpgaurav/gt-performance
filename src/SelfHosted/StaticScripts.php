<?php
/**
 * Minified scripts published as static files.
 *
 * The shared minifier keeps each result in a transient and serves it from a signed
 * endpoint, so every request that misses the browser and edge caches boots
 * WordPress for a few kilobytes of JavaScript: about 650 an hour across two
 * production sites on 2026-10-15. This package also writes each result once to
 * wp-content/cache/gt-performance/assets/js/<id>.js and links that file instead.
 * The web server answers it without PHP, and a CDN caches it by its extension.
 *
 * The transient stays the source of truth and the signed endpoint the fallback:
 * - a directory that cannot be written keeps the endpoint URL;
 * - a file removed by a purge or by garbage collection is written again the next
 *   time a page that still names it is requested, and that request is sent on to
 *   the endpoint;
 * - a page that is still rendered refreshes its files' modification time, so
 *   collection only reclaims what no current page links.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\SelfHosted;

use GTPerformance\Core\AtomicFile;
use GTPerformance\Core\Paths;
use GTPerformance\Core\SafeMode;
use GTPerformance\Core\Settings;
use GTPerformance\Optimization\JavaScriptMinifier;

final class StaticScripts {
	/** Below Paths::assets(). */
	public const DIRECTORY = 'js';

	/**
	 * How often a linked file's modification time is refreshed. Garbage collection
	 * reclaims artifacts untouched for 14 days, so a daily refresh keeps every file
	 * a current page names well inside that window without a write per render.
	 */
	private const KEEPALIVE = DAY_IN_SECONDS;

	public function register(): void {
		add_filter( 'gt_performance_minified_script_url', array( $this, 'publish' ), 10, 3 );
		add_action( 'gt_performance_purged_all', array( $this, 'onPurgedAll' ), 10, 0 );
		add_action( 'init', array( $this, 'restore' ), -100 );
	}

	/**
	 * Return the static URL of a minified script, writing the file when needed.
	 *
	 * @param string $endpoint Signed endpoint URL, kept whenever the file cannot be published.
	 * @param string $id       Artifact id.
	 * @param string $body     Minified, hash-verified JavaScript.
	 */
	public function publish( string $endpoint, string $id, string $body ): string {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $id ) || '' === $body || ! $this->directoryIsSafe() ) {
			return $endpoint;
		}

		$path = $this->path( $id );
		clearstatcache( true, $path );
		// The id hashes the plugin version and the source, so a file under this name
		// can only hold this body. AtomicFile never leaves a partial one.
		if ( is_file( $path ) && ! is_link( $path ) && filesize( $path ) === strlen( $body ) ) {
			$this->keepAlive( $path );

			return $this->url( $id );
		}

		return $this->write( $id, $body ) ? $this->url( $id ) : $endpoint;
	}

	public function onPurgedAll(): void {
		$this->purge();
	}

	/**
	 * Remove every published script. A page cached elsewhere that still names one
	 * gets it back through restore(), so a purge never breaks a page.
	 */
	public function purge(): int {
		$directory = $this->directory();
		if ( ! $this->directoryIsSafe() || ! is_dir( $directory ) ) {
			return 0;
		}

		$removed = 0;
		$files   = glob( $directory . '/*.js' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( ! is_link( $file ) && is_file( $file ) && @unlink( $file ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Answer a request for a published script that is no longer on disk.
	 *
	 * The web server only hands such a request to WordPress when the file is
	 * missing. Write it again while minification is on, then send the browser to
	 * the signed endpoint, which serves the result or falls back to the original.
	 */
	public function restore(): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$redirect = $this->restoreTarget( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		if ( null === $redirect ) {
			return;
		}

		header( 'Cache-Control: no-store, private, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		wp_safe_redirect( $redirect, 302, 'GT Performance' );
		exit;
	}

	/**
	 * Where a request for a missing published script should go, or null when the
	 * request is not one, or its artifact has expired and WordPress should answer
	 * with its usual 404.
	 */
	public function restoreTarget( string $requestUri ): ?string {
		$path   = wp_parse_url( $requestUri, PHP_URL_PATH );
		$prefix = wp_parse_url( Paths::assetsUrl( self::DIRECTORY . '/' ), PHP_URL_PATH );
		if ( ! is_string( $path ) || ! is_string( $prefix ) || '' === $prefix || ! str_starts_with( $path, $prefix ) ) {
			return null;
		}
		if ( ! preg_match( '/^([a-f0-9]{64})\.js$/D', substr( $path, strlen( $prefix ) ), $match ) ) {
			return null;
		}

		$minifier = new JavaScriptMinifier();
		$artifact = $minifier->artifact( $match[1] );
		if ( null === $artifact || ! isset( $artifact['source'] ) || '' === $artifact['source'] ) {
			return null;
		}

		if ( ! SafeMode::active() && (bool) Settings::get( 'javascript.minify', false ) && $this->directoryIsSafe() ) {
			$this->write( $match[1], $artifact['body'] );
		}

		return $minifier->endpointUrl( $match[1], $artifact['source'] );
	}

	public function directory(): string {
		return Paths::assets() . '/' . self::DIRECTORY;
	}

	private function path( string $id ): string {
		return $this->directory() . '/' . $id . '.js';
	}

	private function url( string $id ): string {
		return Paths::assetsUrl( self::DIRECTORY . '/' . $id . '.js' );
	}

	/** Never write or delete through a link out of the plugin's own cache tree. */
	private function directoryIsSafe(): bool {
		return Paths::cacheRootIsSafe() && ! is_link( Paths::assets() ) && ! is_link( $this->directory() );
	}

	private function write( string $id, string $body ): bool {
		$directory = $this->directory();
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}
		if ( ! wp_is_writable( $directory ) ) {
			return false;
		}
		if ( ! is_file( $directory . '/index.html' ) ) {
			@file_put_contents( $directory . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Directory-listing guard; a failure only loses the guard.
		}

		return AtomicFile::write( $this->path( $id ), $body );
	}

	private function keepAlive( string $path ): void {
		$modified = filemtime( $path );
		if ( false !== $modified && time() - $modified > self::KEEPALIVE ) {
			@touch( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort; collection falls back to access time.
		}
	}
}
