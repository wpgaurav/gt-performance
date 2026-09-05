<?php
/**
 * The two distribution packages, and what must not cross between them.
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
	 * Everything that ships in both packages must be able to run without the
	 * licensing subsystem, because the WordPress.org package does not contain it.
	 * Naming the class anywhere outside src/Licensing would put a licensing string
	 * into the artifact a reviewer reads.
	 */
	public function test_shared_code_never_references_the_licensing_subsystem(): void {
		$offenders = array();
		$root      = $this->root() . '/src';

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = str_replace( $root . '/', '', $file->getPathname() );
			if ( str_starts_with( $path, 'Licensing/' ) ) {
				continue;
			}
			if ( str_contains( (string) file_get_contents( $file->getPathname() ), 'Licensing' ) ) {
				$offenders[] = $path;
			}
		}

		self::assertSame(
			array(),
			$offenders,
			'The WordPress.org package excludes src/Licensing, so shared code must not name it.'
		);
	}

	/**
	 * The main file must not name a channel either. It discovers them by shape, so a
	 * package that ships no channel matches nothing.
	 */
	public function test_the_bootstrap_discovers_channels_without_naming_them(): void {
		$source = (string) file_get_contents( $this->root() . '/gt-performance.php' );

		self::assertStringNotContainsString( 'Licensing', $source );
		self::assertStringContainsString( "glob( GTPERF_DIR . '/src/*/channel.php' )", $source );
		self::assertStringContainsString( 'require_once $gt_performance_channel;', $source );
	}

	/**
	 * FluentCart's get_license_version endpoint returns a download URL only for an
	 * activated license, so the self-hosted package is useless without this.
	 */
	public function test_the_fluentcart_channel_ships_an_update_client(): void {
		foreach ( array( 'channel.php', 'Updater.php', 'LicenseModule.php', 'FluentCartClient.php' ) as $file ) {
			self::assertFileExists( $this->root() . '/src/Licensing/' . $file );
		}
	}

	/**
	 * The 1.0.1 release renamed every prefix. Licensing was deleted before that
	 * landed, so restoring it reintroduced the old names until they were ported.
	 */
	public function test_the_restored_licenser_uses_the_current_prefix(): void {
		$offenders = array();
		foreach ( glob( $this->root() . '/src/Licensing/*.php' ) ?: array() as $file ) {
			if ( preg_match( '/\bGTP_[A-Z]|\bgtp_license|\x27gtp_notice\x27/', (string) file_get_contents( $file ) ) ) {
				$offenders[] = basename( $file );
			}
		}

		self::assertSame( array(), $offenders, 'The GTP_ prefix was renamed to GTPERF_ in 1.0.1.' );
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
