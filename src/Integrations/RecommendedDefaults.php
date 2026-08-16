<?php
/**
 * Safe enable-time defaults for external integrations.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Integrations;

use GTPerformance\Core\Settings;

final class RecommendedDefaults {
	/**
	 * Return non-secret values the admin UI may apply when an integration is
	 * switched on. Text and numeric values are used only to fill empty fields;
	 * boolean safeguards are explicit recommendations for the newly enabled
	 * integration.
	 *
	 * @return array<string, array<string, array<string, bool|float|int|string|list<string>>>>
	 */
	public static function profiles( string $homeUrl = '' ): array {
		$defaults = Settings::defaults();
		$homeHost = strtolower( (string) wp_parse_url( $homeUrl, PHP_URL_HOST ) );

		return array(
			'cloudflare'        => array(
				'cloudflare' => array(
					'auth_mode' => (string) $defaults['cloudflare']['auth_mode'],
					'domain'    => $homeHost,
					'edge_ttl'  => (int) $defaults['cloudflare']['edge_ttl'],
				),
			),
			'xcloud'           => array(
				'xcloud' => array(
					'domain' => $homeHost,
				),
			),
			'cdn'              => array(
				'cdn' => array(
					'file_types' => array_values( array_map( 'strval', (array) $defaults['cdn']['file_types'] ) ),
				),
			),
			'compatibility'    => array(
				'integrations' => array(
					'perfmatters_owner' => (string) $defaults['integrations']['perfmatters_owner'],
					'akismet'           => true,
					'jetpack'           => true,
				),
			),
			'private_fragments' => array(
				'private_fragments' => array(
					'cart_count'   => true,
					'account_link' => true,
				),
			),
			'redis'            => array(
				'redis' => array(
					'host'               => (string) $defaults['redis']['host'],
					'port'               => (int) $defaults['redis']['port'],
					'database'           => (int) $defaults['redis']['database'],
					'connection_timeout' => (float) $defaults['redis']['connection_timeout'],
					'read_timeout'       => (float) $defaults['redis']['read_timeout'],
				),
			),
			'fleet'            => array(
				'fleet' => array(
					'allow_imports'  => true,
					'policy_modules' => array_values( array_map( 'strval', (array) $defaults['fleet']['policy_modules'] ) ),
				),
			),
		);
	}
}
