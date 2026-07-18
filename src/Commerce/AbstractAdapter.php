<?php
/**
 * Commerce adapter helpers.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

abstract class AbstractAdapter implements CommerceAdapter {
	/**
	 * @param list<string|false> $urls URLs.
	 * @return list<string>
	 */
	protected function pathsFromUrls( array $urls ): array {
		$paths = array();
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$paths[] = trailingslashit( '/' . ltrim( $path, '/' ) );
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * @param list<int> $postIds Page IDs.
	 * @return list<string>
	 */
	protected function pathsFromPageIds( array $postIds ): array {
		$urls = array();
		foreach ( $postIds as $postId ) {
			if ( $postId > 0 ) {
				$urls[] = get_permalink( $postId );
			}
		}

		return $this->pathsFromUrls( $urls );
	}

	/**
	 * @return list<string>
	 */
	protected function commonRelatedUrls( int $postId, string $postType ): array {
		$urls = array_filter(
			array(
				get_permalink( $postId ),
				home_url( '/' ),
				get_post_type_archive_link( $postType ),
			),
			'is_string'
		);

		$taxonomies = get_object_taxonomies( $postType, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $postId, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( is_string( $url ) ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}
}
