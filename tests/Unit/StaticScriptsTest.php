<?php
/** Minified scripts published as static files by the self-hosted package. */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Paths;
use GTPerformance\Optimization\JavaScriptMinifier;
use GTPerformance\SelfHosted\StaticScripts;
use PHPUnit\Framework\TestCase;

final class StaticScriptsTest extends TestCase {
	private string $path;
	private string $url;
	private string $source = "// A comment that should be removed from the delivered script.\nwindow.gtperfStatic = function ( number ) {\n    return number + 1;\n};\n";
	private StaticScripts $scripts;

	protected function setUp(): void {
		$this->path = WP_CONTENT_DIR . '/review-static-' . uniqid() . '.js';
		$this->url  = content_url( '/' . basename( $this->path ) ) . '?ver=original';
		// Identical sources share one artifact, which remembers the first URL it came from.
		$this->source .= 'window.gtperfStaticRun = "' . basename( $this->path ) . '";' . "\n";
		file_put_contents( $this->path, $this->source );
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array( 'javascript' => array( 'minify' => true ) );
		unset( $GLOBALS['gtperf_test_filters']['gt_performance_minified_script_url'] );
		$this->scripts = new StaticScripts();
		$this->scripts->register();
	}

	protected function tearDown(): void {
		unlink( $this->path );
		$directory = $this->scripts->directory();
		if ( is_dir( $directory ) ) {
			chmod( $directory, 0o755 );
			foreach ( glob( $directory . '/*' ) ?: array() as $file ) {
				unlink( $file );
			}
			rmdir( $directory );
		}
		unset( $GLOBALS['gtperf_test_options']['gt_performance_settings'], $GLOBALS['gtperf_test_filters']['gt_performance_minified_script_url'] );
		foreach ( array( 'gt_performance_purged_all', 'init' ) as $hook ) {
			$GLOBALS['gtperf_test_registered_actions'][ $hook ] = array_values(
				array_filter(
					$GLOBALS['gtperf_test_registered_actions'][ $hook ] ?? array(),
					fn( $callback ): bool => ! ( is_array( $callback ) && $callback[0] === $this->scripts )
				)
			);
		}
	}

	private function id( string $url ): string {
		self::assertSame( 1, preg_match( '#/assets/js/([a-f0-9]{64})\.js$#', $url, $match ), $url );

		return $match[1];
	}

	/** @return array<string, string> */
	private function descriptor( string $endpoint ): array {
		parse_str( (string) wp_parse_url( $endpoint, PHP_URL_QUERY ), $query );

		return $query;
	}

	public function test_minified_scripts_are_linked_as_static_files_holding_the_cached_result(): void {
		$minifier = new JavaScriptMinifier();
		$url      = (string) $minifier->minifiedUrl( $this->url );
		$id       = $this->id( $url );
		$file     = $this->scripts->directory() . '/' . $id . '.js';

		self::assertSame( Paths::assetsUrl( 'js/' . $id . '.js' ), $url );
		self::assertStringNotContainsString( '?', $url, 'A query string would keep CDNs from caching the file by extension.' );
		self::assertFileExists( $file );
		self::assertSame( $minifier->artifact( $id )['body'] ?? null, file_get_contents( $file ) );
		self::assertStringNotContainsString( 'A comment', (string) file_get_contents( $file ) );
		self::assertFileExists( $this->scripts->directory() . '/index.html' );
		self::assertSame( $url, $minifier->minifiedUrl( $this->url ), 'A second render reuses the file.' );
		self::assertSame( $this->source, file_get_contents( $this->path ) );
	}

	public function test_an_unwritable_directory_keeps_the_signed_endpoint(): void {
		mkdir( $this->scripts->directory(), 0o555, true );
		if ( is_writable( $this->scripts->directory() ) ) {
			self::markTestSkipped( 'Running with privileges that ignore directory permissions.' );
		}

		$url = (string) ( new JavaScriptMinifier() )->minifiedUrl( $this->url );

		self::assertStringContainsString( JavaScriptMinifier::PARAM . '=', $url );
		self::assertSame( 200, ( new JavaScriptMinifier() )->response( $this->descriptor( $url ) )['status'] );
		self::assertSame( array(), glob( $this->scripts->directory() . '/*.js' ) );
	}

	public function test_a_linked_directory_is_never_written_through(): void {
		$elsewhere = WP_CONTENT_DIR . '/elsewhere-' . uniqid();
		mkdir( $elsewhere );
		wp_mkdir_p( Paths::assets() );
		symlink( $elsewhere, $this->scripts->directory() );
		try {
			$url = (string) ( new JavaScriptMinifier() )->minifiedUrl( $this->url );
			self::assertStringContainsString( JavaScriptMinifier::PARAM . '=', $url );
			self::assertSame( array(), glob( $elsewhere . '/*' ) );
			self::assertSame( 0, $this->scripts->purge() );
		} finally {
			unlink( $this->scripts->directory() );
			rmdir( $elsewhere );
		}
	}

