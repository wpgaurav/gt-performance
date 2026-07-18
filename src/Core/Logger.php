<?php
/**
 * Redacted diagnostic logger.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Logger {
	/**
	 * @param array<string, scalar|null> $context Log context.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! (bool) Settings::get( 'debug', false ) ) {
			return;
		}

		$redacted = array();
		foreach ( $context as $key => $value ) {
			$redacted[ $key ] = preg_match( '/token|secret|password|key|cookie|nonce/i', $key )
				? '[redacted]'
				: $value;
		}

		$line = wp_json_encode(
			array(
				'time'    => gmdate( 'c' ),
				'level'   => sanitize_key( $level ),
				'message' => $message,
				'context' => $redacted,
			),
			JSON_UNESCAPED_SLASHES
		);

		if ( ! is_string( $line ) ) {
			return;
		}

		wp_mkdir_p( Paths::logs() );
		file_put_contents( Paths::logs() . '/gt-performance.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}
}
