<?php
/**
 * The WordPress.org package is the only distribution channel, and nothing from
 * the retired self-hosted package may come back into it.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DistributionChannelTest extends TestCase {
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The self-hosted package carried a licenser, its own update client, and static
	 * script files. Directory review reads every file the package ships, and asked
	 * for minification without generated JavaScript files.
	 */
	public function test_no_licensing_or_self_hosted_code_remains(): void {
		self::assertDirectoryDoesNotExist( $this->root() . '/src/Licensing' );
		self::assertDirectoryDoesNotExist( $this->root() . '/src/SelfHosted' );
		self::assertSame( array(), glob( $this->root() . '/src/*/channel.php' ) ?: array() );

		$offenders = array();
		$root      = $this->root() . '/src';
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( preg_match( '/Licensing|SelfHosted|license_key|get_license_version|activation_hash/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$offenders[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		self::assertSame( array(), $offenders );
		self::assertStringNotContainsString( 'file_put_contents', (string) file_get_contents( $root . '/Optimization/JavaScriptMinifier.php' ) );
	}

	/**
	 * Directory-hosted plugins take updates from WordPress.org, and the directory
	 * reads Plugin URI as the plugin's canonical page.
	 */
	public function test_the_plugin_header_belongs_to_the_directory(): void {
		$source = (string) file_get_contents( $this->root() . '/gt-performance.php' );

		self::assertDoesNotMatchRegularExpression( '/^Update URI:/m', $source );
		self::assertDoesNotMatchRegularExpression( '/^Plugin URI: .*\/product\//m', $source );
		self::assertStringNotContainsString( 'channel.php', $source );
	}

	/**
	 * A scratch directory in the repo root shipped into a production install and
	 * served 531 KB of internal audit notes over HTTP. An exclude list only stops
	 * what it already knows about, so the build allowlists the top level instead.
	 */
	public function test_the_build_allowlists_what_may_be_packaged(): void {
		$build = (string) file_get_contents( $this->root() . '/bin/build-package.sh' );

		self::assertStringContainsString( 'local allowed=', $build );
		self::assertStringContainsString( 'Unexpected top-level entries', $build );
		self::assertStringNotContainsString( ' __work ', $build, '__work must never be allowlisted.' );
	}
}
