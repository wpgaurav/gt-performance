<?php
/**
 * Bounded private diagnostics stored in WordPress, never in public log files.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Logger {
	public const OPTION = 'gt_performance_diagnostic_log';

	/** @param array<string, scalar|null> $context Log context. */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! (bool) Settings::get( 'debug', false ) ) {
			return;
		}
		$redacted = array();
		foreach ( array_slice( $context, 0, 20, true ) as $key => $value ) {
			$redacted[ substr( $key, 0, 80 ) ] = preg_match( '/token|secret|password|key|cookie|nonce|authorization/i', $key )
				? '[redacted]'
				: ( is_string( $value ) ? self::redact( $value ) : $value );
		}
		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? array_slice( $entries, -99 ) : array();
		$entries[] = array(
			'time' => gmdate( 'c' ),
			'level' => sanitize_key( $level ),
			'message' => self::redact( $message ),
			'context' => $redacted,
		);
		update_option( self::OPTION, $entries, false );
	}

	/** Remove secrets from diagnostic messages before persisting or displaying them. */
	public static function redact( string $value ): string {
		// Error strings can carry URLs with credentials even when their key is "error".
		$value = preg_replace_callback(
			'#https?://[^\s<>"\']+#i',
			static function ( array $match ): string {
				$parts = wp_parse_url( $match[0] );
				return is_array( $parts ) && isset( $parts['host'] )
					? ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( $parts['path'] ?? '' )
					: '[redacted-url]';
			},
			$value
		) ?? '';
		$value = preg_replace( '/\b(?:Bearer\s+\S+|(?:password|token|secret|nonce|api[_-]?key)\s*[:=]\s*\S+)/i', '[redacted]', $value ) ?? '';
		return substr( $value, 0, 1000 );
	}

	/** Remove only the two known plaintext log files from older releases. */
	public static function removeLegacyFiles(): bool {
		if ( ! Paths::cacheRootIsSafe() || is_link( Paths::logs() ) ) {
			return false;
		}
		$removed = true;
		foreach ( array( 'gt-performance.log', 'gt-performance.log.1' ) as $name ) {
			$file = Paths::logs() . '/' . $name;
			if ( is_file( $file ) || is_link( $file ) ) {
				wp_delete_file( $file );
				clearstatcache( true, $file );
				$removed = ! file_exists( $file ) && ! is_link( $file ) && $removed;
			}
		}
		return $removed;
	}
}
