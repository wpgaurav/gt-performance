<?php
/**
 * Cloudflare API secret encryption.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

use GTPerformance\Core\SecretCipher;

final class TokenCipher {
	public function encrypt( string $plain ): string {
		return ( new SecretCipher( 'cloudflare' ) )->encrypt( $plain );
	}

	public function decrypt( string $stored, string $constantName = 'GTPERF_CLOUDFLARE_API_TOKEN' ): string {
		return ( new SecretCipher( 'cloudflare' ) )->decrypt( $stored, $constantName );
	}
}
