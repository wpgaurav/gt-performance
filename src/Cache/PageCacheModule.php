<?php
/**
 * Runtime page-cache capture and invalidation.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;

final class PageCacheModule implements Module {
	private ?RequestContext $request = null;
	private ?Decision $decision      = null;
	/** @var array<int, true> */
	private array $purgedCommentPosts = array();
	/** @var array<int, true> */
	private array $purgedPublishedPosts = array();

	public function __construct(
		private readonly Logger $logger,
		private readonly FileStore $store = new FileStore(),
		private readonly Eligibility $eligibility = new Eligibility(),
		private readonly ResponseValidator $validator = new ResponseValidator(),
		private readonly PostPublishPurgePolicy $postPublishPurgePolicy = new PostPublishPurgePolicy(),
	) {
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'startCapture' ), -9999 );
		add_action( 'save_post', array( $this, 'purgePost' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'purgeDeletedPost' ), 20, 2 );
		add_action( 'wp_insert_comment', array( $this, 'purgeInsertedComment' ), 20, 2 );
		add_action( 'comment_post', array( $this, 'purgeCommentById' ), 20 );
		add_action( 'edit_comment', array( $this, 'purgeCommentById' ), 20 );
		add_action( 'deleted_comment', array( $this, 'purgeDeletedComment' ), 20, 2 );
		add_action( 'transition_comment_status', array( $this, 'purgeCommentTransition' ), 20, 3 );
		add_action( 'wp_update_nav_menu', array( $this, 'purgeAll' ), 20 );
		add_action( 'switch_theme', array( $this, 'purgeAll' ), 20 );
		add_action( 'customize_save_after', array( $this, 'purgeAll' ), 20 );
	}

	public function startCapture(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( is_feed() || is_robots() ) {
			nocache_headers();
			header( 'Cache-Control: no-store, private, max-age=0' );
			return;
		}

		$this->request  = RequestContext::fromGlobals();
		$config         = $this->cacheConfig();
		$this->decision = $this->eligibility->decide( $this->request, $config );

		if ( ! $this->decision->cacheable ) {
			nocache_headers();
			header( 'Cache-Control: no-store, private, max-age=0' );
			if ( (bool) Settings::get( 'debug', false ) ) {
				header( 'X-GT-Cache: BYPASS' );
				header( 'X-GT-Cache-Reason: ' . sanitize_key( $this->decision->reason ) );
			}
			return;
		}

		// Do not advertise shared caching until the generated response passes body and
		// header safety validation in capture(). Unsafe responses receive an explicit
		// private directive there; safe responses are upgraded to the public policy.
		header( 'X-GT-Cache: MISS' );
		ob_start( array( $this, 'capture' ) );
	}

	public function capture( string $html ): string {
		if ( null === $this->request ) {
			return $html;
		}

		$decision = $this->validator->validate( $html, http_response_code(), headers_list() );
		if ( $decision->cacheable && defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			$decision = Decision::deny( 'donotcachepage' );
		}

		if ( ! $decision->cacheable ) {
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, private, max-age=0' );
				if ( (bool) Settings::get( 'debug', false ) ) {
					header( 'X-GT-Cache: DYNAMIC' );
					header( 'X-GT-Cache-Reason: ' . sanitize_key( $decision->reason ) );
				}
			}
			$this->logger->log( 'debug', 'Response not cached', array( 'reason' => $decision->reason ) );
			return $html;
		}

		$optimized = apply_filters( 'gt_performance_html', $html, $this->request );
		if ( ! is_string( $optimized ) || '' === trim( $optimized ) ) {
			$optimized = $html;
		}

		$config = $this->cacheConfig();
		$hash   = ( new CacheKey() )->hash( ( new CacheKey() )->make( $this->request, $config ) );
		$now    = time();
		$stored = $this->store->write(
			$hash,
			$optimized,
			array(
				'stored_at'   => $now,
				'fresh_until' => $now + max( 0, (int) $config['fresh_ttl'] ),
				'stale_until' => $now + max( 0, (int) $config['fresh_ttl'] ) + max( 0, (int) $config['stale_ttl'] ),
				'url'         => $this->request->scheme . '://' . $this->request->host . $this->request->path,
				'generation'  => (int) $config['generation'],
			)
		);

		if ( $stored ) {
			do_action( 'gt_performance_cache_stored', $this->request, $hash );
		}

		$this->sendCacheHeaders();

		return $optimized;
	}

	public function sendCacheHeaders(): void {
		if ( null === $this->decision || ! $this->decision->cacheable || headers_sent() ) {
			return;
		}

		$fresh   = max( 0, (int) Settings::get( 'cache.fresh_ttl', 3600 ) );
		$stale   = max( 0, (int) Settings::get( 'cache.stale_ttl', 86400 ) );
		$browser = max( 0, (int) Settings::get( 'cache.browser_ttl', 300 ) );
		$ifError = max( 0, (int) Settings::get( 'cache.stale_if_error', 0 ) );

		$directives = array(
			'public',
			'max-age=' . $browser,
			's-maxage=' . $fresh,
			'stale-while-revalidate=' . $stale,
		);
		if ( $ifError > 0 ) {
			$directives[] = 'stale-if-error=' . $ifError;
		}
		header( 'Cache-Control: ' . implode( ', ', $directives ) );

		// A mobile cache variant makes the HTML vary by User-Agent, so any shared
		// cache in front of the origin must key on it too.
		$vary = (bool) Settings::get( 'cache.separate_mobile', false )
			? 'Accept-Encoding, User-Agent'
			: 'Accept-Encoding';
		header( 'Vary: ' . $vary );
	}

	public function purgePost( int $postId, \WP_Post $post ): void {
		if (
			wp_is_post_revision( $postId )
			|| 'auto-draft' === $post->post_status
			|| ! is_post_publicly_viewable( $post )
			|| isset( $this->purgedPublishedPosts[ $postId ] )
		) {
			return;
		}

		$this->purgedPublishedPosts[ $postId ] = true;
		$this->purgePublishedPost( $postId, $post );
	}

	public function purgePostById( int $postId ): void {
		$urls = array_filter(
			array(
				get_permalink( $postId ),
				home_url( '/' ),
				get_post_type_archive_link( (string) get_post_type( $postId ) ),
			),
			'is_string'
		);

		$urls = array_values( array_unique( $urls ) );
		( new Purger( $this->store ) )->purgeUrls( $urls );

		do_action( 'gt_performance_enqueue_preload', $urls );
	}

	public function purgeDeletedPost( int $postId, \WP_Post $post ): void {
		if ( 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->purgePostById( $postId );
	}

	public function purgeCommentById( int $commentId ): void {
		$comment = get_comment( $commentId );
		if ( $comment instanceof \WP_Comment ) {
			$this->purgeCommentPost( (int) $comment->comment_post_ID );
		}
	}

	public function purgeInsertedComment( int $commentId, \WP_Comment $comment ): void {
		unset( $commentId );
		$this->purgeCommentPost( (int) $comment->comment_post_ID );
	}

	public function purgeDeletedComment( int $commentId, \WP_Comment $comment ): void {
		unset( $commentId );
		$this->purgeCommentPost( (int) $comment->comment_post_ID );
	}

	public function purgeCommentTransition( string $newStatus, string $oldStatus, \WP_Comment $comment ): void {
		if ( $newStatus === $oldStatus ) {
			return;
		}

		$this->purgeCommentPost( (int) $comment->comment_post_ID );
	}

	public function purgeAll(): void {
		( new Purger( $this->store ) )->purgeAll();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cacheConfig(): array {
		$config               = (array) Settings::get( 'cache', array() );
		$config['generation'] = (int) Settings::get( 'generation', 1 );

		return apply_filters( 'gt_performance_cache_policy', $config );
	}

	private function purgeCommentPost( int $postId ): void {
		if ( $postId <= 0 || isset( $this->purgedCommentPosts[ $postId ] ) ) {
			return;
		}

		$this->purgedCommentPosts[ $postId ] = true;
		$this->purgePostById( $postId );
	}

	private function purgePublishedPost( int $postId, \WP_Post $post ): void {
		$mode = (string) Settings::get( 'cache.post_publish_purge', PostPublishPurgePolicy::RELATED );
		$plan = $this->postPublishPurgePolicy->plan( $mode, $this->publishedPostUrls( $postId, $post ) );

		if ( $plan['all'] ) {
			( new Purger( $this->store ) )->purgeAll();
			return;
		}

		if ( ! $plan['urls'] ) {
			return;
		}

		( new Purger( $this->store ) )->purgeUrls( $plan['urls'] );
		do_action( 'gt_performance_enqueue_preload', $plan['urls'] );
	}

	/**
	 * @return list<string>
	 */
	private function publishedPostUrls( int $postId, \WP_Post $post ): array {
		$urls = array_filter(
			array(
				get_permalink( $postId ),
				home_url( '/' ),
				get_post_type_archive_link( $post->post_type ),
				$post->post_author > 0 ? get_author_posts_url( (int) $post->post_author ) : false,
			),
			'is_string'
		);

		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy || ! $taxonomy->public ) {
				continue;
			}

			$terms = get_the_terms( $postId, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}
}
