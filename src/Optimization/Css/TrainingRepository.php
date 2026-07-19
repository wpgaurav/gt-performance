<?php
/**
 * Bounded CSS selector training state and publishing.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

use GTPerformance\Core\Settings;

final class TrainingRepository {
	private const OPTION   = 'gt_performance_css_training';
	private const PREVIOUS = 'gt_performance_css_training_previous';

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$state = array_merge(
			array(
				'active'      => false,
				'user_id'     => 0,
				'started_at'  => '',
				'expires_at'  => 0,
				'candidates'  => array(),
				'last_seen_at' => '',
			),
			$saved
		);

		if ( (bool) $state['active'] && time() > (int) $state['expires_at'] ) {
			$state['active'] = false;
			update_option( self::OPTION, $state, false );
		}

		$state['candidates'] = ( new SelectorObservation() )->sanitizeMany( (array) $state['candidates'] );

		return $state;
	}

	public function start( int $userId ): void {
		update_option(
			self::OPTION,
			array(
				'active'       => true,
				'user_id'      => max( 1, $userId ),
				'started_at'   => current_time( 'mysql', true ),
				'expires_at'   => time() + HOUR_IN_SECONDS,
				'candidates'   => array(),
				'last_seen_at' => '',
			),
			false
		);
	}

	public function stop(): void {
		$state           = $this->state();
		$state['active'] = false;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * @param list<string> $selectors Observed selectors.
	 */
	public function observe( int $userId, array $selectors ): int {
		$state = $this->state();
		if ( ! (bool) $state['active'] || (int) $state['user_id'] !== $userId ) {
			return 0;
		}

		$incoming            = ( new SelectorObservation() )->sanitizeMany( $selectors );
		$state['candidates'] = array_slice(
			array_values( array_unique( array_merge( (array) $state['candidates'], $incoming ) ) ),
			0,
			500
		);
		$state['last_seen_at'] = current_time( 'mysql', true );
		update_option( self::OPTION, $state, false );

		return count( (array) $state['candidates'] );
	}

	public function publish(): int {
		$state    = $this->state();
		$settings = Settings::all();
		$previous = (array) ( $settings['css']['trained_selectors'] ?? array() );
		update_option( self::PREVIOUS, $previous, false );

		$published = ( new SelectorObservation() )->sanitizeMany( (array) $state['candidates'] );
		$settings['css']['trained_selectors'] = $published;
		Settings::save( $settings );
		$this->stop();

		return count( $published );
	}

	public function rollback(): int {
		$previous = get_option( self::PREVIOUS, array() );
		$previous = ( new SelectorObservation() )->sanitizeMany( is_array( $previous ) ? $previous : array() );
		$settings = Settings::all();
		$settings['css']['trained_selectors'] = $previous;
		Settings::save( $settings );

		return count( $previous );
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}
}
