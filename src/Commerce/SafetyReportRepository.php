<?php
/**
 * Bounded commerce safety-run history.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

final class SafetyReportRepository {
	private const OPTION = 'gt_performance_commerce_safety_runs';

	/**
	 * @param array<string, mixed> $report Redacted safety report.
	 */
	public function add( array $report ): void {
		$reports = $this->recent( 9 );
		array_unshift( $reports, $report );
		update_option( self::OPTION, array_slice( $reports, 0, 10 ), false );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit = 10 ): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? array_values( array_filter( $saved, 'is_array' ) ) : array();

		return array_slice( $saved, 0, max( 1, min( 20, $limit ) ) );
	}
}
