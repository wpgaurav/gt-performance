<?php
/** External minification without generated executable files. */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Settings;
use GTPerformance\Optimization\JavaScriptMinifier;
use GTPerformance\Optimization\JavaScriptOptimizer;
use PHPUnit\Framework\TestCase;

final class JavaScriptMinifierTest extends TestCase {
	private string $path;
	private string $url;
	private string $source = "// A comment that should be removed from the delivered script.\nwindow.gtperfTest = function ( number ) {\n    return number + 1;\n};\n";

	protected function setUp(): void {
		$this->path = WP_CONTENT_DIR . '/review-minify-' . uniqid() . '.js';
		$this->url = content_url( '/' . basename( $this->path ) ) . '?ver=original';
		file_put_contents( $this->path, $this->source );
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array( 'javascript' => array( 'minify' => true ) );
		unset( $GLOBALS['wp_scripts'] );
	}

	protected function tearDown(): void {
		unlink( $this->path );
		unset( $GLOBALS['gtperf_test_options']['gt_performance_settings'], $GLOBALS['wp_scripts'] );
	}

	/** @return array<string, string> */
	private function descriptor( ?string $url = null ): array {
		$url ??= ( new JavaScriptMinifier() )->minifiedUrl( $this->url );
		self::assertNotNull( $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	public function test_minification_stays_external_caches_and_preserves_source(): void {
		$minifier = new JavaScriptMinifier();
		$url = $minifier->minifiedUrl( $this->url );
		$response = $minifier->response( $this->descriptor( $url ) );
		self::assertSame( 200, $response['status'] );
		self::assertStringContainsString( 'function', $response['body'] );
		self::assertStringNotContainsString( 'A comment', $response['body'] );
		self::assertLessThan( strlen( $this->source ), strlen( $response['body'] ) );
		self::assertSame( $url, $minifier->minifiedUrl( $this->url ) );
		self::assertSame( $this->source, file_get_contents( $this->path ) );
		self::assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/cache/gt-performance/assets/js' );
		self::assertSame( 'application/javascript; charset=UTF-8', $response['headers']['Content-Type'] );
		self::assertSame( 'nosniff', $response['headers']['X-Content-Type-Options'] );
		self::assertStringContainsString( 'immutable', $response['headers']['Cache-Control'] );
	}

	public function test_source_revision_changes_url_even_with_same_file_size_and_mtime(): void {
		$minifier = new JavaScriptMinifier();
		$old = $minifier->minifiedUrl( $this->url );
		$mtime = filemtime( $this->path );
		file_put_contents( $this->path, str_replace( '+ 1', '+ 2', $this->source ) );
		touch( $this->path, $mtime );
		self::assertNotSame( $old, $minifier->minifiedUrl( $this->url ) );
	}

	public function test_encoded_version_values_survive_signed_url_round_trip(): void {
		$url = content_url( '/' . basename( $this->path ) ) . '?ver=build%26one%2Btwo%3Dthree';
		$minifier = new JavaScriptMinifier();
		$query = $this->descriptor( $minifier->minifiedUrl( $url ) );
		self::assertSame( $url, $query[ JavaScriptMinifier::SOURCE_PARAM ] );
		self::assertSame( 200, $minifier->response( $query )['status'] );
	}

	public function test_expired_and_corrupted_artifacts_redirect_to_the_signed_original(): void {
		$minifier = new JavaScriptMinifier();
		$query = $this->descriptor();
		$key = JavaScriptMinifier::CACHE_PREFIX . $query[ JavaScriptMinifier::PARAM ];
		delete_transient( $key );
		$miss = $minifier->response( $query );
		self::assertSame( 302, $miss['status'] );
		self::assertSame( $this->url, $miss['redirect'] );
		self::assertSame( '', $miss['body'] );
		self::assertStringContainsString( 'no-store', $miss['headers']['Cache-Control'] );
		set_transient( $key, array( 'body' => 'changed code', 'hash' => str_repeat( '0', 64 ) ) );
		self::assertSame( 302, $minifier->response( $query )['status'] );
	}

	public function test_invalid_signatures_cannot_read_artifacts_or_create_open_redirects(): void {
		$minifier = new JavaScriptMinifier();
		$query = $this->descriptor();
		$query[ JavaScriptMinifier::SOURCE_PARAM ] = 'https://attacker.example/script.js';
		self::assertSame( 403, $minifier->response( $query )['status'] );
		$query = $this->descriptor();
		$query[ JavaScriptMinifier::SIGNATURE_PARAM ] = str_repeat( '0', 64 );
		self::assertSame( 403, $minifier->response( $query )['status'] );
		$query[ JavaScriptMinifier::PARAM ] = array( 'bad' );
		self::assertSame( 403, $minifier->response( $query )['status'] );
	}

	public function test_http_head_revalidation_methods_and_disable_switch(): void {
		$minifier = new JavaScriptMinifier();
		$query = $this->descriptor();
		$get = $minifier->response( $query );
		$head = $minifier->response( $query, 'HEAD' );
		self::assertSame( 200, $head['status'] );
		self::assertSame( '', $head['body'] );
		self::assertSame( $get['headers'], $head['headers'] );
		self::assertSame( 304, $minifier->response( $query, 'GET', $get['headers']['ETag'] )['status'] );
		self::assertSame( 405, $minifier->response( $query, 'POST' )['status'] );
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['javascript']['minify'] = false;
		self::assertSame( $this->url, $minifier->response( $query )['redirect'] );
	}

	public function test_unsafe_or_url_dependent_inputs_remain_original(): void {
		$minifier = new JavaScriptMinifier();
		foreach ( array( 'https://attacker.example/remote.js', content_url( '/../secret.js' ), content_url( '/%2e%2e/secret.js' ), content_url( '/%252e%252e/secret.js' ), content_url( '/payload.php' ), $this->url . '&action=dynamic', 'file://' . $this->path, 'https://user@example.com/wp-content/test.js' ) as $url ) {
			self::assertNull( $minifier->minifiedUrl( $url ), $url );
		}
		foreach ( array( 'document.currentScript.src', 'import("./chunk.js")', 'import.meta.url' ) as $code ) {
			file_put_contents( $this->path, '// More text to shrink' . "\n" . $code . ';' );
			self::assertNull( $minifier->minifiedUrl( $this->url ) );
		}
	}

	public function test_minification_retains_defer_and_delay_matching_and_attributes(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['javascript'] += array( 'defer' => true, 'delay' => true, 'delay_patterns' => array( basename( $this->path ) ) );
		$output = ( new JavaScriptOptimizer() )->optimize( '<script id="widget" nonce="keep" src="' . $this->url . '"></script>' );
		$processor = new \WP_HTML_Tag_Processor( $output );
		self::assertTrue( $processor->next_tag( 'SCRIPT' ) );
		self::assertSame( 'text/gtp-delayed', $processor->get_attribute( 'type' ) );
		self::assertNull( $processor->get_attribute( 'src' ) );
		self::assertSame( 'keep', $processor->get_attribute( 'nonce' ) );
		self::assertStringContainsString( 'gtperf_js=', $processor->get_attribute( 'data-gtp-src' ) );
		self::assertStringContainsString( '/assets/delay.js', $output );
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['javascript']['delay'] = false;
		// Deferral needs WordPress to know the script: registered, printed, no inline "after" code.
		wp_register_script( 'gtp-minify-test', $this->url, array(), null );
		wp_scripts()->done[] = 'gtp-minify-test';
		try {
			$output = ( new JavaScriptOptimizer() )->optimize( '<script id="gtp-minify-test-js" src="' . $this->url . '"></script>' );
		} finally {
			wp_scripts()->done = array_values( array_diff( wp_scripts()->done, array( 'gtp-minify-test' ) ) );
			wp_deregister_script( 'gtp-minify-test' );
		}
		self::assertStringContainsString( 'gtperf_js=', $output );
		self::assertStringContainsString( 'defer=""', $output );
	}

	public function test_opt_in_exclusions_integrity_and_modules_are_preserved(): void {
		$optimizer = new JavaScriptOptimizer();
		foreach ( array( ' integrity="sha256-existing"', ' type="module"', ' type="application/ld+json"' ) as $attributes ) {
			$html = '<script' . $attributes . ' src="' . $this->url . '"></script>';
			self::assertSame( $html, $optimizer->optimize( $html ) );
		}
		$html = '<script src="' . $this->url . '"></script>';
		$GLOBALS['gtperf_test_options']['gt_performance_settings']['javascript']['exclusions'] = array( basename( $this->path ) );
		self::assertSame( $html, $optimizer->optimize( $html ) );
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array();
		self::assertSame( $html, $optimizer->optimize( $html ) );
	}

	public function test_asset_requests_never_hit_page_cache_even_if_ignored(): void {
		$config = Settings::defaults()['cache'];
		$config['ignored_query_params'][] = 'gtperf_js';
		$request = new RequestContext( 'GET', 'https', 'example.com', '/', array( 'gtperf_js' => 'id' ), array(), array(), '' );
		self::assertSame( 'javascript_asset', ( new Eligibility() )->decide( $request, $config )->reason );
	}
}
