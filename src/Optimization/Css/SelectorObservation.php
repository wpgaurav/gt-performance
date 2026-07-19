<?php
/**
 * Privacy-safe selector observations from administrator training sessions.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

final class SelectorObservation {
	/**
	 * @param list<string> $selectors Candidate selectors.
	 * @return list<string>
	 */
	public function sanitizeMany( array $selectors ): array {
		$clean = array();
		foreach ( array_slice( $selectors, 0, 500 ) as $selector ) {
			$selector = $this->sanitize( $selector );
			if ( '' !== $selector ) {
				$clean[] = $selector;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	public function sanitize( string $selector ): string {
		$selector = trim( $selector );
		if ( '' === $selector || strlen( $selector ) > 120 ) {
			return '';
		}

		if ( str_contains( $selector, 'wpadminbar' ) || str_contains( $selector, 'gtp-' ) ) {
			return '';
		}

		if ( ! preg_match( '/^(?:[a-z][a-z0-9-]*)?(?:[.#][a-zA-Z_][a-zA-Z0-9_-]*){1,5}$/', $selector ) ) {
			return '';
		}

		return $selector;
	}
}
