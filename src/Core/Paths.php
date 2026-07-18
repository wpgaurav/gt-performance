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
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( GTP_DIR );

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
}
