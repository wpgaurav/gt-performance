<?php
/**
 * Post-publish purge policy tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\PostPublishPurgePolicy;
use PHPUnit\Framework\TestCase;

final class PostPublishPurgePolicyTest extends TestCase {
	private PostPublishPurgePolicy $policy;

	protected function setUp(): void {
		$this->policy = new PostPublishPurgePolicy();
	}

	public function testRelatedModePurgesEveryUniqueRelatedUrl(): void {
		$plan = $this->policy->plan(
			PostPublishPurgePolicy::RELATED,
			array( 'https://example.com/post/', 'https://example.com/', 'https://example.com/' )
		);

		self::assertFalse( $plan['all'] );
		self::assertSame( array( 'https://example.com/post/', 'https://example.com/' ), $plan['urls'] );
	}

	public function testPostModePurgesOnlyTheFirstUrl(): void {
		$plan = $this->policy->plan(
			PostPublishPurgePolicy::POST,
			array( 'https://example.com/post/', 'https://example.com/' )
		);

		self::assertFalse( $plan['all'] );
		self::assertSame( array( 'https://example.com/post/' ), $plan['urls'] );
	}

	public function testAllModeRequestsAFullPurge(): void {
		$plan = $this->policy->plan( PostPublishPurgePolicy::ALL, array( 'https://example.com/post/' ) );

		self::assertTrue( $plan['all'] );
		self::assertSame( array(), $plan['urls'] );
	}

	public function testNoneModeDisablesAutomaticPurging(): void {
		$plan = $this->policy->plan( PostPublishPurgePolicy::NONE, array( 'https://example.com/post/' ) );

		self::assertFalse( $plan['all'] );
		self::assertSame( array(), $plan['urls'] );
	}

	public function testInvalidModeFallsBackToRelatedPurging(): void {
		self::assertSame( PostPublishPurgePolicy::RELATED, $this->policy->sanitize( 'unsupported' ) );
	}

	public function testDependentsKeepOnlyTheFirstPageOfEachListing(): void {
		unset( $GLOBALS['gtperf_test_filters']['gt_performance_purge_paginated_listings'], $GLOBALS['wp_rewrite'] );
		$urls = array(
			'https://example.com/category/news/',
			'https://example.com/category/news/page/2/',
			'https://example.com/blog/page/14',
			'https://example.com/?cat=3&paged=2',
			'https://example.com/?cat=3&paged=1',
			'https://example.com/pages/2/',
			'https://example.com/landing/',
		);

		self::assertSame(
			array( 'https://example.com/category/news/', 'https://example.com/?cat=3&paged=1', 'https://example.com/pages/2/', 'https://example.com/landing/' ),
			$this->policy->firstPagesOnly( $urls )
		);
	}

	public function testPaginationBaseFollowsTheSiteAndTheFilterRestoresEveryPage(): void {
		$GLOBALS['wp_rewrite'] = (object) array( 'pagination_base' => 'seite' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for WP_Rewrite.
		$urls                  = array( 'https://example.com/kategorie/news/seite/2/', 'https://example.com/kategorie/news/page/2/' );

		self::assertSame( array( 'https://example.com/kategorie/news/page/2/' ), $this->policy->firstPagesOnly( $urls ) );

		add_filter( 'gt_performance_purge_paginated_listings', static fn(): bool => true );
		self::assertSame( $urls, $this->policy->firstPagesOnly( $urls ) );

		unset( $GLOBALS['gtperf_test_filters']['gt_performance_purge_paginated_listings'], $GLOBALS['wp_rewrite'] );
	}
}
