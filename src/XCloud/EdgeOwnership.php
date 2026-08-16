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
	public function xcloudOwnsEdge(): bool {
		return (bool) Settings::get( 'xcloud.enabled', false )
			&& (
				(bool) Settings::get( 'xcloud.enterprise_available', false )
				|| (bool) Settings::get( 'xcloud.free_edge_cache_enabled', false )
			);
	}

	public function hasDirectCloudflareConflict(): bool {
		return $this->xcloudOwnsEdge() && (bool) Settings::get( 'cloudflare.enabled', false );
	}
}
