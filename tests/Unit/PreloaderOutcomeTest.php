<?php
/**
 * Preload outcomes are evidence, not HTTP status.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\Preloader;
use PHPUnit\Framework\TestCase;

final class PreloaderOutcomeTest extends TestCase {
	private const NOW = 1_800_000_000;

	public function test_non_200_is_a_failure(): void {
		self::assertSame( array( 'status' => 'failed', 'detail' => 'http_301', 'http' => 301 ), Preloader::classify( 301, '', '', null, self::NOW, self::NOW ) );
	}

	public function test_fresh_origin_entry_is_ready_and_says_whether_it_was_rebuilt(): void {
		$rebuilt = array( 'stored_at' => self::NOW, 'fresh_until' => self::NOW + 3600 );
		$older   = array( 'stored_at' => self::NOW - 600, 'fresh_until' => self::NOW + 3000 );

		self::assertSame( 'rebuilt', Preloader::classify( 200, '', 'MISS', $rebuilt, self::NOW, self::NOW )['detail'] );
		self::assertSame( 'origin_ready', Preloader::classify( 200, 'HIT', 'HIT', $older, self::NOW, self::NOW )['status'] );
		self::assertSame( 'existing', Preloader::classify( 200, 'HIT', 'HIT', $older, self::NOW, self::NOW )['detail'] );
	}

	public function test_edge_hit_without_an_origin_artifact_is_not_an_origin_rebuild(): void {
		$outcome = Preloader::classify( 200, 'hit', '', null, self::NOW, self::NOW );

		self::assertSame( 'edge_observed', $outcome['status'] );
		self::assertSame( 'cf_hit', $outcome['detail'] );
	}

	public function test_stale_origin_entry_with_edge_hit_is_edge_evidence(): void {
		$stale = array( 'stored_at' => self::NOW - 7200, 'fresh_until' => self::NOW - 1 );

		self::assertSame( 'edge_observed', Preloader::classify( 200, 'STALE', '', $stale, self::NOW, self::NOW )['status'] );
	}

	public function test_a_200_that_stored_nothing_is_only_requested(): void {
		self::assertSame( array( 'status' => 'requested', 'detail' => 'gt_dynamic', 'http' => 200 ), Preloader::classify( 200, 'DYNAMIC', 'DYNAMIC', null, self::NOW, self::NOW ) );
		self::assertSame( 'no_artifact', Preloader::classify( 200, 'MISS', '', null, self::NOW, self::NOW )['detail'] );
	}
}
