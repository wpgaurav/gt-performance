<?php
/**
 * Inert configuration data files.
 *
 * Runtime copies are authenticated encrypted JSON, never PHP. The key is
 * derived from the site's existing AUTH_KEY and is never stored in the cache.
 * This protects secrets even on servers that ignore directory access rules.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class ConfigFile {
	/**
	 * Publish a configuration payload through an atomic same-filesystem rename so
	 * a drop-in can never read a half-written file.
	 *
	 * @param array<string, mixed> $config Configuration payload.
	 */
	public static function write( string $path, array $config ): bool {
		$json = wp_json_encode( $config );
		if ( ! is_string( $json ) || ! function_exists( 'openssl_encrypt' ) || ! defined( 'AUTH_KEY' ) || strlen( AUTH_KEY ) < 16 || 'put your unique phrase here' === AUTH_KEY ) {
			return false;
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $exception ) {
			return false;
		}
		$key = hash( 'sha256', 'gt-performance-runtime-v1|' . AUTH_KEY, true );
		$tag = '';
		$ciphertext = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'gt-performance-runtime-v1', 16 );
		if ( false === $ciphertext ) {
			return false;
		}
		$payload = wp_json_encode(
			array(
				'version' => 1,
				'data' => base64_encode( $iv . $tag . $ciphertext ),
			)
		);
		if ( ! is_string( $payload ) ) {
			return false;
		}
		$temp = dirname( $path ) . '/gtperf-config-' . wp_generate_uuid4() . '.json';
		$stream = fopen( $temp, 'xb' );
		if ( false === $stream ) {
			return false;
		}

		try {
			// Restrict the empty file before writing credentials; rename preserves
			// these permissions. Fail closed if the host cannot protect the file.
			if ( ! chmod( $temp, 0600 ) ) {
				return false;
			}
			if ( strlen( $payload ) !== fwrite( $stream, $payload ) ) {
				return false;
			}
			$closed = fclose( $stream );
			$stream = false;
			return $closed && rename( $temp, $path );
		} finally {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
			if ( is_file( $temp ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup after a failed write or atomic publish.
				@unlink( $temp );
			}
		}
	}

	/**
	 * Read a configuration payload, or null when the file is missing or invalid.
	 *
	 * Runs inside advanced-cache.php before WordPress loads, so it uses no
	 * WordPress functions.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function read( string $path ): ?array {
		// Configuration paths are local files, never remote URLs or PHP streams.
		$path = realpath( $path );
		if ( false === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent cache purge may remove this local file after validation.
		$raw = @file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			return null;
		}

		return self::decode( $raw );
	}

	/**
	 * Authenticate and decrypt the JSON envelope.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function decode( string $raw ): ?array {
		if ( ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) || strlen( AUTH_KEY ) < 16 || 'put your unique phrase here' === AUTH_KEY ) {
			return null;
		}
		$envelope = json_decode( $raw, true );
		if ( ! is_array( $envelope ) || 1 !== ( $envelope['version'] ?? null ) || ! is_string( $envelope['data'] ?? null ) ) {
			return null;
		}
		$bytes = base64_decode( $envelope['data'], true );
		if ( false === $bytes || strlen( $bytes ) <= 28 ) {
			return null;
		}
		$key = hash( 'sha256', 'gt-performance-runtime-v1|' . AUTH_KEY, true );
		$json = openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ), 'gt-performance-runtime-v1' );
		$decoded = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $decoded ) ? $decoded : null;
	}
}
