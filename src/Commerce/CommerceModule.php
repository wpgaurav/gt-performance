<?php
/**
 * Commerce cache policy compiler and invalidation.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Cache\SharedCacheHeaders;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class CommerceModule implements Module {
	private Registry $registry;

	public function __construct() {
		$this->registry = new Registry();
	}

	public function register(): void {
		add_filter( 'gt_performance_cache_policy', array( $this, 'mergePolicy' ) );
		add_filter( 'gt_performance_compiled_config', array( $this, 'mergeCompiledConfig' ) );
		add_action( 'init', array( $this, 'synchronizeCompiledPolicy' ), 99 );
		add_action( 'send_headers', array( $this, 'protectDynamicResponse' ), -9999 );
		add_action( 'save_post', array( $this, 'purgeProduct' ), 30, 2 );

		// A price change, a stock movement or a sale-schedule transition goes through
		// the commerce plugin's own CRUD layer and never reaches save_post, so the
		// cached product page kept advertising the old price and an in-stock badge for
		// a product that had sold out.
		foreach ( array(
			'woocommerce_product_set_stock',
			'woocommerce_variation_set_stock',
			'woocommerce_product_set_stock_status',
			'woocommerce_variation_set_stock_status',
			'woocommerce_product_object_updated_props',
			'woocommerce_scheduled_sales',
			'edd_update_product_price',
			'fluent_cart/product_updated',
		) as $hook ) {
			add_action( $hook, array( $this, 'purgeCommerceObject' ), 30 );
		}
	}

	/**
	 * Invalidate a product whose price or stock changed outside the post save.
	 *
	 * The hooks these come from pass either a product object or an id depending on
	 * the plugin and the version, so accept both rather than binding to one shape.
	 *
	 * @param mixed $product Product object or post id.
	 */
	public function purgeCommerceObject( mixed $product ): void {
		$postId = 0;

		if ( is_numeric( $product ) ) {
			$postId = (int) $product;
		} elseif ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$postId = (int) $product->get_id();
		} elseif ( $product instanceof \WP_Post ) {
			$postId = (int) $product->ID;
		}

		if ( $postId <= 0 ) {
			return;
		}

		// A variation's price shows on its parent's page, not its own.
		$parent = (int) wp_get_post_parent_id( $postId );
		foreach ( array_unique( array_filter( array( $postId, $parent ) ) ) as $id ) {
			$url = get_permalink( $id );
			if ( is_string( $url ) && '' !== $url ) {
				do_action( 'gt_performance_enqueue_purge', array( $url ) );
			}
		}
	}

	/**
	 * @param array<string, mixed> $cache Cache policy.
	 * @return array<string, mixed>
	 */
	public function mergePolicy( array $cache ): array {
		$policy = $this->registry->policy();

		$cache['bypass_paths']        = array_values(
			array_unique( array_merge( (array) ( $cache['bypass_paths'] ?? array() ), $policy['paths'] ) )
		);
		$cache['bypass_cookies']      = array_values(
			array_unique( array_merge( (array) ( $cache['bypass_cookies'] ?? array() ), $policy['cookies'] ) )
		);
		$cache['bypass_query_params'] = array_values(
			array_unique( array_merge( (array) ( $cache['bypass_query_params'] ?? array() ), $policy['query'] ) )
		);

		return $cache;
	}

	/**
	 * @param array<string, mixed> $compiled Compiled configuration.
	 * @return array<string, mixed>
	 */
	public function mergeCompiledConfig( array $compiled ): array {
		$compiled['cache'] = $this->mergePolicy( (array) $compiled['cache'] );

		return $compiled;
	}

	public function synchronizeCompiledPolicy(): void {
		$policy = $this->registry->policy();
		$hash   = hash( 'sha256', (string) wp_json_encode( $policy ) );
		$old    = (string) get_option( 'gt_performance_commerce_policy_hash', '' );

		if ( ! hash_equals( $old, $hash ) ) {
			Settings::compile();
			update_option( 'gt_performance_commerce_policy_hash', $hash, false );
		}
	}

	public function protectDynamicResponse(): void {
		$config   = $this->mergePolicy( (array) Settings::get( 'cache', array() ) );
		$decision = ( new Eligibility() )->decide( RequestContext::fromGlobals(), array_merge( $config, array( 'enabled' => true ) ) );

		if ( $decision->cacheable ) {
			return;
		}

		if ( str_starts_with( $decision->reason, 'path:' )
			|| str_starts_with( $decision->reason, 'cookie:' )
			|| str_starts_with( $decision->reason, 'query:' ) ) {
			nocache_headers();
			SharedCacheHeaders::noStore();
			header( 'X-GT-Commerce-Cache: BYPASS' );
		}
	}

	public function purgeProduct( int $postId, \WP_Post $post ): void {
		if ( wp_is_post_revision( $postId ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$urls = array();
		foreach ( $this->registry->active() as $adapter ) {
			if ( $adapter->isProduct( $postId, $post ) ) {
				$urls = array_merge( $urls, $adapter->relatedUrls( $postId ) );
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( $urls ) {
			do_action( 'gt_performance_enqueue_purge', $urls );
			do_action( 'gt_performance_enqueue_preload', $urls );
		}
	}
}
