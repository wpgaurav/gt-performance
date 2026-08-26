<?php
/**
 * Administrator-only browser training for dynamic CSS selectors.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class TrainingModule implements Module {
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), PHP_INT_MAX );
		add_action( 'wp_ajax_gtp_css_training_observe', array( $this, 'observe' ) );
		add_filter( 'gt_performance_css_safelist', array( $this, 'safelist' ), 5 );
	}

	public function enqueue(): void {
		$state = ( new TrainingRepository() )->state();
		if ( ! current_user_can( 'manage_options' ) || ! (bool) $state['active'] || get_current_user_id() !== (int) $state['user_id'] ) {
			return;
		}

		wp_enqueue_script(
			'gt-performance-css-training',
			plugins_url( 'assets/css-training.js', GTPERF_FILE ),
			array(),
			GTPERF_VERSION,
			true
		);
		wp_localize_script(
			'gt-performance-css-training',
			'gtPerformanceCssTraining',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gtperf_css_training_observe' ),
			)
		);
	}

	public function observe(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'CSS training is restricted to administrators.', 'gt-performance' ) ), 403 );
		}
		check_ajax_referer( 'gtperf_css_training_observe', 'nonce' );
		// Capability and nonce checks above authorize this explicit request field.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$selectors = isset( $_POST['selectors'] ) && is_array( $_POST['selectors'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['selectors'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$count = ( new TrainingRepository() )->observe( get_current_user_id(), array_map( 'strval', $selectors ) );
		wp_send_json_success( array( 'count' => $count ) );
	}

	/**
	 * @param list<string> $safelist Selector patterns.
	 * @return list<string>
	 */
	public function safelist( array $safelist ): array {
		$trained = ( new SelectorObservation() )->safelistPatterns(
			array_map( 'strval', (array) Settings::get( 'css.trained_selectors', array() ) )
		);

		return array_values(
			array_unique(
				array_merge( $safelist, $trained )
			)
		);
	}
}
