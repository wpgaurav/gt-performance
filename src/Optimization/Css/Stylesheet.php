<?php
/**
 * Stylesheet value object.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

final class Stylesheet {
	public function __construct(
		public readonly string $source,
		public readonly string $css,
		public readonly string $media = 'all',
	) {
	}
}
