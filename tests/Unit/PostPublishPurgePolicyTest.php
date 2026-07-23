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
}
