<?php
/**
 * Self-hosted channel bootstrap.
 *
 * Discovered by gt-performance.php through its glob over src/<Area>/channel.php.
 * The WordPress.org package excludes src/SelfHosted, as it does the licensing
 * area: directory review asked for minification without generated JavaScript
 * files, so that package keeps the transient and signed endpoint alone. This
 * package also publishes each minified script as a static file the web server
 * answers without PHP.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'gt_performance_loaded',
	static function (): void {
		( new \GTPerformance\SelfHosted\StaticScripts() )->register();
	}
);
