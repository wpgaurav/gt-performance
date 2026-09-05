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

		// Shared admin code must not name this subsystem: the WordPress.org package
		// does not contain it. The channel registers its own screen instead.
		add_filter( 'gt_performance_admin_tabs', array( $this, 'registerTab' ) );
		add_filter( 'gt_performance_admin_tab_labels', array( $this, 'registerTabLabel' ) );
		add_action( 'gt_performance_render_tab_license', array( $this, 'renderTab' ) );
		add_filter( 'gt_performance_admin_notices', array( $this, 'registerNotices' ) );
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

	/**
	 * @param array<string, array{0:string,1:string}> $notices Notice map.
	 * @return array<string, array{0:string,1:string}>
	 */
	public function registerNotices( array $notices ): array {
		return array_merge(
			$notices,
			array(
				'license-activated'          => array( __( 'This site was activated and will now receive updates.', 'gt-performance' ), 'success' ),
				'license-deactivated'        => array( __( 'This site was deactivated. It will no longer receive updates.', 'gt-performance' ), 'success' ),
				'license-checked'            => array( __( 'The license was re-checked and update information refreshed.', 'gt-performance' ), 'success' ),
				'gtperf_license_product'     => array( __( 'The update channel is not configured for this build.', 'gt-performance' ), 'error' ),
				'gtperf_license_connection'  => array( __( 'The update server could not be reached. Check outbound HTTPS from this server.', 'gt-performance' ), 'error' ),
				'gtperf_license_response'    => array( __( 'The update server returned an unreadable response.', 'gt-performance' ), 'error' ),
				'gtperf_license_rejected'    => array( __( 'The update server rejected the license key.', 'gt-performance' ), 'error' ),
				'gtperf_license_missing_key' => array( __( 'Enter a license key before activating.', 'gt-performance' ), 'error' ),
			)
		);
	}

	/**
	 * @param list<string> $tabs Registered tabs.
	 * @return list<string>
	 */
	public function registerTab( array $tabs ): array {
		$tabs[] = 'license';

		return $tabs;
	}

	/**
	 * @param array<string, string> $labels Tab labels.
	 * @return array<string, string>
	 */
	public function registerTabLabel( array $labels ): array {
		$labels['license'] = __( 'License', 'gt-performance' );

		return $labels;
	}

	/**
	 * The License screen.
	 *
	 * Rendered by this channel rather than by AdminModule, because the WordPress.org
	 * package does not contain this subsystem and its shared admin code must not
	 * name it.
	 */
	public function renderTab(): void {
		$repository = new LicenseRepository();
		$state      = $repository->state();
		$status     = (string) $state['status'];
		$constant   = $repository->isConstantManaged();
		$active     = 'valid' === $status;
		?>
		<div class="gtp-page-heading">
			<div>
				<h2><?php esc_html_e( 'License', 'gt-performance' ); ?></h2>
				<p><?php esc_html_e( 'Updates for this copy come from gauravtiwari.org. Activate the site to receive them.', 'gt-performance' ); ?></p>
			</div>
		</div>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Update channel', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'GT Performance is free. The key identifies this site so the update server can serve it the package.', 'gt-performance' ); ?></p>
				</div>
			</div>
			<dl class="gtp-definition-list">
				<div>
					<dt><?php esc_html_e( 'Status', 'gt-performance' ); ?></dt>
					<dd><?php echo esc_html( $this->statusLabel( $status ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Key', 'gt-performance' ); ?></dt>
					<dd><code><?php echo esc_html( '' !== $repository->maskedKey() ? $repository->maskedKey() : __( 'Not set', 'gt-performance' ) ); ?></code></dd>
				</div>
				<?php if ( '' !== (string) $state['expiration_date'] ) : ?>
					<div>
						<dt><?php esc_html_e( 'Expires', 'gt-performance' ); ?></dt>
						<dd><?php echo esc_html( (string) $state['expiration_date'] ); ?></dd>
					</div>
				<?php endif; ?>
				<?php if ( (int) $state['activation_limit'] > 0 ) : ?>
					<div>
						<dt><?php esc_html_e( 'Activations', 'gt-performance' ); ?></dt>
						<dd><?php echo esc_html( (int) $state['activations_count'] . ' / ' . (int) $state['activation_limit'] ); ?></dd>
					</div>
				<?php endif; ?>
			</dl>

			<?php if ( $constant ) : ?>
				<p class="gtp-callout">
					<?php esc_html_e( 'The key is set in wp-config.php through GTPERF_LICENSE_KEY, so it cannot be changed here.', 'gt-performance' ); ?>
				</p>
			<?php elseif ( ! $active ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gtp-inline-form">
					<?php wp_nonce_field( 'gtperf_license_activate' ); ?>
					<input type="hidden" name="action" value="gtperf_license_activate">
					<label for="gtp-license-key" class="screen-reader-text"><?php esc_html_e( 'License key', 'gt-performance' ); ?></label>
					<input type="text" id="gtp-license-key" name="license_key" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'Paste your license key', 'gt-performance' ); ?>" required>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Activate', 'gt-performance' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( $repository->hasCredentials() ) : ?>
				<div class="gtp-inline-form">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'gtperf_license_check' ); ?>
						<input type="hidden" name="action" value="gtperf_license_check">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Check for updates', 'gt-performance' ); ?></button>
					</form>
					<?php if ( ! $constant ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'gtperf_license_deactivate' ); ?>
							<input type="hidden" name="action" value="gtperf_license_deactivate">
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Deactivate this site', 'gt-performance' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function statusLabel( string $status ): string {
		return match ( $status ) {
			'valid'   => __( 'Active', 'gt-performance' ),
			'expired' => __( 'Expired', 'gt-performance' ),
			'invalid' => __( 'Invalid', 'gt-performance' ),
			default   => __( 'Not activated', 'gt-performance' ),
		};
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
