<?php
/**
 * Cache request context.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class RequestContext {
	/**
	 * @param array<string, string> $query Query values.
	 * @param array<string, string> $cookies Cookie values.
	 * @param array<string, string> $headers Request headers.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $scheme,
		public readonly string $host,
		public readonly string $path,
		public readonly array $query,
		public readonly array $cookies,
		public readonly array $headers,
		public readonly string $userAgent,
	) {
	}

	public static function fromGlobals(): self {
		$server = $_SERVER;
		$https  = isset( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] );
		$proto  = isset( $server['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( (string) $server['HTTP_X_FORWARDED_PROTO'] ) : '';
		$scheme = $https || 'https' === $proto ? 'https' : 'http';
		$uri    = (string) ( $server['REQUEST_URI'] ?? '/' );
		$parsedPath = wp_parse_url( $uri, PHP_URL_PATH );
		$path       = false === $parsedPath || null === $parsedPath ? '/' : (string) $parsedPath;
		$query  = array();
		$parsedQuery = wp_parse_url( $uri, PHP_URL_QUERY );
		parse_str( false === $parsedQuery || null === $parsedQuery ? '' : (string) $parsedQuery, $query );

		$cookies = array();
		foreach ( $_COOKIE as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$cookies[ (string) $key ] = (string) $value;
			}
		}

		$headers = array();
		foreach ( $server as $key => $value ) {
			if ( str_starts_with( (string) $key, 'HTTP_' ) && is_scalar( $value ) ) {
				$name             = strtolower( str_replace( '_', '-', substr( (string) $key, 5 ) ) );
				$headers[ $name ] = (string) $value;
			}
		}

		return new self(
			strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ),
			$scheme,
			strtolower( (string) ( $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '' ) ),
			self::normalizePath( $path ),
			self::scalarMap( $query ),
			$cookies,
			$headers,
			(string) ( $server['HTTP_USER_AGENT'] ?? '' ),
		);
	}

	/**
	 * Build a safe, cookie-free request context for diagnostics and policy previews.
	 *
	 * @param array<string, string> $cookies Diagnostic cookie names and inert values.
	 * @param array<string, string> $headers Diagnostic request headers.
	 */
	public static function fromUrl( string $url, array $cookies = array(), array $headers = array(), string $userAgent = '' ): ?self {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		return new self(
			'GET',
			'https' === strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) ? 'https' : 'http',
			strtolower( (string) $parts['host'] ),
			self::normalizePath( (string) ( $parts['path'] ?? '/' ) ),
			self::scalarMap( $query ),
			$cookies,
			array_change_key_case( $headers, CASE_LOWER ),
			$userAgent,
		);
	}

	/**
	 * @param array<mixed> $values Values.
	 * @return array<string, string>
	 */
	private static function scalarMap( array $values ): array {
		$result = array();
		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$result[ (string) $key ] = (string) $value;
			}
		}

		return $result;
	}

	public static function normalizePath( string $path ): string {
		$path = '/' . ltrim( $path, '/' );

		$normalized = preg_replace( '#/+#', '/', $path );

		return null === $normalized || '' === $normalized ? '/' : $normalized;
	}
}
