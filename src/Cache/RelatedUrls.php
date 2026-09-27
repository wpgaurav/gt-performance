<?php
/**
 * The conservative set of public URLs a post change may affect, with reasons.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class RelatedUrls {
	/**
	 * Post URL first, then home, archive, author, and current term archives.
	 *
	 * @return array<string, string> URL => reason.
	 */
	public static function forPost( \WP_Post $post ): array {
		$urls = array();
		self::add( $urls, get_permalink( $post ), 'permalink' );
		self::add( $urls, home_url( '/' ), 'home' );
		self::add( $urls, get_post_type_archive_link( $post->post_type ), 'post type archive' );
		if ( $post->post_author > 0 ) {
			self::add( $urls, get_author_posts_url( (int) $post->post_author ), 'author archive' );
		}

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy || ! $taxonomy->public ) {
				continue;
			}
			$terms = get_the_terms( $post->ID, $taxonomy->name );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				self::add( $urls, get_term_link( $term ), 'term archive: ' . $term->name );
			}
		}

		return $urls;
	}

	/**
	 * @param array<string, string> $urls URL => reason.
	 */
	private static function add( array &$urls, mixed $url, string $reason ): void {
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		// Filters can point an archive at another site (an author URL on a personal
		// domain, for example). Nothing of this site is cached there, and a
		// foreign URL must not reach this zone's edge purge.
		if ( ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), \GTPerformance\Core\Settings::canonicalHosts(), true ) ) {
			return;
		}
		// The posts archive is often the home URL without its trailing slash.
		foreach ( array_keys( $urls ) as $existing ) {
			if ( untrailingslashit( $existing ) === untrailingslashit( $url ) ) {
				return;
			}
		}
		$urls[ $url ] = $reason;
	}
}
