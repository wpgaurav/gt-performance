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

	/**
	 * Compiled cache configuration.
	 *
	 * The `.json.php` suffix is literal: the payload is JSON, and the `.php`
	 * extension exists so a direct web request hits the guard line instead of
	 * the data. The name deliberately differs from the `config.php` used up to
	 * 1.0.0, so a drop-in left over from that release finds nothing and returns
	 * rather than executing a file it would expect to `return` an array.
	 */
	public static function config(): string {
		return self::cacheRoot() . '/config.json.php';
	}

	public static function redisConfig(): string {
		return self::cacheRoot() . '/redis-config.json.php';
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

		// The cache root itself cannot be denied wholesale: assets/ under it is linked
		// into the page and must stay reachable. But the two config files directly in it
		// are not assets, and redis-config.json.php holds a host, username and password
		// in clear text. Deny those by name.
		$root = self::cacheRoot();
		if ( is_dir( $root ) ) {
			$file = $root . '/.htaccess';
			if ( ! is_file( $file ) ) {
				$rule = "# GT Performance: deny direct access to configuration payloads.\n"
					. "<FilesMatch \"\\.json\\.php$\">\n"
					. "\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n"
					. "\t<IfModule !mod_authz_core.c>\n\t\tDeny from all\n\t</IfModule>\n"
					. "</FilesMatch>\n";
				@file_put_contents( $file, $rule ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		// Belt and braces for servers that ignore .htaccess: the config payloads carry
		// credentials and only PHP needs to read them.
		foreach ( array( self::config(), self::redisConfig() ) as $file ) {
			if ( is_file( $file ) ) {
				@chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}
