<?php
/**
 * Explain and verify accept the hostnames `--page-url` accepts.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Diagnostics\CacheInspector;
use PHPUnit\Framework\TestCase;

final class CacheInspectorHostsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['gtperf_test_filters'] );
	}

	public function test_a_canonical_alias_is_inspected_and_another_site_is_not(): void {
		$inspector = new CacheInspector();
		self::assertIsArray( $inspector->inspect( 'https://example.com/about/' ) );
		self::assertInstanceOf( \WP_Error::class, $inspector->inspect( 'https://staging.example.com/about/' ), 'Control: an alias nobody declared is refused.' );

		$GLOBALS['gtperf_test_filters']['gt_performance_canonical_hosts'][] = static fn ( array $hosts ): array => array_merge( $hosts, array( 'Staging.Example.com' ) );

		$report = $inspector->inspect( 'https://staging.example.com/about/' );
		self::assertIsArray( $report, '--page-url lets a filtered alias through, so Explain must not then refuse it.' );
		self::assertSame( 'https://staging.example.com/about/', $report['url'] );
		self::assertInstanceOf( \WP_Error::class, $inspector->inspect( 'https://other.test/about/' ) );
		self::assertInstanceOf( \WP_Error::class, $inspector->inspect( 'ftp://example.com/about/' ) );
	}
}
