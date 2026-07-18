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
	private const RULE_REF = 'gt-performance-free-html-cache';

	public function __construct(
		private readonly ApiClient $client,
	) {
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

		$rule = $this->rule( $host, $cache );

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

		foreach ( (array) ( $ruleset['rules'] ?? array() ) as $existing ) {
			if ( is_array( $existing ) && self::RULE_REF === ( $existing['ref'] ?? '' ) ) {
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
	 * @param array<string, mixed> $cache Cache policy.
	 * @return array<string, mixed>
	 */
	private function rule( string $host, array $cache ): array {
		$ignored = array_values( array_filter( array_map( 'strval', (array) ( $cache['ignored_query_params'] ?? array() ) ) ) );
		$action  = array(
			'cache'       => true,
			'edge_ttl'    => array( 'mode' => 'respect_origin' ),
			'browser_ttl' => array( 'mode' => 'respect_origin' ),
			'serve_stale' => array( 'disable_stale_while_updating' => false ),
			'cache_key'   => array(
				'cache_deception_armor'      => true,
				'ignore_query_strings_order' => true,
				'cache_by_device_type'       => (bool) ( $cache['separate_mobile'] ?? false ),
			),
		);

		if ( $ignored ) {
			$action['cache_key']['custom_key'] = array(
				'query_string' => array(
					'exclude' => array( 'list' => $ignored ),
				),
			);
		}

		return array(
			'ref'               => self::RULE_REF,
			'description'       => 'GT Performance: cache eligible public HTML',
			'expression'        => ( new RuleExpression() )->compile( $host, $cache ),
			'action'            => 'set_cache_settings',
			'action_parameters' => $action,
			'enabled'           => true,
		);
	}

	/**
	 * Retries without a custom cache key when the zone's Free-plan capabilities reject it.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function requestWithFreeFallback( string $method, string $path, array $body ): array|\WP_Error {
		$result = $this->client->request( $method, $path, $body );
		if ( ! is_wp_error( $result ) ) {
			delete_option( 'gt_performance_cloudflare_query_key_fallback' );
			return $result;
		}

		$fallback = $body;
		if ( isset( $fallback['rules'][0]['action_parameters']['cache_key']['custom_key'] ) ) {
			unset( $fallback['rules'][0]['action_parameters']['cache_key']['custom_key'] );
		} elseif ( isset( $fallback['action_parameters']['cache_key']['custom_key'] ) ) {
			unset( $fallback['action_parameters']['cache_key']['custom_key'] );
		} else {
			return $result;
		}

		$retried = $this->client->request( $method, $path, $fallback );
		if ( ! is_wp_error( $retried ) ) {
			update_option( 'gt_performance_cloudflare_query_key_fallback', true, false );
		}

		return $retried;
	}
}
