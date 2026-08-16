<?php
/**
 * Shared-cache response directives.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class SharedCacheHeaders {
	/**
	 * Keep a private response out of browsers, generic CDNs, and Cloudflare.
	 * Cloudflare's dedicated header has the highest origin-header precedence.
	 */
	public static function noStore(): void {
		header( 'Cache-Control: no-store, private, max-age=0' );
		header( 'CDN-Cache-Control: no-store' );
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
	}
}
