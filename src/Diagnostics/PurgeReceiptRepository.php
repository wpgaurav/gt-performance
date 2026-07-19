<?php
/**
 * Bounded purge verification receipt history.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Diagnostics;

final class PurgeReceiptRepository {
	private const OPTION = 'gt_performance_purge_receipts';

	/**
	 * @param array<string, mixed> $receipt Redacted verification evidence.
	 */
	public function add( array $receipt ): void {
		$receipts = $this->recent( 19 );
		array_unshift( $receipts, $receipt );
		update_option( self::OPTION, array_slice( $receipts, 0, 20 ), false );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit = 20 ): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? array_values( array_filter( $saved, 'is_array' ) ) : array();

		return array_slice( $saved, 0, max( 1, min( 50, $limit ) ) );
	}
}
