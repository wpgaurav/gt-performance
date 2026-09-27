<?php
/**
 * What a change would purge, and why, without purging anything.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Settings;

final class PurgePreview {
	public function __construct(
		private readonly DependencyIndex $index = new DependencyIndex(),
	) {
	}

	/**
	 * The purge an update to this post would trigger under the current policy.
	 *
	 * @param bool $membership Preview a publication, withdrawal, or term change rather than a content edit.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function forPost( int $postId, bool $membership = false ): array|\WP_Error {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'gtperf_preview_post', __( 'No such post.', 'gt-performance' ), array( 'status' => 404 ) );
		}
		$mode = ( new PostPublishPurgePolicy() )->sanitize( (string) Settings::get( 'cache.post_publish_purge', PostPublishPurgePolicy::RELATED ) );
		$urls = array();

		if ( PostPublishPurgePolicy::ALL === $mode ) {
			return $this->result( $post, $mode, $membership, array(), true );
		}
		if ( PostPublishPurgePolicy::NONE !== $mode ) {
			$related = RelatedUrls::forPost( $post );
			if ( PostPublishPurgePolicy::POST === $mode ) {
				$related = array_slice( $related, 0, 1, true );
			}
			foreach ( $related as $url => $reason ) {
				$urls[ $url ][] = $reason;
			}
		}
		if ( PostPublishPurgePolicy::RELATED === $mode ) {
			foreach ( ( new DependencyInvalidator( $this->index ) )->forPost( $post, $membership ) as $url => $signatures ) {
				foreach ( $signatures as $signature ) {
					$urls[ $url ][] = 'recorded dependency: ' . self::describe( $signature );
				}
			}
		}

		return $this->result( $post, $mode, $membership, $urls, false );
	}

	/**
	 * @param array<string, list<string>> $urls URL => reasons.
	 * @return array<string, mixed>
	 */
	private function result( \WP_Post $post, string $mode, bool $membership, array $urls, bool $all ): array {
		$items = array();
		foreach ( $urls as $url => $reasons ) {
			$items[] = array(
				'url'     => $url,
				'reasons' => array_values( array_unique( $reasons ) ),
			);
		}

		return array(
			'post_id'      => (int) $post->ID,
			'policy'       => $mode,
			'change'       => $membership ? 'membership' : 'content',
			'purge_all'    => $all,
			'urls'         => $items,
			'completeness' => array(
				'indexed_pages' => $this->index->indexedPages(),
				'note'          => 'Only pages stored since the last settings change have recorded dependencies. The related-URL set covers the rest; other cached pages keep serving until they expire.',
			),
		);
	}

	private static function describe( string $signature ): string {
		list( $type, $id ) = array_pad( explode( ':', $signature, 2 ), 2, '' );

		return match ( $type ) {
			'post'  => 'shows post ' . $id,
			'term'  => 'lists term ' . $id,
			'pt'    => 'lists ' . ( '*' === $id ? 'several post types' : $id . ' posts' ),
			'ptv'   => 'lists ' . ( '*' === $id ? 'posts' : $id . ' posts' ) . ' in an order any edit can change',
			'broad' => 'too many dependencies to record individually',
			default => $signature,
		};
	}
}
