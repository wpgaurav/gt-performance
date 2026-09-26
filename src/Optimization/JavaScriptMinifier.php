<?php
/**
 * In-memory minification with transient storage and signed external delivery.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\SafeMode;
use GTPerformance\Core\Settings;

final class JavaScriptMinifier {
	public const PARAM = 'gtperf_js';
	public const SOURCE_PARAM = 'gtperf_js_source';
	public const SIGNATURE_PARAM = 'gtperf_js_sig';
	public const CACHE_PREFIX = 'gtperf_js_';
	private const MAX_BYTES = 2 * MB_IN_BYTES;
	private const CACHE_TTL = 14 * DAY_IN_SECONDS;

	/** Return a signed URL only after a smaller result has been cached. */
	public function minifiedUrl( string $url ): ?string {
		$relative = $this->relativePath( $url );
		if ( null === $relative || str_ends_with( strtolower( $relative ), '.min.js' ) ) {
			return null;
		}
		$root = realpath( WP_CONTENT_DIR );
		$path = realpath( WP_CONTENT_DIR . '/' . $relative );
		if ( false === $root || false === $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		if ( false === $size || $size < 1 || $size > self::MAX_BYTES ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Read-only, realpath-validated local .js file inside wp-content; never a URL. Bounded against concurrent growth.
		$source = @file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 );
		if ( ! is_string( $source ) || strlen( $source ) > self::MAX_BYTES || preg_match( '/\bcurrentScript\b|\bimport\s*[.(]/', $source ) ) {
			// These scripts resolve resources relative to their own URL. Preserve it.
			return null;
		}

		$id = hash( 'sha256', GTPERF_VERSION . '|js-v1|' . $source );
		$cached = get_transient( self::CACHE_PREFIX . $id );
		if ( ! $this->validArtifact( $cached ) ) {
			try {
				// Disable the library's automatic file loading: only the already
				// validated source string may be processed, even if it resembles a path.
				$minifier = new class( $source ) extends \MatthiasMullie\Minify\JS {
					/**
					 * @param string $data JavaScript source text.
					 * @return string
					 */
					protected function load( $data ) {
						return $data;
					}
				};
				// No output path: this returns text and never writes a file.
				$code = $minifier->minify();
			} catch ( \Throwable ) {
				return null;
			}
			if ( '' === trim( $code ) || strlen( $code ) >= strlen( $source ) ) {
				return null;
			}
			$cached = array(
				'body' => $code,
				'hash' => hash( 'sha256', $code ),
			);
			if ( ! set_transient( self::CACHE_PREFIX . $id, $cached, self::CACHE_TTL ) ) {
				return null;
			}
		}

		return add_query_arg(
			array(
				self::PARAM => $id,
				self::SOURCE_PARAM => rawurlencode( $url ),
				self::SIGNATURE_PARAM => $this->signature( $id, $url ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Read a pre-generated artifact. Public requests never read or minify a file.
	 *
	 * The signed original URL supplies a safe fallback if WordPress evicts the
	 * transient while a page cache still references it. It is never fetched here.
	 *
	 * @param array<string, mixed> $query Decoded request query.
	 * @return array{status:int,headers:array<string,string>,body:string,redirect?:string}
	 */
	public function response( array $query, string $method = 'GET', string $ifNoneMatch = '' ): array {
		$headers = array(
			'Content-Type' => 'application/javascript; charset=UTF-8',
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control' => 'no-store, private, max-age=0',
		);
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$headers['Allow'] = 'GET, HEAD';
			return array(
				'status' => 405,
				'headers' => $headers,
				'body' => '',
			);
		}
		$id = $query[ self::PARAM ] ?? '';
		$source = $query[ self::SOURCE_PARAM ] ?? '';
		$signature = $query[ self::SIGNATURE_PARAM ] ?? '';
		if ( ! is_string( $id ) || ! is_string( $source ) || ! is_string( $signature ) || ! preg_match( '/^[a-f0-9]{64}$/D', $id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) || null === $this->relativePath( $source ) || ! hash_equals( $this->signature( $id, $source ), $signature ) ) {
			return array(
				'status' => 403,
				'headers' => $headers,
				'body' => '',
			);
		}

		$artifact = get_transient( self::CACHE_PREFIX . $id );
		if ( SafeMode::active() || ! (bool) Settings::get( 'javascript.minify', false ) || ! $this->validArtifact( $artifact ) ) {
			return array(
				'status' => 302,
				'headers' => $headers,
				'body' => '',
				'redirect' => $source,
			);
		}

		$etag = '"' . $artifact['hash'] . '"';
		$headers['ETag'] = $etag;
		$headers['Cache-Control'] = 'public, max-age=31536000, immutable';
		$headers['Vary'] = 'Accept-Encoding';
		$notModified = in_array( $etag, array_map( 'trim', explode( ',', str_replace( 'W/', '', $ifNoneMatch ) ) ), true );
		return array(
			'status' => $notModified ? 304 : 200,
			'headers' => $headers,
			'body' => $notModified || 'HEAD' === $method ? '' : $artifact['body'],
		);
	}

	/** Serve the signed read-only endpoint before templates and page optimization. */
	public function serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset route; the descriptor is authenticated with an HMAC below, not an expiring admin nonce.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		$query = array();
		foreach ( array( self::PARAM, self::SOURCE_PARAM, self::SIGNATURE_PARAM ) as $key ) {
			if ( self::SOURCE_PARAM === $key ) {
				// sanitize_text_field() removes percent-encoded URL bytes, which would
				// corrupt version values and invalidate an otherwise valid signature.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC-authenticated URL, checked against the site's content origin in response().
				$query[ $key ] = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? esc_url_raw( wp_unslash( $_GET[ $key ] ) ) : '';
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC-authenticated asset descriptor, strictly validated by response().
			$query[ $key ] = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$etag = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		$response = $this->response( $query, $method, $etag );
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( isset( $response['redirect'] ) ) {
			wp_safe_redirect( $response['redirect'], 302, 'GT Performance' );
		} else {
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hash-verified JavaScript asset served as application/javascript with nosniff, never HTML or server-executed code.
		}
		exit;
	}

	/** Return a decoded local .js path only for this site's public content URL. */
	private function relativePath( string $url ): ?string {
		if ( strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		$content = wp_parse_url( content_url( '/' ) );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $content ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		foreach ( array( $content, $home ) as $origin ) {
			foreach ( array( 'scheme', 'host', 'port' ) as $key ) {
				if ( ( $parts[ $key ] ?? null ) !== ( $origin[ $key ] ?? null ) ) {
					return null;
				}
			}
		}
		if ( ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return null;
		}
		$prefix = rtrim( (string) ( $content['path'] ?? '' ), '/' ) . '/';
		$path = (string) ( $parts['path'] ?? '' );
		if ( ! str_starts_with( $path, $prefix ) ) {
			return null;
		}
		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( array_diff( array_keys( $query ), array( 'ver' ) ) || ( isset( $query['ver'] ) && ! is_string( $query['ver'] ) ) ) {
			return null;
		}
		$relative = rawurldecode( substr( $path, strlen( $prefix ) ) );
		if ( ! str_ends_with( strtolower( $relative ), '.js' ) || preg_match( '#[\x00-\x20\x7f%\\\\]|(?:^|/)\.{1,2}(?:/|$)#', $relative ) ) {
			return null;
		}
		return $relative;
	}

	private function signature( string $id, string $source ): string {
		return hash_hmac( 'sha256', 'gtperf-js|' . $id . '|' . $source, wp_salt( 'auth' ) );
	}

	/**
	 * @phpstan-assert-if-true array{body:string,hash:string} $artifact
	 */
	private function validArtifact( mixed $artifact ): bool {
		return is_array( $artifact ) && isset( $artifact['body'], $artifact['hash'] )
			&& is_string( $artifact['body'] ) && is_string( $artifact['hash'] )
			&& '' !== $artifact['body'] && strlen( $artifact['body'] ) <= self::MAX_BYTES
			&& hash_equals( hash( 'sha256', $artifact['body'] ), $artifact['hash'] );
	}
}
