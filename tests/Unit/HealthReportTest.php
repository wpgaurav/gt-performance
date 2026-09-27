<?php
/**
 * Health thresholds, redaction, and the Site Health mapping.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\HealthReport;
use GTPerformance\Diagnostics\SiteHealth;
use PHPUnit\Framework\TestCase;

final class HealthReportTest extends TestCase {
	private const NOW = 1_800_000_000;

	/**
	 * @param array<string, mixed> $overrides Evidence to replace.
	 * @return array<string, mixed>
	 */
	private function evidence( array $overrides = array() ): array {
		return array_replace(
			array(
				'queue'            => array(
					'ready'          => true,
					'paused'         => false,
					'pending'        => 2,
					'running'        => 0,
					'failed'         => 0,
					'oldest_due_age' => 30,
				),
				'heartbeat'        => self::NOW - 60,
				'cron'             => array(
					'status' => 'pass',
					'value'  => 'WordPress request spawning is enabled.',
				),
				'storage_writable' => true,
				'cache_enabled'    => true,
				'page_dropin'      => 'owned',
				'wp_cache'         => 'enabled',
				'redis_enabled'    => false,
				'redis_dropin'     => 'missing',
				'edge_conflict'    => false,
				'config_error'     => false,
				'purges'           => array(),
				'css_enabled'      => false,
				'css'              => array(),
				'warm'             => null,
			),
			$overrides
		);
	}

	/**
	 * @param list<array<string, mixed>> $checks Checks.
	 * @return array<string, array<string, mixed>>
	 */
	private function byId( array $checks ): array {
		return array_column( $checks, null, 'id' );
	}

	public function test_healthy_site_passes_and_optional_integrations_are_omitted(): void {
		$checks = HealthReport::evaluate( $this->evidence(), self::NOW );
		$byId   = $this->byId( $checks );

		self::assertSame( 'pass', HealthReport::overall( $checks ) );
		self::assertArrayNotHasKey( 'object_cache_dropin', $byId );
		self::assertArrayNotHasKey( 'css_reports', $byId );
		self::assertArrayNotHasKey( 'edge_ownership', $byId );
		self::assertArrayNotHasKey( 'visitor_variation', $byId );
		self::assertSame( 'info', $byId['warming']['status'] );
		self::assertSame( 0, $byId['warming']['observed_at'] );
	}

	public function test_backlog_stale_heartbeat_and_failures_need_review(): void {
		$checks = $this->byId(
			HealthReport::evaluate(
				$this->evidence(
					array(
						'queue'     => array(
							'ready'          => true,
							'paused'         => true,
							'pending'        => 40,
							'running'        => 1,
							'failed'         => 0,
							'oldest_due_age' => 16 * 60,
						),
						'heartbeat' => self::NOW - 20 * 60,
					)
				),
				self::NOW
			)
		);

		self::assertSame( 'warning', $checks['queue']['status'] );
		self::assertStringContainsString( 'Optional work is paused.', $checks['queue']['value'] );
		self::assertStringContainsString( '16m', $checks['queue']['value'] );
		self::assertSame( 'warning', $checks['queue_heartbeat']['status'] );
		self::assertSame( self::NOW - 20 * 60, $checks['queue_heartbeat']['observed_at'] );
	}

	public function test_unwritable_storage_conflicting_dropin_and_unpublished_settings_fail(): void {
		$checks = HealthReport::evaluate(
			$this->evidence(
				array(
					'storage_writable' => false,
					'page_dropin'      => 'conflict',
					'config_error'     => true,
				)
			),
			self::NOW
		);
		$byId = $this->byId( $checks );

		self::assertSame( 'fail', HealthReport::overall( $checks ) );
		self::assertSame( 'fail', $byId['storage']['status'] );
		self::assertSame( 'fail', $byId['page_dropin']['status'] );
		self::assertSame( 'fail', $byId['configuration']['status'] );
	}

	public function test_disabled_cache_does_not_flag_a_missing_dropin(): void {
		$byId = $this->byId( HealthReport::evaluate( $this->evidence( array( 'cache_enabled' => false, 'page_dropin' => 'missing', 'wp_cache' => 'disabled' ) ), self::NOW ) );

		self::assertSame( 'info', $byId['page_dropin']['status'] );
	}

	public function test_saved_evidence_reports_its_own_time(): void {
		$byId = $this->byId(
			HealthReport::evaluate(
				$this->evidence(
					array(
						'purges'      => array(
							array( 'status' => 'warning', 'created_at' => '2027-01-15 08:00:00' ),
							array( 'status' => 'verified', 'created_at' => '2027-01-14 08:00:00' ),
						),
						'css_enabled' => true,
						'css'         => array( 'sampled' => 12, 'ready' => 9, 'queued' => 1, 'processing' => 0, 'failed' => 2 ),
						'warm'        => array(
							'state'      => 'capacity_limited',
							'updated_at' => self::NOW - 300,
							'warnings'   => array( 'foreign_entries' ),
							'targets'    => array(
								'sitemap' => array( 'fetched' => 3 ),
								'url'     => array( 'origin_ready' => 40, 'edge_observed' => 5, 'skipped' => 10 ),
							),
						),
					)
				),
				self::NOW
			)
		);

		self::assertSame( 'warning', $byId['purge_verification']['status'] );
		self::assertSame( strtotime( '2027-01-15 08:00:00 UTC' ), $byId['purge_verification']['observed_at'] );
		self::assertSame( 'saved', $byId['purge_verification']['source'] );
		self::assertSame( 'warning', $byId['css_reports']['status'] );
		self::assertSame( 'warning', $byId['warming']['status'] );
		self::assertSame( self::NOW - 300, $byId['warming']['observed_at'] );
		self::assertStringContainsString( '40 origin ready, 5 edge observed', $byId['warming']['value'] );
		self::assertStringContainsString( 'foreign_entries', $byId['warming']['value'] );
	}

	public function test_a_run_without_progress_is_reported_as_stalled(): void {
		$warm = array(
			'state'      => 'warming',
			'updated_at' => self::NOW - 45 * 60,
			'warnings'   => array(),
			'targets'    => array( 'sitemap' => array(), 'url' => array( 'queued' => 3 ) ),
		);
		$byId = $this->byId( HealthReport::evaluate( $this->evidence( array( 'warm' => $warm ) ), self::NOW ) );

		self::assertSame( 'warning', $byId['warming']['status'] );
		self::assertStringContainsString( 'No progress for 45m', $byId['warming']['value'] );

		$warm['updated_at'] = self::NOW - 60;
		self::assertSame( 'pass', $this->byId( HealthReport::evaluate( $this->evidence( array( 'warm' => $warm ) ), self::NOW ) )['warming']['status'] );
	}

	public function test_export_redacts_paths_and_credentials(): void {
		$report = HealthReport::redact(
			array(
				'checks' => array(
					array( 'value' => 'Failed at ' . WP_CONTENT_DIR . '/cache/gt-performance with token=abc123 from https://user:pw@example.com/wp-sitemap.xml?key=1' ),
				),
			)
		);
		$value = $report['checks'][0]['value'];

		self::assertStringNotContainsString( WP_CONTENT_DIR, $value );
		self::assertStringNotContainsString( 'abc123', $value );
		self::assertStringNotContainsString( 'pw@', $value );
		self::assertStringNotContainsString( 'key=1', $value );
	}

	public function test_site_health_maps_worst_status_and_lists_only_attention_items(): void {
		$checks = HealthReport::evaluate( $this->evidence( array( 'queue' => array( 'ready' => false, 'paused' => false, 'pending' => 0, 'running' => 0, 'failed' => 0, 'oldest_due_age' => 0 ) ) ), self::NOW );
		$result = SiteHealth::result( $checks );

		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( SiteHealth::TEST, $result['test'] );
		self::assertStringContainsString( 'Schema upgrade pending', $result['description'] );
		self::assertStringNotContainsString( 'Cache storage', $result['description'] );
		self::assertSame( 'good', SiteHealth::result( HealthReport::evaluate( $this->evidence(), self::NOW ) )['status'] );
	}

	public function test_sitemap_sources_are_limited_to_this_site(): void {
		$GLOBALS['gtperf_test_options'][ Settings::OPTION ] = Settings::defaults();
		$sources = array( 'https://example.com/sitemap_index.xml', 'https://evil.test/sitemap.xml', 'http://example.com/http.xml', 'https://example.com:8443/port.xml', 'https://user:pw@example.com/creds.xml', 'javascript:alert(1)' );
		for ( $i = 0; $i < 12; ++$i ) {
			$sources[] = 'https://example.com/extra-' . $i . '.xml';
		}

		$saved = Settings::sanitize( array( 'cache' => array( 'preload_sitemaps' => implode( "\n", $sources ) ) ) );

		self::assertCount( 10, $saved['cache']['preload_sitemaps'] );
		self::assertSame( 'https://example.com/sitemap_index.xml', $saved['cache']['preload_sitemaps'][0] );
		self::assertSame( 'https://example.com/extra-0.xml', $saved['cache']['preload_sitemaps'][1] );
	}

	public function test_language_and_currency_plugins_are_named_as_a_warning(): void {
		$checks = HealthReport::evaluate( $this->evidence( array( 'visitor_variation' => array( 'WPML', 'CURCY Multi Currency for WooCommerce' ) ) ), self::NOW );
		$check  = $this->byId( $checks )['visitor_variation'];

		self::assertSame( 'warning', $check['status'] );
		self::assertStringStartsWith( 'WPML, CURCY Multi Currency for WooCommerce can show different pages', $check['value'] );
		self::assertSame( 'warning', HealthReport::overall( $checks ) );
	}
}
