<?php
/**
 * Selector safelist matching and validation.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

final class SelectorSafelist {
	/**
	 * @param list<string> $patterns Literal fragments or delimited regular expressions.
	 */
	public function matches( string $selector, array $patterns ): bool {
		foreach ( $this->validate( $patterns )['valid'] as $pattern ) {
			if ( $this->isRegularExpression( $pattern ) ) {
				if ( 1 === preg_match( $pattern, $selector ) ) {
					return true;
				}
				continue;
			}

			if ( str_contains( $selector, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $patterns Literal fragments or delimited regular expressions.
	 * @return array{valid:list<string>,invalid:list<string>}
	 */
	public function validate( array $patterns ): array {
		$valid   = array();
		$invalid = array();

		foreach ( array_values( array_unique( array_filter( array_map( 'trim', $patterns ) ) ) ) as $pattern ) {
			if ( $this->looksLikeRegularExpression( $pattern ) && ! $this->isRegularExpression( $pattern ) ) {
				$invalid[] = $pattern;
				continue;
			}

			$valid[] = $pattern;
		}

		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/**
	 * @param mixed $value Raw textarea or saved list.
	 * @return list<string>
	 */
	public static function split( mixed $value ): array {
		if ( is_string( $value ) ) {
			$lines = preg_split( '/\R+/', $value );
			$value = false === $lines ? array() : $lines;
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn( mixed $pattern ): string => trim( (string) $pattern ),
					$value
				),
				static fn( string $pattern ): bool => '' !== $pattern
			)
		);
	}

	private function looksLikeRegularExpression( string $pattern ): bool {
		return str_starts_with( $pattern, '/' );
	}

	private function isRegularExpression( string $pattern ): bool {
		if ( ! preg_match( '#^/.+/[imsxuADSUXJ]*$#s', $pattern ) ) {
			return false;
		}

		$valid = false;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporary guard while probing a user-supplied regular expression; restored immediately after.
		set_error_handler(
			static function (): bool {
				return true;
			}
		);
		try {
			$valid = false !== preg_match( $pattern, '' );
		} finally {
			restore_error_handler();
		}

		return $valid;
	}
}
