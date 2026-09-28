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
use GTPerformance\Core\OutputBuffer;
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
		private readonly DependencyInvalidator $dependencies = new DependencyInvalidator(),
	) {
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'startCapture' ), -9999 );
		$this->dependencies->register();
		add_action( 'save_post', array( $this, 'purgePost' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'purgeStatusTransition' ), 20, 3 );
		add_action( 'post_updated', array( $this, 'purgeRenamedPost' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'purgeDeletedPost' ), 20, 2 );
		add_action( 'wp_insert_comment', array( $this, 'purgeInsertedComment' ), 20, 2 );
		add_action( 'comment_post', array( $this, 'purgeCommentById' ), 20 );
		add_action( 'edit_comment', array( $this, 'purgeCommentById' ), 20 );
		add_action( 'deleted_comment', array( $this, 'purgeDeletedComment' ), 20, 2 );
		add_action( 'transition_comment_status', array( $this, 'purgeCommentTransition' ), 20, 3 );
		add_action( 'wp_update_nav_menu', array( $this, 'purgeAll' ), 20 );
		add_action( 'switch_theme', array( $this, 'purgeAll' ), 20 );
		add_action( 'customize_save_after', array( $this, 'purgeAll' ), 20 );
		add_action( 'upgrader_process_complete', array( $this, 'purgeAfterUpgrade' ), 20, 2 );
	}

	public function startCapture(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Nothing reads what capture() writes until the owned drop-in is in place and
		// WP_CACHE is on, which is not the state a fresh activation leaves behind. Until
		// then the buffer, the optimizer chain, the key hash and two file writes are all
		// work whose only product is disk usage. Optimize-only mode stores nothing, so
		// it needs neither.
		$optimizeOnly = Settings::optimizeOnly();
		if ( ! $optimizeOnly && ! self::storageActive() ) {
			return;
		}

		// Safe mode must show the page WordPress would render, so nothing is stored and
		// the response is marked private rather than served from an existing entry.
		if ( \GTPerformance\Core\SafeMode::active() ) {
			nocache_headers();
			SharedCacheHeaders::noStore();
			header( 'X-GT-Cache: SAFE-MODE' );
			return;
		}

		if ( is_feed() || is_robots() ) {
			nocache_headers();
			SharedCacheHeaders::noStore();
			return;
		}

		$this->request = RequestContext::fromGlobals();
		if ( $this->isCssPreview() ) {
			nocache_headers();
			SharedCacheHeaders::noStore();
			OutputBuffer::start( array( $this, 'capturePreview' ) );
			return;
		}

		if ( \GTPerformance\Optimization\Css\UnusedCssOptimizer::isGeneratorRequest() ) {
			$this->request = \GTPerformance\Optimization\Css\UnusedCssOptimizer::publicRequest( $this->request );
			$this->decision = $this->eligibility->decide( $this->request, $this->cacheConfig() );
			if ( $this->decision->cacheable ) {
				OutputBuffer::start( array( $this, 'captureGenerator' ) );
			}
			return;
		}

		$config         = $this->cacheConfig();
		$this->decision = $this->eligibility->decide( $this->request, $config );

		// The host's page cache decides what it stores for a page the eligibility
		// rules would cache, and the page is optimized on its way there. A response
		// they would never cache is not transformed, and it is marked no-store as in
		// store mode: a shared cache that gets no Cache-Control applies its own
		// default lifetime (two hours at Cloudflare), which would share a page this
		// plugin just refused to.
		if ( $optimizeOnly ) {
			if ( $this->decision->cacheable ) {
				OutputBuffer::start( array( $this, 'captureOptimizeOnly' ) );
			} else {
				nocache_headers();
				SharedCacheHeaders::noStore();
			}
			return;
		}

		if ( ! $this->decision->cacheable ) {
			nocache_headers();
			SharedCacheHeaders::noStore();
			if ( (bool) Settings::get( 'debug', false ) ) {
				header( 'X-GT-Cache: BYPASS' );
				header( 'X-GT-Cache-Reason: ' . DropinRuntime::reasonHeader( $this->decision->reason ) );
			}
			return;
		}

		// Do not advertise shared caching until the generated response passes body and
		// header safety validation in capture(). Unsafe responses receive an explicit
		// private directive there; safe responses are upgraded to the public policy.
		//
		// The drop-in already labelled deliberate stale rebuilds as REVALIDATE;
		// overwriting that with MISS would make the two indistinguishable in logs.
		if ( ! self::hasCacheStatus( 'REVALIDATE' ) ) {
			header( 'X-GT-Cache: MISS' );
		}
		( new DependencyRecorder() )->start();
		OutputBuffer::start( array( $this, 'capture' ) );
	}

	/**
	 * Whether an owned drop-in is installed and WP_CACHE is on, so a stored entry
	 * can actually be served. Memoized: status() stats and reads a file, and this is
	 * consulted on every frontend request.
	 */
	private static function storageActive(): bool {
		static $active = null;

		if ( null === $active ) {
			$active = defined( 'WP_CACHE' ) && WP_CACHE && 'owned' === ( new DropinInstaller() )->status();
		}

		return $active;
	}

	/**
	 * Whether the early drop-in already queued a given X-GT-Cache status.
	 */
	private static function hasCacheStatus( string $status ): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === strcasecmp( trim( $header ), 'X-GT-Cache: ' . $status ) ) {
				return true;
			}
		}

		return false;
	}

	public function capture( string $html ): string {
		if ( null === $this->request ) {
			return $html;
		}

		$decision = $this->validator->validate( $html, http_response_code(), headers_list() );
		if ( $decision->cacheable && defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			$decision = Decision::deny( 'donotcachepage' );
		}
		if ( $decision->cacheable && \GTPerformance\Optimization\PageOverrides::noCache() ) {
			$decision = Decision::deny( 'page-option' );
		}

		if ( ! $decision->cacheable ) {
			if ( ! headers_sent() ) {
				SharedCacheHeaders::noStore();
				if ( (bool) Settings::get( 'debug', false ) ) {
					header( 'X-GT-Cache: DYNAMIC' );
					header( 'X-GT-Cache-Reason: ' . DropinRuntime::reasonHeader( $decision->reason ) );
				}
			}
			$this->logger->log( 'debug', 'Response not cached', array( 'reason' => $decision->reason ) );
			return $html;
		}

		$optimized = apply_filters( 'gt_performance_html', $html, $this->request );
		if ( ! is_string( $optimized ) || '' === trim( $optimized ) ) {
			$optimized = $html;
		}

		$config  = $this->cacheConfig();
		$hash    = ( new CacheKey() )->hash( ( new CacheKey() )->make( $this->request, $config ) );
		$base    = $this->request->scheme . '://' . $this->request->host . $this->request->path;
		$variant = ( new CacheKey() )->variant( $this->request, $config );

		// A variant a purge of its page cannot find would outlive every edit, so a
		// page that already holds the maximum is served fresh instead of stored.
		if ( '' !== $variant && ! $this->store->addVariant( $base, $hash, $base . '?' . $variant ) ) {
			if ( ! headers_sent() ) {
				SharedCacheHeaders::noStore();
			}
			$this->logger->log( 'debug', 'Response not cached', array( 'reason' => 'variant_limit' ) );
			return $optimized;
		}

		$now    = time();
		$stored = $this->store->write(
			$hash,
			$optimized,
			array(
				'stored_at'   => $now,
				'fresh_until' => $now + max( 0, (int) $config['fresh_ttl'] ),
				'stale_until' => $now + max( 0, (int) $config['fresh_ttl'] ) + max( 0, (int) $config['stale_ttl'] ),
				// The preloader rebuilds a stale entry from this URL, so a variant keeps its query.
				'url'         => '' === $variant ? $base : $base . '?' . $variant,
				'generation'  => (int) $config['generation'],
				'headers'     => DropinRuntime::replayableHeaders( headers_list() ),
			)
		);

		if ( $stored ) {
			do_action( 'gt_performance_cache_stored', $this->request, $hash );
		}

		$this->sendCacheHeaders();

		return $optimized;
	}

	/**
	 * Optimize-only mode: run the pipeline on a response that passes the same body
	 * and header checks a stored page must pass, and store nothing.
	 */
	public function captureOptimizeOnly( string $html ): string {
		if ( null === $this->request ) {
			return $html;
		}

		$decision = $this->validator->validate( $html, (int) http_response_code(), headers_list() );
		if ( ! $decision->cacheable || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || \GTPerformance\Optimization\PageOverrides::noCache() ) {
			return $html;
		}

		if ( ! headers_sent() && (bool) Settings::get( 'debug', false ) ) {
			header( 'X-GT-Cache: OPTIMIZE-ONLY' );
			// Replaces the idle drop-in's own debug reason.
			header( 'X-GT-Cache-Reason: optimize-only' );
		}

		return $this->capturePreview( $html );
	}

	/**
	 * Run the optimization pipeline for an authorized CSS preview without
	 * storing the response in either the page cache or a shared edge cache.
	 */
	public function capturePreview( string $html ): string {
		if ( null === $this->request ) {
			return $html;
		}

		$optimized = apply_filters( 'gt_performance_html', $html, $this->request );

		return is_string( $optimized ) && '' !== trim( $optimized ) ? $optimized : $html;
	}

	/** Generate from eligible public HTML without storing the signed request. */
	public function captureGenerator( string $html ): string {
		$decision = $this->validator->validate( $html, http_response_code(), headers_list() );
		if ( $decision->cacheable && ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
			$html = $this->capturePreview( $html );
		}
		if ( ! headers_sent() ) {
			SharedCacheHeaders::noStore();
		}
		return $html;
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
		$this->purgePublishedPost( $post );
	}

	/**
	 * Purge a post that stops being publicly viewable.
	 *
	 * The save_post handler returns early for any post that is not
	 * publicly viewable, and trashing or unpublishing reaches save_post with the new
	 * status already applied. Without this the withdrawn page keeps being served from
	 * disk for the rest of its stale window while WordPress itself would answer 404.
	 */
	public function purgeStatusTransition( string $newStatus, string $oldStatus, \WP_Post $post ): void {
		if ( $newStatus === $oldStatus || 'publish' !== $oldStatus || 'revision' === $post->post_type ) {
			return;
		}

		$this->purgePostById( (int) $post->ID );
	}

	/**
	 * Purge the previous permalink when a slug or parent changes.
	 *
	 * The purge set is built after the save, so it only ever names the new URL. The
	 * old URL keeps serving its stored body with a 200 ahead of the canonical redirect
	 * WordPress would issue.
	 */
	public function purgeRenamedPost( int $postId, \WP_Post $after, \WP_Post $before ): void {
		if ( wp_is_post_revision( $postId ) || 'revision' === $after->post_type ) {
			return;
		}

		if ( $after->post_name === $before->post_name && (int) $after->post_parent === (int) $before->post_parent ) {
			return;
		}

		$previous = get_permalink( $before );
		if ( ! is_string( $previous ) || '' === $previous ) {
			return;
		}

		( new Purger( $this->store ) )->purgeUrls( array( $previous ) );
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
		$post = get_post( $postId );
		// A withdrawn or deleted post leaves every listing that showed it.
		$dependents = $post instanceof \WP_Post ? array_keys( $this->dependencies->forPost( $post, true ) ) : array();
		( new Purger( $this->store ) )->purgeUrls( array_values( array_unique( array_merge( $urls, $dependents ) ) ) );

		do_action( 'gt_performance_enqueue_preload', $urls );
	}

	public function purgeDeletedPost( int $postId, \WP_Post $post ): void {
		// Trashing already purged a post's pages; emptying the trash, one post at a
		// time, would purge the homepage and every listing again for each one.
		if ( 'revision' === $post->post_type || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
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
		// Spam and trashed comments were never on the page, so deleting them changes nothing public.
		if ( in_array( (string) $comment->comment_approved, array( 'spam', 'trash' ), true ) ) {
			return;
		}
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
	 * Purge after WordPress, a theme, or a plugin is updated.
	 *
	 * Updated code ships new asset versions and often new markup, so cached pages
	 * kept pointing at stylesheets and scripts that no longer match. Translation
	 * updates change neither, and installing something new changes nothing until
	 * it is activated.
	 *
	 * @param mixed $upgrader WP_Upgrader instance; unused.
	 * @param mixed $extra    What was upgraded, as passed by WordPress.
	 */
	public function purgeAfterUpgrade( mixed $upgrader, mixed $extra = array() ): void {
		unset( $upgrader );
		if ( ! is_array( $extra ) || 'update' !== ( $extra['action'] ?? '' ) ) {
			return;
		}
		if ( in_array( $extra['type'] ?? '', array( 'core', 'plugin', 'theme' ), true ) ) {
			$this->purgeAll();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cacheConfig(): array {
		$config               = (array) Settings::get( 'cache', array() );
		$config['generation'] = (int) Settings::get( 'generation', 1 );
		// Must match what Settings::compile() writes for the drop-in, or the two sides
		// of the cache disagree about which requests are eligible.
		$config['hosts']      = Settings::canonicalHosts();

		return apply_filters( 'gt_performance_cache_policy', $config );
	}

	private function isCssPreview(): bool {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['gtperf_css_preview'] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['gtperf_css_preview'] ) );

		return (bool) wp_verify_nonce( $nonce, 'gtperf_css_preview' );
	}

	private function purgeCommentPost( int $postId ): void {
		if ( $postId <= 0 || isset( $this->purgedCommentPosts[ $postId ] ) ) {
			return;
		}

		$this->purgedCommentPosts[ $postId ] = true;
		$this->purgePostById( $postId );
	}

	private function purgePublishedPost( \WP_Post $post ): void {
		$mode    = (string) Settings::get( 'cache.post_publish_purge', PostPublishPurgePolicy::RELATED );
		$related = array_keys( RelatedUrls::forPost( $post ) );
		$plan    = $this->postPublishPurgePolicy->plan( $mode, $related );

		if ( $plan['all'] ) {
			( new Purger( $this->store ) )->purgeAll();
			return;
		}

		if ( ! $plan['urls'] ) {
			return;
		}

		// Pages that recorded this post, its terms, or its listings, beyond the
		// heuristic set. They are purged but not preloaded: there can be hundreds,
		// and visitors or stale revalidation refill them at a normal pace.
		$dependents = PostPublishPurgePolicy::RELATED === $mode ? array_keys( $this->dependencies->forPost( $post ) ) : array();
		( new Purger( $this->store ) )->purgeUrls( array_values( array_unique( array_merge( $plan['urls'], $dependents ) ) ) );
		do_action( 'gt_performance_enqueue_preload', $plan['urls'] );
	}
}
