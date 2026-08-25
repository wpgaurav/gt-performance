<?php
/**
 * Tests for FluentCart update metadata normalization.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Licensing\UpdateResponse;
use PHPUnit\Framework\TestCase;

final class UpdateResponseTest extends TestCase {
	public function testNormalizesVerifiedFluentCartResponse(): void {
		$response = UpdateResponse::normalize(
			array(
				'new_version'    => '0.2.0',
				'name'           => 'GT Performance',
				'slug'           => 'unexpected-server-slug',
				'package'        => 'https://example.com/?fluent-cart=download_license_package&fct_package=private',
				'homepage'       => 'https://gauravtiwari.org/product/gt-performance/',
				'license_status' => 'valid',
				'sections'       => array(
					'description' => 'Description',
					'changelog'   => '<h4>0.2.0</h4>',
				),
				'icons'          => array(
					'1x' => 'https://example.com/icon.png',
				),
			)
		);

		self::assertIsArray( $response );
		self::assertSame( 'gt-performance', $response['slug'] );
		self::assertSame( '0.2.0', $response['new_version'] );
		self::assertSame( 'valid', $response['license_status'] );
		self::assertStringContainsString( 'download_license_package', $response['package'] );
		self::assertSame( '<h4>0.2.0</h4>', $response['sections']['changelog'] );
	}

	public function testRejectsMissingOrMalformedVersion(): void {
		self::assertNull( UpdateResponse::normalize( array() ) );
		self::assertNull( UpdateResponse::normalize( array( 'new_version' => '<script>' ) ) );
		self::assertNull( UpdateResponse::normalize( array( 'new_version' => 'version_2' ) ) );
	}

	public function testDropsUnsafePackageAndAssetUrls(): void {
		$response = UpdateResponse::normalize(
			array(
				'new_version'    => '0.2.0-beta.1',
				'package'        => 'javascript:alert(1)',
				'license_status' => 'valid',
				'banners'        => array(
					'low' => 'data:text/html,bad',
				),
				'icons'          => array(
					'2x' => 'https://example.com/icon.png',
				),
			)
		);

		self::assertIsArray( $response );
		self::assertSame( '', $response['package'] );
		self::assertSame( array(), $response['banners'] );
		self::assertSame( 'https://example.com/icon.png', $response['icons']['2x'] );
	}
}
