<?php
/**
 * Deterministic per-URL CSS rollout gate.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

final class Rollout {
	public function allows( string $url, int $percentage, bool $preview = false ): bool {
		if ( $preview || $percentage >= 100 ) {
			return true;
		}
		if ( $percentage <= 0 ) {
			return false;
		}

		$bucket = hexdec( substr( hash( 'sha256', strtolower( $url ) ), 0, 8 ) ) % 100;

		return $bucket < $percentage;
	}
}
