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

	/** Hash of the policy last compiled; Settings clears it when a compile ran without this module. */
	public const POLICY_HASH_OPTION = 'gt_performance_commerce_policy_hash';

	private static bool $registered = false;

	public static function registered(): bool {
		return self::$registered;
	}

	public function register(): void {
		self::$registered = true;
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
		$now    = array();
		foreach ( array_unique( array_filter( array( $postId, $parent ) ) ) as $id ) {
			$url = get_permalink( $id );
			if ( is_string( $url ) && '' !== $url ) {
				$now[] = $url;
			}
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				foreach ( $this->registry->active() as $adapter ) {
					if ( $adapter->isProduct( $id, $post ) ) {
						array_push( $now, ...$adapter->relatedUrls( $id ) );
					}
				}
			}
		}

		// These hooks fire after the new price or stock is saved, and they are the
		// only purge that does. The post save purged earlier, before the store wrote
		// the price, so a visit in between cached the old one again: on
		// gtp-demo.gatilab.com a price change reached visitors only when this
		// purge's queue job ran, 80 seconds later, and Cloudflare had re-stored the
		// stale page meanwhile. The product, its shop page, and its categories go
		// now; the edge purge is still batched per request.
		$now = array_values( array_unique( array_filter( $now ) ) );
		if ( $now ) {
			( new \GTPerformance\Cache\Purger() )->purgeUrls( $now );
		}

		foreach ( array_unique( array_filter( array( $postId, $parent ) ) ) as $id ) {
			// Shop pages, grids, and landing pages that show the product carry the
			// same price and stock badge. Stock can also add or remove it from a
			// filtered listing, so this counts as a membership change.
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$dependents = array_keys( ( new \GTPerformance\Cache\DependencyInvalidator() )->forPost( $post, true ) );
				if ( array() !== $dependents ) {
					do_action( 'gt_performance_enqueue_purge', $dependents );
				}
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
		$old    = (string) get_option( self::POLICY_HASH_OPTION, '' );

		if ( ! hash_equals( $old, $hash ) ) {
			Settings::compile();
			update_option( self::POLICY_HASH_OPTION, $hash, false );
		}
	}

	public function protectDynamicResponse(): void {
		$config   = \GTPerformance\Core\Network::scopePolicy( $this->mergePolicy( (array) Settings::get( 'cache', array() ) ) );
		$request  = \GTPerformance\Optimization\Css\UnusedCssOptimizer::publicRequest( RequestContext::fromGlobals() );
		$decision = ( new Eligibility() )->decide( $request, array_merge( $config, array( 'enabled' => true ) ) );

		if ( $decision->cacheable ) {
			return;
		}

		if ( self::mustNotBeShared( $decision->reason ) ) {
			nocache_headers();
			SharedCacheHeaders::noStore();
			header( 'X-GT-Commerce-Cache: BYPASS' );
		}
	}

	/**
	 * Bypass reasons that also have to keep every other cache away: a host's page
	 * cache in optimize-only mode, and Cloudflare in either mode. These are the
	 * rules an administrator or a store configured, plus array-valued parameters,
	 * which would otherwise reach a cache that keys on the raw URL untouched.
	 */
	public static function mustNotBeShared( string $reason ): bool {
		foreach ( array( 'path:', 'cookie:', 'query:', 'query_array:' ) as $prefix ) {
			if ( str_starts_with( $reason, $prefix ) ) {
				return true;
			}
		}

		return false;
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
