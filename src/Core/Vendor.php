<?php
/**
 * Loads the bundled libraries the first time one of their classes is used.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

use Composer\Autoload\ClassLoader;

final class Vendor {
	/**
	 * Namespaces of the libraries in vendor/. A test compares this list with
	 * composer.lock, so a new dependency cannot be left out.
	 */
	public const NAMESPACES = array(
		'MatthiasMullie\\Minify\\',
		'MatthiasMullie\\PathConverter\\',
		'Sabberworm\\CSS\\',
		'Safe\\',
		'Symfony\\Component\\CssSelector\\',
	);

	private static ?ClassLoader $loader = null;

	private static bool $attempted = false;

	/**
	 * Autoloader for the bundled namespaces.
	 *
	 * The libraries are used only while CSS is pruned or JavaScript minified, but
	 * Composer's autoloader was included on every request, and with it 82 function
	 * files of thecodingmachine/safe. Now it loads when one of their classes is first
	 * needed.
	 */
	public static function autoload( string $class ): void {
		foreach ( self::NAMESPACES as $prefix ) {
			if ( str_starts_with( $class, $prefix ) ) {
				self::loader()?->loadClass( $class );

				return;
			}
		}
	}

	/**
	 * Composer's class loader for vendor/, included on first use.
	 *
	 * Composer puts its loader at the front of the autoload queue. It is taken back
	 * off, so the bundled libraries keep the place in the queue they had when
	 * Composer's autoloader was included at plugin load, and Composer's loader never
	 * starts answering for classes other plugins ship.
	 */
	private static function loader(): ?ClassLoader {
		if ( self::$attempted ) {
			return self::$loader;
		}
		self::$attempted = true;

		$file = GTPERF_DIR . '/vendor/autoload.php';
		if ( ! is_readable( $file ) ) {
			return null;
		}

		$loader = require_once $file;
		if ( $loader instanceof ClassLoader ) {
			$loader->unregister();
			self::$loader = $loader;
		} else {
			// Included earlier by something else, which also owns its registration.
			self::$loader = ClassLoader::getRegisteredLoaders()[ dirname( $file ) ] ?? null;
		}

		return self::$loader;
	}
}
