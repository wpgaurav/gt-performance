<?php
/**
 * Disposable WordPress Studio fixture for unused CSS delivery tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

add_action(
	'template_redirect',
	static function (): void {
		$isAdminPreview = isset( $_GET['gtp-css-admin'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['gtp-css-admin'] ) );
		if ( ! $isAdminPreview ) {
			return;
		}

		wp_set_current_user( 1 );
		wp_set_auth_cookie( 1 );
		wp_safe_redirect( admin_url( 'admin.php?page=gt-performance&tab=optimization' ) );
		exit;
	},
	-10002
);

add_action(
	'template_redirect',
	static function (): void {
		$isReport = isset( $_GET['gtp-css-report'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['gtp-css-report'] ) );
		if ( ! $isReport ) {
			return;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( ( new \GTPerformance\Optimization\Css\ReportRepository() )->recent() );
		exit;
	},
	-10001
);

add_action(
	'template_redirect',
	static function (): void {
		$modes = array(
			'file'            => 'file',
			'inline'          => 'inline',
			'hybrid'          => 'hybrid',
			'hybrid-fallback' => 'hybrid',
		);
		$mode  = isset( $_GET['gtp-css-mode'] ) ? sanitize_key( wp_unslash( $_GET['gtp-css-mode'] ) ) : '';
		if ( ! isset( $modes[ $mode ] ) ) {
			return;
		}

		$settings                                    = \GTPerformance\Core\Settings::all();
		$settings['cache']['enabled']                = true;
		$settings['cache']['ignored_query_params'][] = 'gtp-css-mode';
		$settings['cache']['ignored_query_params']   = array_values( array_unique( $settings['cache']['ignored_query_params'] ) );
		$settings['css']['enabled']                  = true;
		$settings['css']['mode']                     = $modes[ $mode ];
		$settings['css']['critical_budget']          = 'hybrid-fallback' === $mode ? 2048 : 14336;
		$settings['css']['safelist']                 = array(
			'gtp-safe',
			'/\.gtp-regex-[a-z]+/',
		);
		update_option( \GTPerformance\Core\Settings::OPTION, $settings, false );
	},
	-10000
);

add_action(
	'template_redirect',
	static function (): void {
		$mode = isset( $_GET['gtp-css-mode'] ) ? sanitize_key( wp_unslash( $_GET['gtp-css-mode'] ) ) : '';
		if ( ! in_array( $mode, array( 'file', 'inline', 'hybrid', 'hybrid-fallback' ), true ) ) {
			return;
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<style>';
		echo '.gtp-used{color:#123456}.gtp-used:hover{color:#654321}';
		if ( 'hybrid-fallback' === $mode ) {
			echo '.gtp-used{--gtp-large-token:"' . esc_html( str_repeat( 'x', 2300 ) ) . '"}';
		}
		echo '.gtp-below-fold{display:block}.gtp-unused{display:none}';
		echo '.gtp-safelisted{outline:1px solid green}';
		echo '.gtp-regex-kept{border-bottom:1px solid green}';
		echo '</style></head><body><main class="gtp-used">';
		echo '<h1>GT Performance unused CSS smoke test</h1>';
		for ( $index = 0; $index < 170; ++$index ) {
			echo '<span>Node ' . esc_html( (string) $index ) . '</span>';
		}
		echo '<div class="gtp-below-fold">Below fold</div>';
		echo '</main></body></html>';
		exit;
	}
);
