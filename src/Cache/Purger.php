<?php
/**
 * Origin and edge purge coordinator.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Settings;

final class Purger {
	public function __construct(
		private readonly FileStore $store = new FileStore(),
	) {
	}

	public function purgeUrl( string $url ): bool {
		return $this->purgeUrls( array( $url ) ) > 0;
	}

	/**
	 * Purge public origin variants and notify edge integrations once per batch.
	 *
	 * @param list<string> $urls URLs to purge.
	 */
	public function purgeUrls( array $urls ): int {
		$config               = (array) Settings::get( 'cache', array() );
		$config['generation'] = (int) Settings::get( 'generation', 1 );
		$cacheKey             = new CacheKey();
		$valid                = array();
		$deleted              = 0;

		foreach ( array_unique( array_filter( $urls, 'is_string' ) ) as $url ) {
			$request = $this->fromUrl( $url );
			if ( null === $request ) {
				continue;
			}

			$valid[] = $url;
			$hash    = $cacheKey->hash( $cacheKey->make( $request, $config ) );
			$deleted += $this->store->delete( $hash ) ? 1 : 0;
			// Sorted, filtered, and translated copies of the page are stored under
			// their own keys and change whenever the page does, here and at the edge.
			$variants = $this->store->purgeVariants( $request->scheme . '://' . $request->host . $request->path );
			$deleted += count( $variants );
			array_push( $valid, ...$variants );

			if ( (bool) ( $config['separate_mobile'] ?? false ) ) {
				$mobile = RequestContext::fromUrl( $url, array(), array(), 'GT Performance Mobile' );
				if ( null !== $mobile ) {
					$mobileHash = $cacheKey->hash( $cacheKey->make( $mobile, $config ) );
					$deleted   += $this->store->delete( $mobileHash ) ? 1 : 0;
				}
			}
		}

		if ( $valid ) {
			do_action( 'gt_performance_purged_urls', $valid, 'origin' );
		}

		return $deleted;
	}

	public function purgeAll(): int {
		$count = $this->store->purgeAll();
		do_action( 'gt_performance_purged_all', 'origin' );

		return $count;
	}

	/**
	 * Finish deferred edge work before an interactive caller reports completion.
	 * Normal post-save invalidation remains batched until shutdown.
	 *
	 * @return bool|\WP_Error
	 */
	public function flushEdge(): bool|\WP_Error {
		return apply_filters( 'gt_performance_flush_edge_purges', true );
	}

	private function fromUrl( string $url ): ?RequestContext {
		return RequestContext::fromUrl( $url );
	}
}
