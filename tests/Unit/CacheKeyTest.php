<?php
/**
 * Cache key tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\RequestContext;
use PHPUnit\Framework\TestCase;

final class CacheKeyTest extends TestCase {
	public function test_ignored_parameters_share_key(): void {
		$config = array(
			'ignored_query_params' => array( 'utm_source' ),
			'separate_mobile'      => false,
			'generation'           => 1,
		);
		$plain = new RequestContext( 'GET', 'https', 'example.com', '/article/', array(), array(), array(), '' );
		$utm   = new RequestContext( 'GET', 'https', 'example.com', '/article/', array( 'utm_source' => 'email' ), array(), array(), '' );
		$keys  = new CacheKey();

		self::assertSame( $keys->make( $plain, $config ), $keys->make( $utm, $config ) );
	}

	public function test_mobile_variant_is_separate_when_enabled(): void {
		$config = array(
			'ignored_query_params' => array(),
			'separate_mobile'      => true,
			'generation'           => 1,
		);
		$desktop = new RequestContext( 'GET', 'https', 'example.com', '/', array(), array(), array(), 'Desktop' );
		$mobile  = new RequestContext( 'GET', 'https', 'example.com', '/', array(), array(), array(), 'iPhone Mobile' );
		$keys    = new CacheKey();

		self::assertNotSame( $keys->make( $desktop, $config ), $keys->make( $mobile, $config ) );
	}
}
