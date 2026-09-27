<?php
/**
 * Stable queue identity; SQL state transitions are tested against a real database.
 *
 * @package GTPerformance
 */
declare(strict_types=1);
namespace GTPerformance\Tests\Unit;

use GTPerformance\Queue\JobRepository;
use PHPUnit\Framework\TestCase;

final class JobRepositoryTest extends TestCase {
	public function test_identity_normalizes_origin_without_losing_path_query_or_variant(): void {
		$jobs = new JobRepository();
		$base = $jobs->activeKey( 'preload_url', array( 'url' => 'https://example.com/a/' ) );
		self::assertSame( $base, $jobs->activeKey( 'preload_url', array( 'url' => 'HTTPS://EXAMPLE.COM:443/a/#section' ) ) );
		foreach ( array( 'https://example.com/b/', 'https://example.com/a/?lang=fr', 'https://example.com:8443/a/' ) as $url ) {
			self::assertNotSame( $base, $jobs->activeKey( 'preload_url', array( 'url' => $url ) ) );
		}
		self::assertNotSame( $base, $jobs->activeKey( 'purge_url', array( 'url' => 'https://example.com/a/' ) ) );
		self::assertNotSame( $base, $jobs->activeKey( 'preload_url', array( 'url' => 'https://example.com/a/', 'variant' => 'mobile' ) ) );
	}

	public function test_payload_order_is_stable_and_generation_and_site_are_distinct(): void {
		global $wpdb;
		$jobs = new JobRepository();
		$key = $jobs->activeKey( 'generate_css', array( 'url' => 'https://example.com/', 'generation' => 1 ) );
		self::assertSame( $key, $jobs->activeKey( 'generate_css', array( 'generation' => 1, 'url' => 'https://example.com/' ) ) );
		self::assertNotSame( $key, $jobs->activeKey( 'generate_css', array( 'generation' => 2, 'url' => 'https://example.com/' ) ) );
		$prefix = $wpdb->prefix;
		try {
			$wpdb->prefix = 'wp_other_';
			self::assertNotSame( $key, $jobs->activeKey( 'generate_css', array( 'generation' => 1, 'url' => 'https://example.com/' ) ) );
		} finally {
			$wpdb->prefix = $prefix;
		}
	}
}
