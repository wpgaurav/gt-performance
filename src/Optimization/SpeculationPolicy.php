<?php
/**
 * Safe speculative loading on top of WordPress core (6.8+).
 *
 * Core already prefetches links on hover or press and excludes admin, login,
 * uploads, theme and plugin files, and (with pretty permalinks) every URL with
 * a query string. This adds what only this plugin knows: the cache bypass
 * paths, which include every active commerce adapter's cart, checkout, and
 * account paths. Prefetching those starts sessions and spends PHP workers on
 * pages that are never cached. With plain permalinks, where content itself is
 * addressed by query string, links carrying action, cart, download, key, or
 * signature parameters are excluded as well.
 *
 * Modes: `core` adds exclusions and leaves core's mode and eagerness alone
 * (the default); `conservative` also pins prefetch on press; `off` disables
 * speculative loading. When another plugin owns speculation, only the
 * exclusions are added. Browsers already skip speculation under Data Saver.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Settings;

final class SpeculationPolicy {
	/** @var list<string> */
	public const MODES = array( 'core', 'conservative', 'off' );

	/** Query parameters that change state, download files, or sign URLs. */
	private const ACTION_PARAMETERS = array( 'action', 'add-to-cart', 'remove_item', 'edd_action', 'eddfile', 'download_file', 'key', 'token', 'signature', 'redirect_to' );

	public static function available(): bool {
		return function_exists( 'wp_get_speculation_rules_configuration' );
	}

	public static function mode(): string {
		$mode = (string) Settings::get( 'speculation.mode', 'core' );

		return in_array( $mode, self::MODES, true ) ? $mode : 'core';
	}

	/**
	 * The Speculative Loading feature plugin, when active, owns mode and eagerness.
	 */
	public static function otherOwner(): string {
		return defined( 'SPECULATION_RULES_VERSION' ) || function_exists( 'plsr_get_speculation_rules' ) ? 'Speculative Loading plugin' : '';
	}

	public function register(): void {
		if ( ! self::available() ) {
			return;
		}
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'configuration' ), 20 );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'exclusions' ), 20 );
	}

	/**
	 * @param array<string, string>|null $config Core configuration.
	 * @return array<string, string>|null
	 */
	public function configuration( ?array $config ): ?array {
		$mode = self::mode();
		if ( 'off' === $mode ) {
			return null;
		}
		if ( 'conservative' !== $mode || '' !== self::otherOwner() || null === $config ) {
			return $config;
		}

		return array(
			'mode'      => 'prefetch',
			'eagerness' => 'conservative',
		);
	}

	/**
	 * @param list<string> $paths Paths core and other plugins already exclude.
	 * @return list<string>
	 */
	public function exclusions( array $paths ): array {
		$policy = (array) apply_filters( 'gt_performance_cache_policy', array( 'hosts' => Settings::canonicalHosts() ) + (array) Settings::get( 'cache', array() ) );

		return array_values( array_unique( array_merge( $paths, self::patterns( (array) ( $policy['bypass_paths'] ?? array() ), '' !== (string) get_option( 'permalink_structure' ) ) ) ) );
	}

	/**
	 * URL patterns for bypass path prefixes.
	 *
	 * @param list<mixed> $prefixes Cache bypass path prefixes.
	 * @return list<string>
	 */
	public static function patterns( array $prefixes, bool $pretty ): array {
		$patterns = array();
		foreach ( $prefixes as $prefix ) {
			$prefix = '/' . ltrim( trim( (string) $prefix ), '/' );
			if ( '/' === $prefix || str_contains( $prefix, '*' ) ) {
				continue;
			}
			// Core already covers these; leave them to it.
			if ( str_starts_with( $prefix, '/wp-admin' ) || str_starts_with( $prefix, '/wp-login.php' ) || str_starts_with( $prefix, '/wp-json' ) ) {
				continue;
			}
			$base       = rtrim( $prefix, '/' );
			$patterns[] = $base;
			$patterns[] = $base . '/*';
		}
		if ( ! $pretty ) {
			// Plain permalinks address content by query string, so core excludes only
			// nonce parameters there. Also exclude parameters that act or download.
			foreach ( self::ACTION_PARAMETERS as $parameter ) {
				$patterns[] = '/*\\?*(^|&)' . $parameter . '=*';
			}
		}

		return array_values( array_unique( $patterns ) );
	}
}
