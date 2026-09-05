<?php
/**
 * What the plugin loads, and when.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ModuleLoadingTest extends TestCase {
	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
	}

	/**
	 * The source with comments stripped.
	 *
	 * These files explain in prose why they avoid certain calls, and that explanation
	 * is worth keeping. Assertions about what the code does must not match it.
	 */
	private function code(): string {
		$out = '';
		foreach ( token_get_all( $this->source() ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}

		return $out;
	}

	/**
	 * Constructing every module eagerly loaded 75 files and 2.33 MB on every
	 * front-end request, including a 154 KB admin-only class.
	 */
	public function test_admin_and_cli_modules_are_not_constructed_on_a_front_end_request(): void {
		$source = $this->source();

		self::assertMatchesRegularExpression(
			'/if \( is_admin\(\) \) \{\s*\$this->modules\[\] = new \\\\GTPerformance\\\\Admin\\\\AdminModule\(\);/',
			$source
		);
		self::assertMatchesRegularExpression(
			'/if \( defined\( \x27WP_CLI\x27 \) && WP_CLI \) \{\s*\$this->modules\[\] = new \\\\GTPerformance\\\\CLI\\\\CliModule\(\);/',
			$source
		);
	}

	/**
	 * Plugin::boot() runs on plugins_loaded priority 1. Resolving the current user
	 * there fires determine_current_user before authentication plugins that hook it
	 * later have registered, which changes who WordPress thinks the visitor is.
	 */
	public function test_the_bootstrap_never_resolves_the_current_user(): void {
		self::assertStringNotContainsString(
			'is_user_logged_in()',
			$this->code(),
			'Use the authentication cookie instead; it has no side effects.'
		);
		self::assertStringContainsString( 'wordpress_logged_in_', $this->code() );
	}

	public function test_the_response_path_modules_are_always_constructed(): void {
		$source = $this->source();

		foreach ( array( 'PageCacheModule', 'CdnModule', 'CommerceModule', 'OptimizationModule' ) as $module ) {
			self::assertMatchesRegularExpression(
				'/\$this->modules = array\((?:[^;]*?)' . preg_quote( $module, '/' ) . '/s',
				$source,
				$module . ' can affect a front-end response and must always load.'
			);
		}
	}
}
