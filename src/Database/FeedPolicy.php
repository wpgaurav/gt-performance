<?php
/**
 * Pure decisions for which feed requests and discovery links stay available.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Database;

final class FeedPolicy {
	/**
	 * The main feed is the default posts feed at /feed/ and its format variants.
	 *
	 * Comment feeds (site-wide and per post), category, tag, custom taxonomy,
	 * author, date, search, and post type archive feeds are all secondary.
	 */
	public function isMainFeed( bool $isCommentFeed, bool $isSingular, bool $isArchive, bool $isSearch ): bool {
		return ! $isCommentFeed && ! $isSingular && ! $isArchive && ! $isSearch;
	}

	/**
	 * Whether the current feed request should be refused with a 404.
	 */
	public function blocksFeed( bool $disableAll, bool $disableSecondary, bool $isMainFeed ): bool {
		if ( $disableAll ) {
			return true;
		}

		return $disableSecondary && ! $isMainFeed;
	}

	/**
	 * Whether feed_links() should be removed, taking every discovery link with it.
	 */
	public function removesAllLinks( bool $removeAll ): bool {
		return $removeAll;
	}

	/**
	 * Whether feed_links_extra() should be removed while the main feed link stays.
	 */
	public function removesSecondaryLinksOnly( bool $removeAll, bool $removeSecondary ): bool {
		return ! $removeAll && $removeSecondary;
	}
}
