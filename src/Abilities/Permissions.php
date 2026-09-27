<?php
/**
 * Who may call GT Performance abilities, and in which mode.
 *
 * The same check runs for MCP, the core abilities REST API, and direct
 * execution, because WP_Ability::execute() calls it before every callback.
 * Behavioral annotations never grant access.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Abilities;

use GTPerformance\Core\Settings;

final class Permissions {
	/**
	 * `read` exposes evidence; `operate` adds bounded URL operations and
	 * settings proposals. Anything else saves as `off`.
	 *
	 * @var list<string>
	 */
	public const MODES = array( 'off', 'read', 'operate' );

	public static function mode(): string {
		$mode = (string) Settings::get( 'agents.mode', 'off' );

		return in_array( $mode, self::MODES, true ) ? $mode : 'off';
	}

	public static function exposed(): bool {
		return 'off' !== self::mode();
	}

	/**
	 * Checked on every call, so switching the mode off denies a client that
	 * still holds a cached tool list.
	 */
	public static function read(): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'gtperf_forbidden', __( 'GT Performance abilities require an administrator account on this site.', 'gt-performance' ), array( 'status' => 403 ) );
		}
		if ( ! self::exposed() ) {
			return new \WP_Error( 'gtperf_agents_disabled', __( 'GT Performance agent access is off. An administrator can allow read-only access on the GT Performance AI tab.', 'gt-performance' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public static function operate(): bool|\WP_Error {
		$read = self::read();
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		if ( 'operate' !== self::mode() ) {
			return new \WP_Error( 'gtperf_agents_read_only', __( 'GT Performance agent access is read-only. An administrator can allow operations on the GT Performance AI tab.', 'gt-performance' ), array( 'status' => 403 ) );
		}

		return true;
	}
}
