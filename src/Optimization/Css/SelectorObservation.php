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

		// The training script sends CSS.escape()d selectors, so class/id tokens may
		// carry escape sequences such as `\:`, `\/`, or a numeric `\32 ` prefix
		// (e.g. Tailwind utility classes `md\:flex`, `w-1\/2`). The previous pattern
		// rejected every escaped selector, so utility-class themes trained empty
		// safelists. Structural punctuation that could break out of a selector
		// (`;{}()<>"'` and commas) remains disallowed.
		$token = '(?:[a-zA-Z0-9_-]|\\\\[0-9a-fA-F]{1,6} ?|\\\\[^\s])+';
		if ( ! preg_match( '/^(?:[a-z][a-z0-9-]*)?(?:[.#]' . $token . '){1,5}$/', $selector ) ) {
			return '';
		}

		return $selector;
	}
}
