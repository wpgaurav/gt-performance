<?php
/**
 * The non-secret projection of settings that leaves wp-admin.
 *
 * Used by read-only abilities today and by settings proposals later. It is an
 * allowlist: a section is either copied whole (it holds behavior toggles only)
 * or narrowed to named keys (it sits next to credentials or provider
 * identifiers). Any key whose name looks like a credential is dropped even in
 * a whole section, so a secret added later cannot leak by default.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class PublicSettings {
	/**
	 * Section => null to copy every key, or the keys to copy.
	 *
	 * @var array<string, list<string>|null>
	 */
	private const SECTIONS = array(
		'cache'        => null,
		'css'          => null,
		'javascript'   => null,
		'media'        => null,
		'fonts'        => null,
		'database'     => null,
		'bloat'        => null,
		'commerce'     => null,
		'integrations' => null,
		'agents'       => null,
		'speculation'  => null,
		'advisor'      => null,
		'cdn'          => array( 'enabled', 'url', 'file_types' ),
		'cloudflare'   => array( 'enabled', 'auth_mode', 'domain', 'zone_id', 'edge_ttl' ),
		'xcloud'       => array( 'enabled', 'domain', 'stack', 'page_cache_enabled', 'page_cache_source', 'redis_enabled', 'object_cache_pro', 'free_edge_cache_enabled', 'enterprise_available', 'checked_at' ),
		'redis'        => array( 'enabled', 'port', 'database', 'tls', 'persistent' ),
	);

	private const SECRET_KEY = '/token|secret|password|passwd|username|email|api_?key|private|credential/i';

	/**
	 * Keys the credential pattern matches that hold no secret, reviewed one by
	 * one. The pattern still drops any new key, so this list only ever loosens
	 * the guard for a name someone has checked.
	 */
	private const NOT_SECRET = array(
		'bloat.disable_password_strength_meter',
	);

	/**
	 * @return list<string>
	 */
	public static function sections(): array {
		return array_keys( self::SECTIONS );
	}

	/**
	 * @param array<string, mixed>|null $settings Settings; defaults to the saved ones.
	 * @return array<string, mixed>
	 */
	public static function view( ?array $settings = null ): array {
		$settings = $settings ?? Settings::all();
		$view     = array(
			'generation' => (int) ( $settings['generation'] ?? 1 ),
			'debug'      => (bool) ( $settings['debug'] ?? false ),
		);

		foreach ( self::SECTIONS as $section => $keys ) {
			$values = (array) ( $settings[ $section ] ?? array() );
			if ( null !== $keys ) {
				$values = array_intersect_key( $values, array_flip( $keys ) );
			}
			$view[ $section ] = array_filter(
				$values,
				static fn ( $key ): bool => in_array( $section . '.' . $key, self::NOT_SECRET, true ) || 1 !== preg_match( self::SECRET_KEY, (string) $key ),
				ARRAY_FILTER_USE_KEY
			);
		}

		return $view;
	}

	/**
	 * Changes with any exposed value or the settings generation, which advances
	 * on every save. It covers only the projection, so it reveals nothing about
	 * the credentials it leaves out.
	 *
	 * @param array<string, mixed> $view Projection from view().
	 */
	public static function hash( array $view ): string {
		return hash( 'sha256', (string) wp_json_encode( $view ) );
	}
}
