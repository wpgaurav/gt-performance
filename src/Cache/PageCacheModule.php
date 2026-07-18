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

	public function __construct(
		private readonly Logger $logger,
		private readonly FileStore $store = new FileStore(),
		private readonly Eligibility $eligibility = new Eligibility(),
		private readonly ResponseValidator $validator = new ResponseValidator(),
	) {
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'startCapture' ), -9999 );
		add_action( 'send_headers', array( $this, 'sendCacheHeaders' ), 9999 );
		add_action( 'save_post', array( $this, 'purgePost' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'purgePostById' ), 20 );
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

		header( 'X-GT-Cache: MISS' );
		$this->sendCacheHeaders();
		ob_start( array( $this, 'capture' ) );
	}

	public function capture( string $html ): string {
		if ( null === $this->request ) {
			return $html;
		}

		$decision = $this->validator->validate( $html, http_response_code(), headers_list() );
		if ( ! $decision->cacheable ) {
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

		return $optimized;
	}

	public function sendCacheHeaders(): void {
		if ( null === $this->decision || ! $this->decision->cacheable ) {
			return;
		}

		$fresh   = max( 0, (int) Settings::get( 'cache.fresh_ttl', 300 ) );
		$stale   = max( 0, (int) Settings::get( 'cache.stale_ttl', 86400 ) );
		$browser = max( 0, (int) Settings::get( 'cache.browser_ttl', 0 ) );
		header( "Cache-Control: public, max-age={$browser}, s-maxage={$fresh}, stale-while-revalidate={$stale}" );
		header( 'Vary: Accept-Encoding' );
	}

	public function purgePost( int $postId, \WP_Post $post ): void {
		if ( wp_is_post_revision( $postId ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->purgePostById( $postId );
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

		$purger = new Purger( $this->store );
		foreach ( array_unique( $urls ) as $url ) {
			$purger->purgeUrl( $url );
		}

		do_action( 'gt_performance_enqueue_preload', $urls );
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
}
