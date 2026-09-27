<?php
/**
 * Record what a cacheable page was built from, while it is being built.
 *
 * Only started for a response the page cache will try to store. Queries are
 * collected at pre_get_posts rather than the_posts, because get_posts() and
 * the Latest Posts block suppress filters and never reach the_posts. Their
 * results and parsed taxonomy clauses are read once the page is stored, when
 * every query has run. Reusable blocks and navigation menus are posts too, but
 * they are fetched directly, so their references come from block rendering.
 *
 * Recording never removes invalidation: the conservative related-URL purge
 * still runs, and a page whose dependencies could not be stored simply has
 * none to add.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class DependencyRecorder {
	private const MAX_QUERIES = 200;

	/** Post types whose posts are rendered into other pages without their own URL. */
	private const EMBEDDED_TYPES = array( 'wp_block', 'wp_navigation' );

	/** @var list<\WP_Query> */
	private array $queries = array();

	/** @var array<string, true> */
	private array $signatures = array();

	public function __construct(
		private readonly DependencyIndex $index = new DependencyIndex(),
	) {
	}

	public function start(): void {
		add_action( 'pre_get_posts', array( $this, 'collect' ), PHP_INT_MAX );
		add_filter( 'pre_render_block', array( $this, 'block' ), 10, 2 );
		add_action( 'gt_performance_cache_stored', array( $this, 'store' ), 10, 1 );
	}

	public function stop(): void {
		remove_action( 'pre_get_posts', array( $this, 'collect' ), PHP_INT_MAX );
		remove_filter( 'pre_render_block', array( $this, 'block' ), 10 );
		remove_action( 'gt_performance_cache_stored', array( $this, 'store' ), 10 );
	}

	public function collect( \WP_Query $query ): void {
		if ( count( $this->queries ) < self::MAX_QUERIES ) {
			$this->queries[] = $query;
		} else {
			$this->signatures['broad:*'] = true;
		}
	}

	/**
	 * @param string|null          $pre   Short-circuited output; returned untouched.
	 * @param array<string, mixed> $block Parsed block.
	 */
	public function block( ?string $pre, array $block ): ?string {
		$name = (string) ( $block['blockName'] ?? '' );
		$ref  = (int) ( $block['attrs']['ref'] ?? 0 );
		if ( $ref > 0 && in_array( $name, array( 'core/block', 'core/navigation' ), true ) ) {
			$this->signatures[ 'post:' . $ref ] = true;
		}

		return $pre;
	}

	/**
	 * Persist the page's dependencies after the page cache stored it.
	 */
	public function store( RequestContext $request ): void {
		global $wp_query;

		$this->stop();

		$queries = $this->queries;
		if ( $wp_query instanceof \WP_Query && ! in_array( $wp_query, $queries, true ) ) {
			$queries[] = $wp_query;
		}
		foreach ( $queries as $query ) {
			foreach ( $this->fromQuery( $query ) as $signature ) {
				$this->signatures[ $signature ] = true;
			}
		}
		if ( $wp_query instanceof \WP_Query ) {
			$object = $wp_query->get_queried_object();
			if ( $object instanceof \WP_Term ) {
				$this->signatures[ 'term:' . $object->term_id ] = true;
			} elseif ( $object instanceof \WP_Post ) {
				$this->signatures[ 'post:' . $object->ID ] = true;
			}
		}

		$query = http_build_query( $request->query, '', '&', PHP_QUERY_RFC3986 );
		$url   = $request->scheme . '://' . $request->host . $request->path . ( '' === $query ? '' : '?' . $query );
		$this->index->replace( $url, self::variant( $request ), array_keys( $this->signatures ) );
	}

	/**
	 * @return list<string>
	 */
	private function fromQuery( \WP_Query $query ): array {
		$types = (array) $query->get( 'post_type' );
		foreach ( $types as $type ) {
			if ( is_string( $type ) && '' !== $type && 'any' !== $type && ! self::relevant( $type ) ) {
				return array();
			}
		}

		$signatures = array();
		foreach ( (array) $query->posts as $post ) {
			$id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
			if ( $id > 0 ) {
				$signatures[] = 'post:' . $id;
			}
		}

		$vars = array(
			'post_type' => $query->get( 'post_type' ),
			'post__in'  => $query->get( 'post__in' ),
			'orderby'   => $query->get( 'orderby' ),
			's'         => $query->get( 's' ),
		);
		if ( $query->is_singular() ) {
			return $signatures;
		}

		return array_merge( $signatures, QueryClassifier::signatures( $vars, self::taxonomies( $query ) ) );
	}

	/**
	 * @return list<array{taxonomy:string,terms:list<int>,operator:string}>
	 */
	private static function taxonomies( \WP_Query $query ): array {
		$clauses = array();
		$tax     = $query->tax_query;
		if ( ! $tax instanceof \WP_Tax_Query ) {
			return $clauses;
		}
		foreach ( $tax->queries as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) ) {
				// Nested groups are not resolved here; they fall back to the post type.
				if ( is_array( $clause ) && ! isset( $clause['taxonomy'] ) ) {
					$clauses[] = array(
						'taxonomy' => '',
						'terms'    => array(),
						'operator' => 'NESTED',
					);
				}
				continue;
			}
			$terms = array();
			foreach ( (array) ( $clause['terms'] ?? array() ) as $term ) {
				if ( 'term_id' === ( $clause['field'] ?? 'term_id' ) || 'term_taxonomy_id' === ( $clause['field'] ?? '' ) ) {
					$terms[] = (int) $term;
					continue;
				}
				$found = get_term_by( (string) $clause['field'], (string) $term, (string) $clause['taxonomy'] );
				if ( $found instanceof \WP_Term ) {
					$terms[] = (int) $found->term_id;
				}
			}
			if ( 'term_taxonomy_id' === ( $clause['field'] ?? '' ) ) {
				$terms = array_map(
					static function ( int $ttId ): int {
						$term = get_term_by( 'term_taxonomy_id', $ttId );
						return $term instanceof \WP_Term ? (int) $term->term_id : 0;
					},
					$terms
				);
			}
			$clauses[] = array(
				'taxonomy' => (string) $clause['taxonomy'],
				'terms'    => array_values( array_filter( $terms ) ),
				'operator' => strtoupper( (string) ( $clause['operator'] ?? 'IN' ) ),
			);
		}

		return $clauses;
	}

	private static function relevant( string $type ): bool {
		return in_array( $type, self::EMBEDDED_TYPES, true ) || is_post_type_viewable( $type );
	}

	private static function variant( RequestContext $request ): string {
		$policy = array( 'separate_mobile' => (bool) \GTPerformance\Core\Settings::get( 'cache.separate_mobile', false ) );
		$key    = ( new CacheKey() )->make( $request, $policy + array( 'generation' => 0 ) );

		return str_contains( $key, '|mobile|' ) ? 'mobile' : 'public';
	}
}
