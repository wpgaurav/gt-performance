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
		$request = $this->fromUrl( $url );
		if ( null === $request ) {
			return false;
		}

		$config               = (array) Settings::get( 'cache', array() );
		$config['generation'] = (int) Settings::get( 'generation', 1 );
		$hash                 = ( new CacheKey() )->hash( ( new CacheKey() )->make( $request, $config ) );
		$deleted              = $this->store->delete( $hash );

		do_action( 'gt_performance_purged_urls', array( $url ), 'origin' );

		return $deleted;
	}

	public function purgeAll(): int {
		$count = $this->store->purgeAll();
		do_action( 'gt_performance_purged_all', 'origin' );

		return $count;
	}

	private function fromUrl( string $url ): ?RequestContext {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$scalar = array();
		foreach ( $query as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$scalar[ (string) $key ] = (string) $value;
			}
		}

		return new RequestContext(
			'GET',
			(string) ( $parts['scheme'] ?? 'https' ),
			(string) $parts['host'],
			(string) ( $parts['path'] ?? '/' ),
			$scalar,
			array(),
			array(),
			'',
		);
	}
}
