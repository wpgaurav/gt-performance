<?php
/**
 * Purpose-bound signatures for registered private fragments.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\PrivateFragments;

final class Signer {
	public function __construct(
		private readonly string $key,
	) {
	}

	public static function forSite(): self {
		return new self( wp_salt( 'auth' ) . '|gt-performance-private-fragments' );
	}

	public function sign( string $fragmentId ): string {
		return hash_hmac( 'sha256', 'v1|' . $fragmentId, $this->key );
	}

	public function verify( string $fragmentId, string $signature ): bool {
		return '' !== $signature && hash_equals( $this->sign( $fragmentId ), $signature );
	}
}
