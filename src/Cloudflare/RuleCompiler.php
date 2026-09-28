<?php
/**
 * Cloudflare Free cache-rule compiler and budget planner.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

final class RuleCompiler {
	/**
	 * The one ref every site used before 1.2.0. Refs are unique within a ruleset
	 * and cannot change once written, so two sites in one zone (example.com and
	 * shop.example.com) could not both own a rule under it, and each overwrote or
	 * deleted the other's. A rule under this ref still belongs to the site whose
	 * host its expression names, and keeps the ref when it is updated.
	 */
	public const LEGACY_RULE_REF = 'gt-performance-free-html-cache';
	public const FREE_RULE_LIMIT  = 10;

	/**
	 * Compile the managed cache rule.
	 *
	 * A custom cache key is an Enterprise capability. On plans that reject it the
	 * write succeeds only after RuleManager strips it, so the stored rule can never
	 * carry one. Callers that compare against the stored rule must pass
	 * $allowCustomKey = false, or every comparison reports drift that no sync can
	 * ever resolve.
	 *
	 * @param array<string, mixed> $cache Cache policy.
	 * @return array<string, mixed>
	 */
	public function rule( string $host, array $cache, int $edgeTtl = 0, bool $allowCustomKey = true ): array {
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

		if ( $ignored && $allowCustomKey ) {
			$action['cache_key']['custom_key'] = array(
				'query_string' => array(
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Cloudflare cache-rule schema, not a WP_Query parameter.
					'exclude' => array( 'list' => $ignored ),
				),
			);
		}

		return array(
			'ref'               => self::managedRef( $host ),
			'description'       => 'GT Performance: cache eligible public HTML',
			'expression'        => ( new RuleExpression() )->compile( $host, $cache, $edgeTtl > 0 ),
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
	public function plan( string $host, array $cache, array $existingRules, int $limit = self::FREE_RULE_LIMIT, int $edgeTtl = 0, bool $allowCustomKey = true ): array {
		$expected       = $this->rule( $host, $cache, $edgeTtl, $allowCustomKey );
		$managed        = null;
		$conflicts      = array();
		$normalizedHost = preg_replace( '/:\d+$/', '', strtolower( $host ) );

		foreach ( $existingRules as $rule ) {
			if ( null === $managed && self::owns( $rule, $host ) ) {
				$managed = $rule;
				// An adopted legacy rule keeps its ref, so the ref is not drift.
				$expected['ref'] = (string) $rule['ref'];
				continue;
			}

			if ( 'set_cache_settings' !== (string) ( $rule['action'] ?? '' ) || empty( $rule['enabled'] ) ) {
				continue;
			}

			// A rule that never mentions http.host applies to every hostname in the
			// zone, so a catch-all such as "true" overlaps this site even though the
			// hostname never appears in its expression.
			$expression = (string) ( $rule['expression'] ?? '' );
			// Quoted, so example.com does not match a rule for shop.example.com.
			$namesHost  = str_contains( $expression, '"' . $normalizedHost . '"' );
			$anyHost    = ! str_contains( $expression, 'http.host' );
			if ( ! $namesHost && ! $anyHost ) {
				continue;
			}

			$parameters  = (array) ( $rule['action_parameters'] ?? array() );
			$conflicts[] = array(
				'id'          => $this->plainText( (string) ( $rule['id'] ?? '' ), 64 ),
				'ref'         => $this->plainText( (string) ( $rule['ref'] ?? '' ), 96 ),
				'description' => $this->plainText( (string) ( $rule['description'] ?? '' ), 160 ),
				'expression'  => $this->plainText( $expression, 300 ),
				'scope'       => $anyHost ? 'every-host' : 'this-host',
				'bypasses'    => array_key_exists( 'cache', $parameters ) && false === $parameters['cache'],
			);
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
			'custom_key'      => isset( $expected['action_parameters']['cache_key']['custom_key'] ),
			'override_origin' => $edgeTtl > 0,
			'expression'      => (string) $expected['expression'],
			'expression_size' => strlen( (string) $expected['expression'] ),
			'conflicts'       => $conflicts,
			'rule'            => $expected,
		);
	}

	/**
	 * The ref of the rule this host owns: one per hostname, so sites that share a
	 * Cloudflare zone each keep their own rule.
	 */
	public static function managedRef( string $host ): string {
		return 'gt-performance-html-' . substr( hash( 'sha256', self::normalizeHost( $host ) ), 0, 16 );
	}

	/**
	 * Whether a live rule is the one this host manages: its own ref, or the
	 * pre-1.2.0 shared ref on a rule whose expression names this host.
	 *
	 * @param array<string, mixed> $rule Live rule.
	 */
	public static function owns( array $rule, string $host ): bool {
		$ref = (string) ( $rule['ref'] ?? '' );
		if ( '' === (string) ( $rule['id'] ?? 'unsaved' ) ) {
			return false;
		}

		return self::managedRef( $host ) === $ref
			|| ( self::LEGACY_RULE_REF === $ref && str_starts_with( (string) ( $rule['expression'] ?? '' ), '(http.host eq "' . self::normalizeHost( $host ) . '")' ) );
	}

	/**
	 * @param list<array<string, mixed>> $rules Live rules.
	 * @return array<string, mixed>|null
	 */
	public static function ownedRule( array $rules, string $host ): ?array {
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && self::owns( $rule, $host ) ) {
				return $rule;
			}
		}

		return null;
	}

	private static function normalizeHost( string $host ): string {
		return (string) preg_replace( '/:\d+$/', '', strtolower( $host ) );
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
		$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', wp_strip_all_tags( $value ) ) ?? '';

		return substr( trim( $value ), 0, $limit );
	}
}
