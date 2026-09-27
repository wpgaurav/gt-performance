<?php
/**
 * Field-level differences between two settings projections.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Configuration;

final class Diff {
	/**
	 * Paths are "section.key" (or a top-level key). Lists compare as whole values.
	 *
	 * @param array<string, mixed> $from Earlier values.
	 * @param array<string, mixed> $to   Later values.
	 * @param list<string>         $ignore Paths or sections to leave out.
	 * @return list<array{path:string,from:mixed,to:mixed}>
	 */
	public static function between( array $from, array $to, array $ignore = array( 'generation' ) ): array {
		$flatFrom = self::flatten( $from );
		$flatTo   = self::flatten( $to );
		$changes  = array();
		foreach ( array_unique( array_merge( array_keys( $flatFrom ), array_keys( $flatTo ) ) ) as $path ) {
			$path = (string) $path;
			if ( self::ignored( $path, $ignore ) ) {
				continue;
			}
			$a = $flatFrom[ $path ] ?? null;
			$b = $flatTo[ $path ] ?? null;
			if ( self::normalize( $a ) !== self::normalize( $b ) ) {
				$changes[] = array(
					'path' => $path,
					'from' => $a,
					'to'   => $b,
				);
			}
		}
		usort( $changes, static fn ( array $x, array $y ): int => strcmp( $x['path'], $y['path'] ) );

		return $changes;
	}

	/**
	 * @param array<string, mixed> $values Nested values.
	 * @return array<string, mixed>
	 */
	public static function flatten( array $values ): array {
		$flat = array();
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				foreach ( $value as $child => $childValue ) {
					$flat[ $key . '.' . $child ] = $childValue;
				}
				continue;
			}
			$flat[ (string) $key ] = $value;
		}

		return $flat;
	}

	/**
	 * @param list<string> $ignore Paths or sections.
	 */
	public static function ignored( string $path, array $ignore ): bool {
		foreach ( $ignore as $rule ) {
			if ( $path === $rule || str_starts_with( $path, $rule . '.' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Treat 1 and 1.0, or "1" and true, as they compare after sanitizing.
	 */
	private static function normalize( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'b:1' : 'b:0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 'n:' . ( 0.0 === (float) $value - (int) $value ? (string) (int) $value : (string) (float) $value );
		}

		return (string) wp_json_encode( $value );
	}
}
