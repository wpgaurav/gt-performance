<?php
/**
 * Signed, configuration-only fleet policy bundles.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Fleet;

final class PolicyBundle {
	public const SCHEMA = 1;

	public function __construct(
		private readonly string $key,
	) {
	}

	/**
	 * @param array<string, mixed> $settings Plugin settings.
	 * @param list<string>         $modules  Allowed top-level modules.
	 * @return array<string, mixed>
	 */
	public function create( array $settings, array $modules, string $sourceId, int $issuedAt, string $bundleId ): array {
		$policy = array();
		foreach ( array_values( array_unique( $modules ) ) as $module ) {
			if ( isset( $settings[ $module ] ) && is_array( $settings[ $module ] ) ) {
				$policy[ $module ] = $settings[ $module ];
			}
		}

		$this->removeSecrets( $policy );
		$bundle = array(
			'product'   => 'gt-performance',
			'schema'    => self::SCHEMA,
			'bundle_id' => $bundleId,
			'source_id' => $sourceId,
			'issued_at' => $issuedAt,
			'policy'    => $policy,
		);
		$bundle['signature'] = $this->signature( $bundle );

		return $bundle;
	}

	/**
	 * @param array<string, mixed> $bundle Signed bundle.
	 */
	public function verify( array $bundle, int $now, int $maximumAge = 300 ): bool {
		$signature = (string) ( $bundle['signature'] ?? '' );
		$issuedAt  = (int) ( $bundle['issued_at'] ?? 0 );
		if (
			'gt-performance' !== (string) ( $bundle['product'] ?? '' )
			|| self::SCHEMA !== (int) ( $bundle['schema'] ?? 0 )
			|| '' === (string) ( $bundle['bundle_id'] ?? '' )
			|| ! is_array( $bundle['policy'] ?? null )
			|| $issuedAt <= 0
			|| abs( $now - $issuedAt ) > max( 30, $maximumAge )
			|| '' === $signature
		) {
			return false;
		}

		unset( $bundle['signature'] );

		return hash_equals( $this->signature( $bundle ), $signature );
	}

	/**
	 * @param array<string, mixed> $bundle Verified bundle.
	 * @return array<string, mixed>
	 */
	public function policy( array $bundle ): array {
		$policy = is_array( $bundle['policy'] ?? null ) ? $bundle['policy'] : array();
		$this->removeSecrets( $policy );

		return $policy;
	}

	/**
	 * @param array<string, mixed> $values Values to strip in place.
	 */
	private function removeSecrets( array &$values ): void {
		foreach ( $values as $key => &$value ) {
			if ( preg_match( '/(?:token|secret|password|api_key|license|activation_hash)/i', (string) $key ) ) {
				unset( $values[ $key ] );
				continue;
			}
			if ( is_array( $value ) ) {
				$this->removeSecrets( $value );
			}
		}
		unset( $value );
	}

	/**
	 * @param array<string, mixed> $bundle Unsigned bundle.
	 */
	private function signature( array $bundle ): string {
		unset( $bundle['signature'] );
		$canonical = $this->canonicalize( $bundle );

		return hash_hmac( 'sha256', (string) json_encode( $canonical, JSON_UNESCAPED_SLASHES ), $this->key );
	}

	private function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( $this, 'canonicalize' ), $value );
		}

		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}

		return $value;
	}
}
