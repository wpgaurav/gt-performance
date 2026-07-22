<?php
/**
 * Cloudflare Free cache-rule compiler and budget planner.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

final class RuleCompiler {
	public const MANAGED_RULE_REF = 'gt-performance-free-html-cache';
	public const FREE_RULE_LIMIT  = 10;

	/**
	 * @param array<string, mixed> $cache Cache policy.
	 * @return array<string, mixed>
	 */
	public function rule( string $host, array $cache, int $edgeTtl = 0 ): array {
		$ignored = array_values( array_filter( array_map( 'strval', (array) ( $cache['ignored_query_params'] ?? array() ) ) ) );
		$edgeTtl = max( 0, min( 31536000, $edgeTtl ) );
		$action  = array(
			'cache'       => true,
			'edge_ttl'    => $edgeTtl > 0
				? array(
					'mode'    => 'override_origin',
					'default' => $edgeTtl,
				)
				: array( 'mode' => 'respect_origin' ),
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
			'ref'               => self::MANAGED_RULE_REF,
			'description'       => 'GT Performance: cache eligible public HTML',
			'expression'        => ( new RuleExpression() )->compile( $host, $cache ),
			'action'            => 'set_cache_settings',
			'action_parameters' => $action,
			'enabled'           => true,
		);
	}

	/**
	 * @param array<string, mixed>       $cache         Cache policy.
	 * @param list<array<string, mixed>> $existingRules Existing entrypoint rules.
	 * @return array<string, mixed>
	 */
	public function plan( string $host, array $cache, array $existingRules, int $limit = self::FREE_RULE_LIMIT, int $edgeTtl = 0 ): array {
		$expected    = $this->rule( $host, $cache, $edgeTtl );
		$managed     = null;
		$conflicts   = array();
		$normalizedHost = preg_replace( '/:\d+$/', '', strtolower( $host ) );

		foreach ( $existingRules as $rule ) {
			if ( self::MANAGED_RULE_REF === (string) ( $rule['ref'] ?? '' ) ) {
				$managed = $rule;
				continue;
			}

			if (
				'set_cache_settings' === (string) ( $rule['action'] ?? '' )
				&& ! empty( $rule['enabled'] )
				&& str_contains( (string) ( $rule['expression'] ?? '' ), (string) $normalizedHost )
			) {
				$conflicts[] = array(
					'id'          => $this->plainText( (string) ( $rule['id'] ?? '' ), 64 ),
					'ref'         => $this->plainText( (string) ( $rule['ref'] ?? '' ), 96 ),
					'description' => $this->plainText( (string) ( $rule['description'] ?? '' ), 160 ),
				);
			}
		}

		$expectedHash = $this->fingerprint( $expected );
		$liveHash     = is_array( $managed ) ? $this->fingerprint( $managed ) : '';
		$used         = count( $existingRules );

		return array(
			'operation'       => null === $managed ? 'create' : ( hash_equals( $expectedHash, $liveHash ) ? 'none' : 'update' ),
			'limit'           => max( 1, $limit ),
			'used'            => $used,
			'available'       => max( 0, max( 1, $limit ) - $used ),
			'within_budget'   => null !== $managed || $used < max( 1, $limit ),
			'managed_exists'  => null !== $managed,
			'drift'           => null === $managed || ! hash_equals( $expectedHash, $liveHash ),
			'expected_hash'   => $expectedHash,
			'live_hash'       => $liveHash,
			'expression'      => (string) $expected['expression'],
			'expression_size' => strlen( (string) $expected['expression'] ),
			'conflicts'       => $conflicts,
			'rule'            => $expected,
		);
	}

	/**
	 * @param array<string, mixed> $rule Cache rule.
	 */
	private function fingerprint( array $rule ): string {
		$portable = array(
			'ref'               => (string) ( $rule['ref'] ?? '' ),
			'description'       => (string) ( $rule['description'] ?? '' ),
			'expression'        => (string) ( $rule['expression'] ?? '' ),
			'action'            => (string) ( $rule['action'] ?? '' ),
			'action_parameters' => self::canonicalize( (array) ( $rule['action_parameters'] ?? array() ) ),
			'enabled'           => (bool) ( $rule['enabled'] ?? false ),
		);

		return hash( 'sha256', (string) json_encode( $portable, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Recursively sort array keys so the fingerprint is independent of the key
	 * order Cloudflare uses when it echoes the stored rule. Without this, any
	 * reordering in the API response is misread as configuration drift, causing an
	 * endless redundant PATCH on every sync.
	 *
	 * @param array<string, mixed> $value Rule fragment.
	 * @return array<string, mixed>
	 */
	private static function canonicalize( array $value ): array {
		ksort( $value );
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::canonicalize( $item );
			}
		}

		return $value;
	}

	private function plainText( string $value, int $limit ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', strip_tags( $value ) ) ?? '';

		return substr( trim( $value ), 0, $limit );
	}
}
