<?php
/**
 * Pure feature ownership decisions for overlapping optimization plugins.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Compatibility;

final class FeatureOwnership {
	public function gtOwns( string $mode, bool $gtFeatureEnabled ): bool {
		if ( 'perfmatters' === $mode ) {
			return false;
		}

		return 'gt_performance' === $mode || $gtFeatureEnabled;
	}

	public function disablePerfmatters( string $mode, bool $gtFeatureEnabled ): bool {
		if ( 'perfmatters' === $mode ) {
			return false;
		}

		return 'gt_performance' === $mode || $gtFeatureEnabled;
	}
}
