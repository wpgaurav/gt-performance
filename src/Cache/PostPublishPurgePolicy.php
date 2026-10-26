<?php
/**
 * Post-publish cache purge policy.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class PostPublishPurgePolicy {
	public const RELATED = 'related';
	public const POST    = 'post';
	public const ALL     = 'all';
	public const NONE    = 'none';

	public function sanitize( string $mode ): string {
		return in_array( $mode, $this->modes(), true ) ? $mode : self::RELATED;
	}

	/**
	 * @param list<string> $relatedUrls Post URL followed by related public URLs.
	 * @return array{all: bool, urls: list<string>}
	 */
	public function plan( string $mode, array $relatedUrls ): array {
		$mode = $this->sanitize( $mode );
		$urls = array_values(
			array_unique(
				array_filter(
					$relatedUrls,
					static fn( mixed $url ): bool => is_string( $url ) && '' !== trim( $url )
				)
			)
		);

		if ( self::NONE === $mode ) {
			return array(
				'all'  => false,
				'urls' => array(),
			);
		}

		if ( self::ALL === $mode ) {
			return array(
				'all'  => true,
				'urls' => array(),
			);
		}

		if ( self::POST === $mode ) {
			$urls = isset( $urls[0] ) ? array( $urls[0] ) : array();
		}

		return array(
			'all'  => false,
			'urls' => $urls,
		);
	}

	/**
	 * Drop page two and later of every listing from a post's recorded dependents.
	 *
	 * A post change reorders every page of each listing it appears in, but page
	 * one is the page that carries it and the one visitors land on. The later
	 * pages refresh when their cached copies expire. On gauravtiwari.org they were
	 * 111 of the 500 pages each save purged. Return true from
	 * `gt_performance_purge_paginated_listings` to purge them all again.
	 *
	 * @param list<string> $urls Recorded dependents.
	 * @return list<string>
	 */
	public function firstPagesOnly( array $urls ): array {
		if ( (bool) apply_filters( 'gt_performance_purge_paginated_listings', false ) ) {
			return $urls;
		}

		$base    = isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] ) && isset( $GLOBALS['wp_rewrite']->pagination_base )
			? (string) $GLOBALS['wp_rewrite']->pagination_base
			: 'page';
		$pattern = '#/' . preg_quote( '' !== $base ? $base : 'page', '#' ) . '/\d+/?$#';

		return array_values(
			array_filter(
				$urls,
				static function ( string $url ) use ( $pattern ): bool {
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );
					parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

					return ! preg_match( $pattern, $path ) && (int) ( $query['paged'] ?? 0 ) < 2;
				}
			)
		);
	}

	/**
	 * @return list<string>
	 */
	private function modes(): array {
		return array( self::RELATED, self::POST, self::ALL, self::NONE );
	}
}
