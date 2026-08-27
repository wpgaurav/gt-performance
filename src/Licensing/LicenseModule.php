<?php
/**
 * Licensing hooks and protected admin actions.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Licensing;

use GTPerformance\Contracts\Module;

final class LicenseModule implements Module {
	public function register(): void {
		( new Updater() )->register();

		add_action( 'admin_post_gtperf_license_activate', array( $this, 'activate' ) );
		add_action( 'admin_post_gtperf_license_deactivate', array( $this, 'deactivate' ) );
		add_action( 'admin_post_gtperf_license_check', array( $this, 'check' ) );
		add_action( 'gt_performance_verify_license', array( $this, 'scheduledCheck' ) );
		add_filter( 'plugin_action_links_' . GTPERF_BASENAME, array( $this, 'actionLinks' ) );
	}

	public function activate(): never {
		$this->guard( 'gtperf_license_activate' );
		// The capability and action nonce are verified by guard() above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$key = isset( $_POST['license_key'] )
			? sanitize_text_field( wp_unslash( $_POST['license_key'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = ( new LicenseManager() )->activate( $key );

		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'license-activated' );
	}

	public function deactivate(): never {
		$this->guard( 'gtperf_license_deactivate' );
		$result = ( new LicenseManager() )->deactivate();

		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'license-deactivated' );
	}

	public function check(): never {
		$this->guard( 'gtperf_license_check' );
		$manager = new LicenseManager();
		$result  = $manager->verify();
		if ( ! is_wp_error( $result ) ) {
			$updates = ( new Updater( $manager ) )->metadata( true );
			if ( is_wp_error( $updates ) ) {
				$result = $updates;
			}
		}

		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'license-checked' );
	}

	public function scheduledCheck(): void {
		( new LicenseManager() )->verify();
	}

	/**
	 * @param array<string, string> $links Plugin action links.
	 * @return array<string, string>
	 */
	public function actionLinks( array $links ): array {
		$url = add_query_arg(
			array(
				'page' => 'gt-performance',
				'tab'  => 'license',
			),
			admin_url( 'admin.php' )
		);

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'License', 'gt-performance' ) . '</a>'
		);

		return $links;
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the GT Performance license.', 'gt-performance' ) );
		}

		check_admin_referer( $action );
	}

	private function redirect( string $notice ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'gt-performance',
					'tab'        => 'license',
					'gtperf_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
