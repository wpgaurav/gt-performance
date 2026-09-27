<?php
/**
 * Plain-language readings of unused CSS results, and totals by delivery mode.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\ReportExplainer;
use GTPerformance\Optimization\Css\ReportRepository;
use PHPUnit\Framework\TestCase;

final class CssReportExplainerTest extends TestCase {
	private mixed $wpdb;

	protected function setUp(): void {
		$this->wpdb = $GLOBALS['wpdb'];
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	public function test_a_budget_fallback_reads_as_a_working_page_with_its_measured_size(): void {
		$report = $this->report(
			'ready',
			array(
				'fallback'        => 'critical_budget_exceeded',
				'original_bytes'  => 190 * 1024,
				'generated_bytes' => 100 * 1024,
				'critical_bytes'  => 38 * 1024,
				'critical_budget' => 14336,
			)
		);

		self::assertSame( 'Ready, one file', ReportExplainer::status( $report )['label'] );
		$text = ReportExplainer::explain( $report );
		self::assertStringContainsString( 'Visitors get 100 KB of CSS instead of 190 KB, as one file.', $text );
		self::assertStringContainsString( 'needs 38 KB of CSS, over the 14 KB inline limit', $text );
		self::assertStringNotContainsString( 'critical_budget_exceeded', $text );
	}

	public function test_older_fallbacks_without_a_size_still_explain_themselves(): void {
		$text = ReportExplainer::explain( $this->report( 'ready', array( 'fallback' => 'critical_budget_exceeded' ) ) );

		self::assertStringContainsString( 'more CSS than the inline limit allows', $text );
	}

	/**
	 * @dataProvider failures
	 */
	public function test_every_stored_reason_becomes_a_sentence( string $status, array $meta, string $expected ): void {
		self::assertStringContainsString( $expected, ReportExplainer::explain( $this->report( $status, $meta ) ) );
	}

	/**
	 * @return array<string, array{string, array<string, string>, string}>
	 */
	public static function failures(): array {
		return array(
			'loopback'    => array( 'failed', array( 'error' => 'No completed build. Check the page-cache drop-in, exclusions, and loopback access.' ), 'cannot request its own pages' ),
			'gone'        => array( 'failed', array( 'error' => 'CSS generation returned HTTP 404.' ), 'no longer exists' ),
			'server'      => array( 'failed', array( 'error' => 'CSS generation returned HTTP 503.' ), 'server error' ),
			'empty'       => array( 'failed', array( 'error' => 'The used CSS result was empty.' ), 'markup could not be read' ),
			'unknown'     => array( 'failed', array( 'error' => 'Something odd.' ), 'Something odd. Visitors still get the original CSS' ),
			'no sheets'   => array( 'skipped', array( 'reason' => 'No eligible stylesheets were found.' ), 'Nothing to reduce' ),
			'slow'        => array( 'skipped', array( 'reason' => 'Pruning exceeded the request time budget.' ), 'longer than 20 seconds' ),
			'paused'      => array( 'skipped', array( 'reason' => 'Generation is paused or this URL is excluded by the current settings.' ), 'staged rollout' ),
			'out of date' => array( 'stale', array(), 'until the next visit rebuilds it' ),
		);
	}

	public function test_hybrid_advice_suggests_a_limit_that_fits_three_in_four_pages(): void {
		$advice = ReportExplainer::budgetAdvice( array( 10000, 16000, 18000, 19500, 40000 ), 4, 5, 14336 );

		self::assertStringContainsString( '4 of 5 Hybrid builds', $advice );
		self::assertStringContainsString( 'A limit of 20 KB would fit three in four', $advice );
	}

	public function test_hybrid_advice_recommends_a_file_when_the_top_of_the_page_is_too_big_to_inline(): void {
		$advice = ReportExplainer::budgetAdvice( array( 60000, 70000, 80000 ), 3, 3, 14336 );

		self::assertStringContainsString( 'Choose Generated file', $advice );
		self::assertStringNotContainsString( 'A limit of', $advice );
	}

	public function test_hybrid_advice_is_silent_when_every_page_fits(): void {
		self::assertSame( '', ReportExplainer::budgetAdvice( array( 6000 ), 0, 5, 14336 ) );
	}

	public function test_totals_leave_out_results_from_another_delivery_mode(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function prepare( string $sql, mixed ...$args ): string {
				unset( $args );
				return $sql;
			}
			public function get_results( string $sql, string $format ): array {
				unset( $sql, $format );
				$row = static fn ( string $mode, array $meta ): array => array(
					'mode'     => $mode,
					'status'   => 'ready',
					'metadata' => json_encode( $meta + array( 'generation' => 1, 'revision' => 1, 'original_bytes' => 100, 'generated_bytes' => 40 ) ),
				);
				return array(
					$row( 'file', array() ),
					$row( 'hybrid', array( 'fallback' => 'critical_budget_exceeded', 'critical_bytes' => 30000 ) ),
					$row( 'hybrid', array( 'critical_bytes' => 9000 ) ),
				);
			}
		};

		$repository = new ReportRepository();
		$file       = $repository->statistics( 'file' );
		self::assertSame( 1, $file['ready'] );
		self::assertSame( 1, $file['total'] );
		self::assertSame( 2, $file['other_mode'] );
		self::assertSame( 3, $repository->statistics()['ready'], 'Without a mode every result counts.' );
		self::assertSame(
			array(
				'builds'    => 2,
				'fallbacks' => 1,
				'sizes'     => array( 30000, 9000 ),
			),
			$repository->hybridBudget()
		);
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function report( string $status, array $meta ): array {
		return array(
			'status'   => $status,
			'mode'     => 'hybrid',
			'metadata' => $meta,
		);
	}
}
