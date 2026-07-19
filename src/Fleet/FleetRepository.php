<?php
/**
 * Local fleet identity and replay protection.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Fleet;

final class FleetRepository {
	private const ID_OPTION     = 'gt_performance_fleet_site_id';
	private const EVENTS_OPTION = 'gt_performance_fleet_events';

	public function siteId(): string {
		$siteId = (string) get_option( self::ID_OPTION, '' );
		if ( '' === $siteId ) {
			$siteId = wp_generate_uuid4();
			update_option( self::ID_OPTION, $siteId, false );
		}

		return $siteId;
	}

	public function used( string $bundleId ): bool {
		foreach ( $this->events() as $event ) {
			if ( hash_equals( (string) ( $event['bundle_id'] ?? '' ), $bundleId ) ) {
				return true;
			}
		}

		return false;
	}

	public function record( string $bundleId, string $sourceId, string $status ): void {
		$events = $this->events();
		array_unshift(
			$events,
			array(
				'bundle_id' => sanitize_text_field( $bundleId ),
				'source_id' => sanitize_text_field( $sourceId ),
				'status'    => sanitize_key( $status ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		update_option( self::EVENTS_OPTION, array_slice( $events, 0, 20 ), false );
	}

	/**
	 * @return list<array<string, string>>
	 */
	public function events(): array {
		$saved = get_option( self::EVENTS_OPTION, array() );

		return is_array( $saved ) ? array_values( array_filter( $saved, 'is_array' ) ) : array();
	}
}
