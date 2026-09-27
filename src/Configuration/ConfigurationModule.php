<?php
/**
 * Always-on settings bookkeeping: one hook that records revisions.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Configuration;

use GTPerformance\Contracts\Module;

final class ConfigurationModule implements Module {
	public function register(): void {
		( new RevisionRepository() )->register();
	}
}
