<?php
/**
 * Scoped Cloudflare API token provisioning.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

use GTPerformance\Core\Settings;

final class TokenProvisioner {
	/**
	 * Least-privilege permission groups for everything this plugin does: read the
	 * zone, purge cache, and maintain the managed cache rule.
	 *
	 * `id` is what the API expects when minting a token. `key` and `type` are the
	 * separate vocabulary the dashboard's token template links use. `label` is what
	 * a person has to pick from the dropdown when creating the token by hand.
	 *
	 * @var list<array{id:string, key:string, type:string, label:string}>
	 */
	private const PERMISSION_GROUPS = array(
		array(
			'id'    => 'c8fed203ed3043cba015a93ad1616f1f',
			'key'   => 'zone',
			'type'  => 'read',
			'label' => 'Zone → Zone → Read',
		),
		array(
			'id'    => 'e17beae8b8cb423a99b1730f21238bed',
			'key'   => 'cache_purge',
			'type'  => 'purge',
			'label' => 'Zone → Cache Purge → Purge',
		),
		array(
			'id'    => '9ff81cbbe65c400b97d92c3c1033cab6',
			'key'   => 'cache_settings',
			'type'  => 'edit',
			'label' => 'Zone → Cache Rules → Edit (Cache Settings Write)',
		),
	);

	/**
	 * Cloudflare's documented token template link, opened in the dashboard the
	 * person is already signed in to. Cloudflare exposes no authorization flow that
	 * would let this plugin sign in on anyone's behalf.
	 *
	 * Checked against the live dashboard on 2026-08-18: the link opens the Create
	 * Token form and fills in the token name, but Cloudflare does not currently
	 * preselect the permission groups, and it collapses entries sharing a scope and
	 * type into one row. That is equally true of Cloudflare's own documented
	 * example, so the on-screen permission list stays the authoritative instruction
	 * and these keys are sent for the day the dashboard honours them again.
	 *
	 * @param array<string, mixed>|null $settings Plugin settings.
	 */
	public function templateUrl( ?array $settings = null ): string {
		$settings = $settings ?? Settings::all();
		$zoneId   = trim( (string) ( $settings['cloudflare']['zone_id'] ?? '' ) );

		$keys = array();
		foreach ( self::PERMISSION_GROUPS as $group ) {
			$keys[] = array(
				'key'  => $group['key'],
				'type' => $group['type'],
			);
		}

		// Built by hand because add_query_arg() would encode these a second time.
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Link to the user's own Cloudflare dashboard; no asset is loaded from it.
		return 'https://dash.cloudflare.com/profile/api-tokens'
			. '?permissionGroupKeys=' . rawurlencode( (string) wp_json_encode( $keys ) )
			. '&accountId=' . rawurlencode( '*' )
			. '&zoneId=' . ( '' !== $zoneId ? rawurlencode( $zoneId ) : 'all' )
			. '&name=' . rawurlencode( $this->tokenName( ( new ClientFactory() )->domain( $settings ) ) );
	}

	/**
	 * The permissions a person must select, in the order the dashboard lists them.
	 *
	 * @return list<string>
	 */
	public function requiredPermissions(): array {
		return array_column( self::PERMISSION_GROUPS, 'label' );
	}

	/**
	 * Whether a Global API Key is available to mint a scoped token with.
	 *
	 * @param array<string, mixed>|null $settings Plugin settings.
	 */
	public function canProvision( ?array $settings = null ): bool {
		return ! is_wp_error( ( new ClientFactory() )->createGlobal( $settings ) );
	}

	/**
	 * Mint a zone-scoped API token and store it in place of the current credentials.
	 *
	 * The new token is exercised before it is saved. Cloudflare shows a token's
	 * secret exactly once, so saving one that turns out not to work would strand the
	 * connection with a credential nobody can recover.
	 *
	 * @return array<string, string>|\WP_Error
	 */
	public function provision(): array|\WP_Error {
		$settings = Settings::all();
		$factory  = new ClientFactory();

		$global = $factory->createGlobal( $settings );
		if ( is_wp_error( $global ) ) {
			return $global;
		}

		$domain = $factory->domain( $settings );
		$zoneId = trim( (string) ( $settings['cloudflare']['zone_id'] ?? '' ) );
		if ( '' === $zoneId ) {
			$zone = $global->zoneByName( $domain );
			if ( is_wp_error( $zone ) ) {
				return $zone;
			}
			$zoneId = (string) ( $zone['id'] ?? '' );
		}

		if ( '' === $zoneId ) {
			return new \WP_Error( 'gtperf_cloudflare_zone', __( 'The Cloudflare zone for this site could not be resolved, so a scoped token cannot be created.', 'gt-performance' ) );
		}

		$groups = array();
		foreach ( array_column( self::PERMISSION_GROUPS, 'id' ) as $id ) {
			$groups[] = array( 'id' => $id );
		}

		$created = $global->request(
			'POST',
			'user/tokens',
			array(
				'name'     => $this->tokenName( $domain ),
				'policies' => array(
					array(
						'effect'            => 'allow',
						'resources'         => array( 'com.cloudflare.api.account.zone.' . $zoneId => '*' ),
						'permission_groups' => $groups,
					),
				),
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$token = trim( (string) ( $created['result']['value'] ?? '' ) );
		if ( '' === $token ) {
			return new \WP_Error( 'gtperf_cloudflare_token', __( 'Cloudflare created a token but did not return its secret, so it cannot be saved.', 'gt-performance' ) );
		}

		$unusable = $this->verify( $token, $zoneId );
		if ( $unusable instanceof \WP_Error ) {
			return $unusable;
		}

		$settings['cloudflare']['api_token'] = ( new TokenCipher() )->encrypt( $token );
		$settings['cloudflare']['auth_mode'] = 'token';
		$settings['cloudflare']['zone_id']   = $zoneId;
		Settings::save( $settings );

		return array(
			'name' => $this->tokenName( $domain ),
			'zone' => $domain,
		);
	}

	/**
	 * Prove the new token can do the work before it replaces the working credentials.
	 *
	 * @return \WP_Error|null Error describing why the token is unusable, or null when it works.
	 */
	private function verify( string $token, string $zoneId ): ?\WP_Error {
		$client = new ApiClient( ApiCredentials::apiToken( $token ) );

		$status = $client->request( 'GET', 'user/tokens/verify' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$ruleset = $client->request( 'GET', 'zones/' . rawurlencode( $zoneId ) . '/rulesets/phases/http_request_cache_settings/entrypoint' );
		if ( is_wp_error( $ruleset ) ) {
			$code = (int) ( ( (array) $ruleset->get_error_data() )['status'] ?? 0 );
			// A zone with no cache ruleset yet answers 404, which still proves the
			// token reached the endpoint with sufficient permission.
			if ( 404 !== $code ) {
				return $ruleset;
			}
		}

		return null;
	}

	private function tokenName( string $domain ): string {
		return substr( 'GT Performance - ' . $domain, 0, 120 );
	}
}
