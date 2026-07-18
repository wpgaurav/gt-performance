<?php
/**
 * Module contract.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Contracts;

interface Module {
	/**
	 * Register WordPress hooks for the module.
	 */
	public function register(): void;
}
