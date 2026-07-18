<?php
/**
 * Redis integration status module.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Redis;

use GTPerformance\Contracts\Module;

final class RedisModule implements Module {
	public function register(): void {
		// The drop-in runs before plugins. Runtime hooks are intentionally unnecessary.
	}
}