	public function test_a_rendered_page_keeps_its_files_out_of_garbage_collection(): void {
		$url  = (string) ( new JavaScriptMinifier() )->minifiedUrl( $this->url );
		$file = $this->scripts->directory() . '/' . $this->id( $url ) . '.js';

		touch( $file, time() - 3 * DAY_IN_SECONDS );
		( new JavaScriptMinifier() )->minifiedUrl( $this->url );
		clearstatcache();
		self::assertGreaterThan( time() - HOUR_IN_SECONDS, filemtime( $file ) );

		$recent = time() - HOUR_IN_SECONDS;
		touch( $file, $recent );
		( new JavaScriptMinifier() )->minifiedUrl( $this->url );
		clearstatcache();
		self::assertSame( $recent, filemtime( $file ), 'A file refreshed within the day is left alone.' );
	}

	public function test_purging_everything_removes_published_scripts(): void {
		self::assertContains( array( $this->scripts, 'onPurgedAll' ), $GLOBALS['gtperf_test_registered_actions']['gt_performance_purged_all'] ?? array() );
		$url = (string) ( new JavaScriptMinifier() )->minifiedUrl( $this->url );

		self::assertSame( 1, $this->scripts->purge() );
		self::assertFileDoesNotExist( $this->scripts->directory() . '/' . $this->id( $url ) . '.js' );
		self::assertFileExists( $this->scripts->directory() . '/index.html' );
		self::assertSame( 0, $this->scripts->purge() );
	}

	public function test_a_missing_file_is_written_again_and_sent_to_the_signed_endpoint(): void {
		$minifier = new JavaScriptMinifier();
		$url      = (string) $minifier->minifiedUrl( $this->url );
		$id       = $this->id( $url );
		$file     = $this->scripts->directory() . '/' . $id . '.js';
		$this->scripts->purge();

		$redirect = (string) $this->scripts->restoreTarget( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		self::assertFileExists( $file, 'Later requests are answered by the web server again.' );
		$query = $this->descriptor( $redirect );
		self::assertSame( $id, $query[ JavaScriptMinifier::PARAM ] );
		self::assertSame( $this->url, $query[ JavaScriptMinifier::SOURCE_PARAM ] );
		self::assertSame( 200, $minifier->response( $query )['status'], 'The redirect carries a valid signature.' );
	}

	public function test_a_missing_file_with_minification_off_falls_back_to_the_original(): void {
		$minifier = new JavaScriptMinifier();
		$url      = (string) $minifier->minifiedUrl( $this->url );
		$this->scripts->purge();
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['javascript']['minify'] = false;

		$redirect = (string) $this->scripts->restoreTarget( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		self::assertFileDoesNotExist( $this->scripts->directory() . '/' . $this->id( $url ) . '.js' );
		self::assertSame( $this->url, $minifier->response( $this->descriptor( $redirect ) )['redirect'] ?? null );
	}

	public function test_only_missing_scripts_with_a_live_artifact_are_restored(): void {
		$url  = (string) ( new JavaScriptMinifier() )->minifiedUrl( $this->url );
		$id   = $this->id( $url );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = substr( $path, 0, -strlen( $id . '.js' ) );

		foreach ( array( '/', '/sample-page/', $base . str_repeat( 'g', 64 ) . '.js', $base . $id . '.js.map', $base . '../css/' . $id . '.js', $base . strtoupper( $id ) . '.js', '/wp-content/plugins/x/' . $id . '.js' ) as $candidate ) {
			self::assertNull( $this->scripts->restoreTarget( $candidate ), $candidate );
		}

		delete_transient( JavaScriptMinifier::CACHE_PREFIX . $id );
		self::assertNull( $this->scripts->restoreTarget( $path ), 'An expired artifact leaves WordPress to answer 404.' );
		set_transient( JavaScriptMinifier::CACHE_PREFIX . $id, array( 'body' => 'changed', 'hash' => str_repeat( '0', 64 ), 'source' => $this->url ) );
		self::assertNull( $this->scripts->restoreTarget( $path ), 'An altered artifact is never served.' );
	}

	public function test_the_shared_minifier_ignores_an_unusable_filter_result(): void {
		unset( $GLOBALS['gtperf_test_filters']['gt_performance_minified_script_url'] );
		add_filter( 'gt_performance_minified_script_url', static fn(): string => '' );

		$url = (string) ( new JavaScriptMinifier() )->minifiedUrl( $this->url );

		self::assertStringContainsString( JavaScriptMinifier::PARAM . '=', $url );
		self::assertSame( 200, ( new JavaScriptMinifier() )->response( $this->descriptor( $url ) )['status'] );
	}
}
