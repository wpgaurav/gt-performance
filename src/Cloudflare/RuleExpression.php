<?php
/**
 * Cloudflare Free-compatible cache rule compiler.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

final class RuleExpression {
	/**
	 * @param array<string, mixed> $cache Cache policy.
	 */
	public function compile( string $host, array $cache ): string {
		$parts = array(
			'(http.host eq "' . $this->escape( preg_replace( '/:\\d+$/', '', strtolower( $host ) ) ) . '")',
			'(http.request.method in {"GET" "HEAD"})',
		);

		foreach ( (array) ( $cache['bypass_paths'] ?? array() ) as $path ) {
			$path = (string) $path;
			if ( '' !== $path ) {
				$parts[] = '(not starts_with(http.request.uri.path, "' . $this->escape( $path ) . '"))';
			}
		}

		foreach ( (array) ( $cache['bypass_cookies'] ?? array() ) as $cookie ) {
			$cookie = (string) $cookie;
			if ( '' !== $cookie ) {
				$parts[] = '(not http.cookie contains "' . $this->escape( $cookie ) . '")';
			}
		}

		foreach ( (array) ( $cache['bypass_query_params'] ?? array() ) as $parameter ) {
			$parameter = (string) $parameter;
			if ( '' !== $parameter ) {
				$parts[] = '(not http.request.uri.query contains "' . $this->escape( rawurlencode( $parameter ) . '=' ) . '")';
			}
		}

		return implode( ' and ', $parts );
	}

	private function escape( string $value ): string {
		return addcslashes( $value, "\\\"\n\r" );
	}
}
