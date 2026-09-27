<?php
/**
 * Purge what recorded dependencies say a change affects.
 *
 * Adds to, never replaces, the conservative related-URL purge. It covers what
 * that heuristic could not see:
 *
 * - pages showing a post in a query loop, grid, or widget;
 * - listings a post joins or leaves, including the term it was moved out of;
 * - a term's previous archive URL after a rename;
 * - pages embedding an edited reusable block or navigation menu.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Settings;

final class DependencyInvalidator {
	/** @var array<int, true> Posts whose listing membership changed this request. */
	private array $membership = array();

	/** @var array<int, string> Term archive URLs captured before an edit. */
	private array $termLinks = array();

	public function __construct(
		private readonly DependencyIndex $index = new DependencyIndex(),
		private readonly Purger $purger = new Purger(),
	) {
	}

	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'noteTransition' ), 5, 3 );
		add_action( 'post_updated', array( $this, 'noteUpdate' ), 5, 3 );
		add_action( 'set_object_terms', array( $this, 'termsChanged' ), 20, 6 );
		add_action( 'edit_terms', array( $this, 'rememberTermLink' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'termEdited' ), 20, 3 );
		add_action( 'save_post', array( $this, 'embeddedSaved' ), 20, 2 );
		add_action( 'stick_post', array( $this, 'stickyChanged' ) );
		add_action( 'unstick_post', array( $this, 'stickyChanged' ) );
	}

	public function noteTransition( string $new, string $old, \WP_Post $post ): void {
		if ( $new !== $old && ( 'publish' === $new || 'publish' === $old ) ) {
			$this->membership[ (int) $post->ID ] = true;
		}
	}

	public function noteUpdate( int $postId, \WP_Post $after, \WP_Post $before ): void {
		if ( $after->post_date_gmt !== $before->post_date_gmt || (int) $after->menu_order !== (int) $before->menu_order || (int) $after->post_parent !== (int) $before->post_parent ) {
			$this->membership[ $postId ] = true;
		}
	}

	/**
	 * Recorded dependents of a saved, withdrawn, or deleted post, with reasons.
	 *
	 * @return array<string, list<string>>
	 */
	public function forPost( \WP_Post $post, ?bool $membership = null ): array {
		$membership = $membership ?? isset( $this->membership[ (int) $post->ID ] );
		$terms      = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids = wp_get_object_terms( (int) $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $ids ) ) {
				$terms = array_merge( $terms, array_map( 'intval', $ids ) );
			}
		}
		$signatures = QueryClassifier::affected( (int) $post->ID, $post->post_type, $membership, $terms );
		if ( $post->post_parent > 0 ) {
			$signatures[] = 'post:' . (int) $post->post_parent;
		}

		return $this->index->dependents( $signatures );
	}

	/**
	 * A post moved between terms. The conservative purge only knows the terms the
	 * post has now, so the archive it left kept listing it.
	 *
	 * @param int                   $objectId Object ID.
	 * @param array<int|string>     $terms    Requested terms.
	 * @param array<int|string>     $ttIds    New term taxonomy IDs.
	 * @param string                $taxonomy Taxonomy.
	 * @param bool                  $append   Whether terms were appended.
	 * @param array<int|string>     $oldTtIds Previous term taxonomy IDs.
	 */
	public function termsChanged( int $objectId, array $terms, array $ttIds, string $taxonomy, bool $append, array $oldTtIds ): void {
		unset( $terms, $append );
		$post = get_post( $objectId );
		$tax  = get_taxonomy( $taxonomy );
		if ( ! $post instanceof \WP_Post || ! $tax instanceof \WP_Taxonomy || ! $this->active() ) {
			return;
		}
		$new = array_map( 'intval', $ttIds );
		$old = array_map( 'intval', $oldTtIds );
		sort( $new );
		sort( $old );
		if ( $new === $old || 'publish' !== $post->post_status || ! is_post_publicly_viewable( $post ) ) {
			return;
		}

		$termIds = array();
		$urls    = array();
		foreach ( array_unique( array_merge( $new, $old ) ) as $ttId ) {
			$term = get_term_by( 'term_taxonomy_id', $ttId, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$termIds[] = (int) $term->term_id;
			$link      = $tax->public ? get_term_link( $term ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}
		$dependents = $this->index->dependents( QueryClassifier::affected( $objectId, $post->post_type, true, $termIds ) );
		$this->purge( array_merge( $urls, array_keys( $dependents ) ) );
	}

	public function rememberTermLink( int $termId, string $taxonomy ): void {
		$link = get_term_link( $termId, $taxonomy );
		if ( is_string( $link ) ) {
			$this->termLinks[ $termId ] = $link;
		}
	}

	public function termEdited( int $termId, int $ttId, string $taxonomy ): void {
		unset( $ttId );
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax instanceof \WP_Taxonomy || ! $this->active() ) {
			return;
		}
		$urls = array();
		if ( $tax->public ) {
			$urls[] = $this->termLinks[ $termId ] ?? '';
			$link   = get_term_link( $termId, $taxonomy );
			$urls[] = is_string( $link ) ? $link : '';
		}
		unset( $this->termLinks[ $termId ] );
		$this->purge( array_merge( array_filter( $urls ), array_keys( $this->index->dependents( array( 'term:' . $termId ) ) ) ) );
	}

	/**
	 * Reusable blocks and navigation menus have no public URL, so the post-save
	 * purge ignores them; the pages that embed them are their only output.
	 */
	public function embeddedSaved( int $postId, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, array( 'wp_block', 'wp_navigation' ), true ) || wp_is_post_revision( $postId ) || ! $this->active() ) {
			return;
		}
		$this->purge( array_keys( $this->index->dependents( array( 'post:' . $postId ) ) ) );
	}

	public function stickyChanged( int $postId ): void {
		$post = get_post( $postId );
		if ( $post instanceof \WP_Post && $this->active() ) {
			$this->purge( array_keys( $this->forPost( $post, true ) ) );
		}
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	private function purge( array $urls ): void {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( array() !== $urls ) {
			$this->purger->purgeUrls( $urls );
		}
	}

	private function active(): bool {
		return (bool) Settings::get( 'cache.enabled', true ) && PostPublishPurgePolicy::NONE !== (string) Settings::get( 'cache.post_publish_purge', PostPublishPurgePolicy::RELATED );
	}
}
