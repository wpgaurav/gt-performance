<?php
/**
 * Capability-guarded admin-bar cache and optimization actions.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Admin;

use GTPerformance\Cache\Purger;
use GTPerformance\Contracts\Module;
use GTPerformance\Diagnostics\PurgeVerifier;
use GTPerformance\Optimization\Css\ReportRepository;
use GTPerformance\Optimization\Css\TrainingRepository;
use GTPerformance\Redis\ConnectionTester;

final class AdminBarModule implements Module {
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'menu' ), 90 );
		add_action( 'admin_post_gtperf_quick_action', array( $this, 'handle' ) );
	}

	public function menu( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'gt-performance',
				'title' => '<span class="ab-icon dashicons-performance" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'GT Performance', 'gt-performance' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=gt-performance' ),
				'meta'  => array( 'class' => 'gtp-admin-bar' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'gt-performance',
				'id'     => 'gtp-dashboard',
				'title'  => __( 'Open dashboard', 'gt-performance' ),
				'href'   => admin_url( 'admin.php?page=gt-performance' ),
			)
		);

		$url = $this->currentPublicUrl();
		if ( null !== $url ) {
			$bar->add_node(
				array(
					'parent' => 'gt-performance',
					'id'     => 'gtp-explain-current',
					'title'  => __( 'Explain this page', 'gt-performance' ),
					'href'   => add_query_arg(
						array(
							'page'    => 'gt-performance',
							'tab'     => 'safety',
							'gtperf_url' => $url,
						),
						admin_url( 'admin.php' )
					),
				)
			);
			$this->actionNode( $bar, 'gtp-purge-current', __( 'Purge this URL', 'gt-performance' ), 'purge-url', $url );
			$this->actionNode( $bar, 'gtp-verify-current', __( 'Purge and verify this URL', 'gt-performance' ), 'purge-verify', $url );
			$this->actionNode( $bar, 'gtp-warm-current', __( 'Warm this URL', 'gt-performance' ), 'warm-url', $url );
			$this->actionNode( $bar, 'gtp-css-current', __( 'Regenerate CSS for this URL', 'gt-performance' ), 'regenerate-css', $url );
		}

		$training = ( new TrainingRepository() )->state();
		$this->actionNode(
			$bar,
			'gtp-css-training-toggle',
			! empty( $training['active'] ) ? __( 'Stop CSS Training Mode', 'gt-performance' ) : __( 'Start CSS Training Mode', 'gt-performance' ),
			! empty( $training['active'] ) ? 'stop-css-training' : 'start-css-training'
		);

		$this->actionNode( $bar, 'gtp-purge-all', __( 'Purge page and edge caches', 'gt-performance' ), 'purge-all' );
		$this->actionNode( $bar, 'gtp-flush-object', __( 'Flush object cache', 'gt-performance' ), 'flush-object' );
		$this->actionNode( $bar, 'gtp-test-redis', __( 'Test Redis connection', 'gt-performance' ), 'test-redis' );
		$bar->add_node(
			array(
				'parent' => 'gt-performance',
				'id'     => 'gtp-css-reports',
				'title'  => __( 'View CSS reports', 'gt-performance' ),
				'href'   => admin_url( 'admin.php?page=gt-performance&tab=css-reports' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'gt-performance',
				'id'     => 'gtp-safety-lab',
				'title'  => __( 'Open Safety Lab', 'gt-performance' ),
				'href'   => admin_url( 'admin.php?page=gt-performance&tab=safety' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'gt-performance',
				'id'     => 'gtp-integrations',
				'title'  => __( 'Integrations and object cache', 'gt-performance' ),
				'href'   => admin_url( 'admin.php?page=gt-performance&tab=integrations' ),
			)
		);
	}

	public function handle(): never {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage GT Performance.', 'gt-performance' ) );
		}

		$command = isset( $_GET['command'] ) ? sanitize_key( wp_unslash( $_GET['command'] ) ) : '';
		check_admin_referer( 'gtperf_quick_action_' . $command );
		$url = isset( $_GET['url'] ) ? $this->sameSiteUrl( sanitize_url( wp_unslash( $_GET['url'] ) ) ) : null;

		switch ( $command ) {
			case 'purge-url':
				if ( null !== $url ) {
					( new Purger() )->purgeUrl( $url );
				}
				break;

			case 'warm-url':
				if ( null !== $url ) {
					$this->warm( $url );
				}
				break;

			case 'regenerate-css':
				if ( null !== $url ) {
					( new ReportRepository() )->invalidateUrl( $url );
					( new Purger() )->purgeUrl( $url );
					$this->warm( $url );
				}
				break;

			case 'purge-verify':
				if ( null !== $url ) {
					$result = ( new PurgeVerifier() )->verify( $url );
					$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : ( 'verified' === ( $result['status'] ?? '' ) ? 'purge-verified' : 'purge-warning' ), 'safety' );
				}
				break;

			case 'start-css-training':
				( new TrainingRepository() )->start( get_current_user_id() );
				$this->redirect( 'css-training-started', 'css-reports' );
				break;

			case 'stop-css-training':
				( new TrainingRepository() )->stop();
				$this->redirect( 'css-training-stopped', 'css-reports' );
				break;

			case 'purge-all':
				( new Purger() )->purgeAll();
				break;

			case 'flush-object':
				wp_cache_flush();
				break;

			case 'test-redis':
				$result = ( new ConnectionTester() )->test();
				$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'redis-connected', 'integrations' );
				break;

			default:
				$this->redirect( 'quick-action-invalid', 'dashboard' );
		}

		$referer = wp_get_referer();
		wp_safe_redirect( is_string( $referer ) && '' !== $referer ? $referer : admin_url( 'admin.php?page=gt-performance' ) );
		exit;
	}

	private function actionNode( \WP_Admin_Bar $bar, string $id, string $title, string $command, ?string $url = null ): void {
		$args = array(
			'action'  => 'gtperf_quick_action',
			'command' => $command,
		);
		if ( null !== $url ) {
			$args['url'] = $url;
		}

		$bar->add_node(
			array(
				'parent' => 'gt-performance',
				'id'     => $id,
				'title'  => $title,
				'href'   => wp_nonce_url(
					add_query_arg( $args, admin_url( 'admin-post.php' ) ),
					'gtperf_quick_action_' . $command
				),
			)
		);
	}

	private function currentPublicUrl(): ?string {
		if ( is_admin() ) {
			return null;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return $this->sameSiteUrl( home_url( is_string( $request ) ? $request : '/' ) );
	}

	private function sameSiteUrl( mixed $url ): ?string {
		$url      = esc_url_raw( (string) $url );
		$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$homeHost = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$scheme   = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return '' !== $host && hash_equals( $homeHost, $host ) && in_array( $scheme, array( 'http', 'https' ), true )
			? $url
			: null;
	}

	private function warm( string $url ): void {
		wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'headers'     => array( 'X-GT-Preload' => '1' ),
				'user-agent'  => 'GT-Performance-Admin-Bar/' . GTPERF_VERSION,
			)
		);
	}

	private function redirect( string $notice, string $tab ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'gt-performance',
					'tab'        => $tab,
					'gtperf_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
