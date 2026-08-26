<?php
/**
 * Inert configuration data files.
 *
 * Compiled configuration is stored as JSON and is never included or executed.
 * The files keep a `.php` extension so that a direct web request is terminated
 * by the PHP interpreter itself on servers that do not honour `.htaccess`,
 * which matters because the Redis configuration carries credentials. Only the
 * fixed guard line below is PHP; it never varies with the data, and the payload
 * that follows is read with file_get_contents() and json_decode().
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class ConfigFile {
	/**
	 * Fixed, data-independent first line. Everything after the newline is JSON.
	 *
	 * The PHP tag is closed so that the payload is inline text rather than PHP
	 * source. That matters: PHP parses a whole file before running any of it, so
	 * leaving the tag open would make the JSON a parse error instead of letting
	 * `exit` terminate a direct web request cleanly.
	 */
	public const GUARD = "<?php exit; /* GT Performance data file. Not executable configuration. */ ?>\n";

	/**
	 * Publish a configuration payload through an atomic same-filesystem rename so
	 * a drop-in can never read a half-written file.
	 *
	 * @param array<string, mixed> $config Configuration payload.
	 */
	public static function write( string $path, array $config ): bool {
		$json = wp_json_encode( $config );
		if ( ! is_string( $json ) ) {
			return false;
		}

		$temp = $path . '.' . wp_generate_uuid4() . '.tmp';
		if ( false === file_put_contents( $temp, self::GUARD . $json, LOCK_EX ) ) {
			return false;
		}

		if ( ! rename( $temp, $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup after a failed atomic publish.
			@unlink( $temp );
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some hosts disallow chmod; the guard line still protects the file.
		@chmod( $path, 0640 );

		return true;
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
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			return null;
		}

		return self::decode( $raw );
	}

	/**
	 * Strip the guard line and decode the JSON payload.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function decode( string $raw ): ?array {
		$break = strpos( $raw, "\n" );
		if ( false === $break ) {
			return null;
		}

		$decoded = json_decode( substr( $raw, $break + 1 ), true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
