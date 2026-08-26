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

	/**
	 * Build the context for the current request inside WordPress.
	 *
	 * WordPress adds slashes to the superglobals in wp_magic_quotes(), which
	 * runs after advanced-cache.php. Unslashing here is what keeps this context
	 * byte-identical to the one DropinRuntime builds from the raw superglobals;
	 * without it, any URL or cookie containing a quote would be hashed
	 * differently on each side and could never produce a cache hit.
	 */
	public static function fromGlobals(): self {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is sanitized below through the shared helpers the drop-in also uses.
		$server = array_map( 'wp_unslash', array_filter( $_SERVER, 'is_scalar' ) );
		$https  = isset( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] );
		$proto  = isset( $server['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( self::sanitizeValue( (string) $server['HTTP_X_FORWARDED_PROTO'] ) ) : '';
		$scheme = $https || 'https' === $proto ? 'https' : 'http';
		$uri    = self::sanitizeValue( (string) ( $server['REQUEST_URI'] ?? '/' ), self::MAX_PATH );

		$parsedPath = wp_parse_url( $uri, PHP_URL_PATH );
		$path       = false === $parsedPath || null === $parsedPath ? '/' : (string) $parsedPath;

		$query       = array();
		$parsedQuery = wp_parse_url( $uri, PHP_URL_QUERY );
		parse_str( false === $parsedQuery || null === $parsedQuery ? '' : (string) $parsedQuery, $query );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizeMap() strips control characters and bounds every name and value.
		$cookies = self::sanitizeMap( array_map( 'wp_unslash', array_filter( $_COOKIE, 'is_scalar' ) ) );

		$headers = array();
		foreach ( $server as $key => $value ) {
			if ( ! str_starts_with( (string) $key, 'HTTP_' ) ) {
				continue;
			}

			$name = self::sanitizeHeaderName( str_replace( '_', '-', substr( (string) $key, 5 ) ) );
			if ( '' !== $name ) {
				$headers[ $name ] = self::sanitizeValue( (string) $value );
			}
		}

		return new self(
			self::sanitizeMethod( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ),
			$scheme,
			self::sanitizeHost( (string) ( $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '' ) ),
			self::normalizePath( self::sanitizeValue( $path, self::MAX_PATH ) ),
			self::sanitizeMap( $query ),
			$cookies,
			$headers,
			self::sanitizeUserAgent( (string) ( $server['HTTP_USER_AGENT'] ?? '' ) ),
		);
	}

	/**
	 * Build a safe, cookie-free request context for diagnostics and policy previews.
	 *
	 * @param array<string, string> $cookies Diagnostic cookie names and inert values.
	 * @param array<string, string> $headers Diagnostic request headers.
	 */
	public static function fromUrl( string $url, array $cookies = array(), array $headers = array(), string $userAgent = '' ): ?self {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Loaded by advanced-cache.php before wp_parse_url() exists.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$host = strtolower( (string) $parts['host'] );
		if ( str_contains( $host, ':' ) && ! str_starts_with( $host, '[' ) ) {
			$host = '[' . $host . ']';
		}
		if ( isset( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}

		$diagnosticHeaders = array();
		foreach ( $headers as $name => $value ) {
			$clean = self::sanitizeHeaderName( (string) $name );
			if ( '' !== $clean ) {
				$diagnosticHeaders[ $clean ] = self::sanitizeValue( (string) $value );
			}
		}

		return new self(
			'GET',
			'https' === strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) ? 'https' : 'http',
			self::sanitizeHost( $host ),
			self::normalizePath( self::sanitizeValue( (string) ( $parts['path'] ?? '/' ), self::MAX_PATH ) ),
			self::sanitizeMap( $query ),
			self::sanitizeMap( $cookies ),
			$diagnosticHeaders,
			self::sanitizeUserAgent( $userAgent ),
		);
	}

	/**
	 * Sanitization bounds. The drop-in and the WordPress request path must apply
	 * byte-identical rules: a divergence would either make every cache key miss
	 * or, worse, let the drop-in reach a different bypass decision than
	 * WordPress and serve a cached page to a signed-in visitor.
	 */
	private const MAX_METHOD = 16;
	private const MAX_HOST   = 255;
	private const MAX_PATH   = 2048;
	private const MAX_NAME   = 128;
	private const MAX_VALUE  = 8192;
	private const MAX_AGENT  = 512;

	/**
	 * Strip control characters and bound the length of an untrusted value.
	 *
	 * Runs inside advanced-cache.php before WordPress loads, so it uses no
	 * WordPress functions.
	 */
	public static function sanitizeValue( string $value, int $max = self::MAX_VALUE ): string {
		$clean = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		return substr( null === $clean ? '' : $clean, 0, $max );
	}

	/**
	 * Reduce an untrusted key to printable ASCII without whitespace.
	 */
	public static function sanitizeName( string $name, int $max = self::MAX_NAME ): string {
		$clean = preg_replace( '/[^\x21-\x7E]/', '', $name );

		return substr( null === $clean ? '' : $clean, 0, $max );
	}

	public static function sanitizeMethod( string $method ): string {
		$clean = preg_replace( '/[^A-Za-z]/', '', $method );

		return strtoupper( substr( null === $clean ? '' : $clean, 0, self::MAX_METHOD ) );
	}

	public static function sanitizeHost( string $host ): string {
		$clean = preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', $host );

		return strtolower( substr( null === $clean ? '' : $clean, 0, self::MAX_HOST ) );
	}

	public static function sanitizeUserAgent( string $agent ): string {
		return self::sanitizeValue( $agent, self::MAX_AGENT );
	}

	/**
	 * Reduce an untrusted header name to the lower-cased HTTP token characters.
	 */
	public static function sanitizeHeaderName( string $name ): string {
		$clean = preg_replace( '/[^A-Za-z0-9\-]/', '', $name );

		return strtolower( substr( null === $clean ? '' : $clean, 0, self::MAX_NAME ) );
	}

	/**
	 * @param array<mixed> $values Untrusted key/value pairs.
	 * @return array<string, string>
	 */
	public static function sanitizeMap( array $values ): array {
		$result = array();
		foreach ( $values as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$name = self::sanitizeName( (string) $key );
			if ( '' === $name ) {
				continue;
			}

			$result[ $name ] = self::sanitizeValue( (string) $value );
		}

		return $result;
	}

	public static function normalizePath( string $path ): string {
		$path = '/' . ltrim( $path, '/' );

		$normalized = preg_replace( '#/+#', '/', $path );

		return null === $normalized || '' === $normalized ? '/' : $normalized;
	}
}
