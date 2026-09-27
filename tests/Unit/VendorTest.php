<?php
/**
 * Lazy loading of the bundled libraries.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Vendor;
use PHPUnit\Framework\TestCase;

final class VendorTest extends TestCase {
	/**
	 * A library whose namespace is missing from the list could never be loaded.
	 */
	public function test_every_bundled_namespace_is_listed(): void {
		$root = dirname( __DIR__, 2 );
		$lock = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true );
		self::assertIsArray( $lock );

		$classmap = require $root . '/vendor/composer/autoload_classmap.php';
		$used     = array();
		foreach ( $lock['packages'] as $package ) {
			foreach ( array_keys( $package['autoload']['psr-4'] ?? array() ) as $prefix ) {
				self::assertContains( $prefix, Vendor::NAMESPACES, "{$package['name']} autoloads {$prefix}." );
				$used[ $prefix ] = true;
			}
			// Packages that autoload by classmap: every class they ship must be covered.
			$directory = $root . '/vendor/' . $package['name'] . '/';
			foreach ( $classmap as $class => $file ) {
				if ( str_starts_with( (string) realpath( $file ), (string) realpath( $directory ) . '/' ) ) {
					$prefix = $this->prefixOf( (string) $class );
					self::assertNotNull( $prefix, "{$class} from {$package['name']} is in no listed namespace." );
					$used[ (string) $prefix ] = true;
				}
			}
		}

		self::assertSame( array(), array_values( array_diff( Vendor::NAMESPACES, array_keys( $used ) ) ), 'Every listed namespace belongs to a bundled library.' );
	}

	/**
	 * Boots the real plugin file in a fresh PHP process, where nothing has included
	 * Composer's autoloader yet, as on a live site.
	 */
	public function test_libraries_load_only_when_first_used(): void {
		$root   = dirname( __DIR__, 2 );
		$script = tempnam( sys_get_temp_dir(), 'gtperf-vendor-' );
		file_put_contents(
			$script,
			'<?php
			define( "ABSPATH", sys_get_temp_dir() . "/" );
			function plugin_basename( $file ) { return "gt-performance/gt-performance.php"; }
			function register_activation_hook( ...$args ) {}
			function register_deactivation_hook( ...$args ) {}
			function add_action( ...$args ) {}
			require ' . var_export( $root . '/gt-performance.php', true ) . ';

			$composer = static fn (): bool => class_exists( "Composer\\\\Autoload\\\\ClassLoader", false );
			$result   = array( "at_boot" => $composer() || function_exists( "Safe\\\\preg_match" ) );

			$pruner = new GTPerformance\Optimization\Css\CssPruner();
			$result["after_pruner_built"] = $composer();

			$document = new DOMDocument();
			$document->loadHTML( "<html><body><p class=\"a\">x</p></body></html>" );
			$result["pruned"]     = $pruner->prune( ".a{color:red}.b{color:blue}", $document );
			$result["safe_ready"] = function_exists( "Safe\\\\preg_match" );
			$result["composer_registered"] = count( array_filter( spl_autoload_functions(), static fn ( $f ): bool => is_array( $f ) && $f[0] instanceof Composer\Autoload\ClassLoader ) ) > 0;
			$result["minified"] = ( new MatthiasMullie\Minify\JS( "var  answer  =  42 ;" ) )->minify();
			echo json_encode( $result );'
		);

		try {
			$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1' );
		} finally {
			unlink( $script );
		}
		$result = json_decode( (string) $output, true );
		self::assertIsArray( $result, (string) $output );

		self::assertFalse( $result['at_boot'], 'Loading the plugin loads no library.' );
		self::assertFalse( $result['after_pruner_built'], 'Building the pruner, as every request does, loads no library.' );
		self::assertStringContainsString( '.a', $result['pruned'] );
		self::assertStringNotContainsString( '.b', $result['pruned'] );
		self::assertTrue( $result['safe_ready'], "The parser's Safe functions arrive with the libraries." );
		self::assertFalse( $result['composer_registered'], "Composer's loader does not stay in the autoload queue." );
		self::assertSame( 'var answer=42', $result['minified'] );
	}

	private function prefixOf( string $class ): ?string {
		foreach ( Vendor::NAMESPACES as $prefix ) {
			if ( str_starts_with( $class, $prefix ) ) {
				return $prefix;
			}
		}

		return null;
	}
}
