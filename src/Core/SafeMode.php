<?php
/**
 * A way back.
 *
 * Every complaint about a cache plugin has the same shape: something on the page
 * looks wrong, the owner cannot tell which transformation did it, and there is no
 * way to undo it without disabling the plugin — which leaves the drop-in on disk
 * and the cached HTML still serving, so disabling appears not to help either.
 *
 * Safe mode stops every HTML transformation and refuses to serve or store a cached
 * page, without touching a single setting, the drop-in, or WP_CACHE. It is
 * reversible by removing one line or one URL parameter.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class SafeMode {
	/**
	 * A request-scoped escape hatch for an administrator.
	 *
	 * Restricted to users who could change the settings anyway, so it grants nothing
	 * a visitor does not already lack, and it is nonce-checked so it cannot be
	 * triggered by a link someone else sends.
	 */
	private const PARAMETER = 'gtperf_safe_mode';

	public static function active(): bool {
		static $active = null;

		if ( null !== $active ) {
			return $active;
		}

		// wp-config.php: the site-wide switch. Survives a broken admin screen, which
		// is exactly the situation safe mode exists for.
		if ( defined( 'GTPERF_SAFE_MODE' ) && GTPERF_SAFE_MODE ) {
			$active = true;

			return $active;
		}

		$active = self::requested();

		return $active;
	}

	/**
	 * Whether this request asked for safe mode and is allowed to.
	 */
	private static function requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.
		if ( ! isset( $_GET[ self::PARAMETER ] ) ) {
			return false;
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is the verification.
		$nonce = sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAMETER ] ) );

		return (bool) wp_verify_nonce( $nonce, self::PARAMETER );
	}

	/**
	 * A URL that renders the given page with every transformation disabled.
	 */
	public static function url( string $url ): string {
		return add_query_arg( self::PARAMETER, wp_create_nonce( self::PARAMETER ), $url );
	}

	/**
	 * The parameter must never split the cache: a safe-mode response is not stored,
	 * and the parameter is ignored when computing a key so it cannot mint entries.
	 */
	public static function parameter(): string {
		return self::PARAMETER;
	}
}
