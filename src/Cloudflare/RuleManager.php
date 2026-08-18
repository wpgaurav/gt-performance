<?php
/**
 * Idempotent Cloudflare Cache Rule manager.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

use GTPerformance\Core\Settings;

final class RuleManager {
	/**
	 * Set once a plan has rejected a custom cache key, so drift comparisons expect
	 * the reduced rule Cloudflare is actually willing to store.
	 */
	public const QUERY_KEY_FALLBACK_OPTION = 'gt_performance_cloudflare_query_key_fallback';

	public function __construct(
		private readonly ApiClient $client,
		private readonly RuleCompiler $compiler = new RuleCompiler(),
	) {
	}

	/**
	 * @param array<string, mixed> $cache Cache policy.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview( string $zoneId, string $host, array $cache ): array|\WP_Error {
		$entrypoint = $this->client->request(
			'GET',
			'zones/' . rawurlencode( $zoneId ) . '/rulesets/phases/http_request_cache_settings/entrypoint'
		);

		// Compare against the rule shape this plan can actually store. Comparing
		// against the ideal rule on a plan that strips the custom cache key reports
		// drift forever, and no amount of syncing clears it.
		$allowCustomKey = ! $this->customKeyRejected();

		if ( is_wp_error( $entrypoint ) ) {
			$errorData = $entrypoint->get_error_data();
			$status    = is_array( $errorData ) ? (int) ( $errorData['status'] ?? 0 ) : 0;
			if ( 404 !== $status ) {
				return $entrypoint;
			}

			return $this->compiler->plan( $host, $cache, array(), RuleCompiler::FREE_RULE_LIMIT, $this->edgeTtl(), $allowCustomKey );
		}

		$ruleset            = (array) ( $entrypoint['result'] ?? array() );
		$rules              = array_values( array_filter( (array) ( $ruleset['rules'] ?? array() ), 'is_array' ) );
		$plan               = $this->compiler->plan( $host, $cache, $rules, RuleCompiler::FREE_RULE_LIMIT, $this->edgeTtl(), $allowCustomKey );
		$plan['ruleset_id'] = (string) ( $ruleset['id'] ?? '' );

		return $plan;
	}

	/**
	 * @param array<string, mixed> $cache Cache policy.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function sync( string $zoneId, string $host, array $cache ): array|\WP_Error {
		$entrypoint = $this->client->request(
			'GET',
			'zones/' . rawurlencode( $zoneId ) . '/rulesets/phases/http_request_cache_settings/entrypoint'
		);

		// Always attempt the ideal rule. If the plan has since gained Enterprise
		// cache-key support the write succeeds and clears the fallback flag on its own.
		$rule = $this->compiler->rule( $host, $cache, $this->edgeTtl() );

		if ( is_wp_error( $entrypoint ) ) {
			$errorData = $entrypoint->get_error_data();
			$status    = is_array( $errorData ) ? (int) ( $errorData['status'] ?? 0 ) : 0;
			if ( 404 !== $status ) {
				return $entrypoint;
			}

			return $this->requestWithFreeFallback(
				'POST',
				'zones/' . rawurlencode( $zoneId ) . '/rulesets',
				array(
					'name'        => 'GT Performance cache rules',
					'description' => 'Managed by GT Performance. Safe to remove by disconnecting the plugin.',
					'kind'        => 'zone',
					'phase'       => 'http_request_cache_settings',
					'rules'       => array( $rule ),
				)
			);
		}

		$ruleset   = (array) ( $entrypoint['result'] ?? array() );
		$rulesetId = (string) ( $ruleset['id'] ?? '' );
		if ( '' === $rulesetId ) {
			return new \WP_Error( 'gtp_cloudflare_ruleset', __( 'Cloudflare did not return a cache ruleset ID.', 'gt-performance' ) );
		}

		update_option( 'gt_performance_cloudflare_backup', $ruleset, false );
		$rules = array_values( array_filter( (array) ( $ruleset['rules'] ?? array() ), 'is_array' ) );
		$plan  = $this->compiler->plan( $host, $cache, $rules, RuleCompiler::FREE_RULE_LIMIT, $this->edgeTtl(), ! $this->customKeyRejected() );
		if ( ! (bool) $plan['within_budget'] ) {
			return new \WP_Error(
				'gtp_cloudflare_rule_budget',
				__( 'The Cloudflare Free Cache Rules budget is full. Remove an unused rule or let GT Performance update its existing managed rule.', 'gt-performance' )
			);
		}

		foreach ( $rules as $existing ) {
			if ( RuleCompiler::MANAGED_RULE_REF === ( $existing['ref'] ?? '' ) ) {
				$ruleId = (string) ( $existing['id'] ?? '' );
				if ( '' === $ruleId ) {
					break;
				}

				return $this->requestWithFreeFallback(
					'PATCH',
					'zones/' . rawurlencode( $zoneId ) . '/rulesets/' . rawurlencode( $rulesetId ) . '/rules/' . rawurlencode( $ruleId ),
					$rule
				);
			}
		}

		return $this->requestWithFreeFallback(
			'POST',
			'zones/' . rawurlencode( $zoneId ) . '/rulesets/' . rawurlencode( $rulesetId ) . '/rules',
			$rule
		);
	}

	/**
	 * Retries without a custom cache key when the zone's plan rejects it.
	 *
	 * The fallback flag is only touched when the payload actually carried a custom
	 * key. Clearing it after a write that never contained one would make the next
	 * sync expect the full rule again, and the two shapes would alternate forever.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function requestWithFreeFallback( string $method, string $path, array $body ): array|\WP_Error {
		$carriesCustomKey = isset( $body['rules'][0]['action_parameters']['cache_key']['custom_key'] )
			|| isset( $body['action_parameters']['cache_key']['custom_key'] );

		$result = $this->client->request( $method, $path, $body );
		if ( ! is_wp_error( $result ) ) {
			if ( $carriesCustomKey ) {
				delete_option( self::QUERY_KEY_FALLBACK_OPTION );
			}

			return $result;
		}

		if ( ! $carriesCustomKey ) {
			return $result;
		}

		$fallback = $body;
		if ( isset( $fallback['rules'][0]['action_parameters']['cache_key']['custom_key'] ) ) {
			unset( $fallback['rules'][0]['action_parameters']['cache_key']['custom_key'] );
		} else {
			unset( $fallback['action_parameters']['cache_key']['custom_key'] );
		}

		$retried = $this->client->request( $method, $path, $fallback );
		if ( ! is_wp_error( $retried ) ) {
			update_option( self::QUERY_KEY_FALLBACK_OPTION, true, false );
		}

		return $retried;
	}

	private function customKeyRejected(): bool {
		return (bool) get_option( self::QUERY_KEY_FALLBACK_OPTION, false );
	}

	private function edgeTtl(): int {
		return max( 0, min( 31536000, (int) Settings::get( 'cloudflare.edge_ttl', 0 ) ) );
	}
}
