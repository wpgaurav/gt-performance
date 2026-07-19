<?php
/**
 * Commerce policy audit tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Commerce\PolicyAudit;
use PHPUnit\Framework\TestCase;

final class CommercePolicyAuditTest extends TestCase {
	public function testAuditPassesProtectedCommerceDimensions(): void {
		$checks = ( new PolicyAudit() )->audit(
			'woocommerce',
			array(
				'paths'   => array( '/checkout/' ),
				'cookies' => array( 'wp_woocommerce_session_' ),
				'query'   => array( 'wc-ajax' ),
			),
			array(
				'enabled'             => true,
				'bypass_paths'        => array( '/checkout/' ),
				'bypass_cookies'      => array( 'wp_woocommerce_session_' ),
				'bypass_query_params' => array( 'wc-ajax' ),
				'ignored_query_params' => array(),
			)
		);

		self::assertCount( 3, $checks );
		self::assertSame( array( 'pass', 'pass', 'pass' ), array_column( $checks, 'status' ) );
	}

	public function testAuditFailsAnUnprotectedPath(): void {
		$checks = ( new PolicyAudit() )->audit(
			'fluentcart',
			array( 'paths' => array( '/receipt/' ), 'cookies' => array(), 'query' => array() ),
			array(
				'enabled'             => true,
				'bypass_paths'        => array(),
				'bypass_cookies'      => array(),
				'bypass_query_params' => array(),
				'ignored_query_params' => array(),
			)
		);

		self::assertSame( 'fail', $checks[0]['status'] );
		self::assertFalse( $checks[0]['safe'] );
	}
}
