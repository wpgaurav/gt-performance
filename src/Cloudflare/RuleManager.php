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

	/**
	 * When deactivation removed the managed rule. Reactivating leaves the rule
	 * missing until the next sync, so the Cloudflare tab says so until then.
	 */
	public const REMOVED_OPTION = 'gt_performance_cloudflare_rule_removed';

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
		$result = $this->write( $zoneId, $host, $cache );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		delete_option( self::REMOVED_OPTION );

		// Cloudflare keeps what it stored while this rule was absent or different.
		// On gtp-demo.gatilab.com a request that arrived in the seconds before a
		// deleted rule stopped applying stored the page WordPress sent without GT
		// Performance (no Cache-Control, so Cloudflare's default two hours), and
		// recreating the rule served that copy as a HIT. Nothing cached under an
		// earlier rule is known to be right under this one.
		$purged                 = $this->client->purgeHosts( $zoneId, Settings::canonicalHosts() );
		$result['gtperf_purge'] = is_wp_error( $purged ) ? $purged->get_error_message() : 'ok';

		return $result;
	}

	/**
	 * Delete only the rule this site owns. Restoring a ruleset saved before the
	 * first sync would also undo every rule the site owner changed since, and
	 * another site in the same zone may own a rule of its own, so this host's
	 * managed rule is the only thing touched.
	 *
	 * @return string|\WP_Error `removed`, or `absent` when there was no managed rule.
	 */
	public function remove( string $zoneId, string $host ): string|\WP_Error {
		$entrypoint = $this->client->request(
			'GET',
			'zones/' . rawurlencode( $zoneId ) . '/rulesets/phases/http_request_cache_settings/entrypoint'
		);
		if ( is_wp_error( $entrypoint ) ) {
			$errorData = $entrypoint->get_error_data();
			return 404 === ( is_array( $errorData ) ? (int) ( $errorData['status'] ?? 0 ) : 0 ) ? 'absent' : $entrypoint;
		}

		$ruleset   = (array) ( $entrypoint['result'] ?? array() );
		$rulesetId = (string) ( $ruleset['id'] ?? '' );
		$rule      = RuleCompiler::ownedRule( array_values( array_filter( (array) ( $ruleset['rules'] ?? array() ), 'is_array' ) ), $host );
		if ( null === $rule || '' === $rulesetId ) {
			return 'absent';
		}
		$deleted = $this->client->request(
			'DELETE',
			'zones/' . rawurlencode( $zoneId ) . '/rulesets/' . rawurlencode( $rulesetId ) . '/rules/' . rawurlencode( (string) $rule['id'] )
		);

		return is_wp_error( $deleted ) ? $deleted : 'removed';
	}

	/**
	 * @param array<string, mixed> $cache Cache policy.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write( string $zoneId, string $host, array $cache ): array|\WP_Error {
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
			return new \WP_Error( 'gtperf_cloudflare_ruleset', __( 'Cloudflare did not return a cache ruleset ID.', 'gt-performance' ) );
		}

		$rules = array_values( array_filter( (array) ( $ruleset['rules'] ?? array() ), 'is_array' ) );
		$plan  = $this->compiler->plan( $host, $cache, $rules, RuleCompiler::FREE_RULE_LIMIT, $this->edgeTtl(), ! $this->customKeyRejected() );
		if ( ! (bool) $plan['within_budget'] ) {
			return new \WP_Error(
				'gtperf_cloudflare_rule_budget',
				__( 'The Cloudflare Free Cache Rules budget is full. Remove an unused rule or let GT Performance update its existing managed rule.', 'gt-performance' )
			);
		}

		$existing = RuleCompiler::ownedRule( $rules, $host );
		if ( null !== $existing ) {
			// Cloudflare refuses to change a ref, so an adopted legacy rule keeps its own.
			$rule['ref'] = (string) $existing['ref'];

			return $this->requestWithFreeFallback(
				'PATCH',
				'zones/' . rawurlencode( $zoneId ) . '/rulesets/' . rawurlencode( $rulesetId ) . '/rules/' . rawurlencode( (string) $existing['id'] ),
				$rule
			);
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
