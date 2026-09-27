<?php
/**
 * What a post query on a cached page subscribes to.
 *
 * A page depends on the posts it showed and on the set it asked for. The set
 * matters on its own: a newly published product never appeared on the page
 * that lists "latest products", yet it must invalidate it. Signatures:
 *
 * - `term:<id>`  a query limited (IN) to that term; any post joining or leaving it.
 * - `pt:<type>`  any other query over a post type (`*` for several or any);
 *                any publication, withdrawal, date or term change of that type.
 * - `ptv:<type>` ordered by something an ordinary edit can change (modified
 *                date, comment count, meta values, random); any change at all.
 *
 * A query limited to explicit IDs depends only on those posts, which are
 * recorded as items. Anything this cannot classify falls back to `pt`, never to
 * nothing.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class QueryClassifier {
	/** @var list<string> */
	private const VOLATILE_ORDER = array( 'modified', 'post_modified', 'comment_count', 'rand', 'meta_value', 'meta_value_num' );

	/**
	 * @param array<string, mixed>                                            $vars  Query vars after parsing.
	 * @param list<array{taxonomy:string,terms:list<int>,operator:string}>     $taxes Resolved taxonomy clauses.
	 * @return list<string>
	 */
	public static function signatures( array $vars, array $taxes ): array {
		$types = self::types( $vars['post_type'] ?? '', array() !== $taxes );
		$ids   = array_filter( array_map( 'intval', (array) ( $vars['post__in'] ?? array() ) ) );
		if ( array() !== $ids && array() === $taxes && '' === (string) ( $vars['s'] ?? '' ) ) {
			return array();
		}

		$signatures = array();
		$orderby    = strtolower( is_array( $vars['orderby'] ?? '' ) ? implode( ' ', array_keys( (array) $vars['orderby'] ) ) : (string) ( $vars['orderby'] ?? '' ) );
		foreach ( self::VOLATILE_ORDER as $volatile ) {
			if ( preg_match( '/(^|\s)' . preg_quote( $volatile, '/' ) . '(\s|$)/', $orderby ) ) {
				foreach ( $types as $type ) {
					$signatures[] = 'ptv:' . $type;
				}
				break;
			}
		}

		$termOnly = array() !== $taxes;
		foreach ( $taxes as $clause ) {
			if ( 'IN' !== strtoupper( $clause['operator'] ) || array() === $clause['terms'] ) {
				$termOnly = false;
				continue;
			}
			foreach ( $clause['terms'] as $term ) {
				$signatures[] = 'term:' . (int) $term;
			}
		}
		if ( ! $termOnly ) {
			foreach ( $types as $type ) {
				$signatures[] = 'pt:' . $type;
			}
		}

		return array_values( array_unique( $signatures ) );
	}

	/**
	 * Signatures a change to one post can affect.
	 *
	 * @param list<int> $terms Term IDs the post has, before and after the change.
	 * @return list<string>
	 */
	public static function affected( int $postId, string $type, bool $membership, array $terms ): array {
		$signatures = array( 'post:' . $postId, 'ptv:' . $type, 'ptv:*' );
		if ( $membership ) {
			$signatures[] = 'pt:' . $type;
			$signatures[] = 'pt:*';
			foreach ( array_unique( array_map( 'intval', $terms ) ) as $term ) {
				$signatures[] = 'term:' . $term;
			}
		}

		return $signatures;
	}

	/**
	 * @return list<string>
	 */
	private static function types( mixed $postType, bool $taxonomy ): array {
		if ( is_array( $postType ) ) {
			$postType = array_values( array_filter( array_map( 'strval', $postType ) ) );
			return 1 === count( $postType ) ? array( sanitize_key( $postType[0] ) ) : array( '*' );
		}
		$postType = (string) $postType;
		if ( 'any' === $postType ) {
			return array( '*' );
		}
		if ( '' === $postType ) {
			// Core queries a taxonomy's registered types, which may not include posts.
			return array( $taxonomy ? '*' : 'post' );
		}

		return array( sanitize_key( $postType ) );
	}
}
