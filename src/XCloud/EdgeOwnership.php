<?php
/**
 * Resolves direct Cloudflare versus xCloud Enterprise edge ownership.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\XCloud;

use GTPerformance\Core\Settings;

final class EdgeOwnership {
	public function __construct(
		private readonly EnterprisePurgeHook $hook = new EnterprisePurgeHook(),
	) {
	}

	/**
	 * The edge is xCloud's when its Enterprise purge plugin is installed, which
	 * needs no xCloud API token, or when a connected xCloud site reports
	 * Enterprise or the free edge cache.
	 */
	public function xcloudOwnsEdge(): bool {
		if ( $this->enterprisePurgeHookActive() ) {
			return true;
		}

		return (bool) Settings::get( 'xcloud.enabled', false )
			&& (
				(bool) Settings::get( 'xcloud.enterprise_available', false )
				|| (bool) Settings::get( 'xcloud.free_edge_cache_enabled', false )
			);
	}

	public function enterprisePurgeHookActive(): bool {
		return $this->hook->available();
	}

	/**
	 * Both owners enabled with nothing to decide between them. When xCloud's
	 * purge plugin is installed the choice is already made: xCloud serves the
	 * site, so direct Cloudflare is paused instead of reported as a conflict.
	 */
	public function hasDirectCloudflareConflict(): bool {
		return ! $this->enterprisePurgeHookActive()
			&& $this->xcloudOwnsEdge()
			&& (bool) Settings::get( 'cloudflare.enabled', false );
	}

	/**
	 * Direct Cloudflare is switched on but paused, because xCloud's purge plugin
	 * shows xCloud serves the site. Its settings are kept and it resumes if the
	 * plugin is removed.
	 */
	public function directCloudflarePaused(): bool {
		return $this->enterprisePurgeHookActive() && (bool) Settings::get( 'cloudflare.enabled', false );
	}
}
