<?php
/**
 * Cache eligibility decision.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class Decision {
	public function __construct(
		public readonly bool $cacheable,
		public readonly string $reason,
	) {
	}

	public static function allow(): self {
		return new self( true, 'eligible' );
	}

	public static function deny( string $reason ): self {
		return new self( false, $reason );
	}
}
