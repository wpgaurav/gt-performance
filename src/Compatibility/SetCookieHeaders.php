<?php
/**
 * Selective Set-Cookie header filtering.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Compatibility;

final class SetCookieHeaders {
	/**
	 * @param list<string> $headers Response headers.
	 * @return array{removed: bool, kept: list<string>}
	 */
	public function removeCookie( array $headers, string $cookieName ): array {
		$removed = false;
		$kept    = array();

		foreach ( $headers as $header ) {
			if ( ! str_starts_with( strtolower( $header ), 'set-cookie:' ) ) {
				continue;
			}

			$value = trim( substr( $header, strlen( 'set-cookie:' ) ) );
			$name  = trim( (string) strtok( $value, '=' ) );
			if ( $cookieName === $name ) {
				$removed = true;
				continue;
			}

			$kept[] = $header;
		}

		return array(
			'removed' => $removed,
			'kept'    => $kept,
		);
	}
}
