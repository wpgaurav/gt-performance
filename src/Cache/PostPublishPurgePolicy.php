<?php
/**
 * Post-publish cache purge policy.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class PostPublishPurgePolicy {
	public const RELATED = 'related';
	public const POST    = 'post';
	public const ALL     = 'all';
	public const NONE    = 'none';

	public function sanitize( string $mode ): string {
		return in_array( $mode, $this->modes(), true ) ? $mode : self::RELATED;
	}

	/**
	 * @param list<string> $relatedUrls Post URL followed by related public URLs.
	 * @return array{all: bool, urls: list<string>}
	 */
	public function plan( string $mode, array $relatedUrls ): array {
		$mode = $this->sanitize( $mode );
		$urls = array_values(
			array_unique(
				array_filter(
					$relatedUrls,
					static fn( mixed $url ): bool => is_string( $url ) && '' !== trim( $url )
				)
			)
		);

		if ( self::NONE === $mode ) {
			return array(
				'all'  => false,
				'urls' => array(),
			);
		}

		if ( self::ALL === $mode ) {
			return array(
				'all'  => true,
				'urls' => array(),
			);
		}

		if ( self::POST === $mode ) {
			$urls = isset( $urls[0] ) ? array( $urls[0] ) : array();
		}

		return array(
			'all'  => false,
			'urls' => $urls,
		);
	}

	/**
	 * @return list<string>
	 */
	private function modes(): array {
		return array( self::RELATED, self::POST, self::ALL, self::NONE );
	}
}
