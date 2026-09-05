<?php
/**
 * FluentCart channel bootstrap.
 *
 * Discovered by gt-performance.php through a glob over src/<Area>/channel.php, so
 * no file that ships in every package needs to name this one. The WordPress.org
 * package excludes src/Licensing outright and therefore matches nothing here: it
 * contains no licensing code, no update client, and makes no remote call of its own.
 *
 * This exists because FluentCart's get_license_version endpoint returns a download
 * URL only for an activated license, so a self-hosted install without it has no
 * update path at all.
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
		( new \GTPerformance\Licensing\LicenseModule() )->register();
	}
);
