<?php
/**
 * Normalized FluentCart update metadata.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Licensing;

final class UpdateResponse {
	/**
	 * @param array<string, mixed> $response Raw update response.
	 * @return array<string, mixed>|null
	 */
	public static function normalize( array $response ): ?array {
		$version = trim( (string) ( $response['new_version'] ?? '' ) );
		if (
			'' === $version ||
			! preg_match(
				'/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/',
				$version
			)
		) {
			return null;
		}

		$sections = isset( $response['sections'] ) && is_array( $response['sections'] )
			? $response['sections']
			: array();
		$icons    = isset( $response['icons'] ) && is_array( $response['icons'] )
			? $response['icons']
			: array();
		$banners  = isset( $response['banners'] ) && is_array( $response['banners'] )
			? $response['banners']
			: array();

		return array(
			'name'           => trim( (string) ( $response['name'] ?? 'GT Performance' ) ),
			'slug'           => 'gt-performance',
			'new_version'    => $version,
			'stable_version' => trim( (string) ( $response['stable_version'] ?? $version ) ),
			'url'            => self::url( (string) ( $response['url'] ?? '' ) ),
			'homepage'       => self::url( (string) ( $response['homepage'] ?? '' ) ),
			'last_updated'   => trim( (string) ( $response['last_updated'] ?? '' ) ),
			'package'        => self::url( (string) ( $response['package'] ?? $response['download_link'] ?? '' ) ),
			'sections'       => array(
				'description' => (string) ( $sections['description'] ?? '' ),
				'changelog'   => (string) ( $sections['changelog'] ?? '' ),
			),
			'banners'        => array_filter(
				array(
					'low'  => self::url( (string) ( $banners['low'] ?? '' ) ),
					'high' => self::url( (string) ( $banners['high'] ?? '' ) ),
				)
			),
			'icons'          => array_filter(
				array(
					'1x' => self::url( (string) ( $icons['1x'] ?? '' ) ),
					'2x' => self::url( (string) ( $icons['2x'] ?? '' ) ),
				)
			),
			'requires'       => trim( (string) ( $response['requires'] ?? '6.6' ) ),
			'tested'         => trim( (string) ( $response['tested'] ?? '' ) ),
			'requires_php'   => trim( (string) ( $response['requires_php'] ?? '8.1' ) ),
			'license_status' => strtolower( trim( (string) ( $response['license_status'] ?? 'invalid' ) ) ),
		);
	}

	private static function url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}
}
