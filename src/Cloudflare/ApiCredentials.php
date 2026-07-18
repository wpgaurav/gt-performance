<?php
/**
 * Cloudflare API authentication headers.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

final class ApiCredentials {
	private function __construct(
		private readonly string $mode,
		private readonly string $token = '',
		private readonly string $email = '',
		private readonly string $globalKey = '',
	) {
	}

	public static function apiToken( string $token ): self {
		return new self( 'token', $token );
	}

	public static function globalKey( string $email, string $globalKey ): self {
		return new self( 'global', '', $email, $globalKey );
	}

	public function mode(): string {
		return $this->mode;
	}

	/**
	 * @return array<string, string>
	 */
	public function headers(): array {
		if ( 'global' === $this->mode ) {
			return array(
				'X-Auth-Email' => $this->email,
				'X-Auth-Key'   => $this->globalKey,
			);
		}

		return array( 'Authorization' => 'Bearer ' . $this->token );
	}
}
