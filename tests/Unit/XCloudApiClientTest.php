<?php
/**
 * xCloud API boundary tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\XCloud\ApiClient;
use PHPUnit\Framework\TestCase;

final class XCloudApiClientTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['gtperf_test_http_requests'] = array();
		unset( $GLOBALS['gtperf_test_http_response'] );
	}

	public function testPublicSiteRequestUsesBearerTokenAndV1Route(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->response(
			array(
				'success' => true,
				'data'    => array( 'uuid' => 'site-uuid' ),
			)
		);

		$result  = ( new ApiClient( 'secret', 'https://xcloud.test' ) )->site( 'site-uuid' );
		$request = $GLOBALS['gtperf_test_http_requests'][0];

		self::assertSame( array( 'uuid' => 'site-uuid' ), $result );
		self::assertSame( 'https://xcloud.test/api/v1/sites/site-uuid', $request['url'] );
		self::assertSame( 'Bearer secret', $request['args']['headers']['Authorization'] );
		self::assertSame( 'GET', $request['args']['method'] );
	}

	public function testEnterpriseIdsUseLegacyCapabilityRoute(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->response(
			array(
				'data' => array(
					array(
						'id'     => 221938,
						'name'   => 'gauravtiwari.org',
						'server' => array( 'id' => 45794 ),
					),
				),
			)
		);

		$result = ( new ApiClient( 'secret', 'https://xcloud.test' ) )->enterpriseIdsByDomain( 'gauravtiwari.org' );

		self::assertSame(
			array(
				'server_id' => 45794,
				'site_id' => 221938,
			),
			$result
		);
		self::assertStringContainsString( '/api/site-list?search=gauravtiwari.org', $GLOBALS['gtperf_test_http_requests'][0]['url'] );
	}

	public function testEnterpriseAnalyticsAcceptsNonV1Payload(): void {
		$GLOBALS['gtperf_test_http_response'] = $this->response(
			array(
				'available' => true,
				'totals'    => array(
					'requests'             => 100,
					'served_by_cloudflare' => 60,
				),
			)
		);

		$result = ( new ApiClient( 'secret', 'https://xcloud.test' ) )->enterpriseAnalytics( 45794, 221938 );

		self::assertTrue( $result['available'] );
		self::assertSame( 60, $result['totals']['served_by_cloudflare'] );
		self::assertSame(
			'https://xcloud.test/addons/server/45794/site/221938/cloudflare-enterprise/analytics?range=12h',
			$GLOBALS['gtperf_test_http_requests'][0]['url']
		);
	}

	public function testEnterpriseAnalyticsRejectsInvalidNumericIds(): void {
		$result = ( new ApiClient( 'secret', 'https://xcloud.test' ) )->enterpriseAnalytics( 0, 0 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'gtperf_xcloud_enterprise_ids', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['gtperf_test_http_requests'] );
	}

	/**
	 * @param array<string, mixed> $body JSON body.
	 * @return array<string, mixed>
	 */
	private function response( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $body ),
		);
	}
}
