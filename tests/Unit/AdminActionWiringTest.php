<?php
/**
 * Every admin action a control submits must have a handler registered for it.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A form posts an action name to admin-post.php and WordPress fires
 * `admin_post_{$action}`. When nothing is hooked to that name WordPress does not
 * error: it fires an action with no listeners and exits, so the browser gets a
 * blank page and the server log stays clean. AJAX endpoints fail the same silent
 * way. That is exactly what a partial prefix rename produces, so the two sides
 * are asserted against each other here rather than trusted to move together.
 */
final class AdminActionWiringTest extends TestCase {
	/**
	 * Every PHP file under src/.
	 *
	 * Discovered rather than listed. A hardcoded list is the same failure this
	 * test exists to catch: the first version of it named four files and so did
	 * not notice that the licensing module, which only ships in the store build,
	 * still registered its three handlers under the retired prefix.
	 *
	 * @return list<string>
	 */
	private static function sources(): array {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( GTPERF_DIR . '/src', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() && 'php' === $item->getExtension() ) {
				$files[] = $item->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	private static function allSource(): string {
		$out = '';
		foreach ( self::sources() as $file ) {
			$out .= (string) file_get_contents( $file ) . "\n";
		}

		return $out;
	}

	/**
	 * Action names registered through add_action( 'admin_post_...' ).
	 *
	 * @return list<string>
	 */
	private static function registeredAdminPost(): array {
		preg_match_all( "/add_action\(\s*'admin_post_(?:nopriv_)?([a-z0-9_]+)'/", self::allSource(), $m );

		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Action names registered through add_action( 'wp_ajax_...' ).
	 *
	 * @return list<string>
	 */
	private static function registeredAjax(): array {
		preg_match_all( "/add_action\(\s*'wp_ajax_(?:nopriv_)?([a-z0-9_]+)'/", self::allSource(), $m );

		return array_values( array_unique( $m[1] ) );
	}

	public function test_every_action_button_has_a_registered_handler(): void {
		preg_match_all( "/actionButton\(\s*'([a-z0-9_]+)'/", self::allSource(), $m );
		$submitted = array_values( array_unique( $m[1] ) );

		self::assertNotEmpty( $submitted, 'No action buttons found; the parser has drifted from the code.' );

		$registered = self::registeredAdminPost();
		foreach ( $submitted as $action ) {
			self::assertContains(
				$action,
				$registered,
				"Button posts '{$action}' but no admin_post_{$action} handler is registered. The button will return a blank page."
			);
		}
	}

	public function test_admin_bar_action_has_a_registered_handler(): void {
		$source = (string) file_get_contents( GTPERF_DIR . '/src/Admin/AdminBarModule.php' );
		preg_match( "/'action'\s*=>\s*'([a-z0-9_]+)'/", $source, $m );

		self::assertNotEmpty( $m[1] ?? '', 'No admin-bar action name found.' );
		self::assertContains( $m[1], self::registeredAdminPost() );
	}

	public function test_every_javascript_ajax_action_has_a_registered_handler(): void {
		$registered = self::registeredAjax();
		$found      = 0;

		foreach ( glob( GTPERF_DIR . '/assets/*.js' ) ?: array() as $script ) {
			preg_match_all( '/action:\s*"([a-z0-9_]+)"/', (string) file_get_contents( $script ), $m );
			foreach ( $m[1] as $action ) {
				++$found;
				self::assertContains(
					$action,
					$registered,
					basename( $script ) . " posts '{$action}' but no wp_ajax_{$action} handler is registered."
				);
			}
		}

		self::assertGreaterThan( 0, $found, 'No AJAX actions found in assets; the parser has drifted.' );
	}

	/**
	 * The rename that caused this: `\bgtp_` never matches inside
	 * `admin_post_gtp_...`, because the preceding underscore is a word
	 * character. Hook strings are the place that flaw hides.
	 */
	public function test_no_hook_registration_uses_the_retired_prefix(): void {
		$offenders = array();

		foreach ( self::sources() as $file ) {
			$source = (string) file_get_contents( $file );
			if ( preg_match( "/add_action\(\s*'(?:admin_post|wp_ajax)_(?:nopriv_)?gtp_/", $source ) ) {
				$offenders[] = basename( $file );
			}
		}

		self::assertSame(
			array(),
			$offenders,
			'These files still register hooks under the retired gtp_ prefix: ' . implode( ', ', $offenders )
		);
	}
}
