<?php
/**
 * Feed availability policy tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\Database\FeedPolicy;
use PHPUnit\Framework\TestCase;

final class FeedPolicyTest extends TestCase {
	public function testFeedControlsDefaultToOff(): void {
		$bloat = Settings::defaults()['bloat'];

		self::assertFalse( $bloat['disable_rss_feeds'] );
		self::assertFalse( $bloat['disable_secondary_feeds'] );
		self::assertFalse( $bloat['remove_feed_links'] );
		self::assertFalse( $bloat['remove_secondary_feed_links'] );
	}

	public function testOnlyTheDefaultPostsFeedCountsAsTheMainFeed(): void {
		$policy = new FeedPolicy();

		self::assertTrue( $policy->isMainFeed( false, false, false, false ) );
		self::assertFalse( $policy->isMainFeed( true, false, false, false ), 'comment feed' );
		self::assertFalse( $policy->isMainFeed( false, true, false, false ), 'single post comment feed' );
		self::assertFalse( $policy->isMainFeed( false, false, true, false ), 'category, tag, author, or date feed' );
		self::assertFalse( $policy->isMainFeed( false, false, false, true ), 'search feed' );
	}

	public function testSecondaryOnlyModeKeepsTheMainFeedServing(): void {
		$policy = new FeedPolicy();

		self::assertFalse( $policy->blocksFeed( false, true, true ) );
		self::assertTrue( $policy->blocksFeed( false, true, false ) );
	}

	public function testDisablingEveryFeedAlsoBlocksTheMainFeed(): void {
		$policy = new FeedPolicy();

		self::assertTrue( $policy->blocksFeed( true, false, true ) );
		self::assertTrue( $policy->blocksFeed( true, true, false ) );
	}

	public function testFeedsStayAvailableWhenNeitherControlIsEnabled(): void {
		$policy = new FeedPolicy();

		self::assertFalse( $policy->blocksFeed( false, false, true ) );
		self::assertFalse( $policy->blocksFeed( false, false, false ) );
	}

	public function testRemovingEveryLinkWinsOverTheSecondaryLinkControl(): void {
		$policy = new FeedPolicy();

		self::assertTrue( $policy->removesAllLinks( true ) );
		self::assertFalse( $policy->removesSecondaryLinksOnly( true, true ) );
		self::assertTrue( $policy->removesSecondaryLinksOnly( false, true ) );
		self::assertFalse( $policy->removesSecondaryLinksOnly( false, false ) );
	}
}
