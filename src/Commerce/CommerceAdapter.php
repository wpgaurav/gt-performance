<?php
/**
 * Commerce integration contract.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

interface CommerceAdapter {
	public function id(): string;

	public function active(): bool;

	/**
	 * @return list<string>
	 */
	public function bypassPaths(): array;

	/**
	 * @return list<string>
	 */
	public function bypassCookies(): array;

	/**
	 * @return list<string>
	 */
	public function bypassQueryParameters(): array;

	public function isProduct( int $postId, \WP_Post $post ): bool;

	/**
	 * @return list<string>
	 */
	public function relatedUrls( int $postId ): array;
}
