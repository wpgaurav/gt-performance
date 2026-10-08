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

	/** Reject cache-root aliases into unrelated directories before writing/removing. */
	public static function cacheRootIsSafe(): bool {
		$content = realpath( WP_CONTENT_DIR );
		if ( false === $content || is_link( $content . '/cache' ) || is_link( $content . '/cache/gt-performance' ) ) {
			return false;
		}
		$root = realpath( self::cacheRoot() );
		if ( false !== $root && $root !== $content . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'gt-performance' ) {
			return false;
		}
		// A per-site directory aliased elsewhere would let one site's purge or writes
		// land in another site's store, or outside wp-content entirely.
		return ! is_multisite() || ( ! is_link( self::sitesRoot() ) && ! is_link( self::siteRoot() ) );
	}

	/**
	 * The directory that holds this site's pages, assets, and compiled config.
	 *
	 * A single site keeps the original layout. On a network each site gets its own
	 * directory, keyed by blog id, so one site's settings, purges, and stored pages
	 * can never reach another. It follows switch_to_blog(), which is how network-wide
	 * operations address each site in turn.
	 */
	public static function siteRoot(): string {
		if ( ! is_multisite() ) {
			return self::cacheRoot();
		}

		return self::siteRootFor( get_current_blog_id() );
	}

	public static function siteRootFor( int $blogId ): string {
		return self::sitesRoot() . '/' . max( 0, $blogId );
	}

	/** Parent of every per-site directory on a network. */
	public static function sitesRoot(): string {
		return self::cacheRoot() . '/sites';
	}

	/**
	 * Host and path of every site on the network, which advanced-cache.php reads to
	 * pick the site's configuration before WordPress has resolved the site itself.
	 */
	public static function siteMap(): string {
		return self::cacheRoot() . '/sites.json';
	}

	public static function pages(): string {
		return self::siteRoot() . '/pages';
	}

	public static function assets(): string {
		return self::siteRoot() . '/assets';
	}

	/**
	 * Public URL of the assets directory, matching assets().
	 */
	public static function assetsUrl( string $path = '' ): string {
		$site = is_multisite() ? '/sites/' . get_current_blog_id() : '';

		return content_url( '/cache/gt-performance' . $site . '/assets/' . ltrim( $path, '/' ) );
	}

	public static function locks(): string {
		return self::cacheRoot() . '/locks';
	}

	/**
	 * Compiled cache configuration.
	 *
	 * Authenticated encrypted JSON, distinct from all legacy PHP filenames.
	 */
	public static function config(): string {
		return self::siteRoot() . '/config.json';
	}

	/**
	 * Object-cache configuration. There is one object-cache.php per install, so on a
	 * network this file stays network-wide and only the main site writes it.
	 */
	public static function redisConfig(): string {
		return self::cacheRoot() . '/redis-config.json';
	}

	public static function logs(): string {
		return self::cacheRoot() . '/logs';
	}

	/**
	 * @return list<string>
	 */
	public static function writableDirectories(): array {
		if ( ! is_multisite() ) {
			return array(
				self::cacheRoot(),
				self::pages(),
				self::assets(),
				self::locks(),
			);
		}

		return array(
			self::cacheRoot(),
			self::sitesRoot(),
			self::siteRoot(),
			self::pages(),
			self::assets(),
			self::locks(),
		);
	}

	/**
	 * Block direct web access to cache internals. An empty index.html prevents
	 * directory listing everywhere; a scoped .htaccess denies access to the log,
	 * page, and lock stores on Apache. The assets directory is intentionally left
	 * reachable because generated CSS/JS/font files are linked into the page.
	 */
	public static function harden(): void {
		if ( ! self::cacheRootIsSafe() ) {
			return;
		}
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
		// are not assets, and redis-config.json holds runtime settings
		// as encrypted data. Deny configuration files by suffix as defense in depth.
		$root = self::cacheRoot();
		if ( is_dir( $root ) ) {
			$file = $root . '/.htaccess';
			if ( ! is_file( $file ) ) {
				$rule = "# GT Performance: deny direct access to configuration payloads.\n"
					. "<FilesMatch \"\\.json(?:\\.php)?$\">\n"
					. "\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n"
					. "\t<IfModule !mod_authz_core.c>\n\t\tDeny from all\n\t</IfModule>\n"
					. "</FilesMatch>\n";
				@file_put_contents( $file, $rule ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		// Belt and braces for servers that ignore .htaccess: the config payloads carry
		// credentials and only PHP needs to read them.
		foreach ( array( self::config(), self::redisConfig(), self::siteMap() ) as $file ) {
			if ( is_file( $file ) ) {
				@chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}
