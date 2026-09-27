<?php
/** Speculation rules and script planning against real WordPress core output. */
declare(strict_types=1);
namespace GTPerformance\Tests\Integration;

use GTPerformance\Core\Settings;
use GTPerformance\Optimization\JavaScriptOptimizer;
use GTPerformance\Optimization\SpeculationPolicy;
use PHPUnit\Framework\TestCase;

final class FrontendOptimizationTest extends TestCase {
	private mixed $saved;

	protected function setUp(): void {
		$this->saved = get_option( Settings::OPTION );
	}

	protected function tearDown(): void {
		update_option( Settings::OPTION, $this->saved );
		remove_all_filters( 'wp_speculation_rules_configuration' );
		remove_all_filters( 'wp_speculation_rules_href_exclude_paths' );
	}

	/** @param array<string, mixed> $changes Section => values. */
	private function settings( array $changes ): void {
		$settings = Settings::all();
		foreach ( $changes as $section => $values ) {
			$settings[ $section ] = array_merge( (array) $settings[ $section ], $values );
		}
		update_option( Settings::OPTION, $settings );
	}

	public function test_core_rules_gain_bypass_exclusions_and_modes_behave(): void {
		if ( ! SpeculationPolicy::available() ) {
			self::markTestSkipped( 'Core speculative loading requires WordPress 6.8.' );
		}
		$this->settings( array( 'cache' => array( 'bypass_paths' => array( '/wp-admin/', '/checkout/', '/my-account/' ) ), 'speculation' => array( 'mode' => 'core' ) ) );
		( new SpeculationPolicy() )->register();
		wp_set_current_user( 0 );
		// Core speculates only with pretty permalinks and no signed-in user.
		$permalinks = get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );
		try {
			$this->assertRules();
		} finally {
			update_option( 'permalink_structure', $permalinks );
		}
	}

	private function assertRules(): void {

		$rules = wp_get_speculation_rules();
		self::assertNotNull( $rules );
		$json = (string) wp_json_encode( $rules );
		self::assertStringContainsString( '\/checkout\/*', $json );
		self::assertStringContainsString( '\/my-account', $json );
		self::assertStringContainsString( '"prefetch"', $json, 'Core mode is left as core chose it.' );

		$this->settings( array( 'speculation' => array( 'mode' => 'off' ) ) );
		self::assertNull( wp_get_speculation_rules() );
	}

	public function test_inline_code_on_the_core_jquery_alias_keeps_jquery_blocking(): void {
		$this->settings( array( 'javascript' => array( 'defer' => true, 'delay' => false, 'minify' => false ) ) );
		$scripts = wp_scripts();
		$done    = $scripts->done;
		$inline  = $scripts->get_data( 'jquery', 'after' );
		wp_add_inline_script( 'jquery', 'jQuery(function(){ window.ready = 1; });' );
		try {
			ob_start();
			wp_print_scripts( array( 'jquery' ) );
			$out = ( new JavaScriptOptimizer() )->optimize( '<html><body>' . ob_get_clean() . '</body></html>' );
		} finally {
			$scripts->done = $done;
			$scripts->add_data( 'jquery', 'after', $inline );
		}

		self::assertStringContainsString( 'id="jquery-js-after"', $out );
		self::assertDoesNotMatchRegularExpression( '/<script[^>]*defer[^>]*id="jquery-core-js"|<script[^>]*id="jquery-core-js"[^>]*defer/', $out, 'The alias inline code needs jQuery immediately.' );
	}

	public function test_scripts_printed_by_core_keep_inline_dependent_chains_blocking(): void {
		$this->settings( array( 'javascript' => array( 'defer' => true, 'delay' => false, 'minify' => false ) ) );
		$scripts = wp_scripts();
		$done    = $scripts->done;
		wp_register_script( 'gtp-it-lib', 'https://example.test/lib.js', array(), null );
		wp_register_script( 'gtp-it-widget', 'https://example.test/widget.js', array( 'gtp-it-lib' ), null );
		wp_add_inline_script( 'gtp-it-widget', 'widget.init();' );
		wp_register_script( 'gtp-it-free', 'https://example.test/free.js', array(), null );
		try {
			ob_start();
			wp_print_scripts( array( 'gtp-it-widget', 'gtp-it-free' ) );
			$html = '<html><body>' . ob_get_clean() . '</body></html>';
			$out  = ( new JavaScriptOptimizer() )->optimize( $html );
		} finally {
			$scripts->done = $done;
			foreach ( array( 'gtp-it-widget', 'gtp-it-lib', 'gtp-it-free' ) as $handle ) {
				wp_deregister_script( $handle );
			}
		}

		self::assertMatchesRegularExpression( '/<script(?![^>]*defer)[^>]*id="gtp-it-lib-js"/', $out, 'The library its inline-dependent widget needs stays blocking.' );
		self::assertMatchesRegularExpression( '/<script(?![^>]*defer)[^>]*id="gtp-it-widget-js"/', $out );
		self::assertMatchesRegularExpression( '/<script[^>]*defer[^>]*id="gtp-it-free-js"|<script[^>]*id="gtp-it-free-js"[^>]*defer/', $out );
		self::assertStringContainsString( 'widget.init();', $out );
	}
}
