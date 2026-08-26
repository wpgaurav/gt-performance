<?php
/**
 * Filesystem paths.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Paths {
	public static function cacheRoot(): string {
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( GTPERF_DIR );

		return rtrim( $content, '/\\' ) . '/cache/gt-performance';
	}

	public static function pages(): string {
		return self::cacheRoot() . '/pages';
	}

	public static function assets(): string {
		return self::cacheRoot() . '/assets';
	}

	public static function locks(): string {
		return self::cacheRoot() . '/locks';
	}

	public static function config(): string {
		return self::cacheRoot() . '/config.php';
	}

	public static function redisConfig(): string {
		return self::cacheRoot() . '/redis-config.php';
	}

	public static function logs(): string {
		return self::cacheRoot() . '/logs';
	}

	/**
	 * @return list<string>
	 */
	public static function writableDirectories(): array {
		return array(
			self::cacheRoot(),
			self::pages(),
			self::assets(),
			self::locks(),
			self::logs(),
		);
	}

	/**
	 * Block direct web access to cache internals. An empty index.html prevents
	 * directory listing everywhere; a scoped .htaccess denies access to the log,
	 * page, and lock stores on Apache. The assets directory is intentionally left
	 * reachable because generated CSS/JS/font files are linked into the page.
	 */
	public static function harden(): void {
		foreach ( self::writableDirectories() as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}
			$index = $directory . '/index.html';
			if ( ! is_file( $index ) ) {
				@file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$deny = "# GT Performance: deny direct access.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";

		foreach ( array( self::logs(), self::pages(), self::locks() ) as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}
			$file = $directory . '/.htaccess';
			if ( ! is_file( $file ) ) {
				@file_put_contents( $file, $deny ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}
