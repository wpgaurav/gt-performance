<?php
/**
 * Standalone, accessible GT Performance administration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Admin;

use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\WpCacheConstant;
use GTPerformance\Cloudflare\ApiClient;
use GTPerformance\Cloudflare\ClientFactory;
use GTPerformance\Cloudflare\ConnectionDiagnostics;
use GTPerformance\Cloudflare\RuleManager;
use GTPerformance\Cloudflare\TokenProvisioner;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Compatibility\PluginDetector;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Paths;
use GTPerformance\Core\SecretCipher;
use GTPerformance\Core\Settings;
use GTPerformance\Database\Cleaner;
use GTPerformance\Diagnostics\CacheInspector;
use GTPerformance\Diagnostics\PurgeReceiptRepository;
use GTPerformance\Diagnostics\PurgeVerifier;
use GTPerformance\Integrations\RecommendedDefaults;
use GTPerformance\Optimization\Css\ReportRepository;
use GTPerformance\Optimization\Css\Maintenance;
use GTPerformance\Optimization\Css\SelectorSafelist;
use GTPerformance\Optimization\Css\UnusedCssOptimizer;
use GTPerformance\Redis\ConnectionTester;
use GTPerformance\Redis\ObjectCacheInstaller;
use GTPerformance\XCloud\EdgeOwnership;
use GTPerformance\XCloud\SiteService;

final class AdminModule implements Module {
	private const PAGE_SLUG = 'gt-performance';

	/**
	 * Carries the upstream failure text across the post-then-redirect hop, so the
	 * notice can name the real cause instead of only the stage that failed.
	 */
	private const ERROR_DETAIL_TRANSIENT = 'gtperf_admin_error_detail';

	/**
	 * @var list<string>
	 */
	private const TABS = array(
		'dashboard',
		'cache',
		'optimization',
		'exceptions',
		'cloudflare',
		'cdn',
		'integrations',
		'tools',
	);

	private string $pageHook = '';

	/**
	 * Tabs this build offers.
	 *
	 * A distribution channel can add one without shared code naming it. The
	 * FluentCart package uses this for its License screen; the WordPress.org package
	 * ships no channel, so the list is exactly self::TABS.
	 *
	 * @return list<string>
	 */
	private static function tabs(): array {
		$tabs = array_map( 'strval', (array) apply_filters( 'gt_performance_admin_tabs', self::TABS ) );

		return array_values( array_unique( array_merge( self::TABS, $tabs ) ) );
	}

	public function register(): void {
		add_action( 'admin_post_gtperf_css_regenerate', array( $this, 'regenerateCss' ) );
		add_action( 'wp_ajax_gtperf_css_report', array( $this, 'cssReport' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_init', array( $this, 'legacyRedirect' ) );
		add_filter( 'plugin_action_links_' . GTPERF_BASENAME, array( $this, 'actionLinks' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'afterSettingsUpdate' ), 10, 2 );
		add_action( 'admin_post_gtperf_install_dropin', array( $this, 'installDropin' ) );
		add_action( 'admin_post_gtperf_install_redis', array( $this, 'installRedis' ) );
		add_action( 'admin_post_gtperf_test_redis', array( $this, 'testRedis' ) );
		add_action( 'admin_post_gtperf_xcloud_refresh', array( $this, 'xcloudRefresh' ) );
		add_action( 'admin_post_gtperf_purge', array( $this, 'purge' ) );
		add_action( 'admin_post_gtperf_cloudflare_sync', array( $this, 'cloudflareSync' ) );
		add_action( 'admin_post_gtperf_cloudflare_preview', array( $this, 'cloudflarePreview' ) );
		add_action( 'admin_post_gtperf_cloudflare_diagnose', array( $this, 'cloudflareDiagnose' ) );
		add_action( 'admin_post_gtperf_cloudflare_token', array( $this, 'cloudflareProvisionToken' ) );
		add_action( 'admin_post_gtperf_purge_verify', array( $this, 'purgeVerify' ) );
		add_action( 'admin_post_gtperf_database_clean', array( $this, 'databaseClean' ) );
	}

	public function menu(): void {
		$this->pageHook = add_menu_page(
			__( 'GT Performance', 'gt-performance' ),
			__( 'GT Performance', 'gt-performance' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-performance',
			80
		);
	}

	public function settings(): void {
		register_setting(
			'gt_performance',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * @param array<string, string> $links Plugin action links.
	 * @return array<string, string>
	 */
	public function actionLinks( array $links ): array {
		$url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'gt-performance' ) . '</a>'
		);

		return $links;
	}

	public function legacyRedirect(): void {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect of sanitized routing parameters; no state changes.
		if (
			'options-general.php' !== $pagenow ||
			! isset( $_GET['page'] ) ||
			self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ||
			! current_user_can( 'manage_options' )
		) {
			return;
		}

		$args = array( 'page' => self::PAGE_SLUG );
		foreach ( array( 'tab', 'gtperf_notice' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) );
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		exit;
	}

	public function enqueueAssets( string $hook ): void {
		if ( $this->pageHook !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'gt-performance-admin',
			plugins_url( 'assets/admin.css', GTPERF_FILE ),
			array( 'dashicons' ),
			GTPERF_VERSION
		);
		wp_enqueue_script(
			'gt-performance-admin',
			plugins_url( 'assets/admin.js', GTPERF_FILE ),
			array(),
			GTPERF_VERSION,
			true
		);
		wp_localize_script(
			'gt-performance-admin',
			'gtPerformanceAdmin',
			array(
				'cssRefreshed' => __( 'CSS status refreshed.', 'gt-performance' ),
				'cssRefreshFailed' => __( 'Status could not be refreshed. Reload this page and try again.', 'gt-performance' ),
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( 'gtperf_css_report' ),
				'integrationProfiles' => RecommendedDefaults::profiles( home_url( '/' ) ),
			)
		);
	}

	/**
	 * @param mixed $input Submitted settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input               = is_array( $input ) ? $input : array();
		$current             = Settings::all();
		$input['cloudflare'] = isset( $input['cloudflare'] ) && is_array( $input['cloudflare'] )
			? $input['cloudflare']
			: array();
		$input['redis']      = isset( $input['redis'] ) && is_array( $input['redis'] )
			? $input['redis']
			: array();
		$input['xcloud']     = isset( $input['xcloud'] ) && is_array( $input['xcloud'] )
			? $input['xcloud']
			: array();
		$cipher              = new TokenCipher();

		foreach ( array( 'api_token', 'global_api_key' ) as $secretKey ) {
			$secret = trim( (string) ( $input['cloudflare'][ $secretKey ] ?? '' ) );
			if ( '' === $secret ) {
				$input['cloudflare'][ $secretKey ] = (string) $current['cloudflare'][ $secretKey ];
			} elseif ( ! str_starts_with( $secret, 'sodium:' ) && ! str_starts_with( $secret, 'openssl:' ) ) {
				$input['cloudflare'][ $secretKey ] = $cipher->encrypt( $secret );
			}
		}

		$redisPassword = trim( (string) ( $input['redis']['password'] ?? '' ) );
		if ( '' === $redisPassword ) {
			$input['redis']['password'] = (string) $current['redis']['password'];
		} elseif ( ! str_starts_with( $redisPassword, 'sodium:' ) && ! str_starts_with( $redisPassword, 'openssl:' ) ) {
			$input['redis']['password'] = ( new SecretCipher( 'redis' ) )->encrypt( $redisPassword );
		}

		$xcloudToken = trim( (string) ( $input['xcloud']['api_token'] ?? '' ) );
		if ( '' === $xcloudToken ) {
			$input['xcloud']['api_token'] = (string) $current['xcloud']['api_token'];
		} elseif ( ! str_starts_with( $xcloudToken, 'sodium:' ) && ! str_starts_with( $xcloudToken, 'openssl:' ) ) {
			$input['xcloud']['api_token'] = ( new SecretCipher( 'xcloud' ) )->encrypt( $xcloudToken );
		}

		$patterns   = SelectorSafelist::split( $input['css']['safelist'] ?? array() );
		$validation = ( new SelectorSafelist() )->validate( array_map( 'sanitize_text_field', $patterns ) );
		if ( $validation['invalid'] ) {
			add_settings_error(
				Settings::OPTION,
				'gtperf_invalid_css_regex',
				sprintf(
					/* translators: %s: invalid selector regular expressions. */
					__( 'These selector regular expressions were not saved because they are invalid: %s', 'gt-performance' ),
					implode( ', ', array_slice( $validation['invalid'], 0, 3 ) )
				),
				'error'
			);
		}

		$clean = Settings::sanitize( $input );
		Settings::compile( $clean );

		return $clean;
	}

	/**
	 * @param mixed $old Old settings.
	 * @param mixed $new New settings.
	 */
	public function afterSettingsUpdate( mixed $old, mixed $new ): void {
		if ( is_array( $new ) ) {
			Settings::compile( $new );
		}
		if ( ! is_array( $old ) || ! is_array( $new ) ) {
			return;
		}

		// `generation` is part of the cache key and Settings::sanitize() bumps it on
		// every save, so any save makes every stored entry unreachable. Nothing else
		// ever deletes them, so without this each save leaks the whole store to disk.
		// uninstall.php runs after the plugin's classes are gone, so it can only read a
		// plain option. This mirror is the thing that makes its gate reachable at all:
		// before this, the option was never written and uninstall silently did nothing.
		update_option(
			'gt_performance_remove_data_on_uninstall',
			! empty( $new['remove_data_on_uninstall'] ),
			false
		);

		$generationChanged = (int) ( $old['generation'] ?? 0 ) !== (int) ( $new['generation'] ?? 0 );

		if ( $generationChanged || ( $old['cdn'] ?? array() ) !== ( $new['cdn'] ?? array() ) ) {
			( new Purger() )->purgeAll();
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();
		$tab      = $this->currentTab();
		?>
		<div class="wrap gtp-admin">
			<?php $this->renderHeader( $tab ); ?>
			<?php $this->renderNotice(); ?>
			<?php settings_errors( Settings::OPTION ); ?>
			<main class="gtp-admin__main">
				<?php
				switch ( $tab ) {
					case 'cache':
						$this->renderCache( $settings );
						break;
					case 'optimization':
						$this->renderOptimization( $settings );
						break;
					case 'exceptions':
						$this->renderExceptions( $settings );
						break;
					case 'cloudflare':
						$this->renderCloudflare( $settings );
						break;
					case 'cdn':
						$this->renderCdn( $settings );
						break;
					case 'integrations':
						$this->renderIntegrations( $settings );
						break;
					case 'tools':
						$this->renderTools( $settings );
						break;
					default:
						/**
						 * Render a tab this build does not know about.
						 *
						 * A channel that added a tab through gt_performance_admin_tabs
						 * renders it here. Nothing is echoed unless a listener echoes it,
						 * and the dashboard remains the fallback for a genuinely unknown tab.
						 *
						 * @param array<string, mixed> $settings Current settings.
						 */
						if ( has_action( 'gt_performance_render_tab_' . $tab ) ) {
							do_action( 'gt_performance_render_tab_' . $tab, $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed by the literal above.
							break;
						}

						$this->renderDashboard( $settings );
				}
				?>
			</main>
		</div>
		<?php
	}

	public function installDropin(): void {
		$this->guard( 'gtperf_install_dropin' );
		$result = ( new DropinInstaller() )->install();
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'dropin-installed', 'tools' );
	}

	public function installRedis(): void {
		$this->guard( 'gtperf_install_redis' );
		$result = ( new ObjectCacheInstaller() )->install();
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'redis-installed', 'tools' );
	}

	public function testRedis(): void {
		$this->guard( 'gtperf_test_redis' );
		$result = ( new ConnectionTester() )->test();
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'redis-connected', 'integrations' );
	}

	public function xcloudRefresh(): void {
		$this->guard( 'gtperf_xcloud_refresh' );
		$settings = Settings::all();
		$status   = ( new SiteService() )->refresh( $settings );
		if ( is_wp_error( $status ) ) {
			$this->redirect( $status->get_error_code(), 'integrations' );
		}

		foreach (
			array(
				'site_uuid',
				'server_id',
				'site_id',
				'domain',
				'dashboard_url',
				'stack',
				'page_cache_enabled',
				'page_cache_source',
				'redis_enabled',
				'object_cache_pro',
				'free_edge_cache_enabled',
				'enterprise_available',
				'enterprise_requests',
				'enterprise_edge_requests',
				'enterprise_hit_percent',
				'checked_at',
			) as $key
		) {
			$settings['xcloud'][ $key ] = $status[ $key ];
		}
		$settings['xcloud']['enabled'] = true;
		Settings::save( $settings );

		$notice = ( new EdgeOwnership() )->hasDirectCloudflareConflict()
			? 'xcloud-edge-conflict'
			: 'xcloud-connected';
		$this->redirect( $notice, 'integrations' );
	}

	public function purge(): void {
		$this->guard( 'gtperf_purge' );
		( new Purger() )->purgeAll();
		$this->redirect( 'cache-purged', 'tools' );
	}

	public function cloudflareSync(): void {
		$this->guard( 'gtperf_cloudflare_sync' );
		$settings = Settings::all();
		if ( ( new EdgeOwnership() )->xcloudOwnsEdge() ) {
			$this->redirect( 'gtperf_edge_owner_conflict', 'cloudflare' );
		}
		$factory  = new ClientFactory();
		$client   = $factory->create( $settings );
		if ( is_wp_error( $client ) ) {
			$this->redirectError( $client, 'cloudflare' );
		}

		$zoneId = (string) $settings['cloudflare']['zone_id'];
		if ( '' === $zoneId ) {
			$zone = $client->zoneByName( $factory->domain( $settings ) );
			if ( is_wp_error( $zone ) ) {
				$this->redirectError( $zone, 'cloudflare' );
			}
			$zoneId                            = (string) ( $zone['id'] ?? '' );
			$settings['cloudflare']['zone_id'] = $zoneId;
		}

		$host   = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$cache  = apply_filters( 'gt_performance_cache_policy', (array) $settings['cache'] );
		$result = ( new RuleManager( $client ) )->sync( $zoneId, $host, $cache );
		if ( is_wp_error( $result ) ) {
			// Record what the zone looks like even though the write failed, so the
			// tab can still show which rule is live and how far it has drifted.
			$this->storeCloudflarePlan( $client, $zoneId, $host, $cache );
			$this->redirectError( $result, 'cloudflare' );
		}

		$settings['cloudflare']['enabled']    = true;
		$settings['cloudflare']['drift_hash'] = hash( 'sha256', (string) wp_json_encode( $cache ) );
		Settings::save( $settings );
		$this->storeCloudflarePlan( $client, $zoneId, $host, $cache );
		$this->redirect( 'cloudflare-synced', 'cloudflare' );
	}

	public function cloudflarePreview(): void {
		$this->guard( 'gtperf_cloudflare_preview' );
		$settings = Settings::all();
		$factory  = new ClientFactory();
		$client   = $factory->create( $settings );
		if ( is_wp_error( $client ) ) {
			$this->redirectError( $client, 'cloudflare' );
		}

		$zoneId = (string) $settings['cloudflare']['zone_id'];
		if ( '' === $zoneId ) {
			$zone = $client->zoneByName( $factory->domain( $settings ) );
			if ( is_wp_error( $zone ) ) {
				$this->redirectError( $zone, 'cloudflare' );
			}
			$zoneId = (string) ( $zone['id'] ?? '' );
		}

		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$cache = apply_filters( 'gt_performance_cache_policy', (array) $settings['cache'] );
		$plan  = ( new RuleManager( $client ) )->preview( $zoneId, $host, $cache );
		if ( is_wp_error( $plan ) ) {
			$this->redirectError( $plan, 'cloudflare' );
		}

		$plan['checked_at'] = current_time( 'mysql', true );
		update_option( 'gt_performance_cloudflare_plan', $plan, false );
		$this->redirect( 'cloudflare-previewed', 'cloudflare' );
	}

	/**
	 * Walk the connection one stage at a time and report the stage that breaks.
	 */
	public function cloudflareDiagnose(): void {
		$this->guard( 'gtperf_cloudflare_diagnose' );
		$report = ( new ConnectionDiagnostics() )->run();
		$this->redirect( empty( $report['ok'] ) ? 'cloudflare-diagnosed-fail' : 'cloudflare-diagnosed-ok', 'cloudflare' );
	}

	/**
	 * Mint a least-privilege Cloudflare token from the stored Global API Key.
	 */
	public function cloudflareProvisionToken(): void {
		$this->guard( 'gtperf_cloudflare_token' );
		$created = ( new TokenProvisioner() )->provision();
		if ( is_wp_error( $created ) ) {
			$this->redirectError( $created, 'cloudflare' );
		}

		$this->redirect( 'cloudflare-token-created', 'cloudflare' );
	}

	/**
	 * Best-effort refresh of the stored rule plan. Never fatal: this only powers a
	 * status panel, so a failure here must not mask the operation that ran before it.
	 *
	 * @param array<string, mixed> $cache Cache policy.
	 */
	private function storeCloudflarePlan( ApiClient $client, string $zoneId, string $host, array $cache ): void {
		$plan = ( new RuleManager( $client ) )->preview( $zoneId, $host, $cache );
		if ( is_wp_error( $plan ) ) {
			return;
		}

		$plan['checked_at'] = current_time( 'mysql', true );
		update_option( 'gt_performance_cloudflare_plan', $plan, false );
	}

	public function purgeVerify(): void {
		$this->guard( 'gtperf_purge_verify' );
		// Capability and nonce checks above authorize this explicit URL field.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verifies the action nonce above.
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = ( new PurgeVerifier() )->verify( $url );
		if ( is_wp_error( $result ) ) {
			$this->redirect( $result->get_error_code(), 'tools' );
		}

		$this->redirect( 'verified' === (string) $result['status'] ? 'purge-verified' : 'purge-warning', 'tools' );
	}

	public function databaseClean(): void {
		$this->guard( 'gtperf_database_clean' );
		// The capability and action nonce are verified by guard() above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$tasks  = isset( $_POST['tasks'] ) && is_array( $_POST['tasks'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['tasks'] ) )
			: null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$cleaner = new Cleaner();
		$result  = $cleaner->run( null === $tasks ? null : $cleaner->sanitizeTasks( $tasks ) );
		set_transient( 'gtperf_database_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		$this->redirect( 'database-cleaned', 'tools' );
	}

	private function renderHeader( string $tab ): void {
		$tabs = array(
			'dashboard'    => __( 'Dashboard', 'gt-performance' ),
			'cache'        => __( 'Cache', 'gt-performance' ),
			'optimization' => __( 'Optimization', 'gt-performance' ),
			'exceptions'   => __( 'Exceptions', 'gt-performance' ),
			'cloudflare'   => __( 'Cloudflare', 'gt-performance' ),
			'cdn'          => __( 'CDN', 'gt-performance' ),
			'integrations' => __( 'Integrations', 'gt-performance' ),
			'tools'        => __( 'Tools', 'gt-performance' ),
		);

		/**
		 * Tab labels, keyed by tab slug.
		 *
		 * A channel that registered a tab through gt_performance_admin_tabs supplies
		 * its label here. Only tabs the build actually offers are rendered.
		 *
		 * @param array<string, string> $tabs Tab labels.
		 */
		$tabs = array_map( 'strval', (array) apply_filters( 'gt_performance_admin_tab_labels', $tabs ) );
		$tabs = array_intersect_key( $tabs, array_flip( self::tabs() ) );
		?>
		<header class="gtp-admin__header">
			<div>
				<p class="gtp-admin__eyebrow"><?php esc_html_e( 'GT Performance', 'gt-performance' ); ?></p>
				<h1><?php esc_html_e( 'Performance control center', 'gt-performance' ); ?></h1>
				<p class="gtp-admin__lede"><?php esc_html_e( 'Origin caching, server-side optimization, Cloudflare and custom CDN delivery, and commerce-safe controls in one place.', 'gt-performance' ); ?></p>
			</div>
			<span class="gtp-version"><?php echo esc_html( 'Version ' . GTPERF_VERSION ); ?></span>
		</header>
		<nav class="gtp-tabs" aria-label="<?php esc_attr_e( 'GT Performance sections', 'gt-performance' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="gtp-tab<?php echo $key === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->tabUrl( $key ) ); ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private function renderNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a sanitized notice key; no state changes.
		$notice = isset( $_GET['gtperf_notice'] ) ? sanitize_key( wp_unslash( $_GET['gtperf_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$details = $this->noticeDetails( $notice );

		// The mapped sentence says which stage failed; this says what the upstream
		// service actually reported. Without it every failure reads the same.
		$reason    = get_transient( self::ERROR_DETAIL_TRANSIENT );
		delete_transient( self::ERROR_DETAIL_TRANSIENT );
		$hasReason = is_string( $reason ) && '' !== $reason;

		$tones = array(
			'success' => 'success',
			'error'   => 'danger',
			'warning' => 'warning',
		);
		$tone  = $tones[ (string) $details['type'] ] ?? 'info';

		// Dismissing drops the query argument rather than hiding the node, so a
		// reload cannot resurrect a notice the reader has already dealt with.
		$dismissUrl = remove_query_arg( 'gtperf_notice' );
		$dismiss    = sprintf(
			'<a class="gtp-notice-dock__dismiss" href="%s" aria-label="%s">&times;</a>',
			esc_url( $dismissUrl ),
			esc_attr__( 'Dismiss this notice', 'gt-performance' )
		);
		?>
		<div class="gtp-notice-dock" role="status">
			<?php if ( $hasReason ) : ?>
				<details class="gtp-notice-disclosure" data-gtp-notice>
					<summary class="gtp-notice-pill gtp-notice-pill--<?php echo esc_attr( $tone ); ?>">
						<span class="gtp-notice-pill__dot" aria-hidden="true"></span>
						<span class="gtp-notice-pill__label"><?php echo esc_html( $details['message'] ); ?></span>
						<span class="gtp-notice-pill__more"><?php esc_html_e( 'Why?', 'gt-performance' ); ?></span>
					</summary>
					<div class="gtp-notice-popover">
						<strong><?php esc_html_e( 'Reported reason', 'gt-performance' ); ?></strong>
						<p><?php echo esc_html( $reason ); ?></p>
					</div>
				</details>
			<?php else : ?>
				<span class="gtp-notice-pill gtp-notice-pill--<?php echo esc_attr( $tone ); ?>">
					<span class="gtp-notice-pill__dot" aria-hidden="true"></span>
					<span class="gtp-notice-pill__label"><?php echo esc_html( $details['message'] ); ?></span>
				</span>
			<?php endif; ?>
			<?php echo wp_kses_post( $dismiss ); ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderDashboard( array $settings ): void {
		$dropin     = ( new DropinInstaller() )->status();
		$wpCache    = ( new WpCacheConstant() )->status();
		$redis      = ( new ObjectCacheInstaller() )->status();
		$cssStats   = ( new ReportRepository() )->statistics();
		$cssReady   = $cssStats['ready'];
		$cacheReady = 'owned' === $dropin && 'enabled' === $wpCache && ! empty( $settings['cache']['enabled'] );
		?>
		<div class="gtp-page-heading">
			<div>
				<h2><?php esc_html_e( 'Dashboard', 'gt-performance' ); ?></h2>
				<p><?php esc_html_e( 'A quick read on the parts that affect real visitors.', 'gt-performance' ); ?></p>
			</div>
		</div>
		<section class="gtp-stat-grid" aria-label="<?php esc_attr_e( 'Performance status', 'gt-performance' ); ?>">
			<?php $this->stat( __( 'Page cache', 'gt-performance' ), $cacheReady ? __( 'Active', 'gt-performance' ) : __( 'Needs setup', 'gt-performance' ), $cacheReady ? 'success' : 'warning' ); ?>
			<?php $this->stat( __( 'Cloudflare', 'gt-performance' ), ! empty( $settings['cloudflare']['enabled'] ) ? __( 'Connected', 'gt-performance' ) : __( 'Not connected', 'gt-performance' ), ! empty( $settings['cloudflare']['enabled'] ) ? 'success' : 'neutral' ); ?>
			<?php $this->stat( __( 'Unused CSS', 'gt-performance' ), UnusedCssOptimizer::available() ? __( 'Enabled', 'gt-performance' ) : __( 'Off', 'gt-performance' ), UnusedCssOptimizer::available() ? 'warning' : 'neutral' ); ?>
			<?php $this->stat( __( 'CSS results ready', 'gt-performance' ), number_format_i18n( $cssReady ), $cssReady > 0 ? 'success' : 'neutral' ); ?>
		</section>
		<div class="gtp-dashboard-grid">
			<section class="gtp-panel">
				<div class="gtp-panel__header">
					<div>
						<h3><?php esc_html_e( 'Current configuration', 'gt-performance' ); ?></h3>
						<p><?php esc_html_e( 'The settings most likely to change cache behavior.', 'gt-performance' ); ?></p>
					</div>
				</div>
				<dl class="gtp-definition-list">
					<div><dt><?php esc_html_e( 'Fresh cache lifetime', 'gt-performance' ); ?></dt><dd><?php echo esc_html( human_time_diff( 0, (int) $settings['cache']['fresh_ttl'] ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Stale retention', 'gt-performance' ); ?></dt><dd><?php echo esc_html( human_time_diff( 0, (int) $settings['cache']['stale_ttl'] ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'CSS delivery', 'gt-performance' ); ?></dt><dd><?php echo esc_html( $this->cssModeLabel( (string) $settings['css']['mode'] ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Redis drop-in', 'gt-performance' ); ?></dt><dd><?php echo esc_html( $this->statusLabel( $redis ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Cache directory', 'gt-performance' ); ?></dt><dd><?php echo esc_html( wp_is_writable( Paths::cacheRoot() ) ? __( 'Writable', 'gt-performance' ) : __( 'Not writable', 'gt-performance' ) ); ?></dd></div>
				</dl>
			</section>
			<section class="gtp-panel">
				<div class="gtp-panel__header">
					<div>
						<h3><?php esc_html_e( 'Recommended next step', 'gt-performance' ); ?></h3>
						<p><?php esc_html_e( 'Finish the first incomplete performance layer.', 'gt-performance' ); ?></p>
					</div>
				</div>
				<?php if ( ! $cacheReady ) : ?>
					<p><?php esc_html_e( 'Install the page-cache drop-in below, enable origin caching, then verify a public page before adding more optimizations.', 'gt-performance' ); ?></p>
					<a class="button button-secondary" href="<?php echo esc_url( $this->tabUrl( 'cache' ) ); ?>"><?php esc_html_e( 'Open cache settings', 'gt-performance' ); ?></a>
				<?php elseif ( empty( $settings['cloudflare']['enabled'] ) ) : ?>
					<p><?php esc_html_e( 'Origin caching is ready. Connect Cloudflare Free to cache eligible HTML closer to visitors.', 'gt-performance' ); ?></p>
					<a class="button button-secondary" href="<?php echo esc_url( $this->tabUrl( 'cloudflare' ) ); ?>"><?php esc_html_e( 'Configure Cloudflare', 'gt-performance' ); ?></a>
				<?php elseif ( UnusedCssOptimizer::available() && 0 === $cssReady ) : ?>
					<p><?php esc_html_e( 'Unused CSS is enabled but no ready result exists yet. Visit a public page, then watch the CSS report.', 'gt-performance' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'The core layers are configured. Review exceptions before enabling aggressive JavaScript or media transformations.', 'gt-performance' ); ?></p>
					<a class="button button-secondary" href="<?php echo esc_url( $this->tabUrl( 'exceptions' ) ); ?>"><?php esc_html_e( 'Review exceptions', 'gt-performance' ); ?></a>
				<?php endif; ?>
			</section>
		</div>
		<?php
		$this->renderQuickOperations();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderCache( array $settings ): void {
		$this->pageIntro( __( 'Page cache', 'gt-performance' ), __( 'How pages are stored and served. Cart, checkout, and account pages are never cached.', 'gt-performance' ) );
		$this->settingsFormOpen();
		$this->panelOpen( __( 'Origin cache', 'gt-performance' ), __( 'Keep safe public HTML ready on disk so WordPress does less work.', 'gt-performance' ) );
		$this->checkbox( 'cache', 'enabled', __( 'Enable origin page cache', 'gt-performance' ), __( 'Cache eligible public GET requests after WordPress renders them once.', 'gt-performance' ), $settings );
		$this->checkbox( 'cache', 'separate_mobile', __( 'Separate cache for mobile HTML', 'gt-performance' ), __( 'Store a separate copy for phones. Only needed if your site sends different HTML to them.', 'gt-performance' ), $settings, __( 'Leave this off for a normal responsive theme. It doubles everything that has to be stored and cleared.', 'gt-performance' ) );
		$this->panelClose();

		$this->panelOpen( __( 'Cache lifetime', 'gt-performance' ), __( 'Shorter times suit sites that change often.', 'gt-performance' ) );
		$this->renderCachePresets();
		$this->number( 'cache', 'fresh_ttl', __( 'Fresh cache lifetime', 'gt-performance' ), __( 'Seconds before a cached page needs regeneration.', 'gt-performance' ), $settings, 0, 604800, __( 'seconds', 'gt-performance' ), '1', __( 'How long a stored page is served before WordPress builds it again.', 'gt-performance' ) );
		$this->number( 'cache', 'stale_ttl', __( 'Stale cache retention', 'gt-performance' ), __( 'How long an expired page remains available for background refresh.', 'gt-performance' ), $settings, 0, 2592000, __( 'seconds', 'gt-performance' ), '1', __( 'After that, the old copy is kept a little longer so one visit can refresh it while everyone else still gets a page instantly.', 'gt-performance' ) );
		$this->number( 'cache', 'stale_if_error', __( 'Stale-on-error window', 'gt-performance' ), __( 'How long stale HTML may be used when regeneration fails.', 'gt-performance' ), $settings, 0, 2592000, __( 'seconds', 'gt-performance' ), '1', __( 'If your site errors, visitors keep getting the last good page instead of an error. Set to 0 to turn this off.', 'gt-performance' ) );
		$this->number( 'cache', 'browser_ttl', __( 'Browser cache lifetime', 'gt-performance' ), __( 'How long a visitor browser may reuse HTML without checking again.', 'gt-performance' ), $settings, 0, 604800, __( 'seconds', 'gt-performance' ), '1', __( 'Keep this short so visitors see your changes soon after you publish.', 'gt-performance' ) );
		$this->panelClose();

		$this->panelOpen( __( 'Automatic cache clearing', 'gt-performance' ), __( 'Clear what changed, without throwing away the rest.', 'gt-performance' ) );
		$this->select(
			'cache',
			'post_publish_purge',
			__( 'Cache clearing after publishing', 'gt-performance' ),
			__( 'What to clear when you publish or update something.', 'gt-performance' ),
			$settings,
			array(
				'related' => __( 'Post and related pages (recommended)', 'gt-performance' ),
				'post'    => __( 'Post URL only', 'gt-performance' ),
				'all'     => __( 'Entire page and edge cache', 'gt-performance' ),
				'none'    => __( 'Do not clear automatically', 'gt-performance' ),
			),
			__( 'Related pages means the post itself, your homepage, and the archives it appears in. Clearing everything also rebuilds pages in the background.', 'gt-performance' )
		);
		$this->panelClose();

		$this->panelOpen( __( 'Cache warming', 'gt-performance' ), __( 'After clearing everything, rebuild pages in the background so visitors do not wait.', 'gt-performance' ) );
		$this->checkbox( 'cache', 'preload', __( 'Warm cache after a full purge', 'gt-performance' ), __( 'Uses your sitemap to find pages worth rebuilding first.', 'gt-performance' ), $settings );
		$this->number( 'cache', 'preload_max_urls', __( 'Maximum URLs per warm run', 'gt-performance' ), __( 'Upper bound on sitemap URLs queued after a full purge.', 'gt-performance' ), $settings, 0, 2000, __( 'URLs', 'gt-performance' ), '1', __( 'Set to 0 to stop rebuilding without turning caching off. On a big site, raise this slowly.', 'gt-performance' ) );
		$this->panelClose();
		$this->settingsFormClose();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderOptimization( array $settings ): void {
		$this->pageIntro( __( 'Optimization', 'gt-performance' ), __( 'Make pages smaller and faster: CSS, JavaScript, images, fonts, and WordPress cleanup.', 'gt-performance' ) );
		$this->renderCssStatus();
		$this->settingsFormOpen();

		$this->panelOpen( __( 'Unused CSS', 'gt-performance' ), __( 'Send only the CSS each page actually needs.', 'gt-performance' ) );
		$this->checkbox( 'css', 'enabled', __( 'Remove unused CSS', 'gt-performance' ), __( 'Send only the CSS each page actually needs.', 'gt-performance' ), $settings, __( 'This changes the CSS your site sends to visitors. Check a few pages after you turn it on, and again after you update a theme or plugin. Any stylesheet it cannot read safely is left exactly as it is.', 'gt-performance' ) );
		$this->cssDeliveryOptions( $settings );
		$this->number( 'css', 'critical_budget', __( 'Hybrid inline CSS limit', 'gt-performance' ), __( 'Maximum early-page CSS to inline in Hybrid mode.', 'gt-performance' ), $settings, 2048, 51200, __( 'bytes', 'gt-performance' ), '1', __( 'If the inlined part would be bigger than this, everything is put in a file instead so the page stays small.', 'gt-performance' ) );
		$this->checkbox( 'css', 'keep_dynamic_states', __( 'Preserve dynamic states', 'gt-performance' ), __( 'Keep styles for hover, focus, and other states a visitor triggers.', 'gt-performance' ), $settings );
		$this->select(
			'css',
			'rollout_percent',
			__( 'Staged rollout', 'gt-performance' ),
			__( 'Try the new CSS on a share of your pages first. Set it to 0% to go back to your original stylesheets straight away.', 'gt-performance' ),
			$settings,
			array(
				'0'   => __( '0% - original CSS only', 'gt-performance' ),
				'10'  => '10%',
				'25'  => '25%',
				'50'  => '50%',
				'100' => __( '100% - all eligible URLs', 'gt-performance' ),
			),
			__( 'A page always stays in the same group, so what you check is what visitors see.', 'gt-performance' )
		);
		$this->panelClose();

		$this->panelOpen( __( 'JavaScript', 'gt-performance' ), __( 'Cart, checkout, and payment scripts are never touched.', 'gt-performance' ) );
		$this->checkbox( 'javascript', 'minify', __( 'Minify local JavaScript', 'gt-performance' ), __( 'Create immutable minified copies of eligible local scripts.', 'gt-performance' ), $settings );
		$this->checkbox( 'javascript', 'defer', __( 'Defer safe JavaScript', 'gt-performance' ), __( 'Add defer to eligible external scripts.', 'gt-performance' ), $settings );
		$this->checkbox( 'javascript', 'delay', __( 'Delay selected third-party scripts', 'gt-performance' ), __( 'Hold listed scripts until someone interacts, or for five seconds.', 'gt-performance' ), $settings, __( 'Good for analytics and marketing scripts. Do not use it for consent banners, forms, or checkout.', 'gt-performance' ) );
		$this->panelClose();

		$this->panelOpen( __( 'Images and embeds', 'gt-performance' ), __( 'Load images later when they are off screen, and make smaller modern versions.', 'gt-performance' ) );
		$this->checkbox( 'media', 'lazy_load', __( 'Lazy-load non-critical images', 'gt-performance' ), __( 'Keep the first critical images eager and lazy-load later images.', 'gt-performance' ), $settings );
		$this->checkbox( 'media', 'add_dimensions', __( 'Add missing image dimensions', 'gt-performance' ), __( 'Reduce layout shifts when attachment dimensions are known.', 'gt-performance' ), $settings );
		$this->number( 'media', 'critical_images', __( 'Images to load immediately', 'gt-performance' ), __( 'Number of early images excluded from lazy loading.', 'gt-performance' ), $settings, 0, 10, __( 'images', 'gt-performance' ), '1', __( 'Counted from the top of the page. Include your main hero image.', 'gt-performance' ) );
		$this->checkbox( 'media', 'optimize_uploads', __( 'Generate optimized variants', 'gt-performance' ), __( 'Create the selected modern format when attachments are generated.', 'gt-performance' ), $settings );
		$this->checkbox( 'media', 'rewrite_variants', __( 'Serve optimized image variants', 'gt-performance' ), __( 'Rewrite eligible attachment URLs to the generated WebP or AVIF files.', 'gt-performance' ), $settings, __( 'Turn on variant generation first. Images you already uploaded need regenerating before this can use them.', 'gt-performance' ) );
		$this->select(
			'media',
			'format',
			__( 'Generated format', 'gt-performance' ),
			__( 'AVIF requires image-editor support on the server.', 'gt-performance' ),
			$settings,
			array(
				'webp' => 'WebP',
				'avif' => 'AVIF',
			)
		);
		$this->number( 'media', 'compression', __( 'Image quality', 'gt-performance' ), __( 'Higher values retain more detail and create larger files.', 'gt-performance' ), $settings, 30, 100, '%' );
		$this->checkbox( 'media', 'youtube_previews', __( 'Lightweight YouTube previews', 'gt-performance' ), __( 'Replace eligible embeds with a click-to-load preview.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Fonts', 'gt-performance' ), __( 'Keep font requests predictable and reduce render blocking.', 'gt-performance' ) );
		$this->checkbox( 'fonts', 'self_host_google', __( 'Self-host Google Fonts', 'gt-performance' ), __( 'Copy Google Fonts to your own site so visitors never load them from Google.', 'gt-performance' ), $settings );
		$this->select(
			'fonts',
			'font_display',
			__( 'Font display', 'gt-performance' ),
			__( 'Swap is the safest default for readable text during loading.', 'gt-performance' ),
			$settings,
			array(
				'swap'     => 'swap',
				'fallback' => 'fallback',
				'optional' => 'optional',
				'block'    => 'block',
			),
			__( 'Swap shows your fallback font straight away. Optional may skip the web font on a slow connection. Block can leave text invisible for a moment.', 'gt-performance' )
		);
		$this->panelClose();

		$this->panelOpen( __( 'WordPress quick toggles', 'gt-performance' ), __( 'Turn off things WordPress loads on every page that most sites never use.', 'gt-performance' ) );
		$this->renderWordPressPresets();
		$this->checkbox( 'bloat', 'disable_emojis', __( 'Disable WordPress emoji assets', 'gt-performance' ), __( 'Remove the legacy emoji detection script and styles.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_dashicons', __( 'Disable Dashicons for visitors', 'gt-performance' ), __( 'Keep Dashicons for logged-in users and remove them from public pages.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_embeds', __( 'Disable WordPress embeds', 'gt-performance' ), __( 'Remove oEmbed discovery and the frontend embed script.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_xmlrpc', __( 'Disable XML-RPC', 'gt-performance' ), __( 'Disable legacy XML-RPC requests while leaving the REST API available.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'remove_rsd_link', __( 'Remove RSD link', 'gt-performance' ), __( 'Remove the Really Simple Discovery link from the document head.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'remove_jquery_migrate', __( 'Remove jQuery Migrate for visitors', 'gt-performance' ), __( 'Reduce a legacy dependency on public pages.', 'gt-performance' ), $settings, __( 'Some older themes and plugins still need this. Check menus, forms, sliders, and checkout after turning it on.', 'gt-performance' ) );
		$this->checkbox( 'bloat', 'hide_wp_version', __( 'Remove WordPress version', 'gt-performance' ), __( 'Stop pages from advertising which WordPress version you run.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'remove_shortlink', __( 'Remove shortlink', 'gt-performance' ), __( 'Remove shortlink output from the document head and response headers.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_rss_feeds', __( 'Disable every RSS feed', 'gt-performance' ), __( 'Turn off every feed, including your main one. Leave this off if anyone subscribes to your site.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_secondary_feeds', __( 'Disable secondary feeds only', 'gt-performance' ), __( 'Keep your main feed at /feed/ and turn off the rest.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'remove_feed_links', __( 'Remove every RSS feed link', 'gt-performance' ), __( 'Feeds keep working, but browsers and readers stop finding them automatically.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'remove_secondary_feed_links', __( 'Remove secondary RSS feed links', 'gt-performance' ), __( 'Keep the link to your main feed and remove the others.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_self_pingbacks', __( 'Disable self pingbacks', 'gt-performance' ), __( 'Prevent WordPress from pinging links that point back to this site.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'remove_rest_api_links', __( 'Remove REST API links', 'gt-performance' ), __( 'The REST API keeps working; pages just stop pointing at it.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_google_maps', __( 'Disable Google Maps', 'gt-performance' ), __( 'Remove Google Maps scripts except on paths listed in Exceptions.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_password_strength_meter', __( 'Disable password strength meter', 'gt-performance' ), __( 'Removes the password strength meter. Check your sign-up and account forms after turning it on.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'remove_comment_urls', __( 'Remove comment author URLs', 'gt-performance' ), __( 'Discard author website links to reduce comment backlink spam.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'blank_favicon', __( 'Add a blank fallback favicon', 'gt-performance' ), __( 'Prevent a missing favicon request when the site has no Site Icon.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'remove_global_styles', __( 'Remove global styles', 'gt-performance' ), __( 'Only use this if your theme does not rely on the WordPress default styles.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'separate_block_styles', __( 'Load separate core block styles', 'gt-performance' ), __( 'Load core block CSS only when the corresponding block is rendered.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Editor, comments, and APIs', 'gt-performance' ), __( 'Revisions, autosaves, comments, and background admin requests.', 'gt-performance' ) );
		$this->select(
			'bloat',
			'heartbeat_mode',
			__( 'Heartbeat behavior', 'gt-performance' ),
			__( 'Fewer background requests, while editing still works normally.', 'gt-performance' ),
			$settings,
			array(
				'default'           => __( 'WordPress default', 'gt-performance' ),
				'reduce'            => __( 'Reduce frequency (recommended)', 'gt-performance' ),
				'disable_dashboard' => __( 'Disable outside the editor', 'gt-performance' ),
				'disabled'          => __( 'Disable everywhere', 'gt-performance' ),
			),
			__( 'Turning this off everywhere can break autosave, post locking, and some plugins.', 'gt-performance' )
		);
		$this->number( 'bloat', 'heartbeat_seconds', __( 'Heartbeat interval', 'gt-performance' ), __( 'Slow the admin Heartbeat API without disabling autosave locks.', 'gt-performance' ), $settings, 15, 120, __( 'seconds', 'gt-performance' ) );
		$this->number( 'bloat', 'autosave_interval', __( 'Autosave interval', 'gt-performance' ), __( 'Increase the editor autosave interval to reduce background requests.', 'gt-performance' ), $settings, 15, 3600, __( 'seconds', 'gt-performance' ) );
		$this->select(
			'bloat',
			'disable_rest_api',
			__( 'REST API access', 'gt-performance' ),
			__( 'Turning the REST API off can break the block editor and other plugins.', 'gt-performance' ),
			$settings,
			array(
				'default'   => __( 'Keep enabled', 'gt-performance' ),
				'non_admin' => __( 'Administrators only', 'gt-performance' ),
				'disabled'  => __( 'Disable all requests', 'gt-performance' ),
			),
			__( 'Restricting it can break the block editor, the mobile app, and other plugins.', 'gt-performance' )
		);
		$this->checkbox( 'bloat', 'disable_comments', __( 'Disable comments', 'gt-performance' ), __( 'Close comments and pingbacks across all public post types.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Scheduled database optimization', 'gt-performance' ), __( 'Save cleanup tasks and run a bounded batch daily, weekly, or monthly.', 'gt-performance' ) );
		$this->checkbox( 'database', 'enabled', __( 'Schedule database cleanup', 'gt-performance' ), __( 'Run the selected database tasks automatically.', 'gt-performance' ), $settings );
		$this->select(
			'database',
			'schedule',
			__( 'Cleanup schedule', 'gt-performance' ),
			__( 'The schedule starts one hour after settings are saved.', 'gt-performance' ),
			$settings,
			array(
				'daily'   => __( 'Daily', 'gt-performance' ),
				'weekly'  => __( 'Weekly', 'gt-performance' ),
				'monthly' => __( 'Monthly', 'gt-performance' ),
			)
		);
		$this->number( 'database', 'retain_revisions', __( 'Scheduled revisions to retain', 'gt-performance' ), __( 'How many recent revisions to keep per post when cleanup runs.', 'gt-performance' ), $settings, 0, 100, __( 'revisions', 'gt-performance' ) );
		$this->databaseTaskSettings( $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Diagnostics', 'gt-performance' ), __( 'Only turn these on while you are troubleshooting.', 'gt-performance' ) );
		$this->checkboxRoot( 'debug', __( 'Diagnostic logging', 'gt-performance' ), __( 'Write redacted plugin errors to the GT Performance log directory.', 'gt-performance' ), $settings );
		$this->checkboxRoot( 'remove_data_on_uninstall', __( 'Remove all data when the plugin is deleted', 'gt-performance' ), __( 'Delete everything this plugin created when you delete the plugin.', 'gt-performance' ), $settings, __( 'Leave this off to keep your settings if you reinstall. With it off, deleting the plugin leaves its data behind, including saved Redis credentials.', 'gt-performance' ) );
		$this->panelClose();

		$this->settingsFormClose();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderExceptions( array $settings ): void {
		$this->pageIntro( __( 'Exceptions', 'gt-performance' ), __( 'Protect dynamic URLs and scripts, and preserve selectors that server-side analysis cannot discover from the initial HTML.', 'gt-performance' ) );
		$this->settingsFormOpen();

		$this->panelOpen( __( 'Cache exceptions', 'gt-performance' ), __( 'Enter one path, cookie-name prefix, or parameter per line. Paths match complete URL segments and parameter names match exactly.', 'gt-performance' ) );
		$this->textarea( 'cache', 'bypass_paths', __( 'Paths that must stay dynamic', 'gt-performance' ), __( 'Examples: /account/ or /members/. Core WordPress paths are included by default.', 'gt-performance' ), $settings, '/account/', __( '/account/ matches /account and its child paths, but does not match /accounting. Add only paths whose HTML varies by visitor or request.', 'gt-performance' ) );
		$this->textarea( 'cache', 'bypass_cookies', __( 'Cookie prefixes that bypass cache', 'gt-performance' ), __( 'Bypass when a request contains a cookie name beginning with one of these values.', 'gt-performance' ), $settings, 'membership_session_', __( 'Enter cookie names or stable prefixes, without cookie values. Example: membership_session_ matches every cookie whose name starts that way.', 'gt-performance' ) );
		$this->textarea( 'cache', 'bypass_query_params', __( 'Never cache query parameters', 'gt-performance' ), __( 'Bypass the cache whenever one of these parameters is present.', 'gt-performance' ), $settings, 'preview' );
		$this->textarea( 'cache', 'ignored_query_params', __( 'Parameters that do not change content', 'gt-performance' ), __( 'Remove these parameters from the cache key so equivalent URLs share public HTML.', 'gt-performance' ), $settings, 'utm_source', __( 'Only list tracking parameters that never alter the page. Ignoring a parameter that changes price, language, personalization, or content can serve the wrong HTML.', 'gt-performance' ) );
		$this->panelClose();

		$this->panelOpen( __( 'Unused CSS exceptions', 'gt-performance' ), __( 'Use partial selector matches, regular expressions, or stylesheet URLs. Add the smallest stable pattern that protects the dynamic component.', 'gt-performance' ) );
		$this->textarea( 'css', 'safelist', __( 'Selector safelist', 'gt-performance' ), __( 'One pattern per line. Plain text is a partial match; use a delimited expression such as /^\\.modal(?:--|\\b)/i for regex matching.', 'gt-performance' ), $settings, ".is-open\n/^\\.modal(?:--|\\b)/i" );
		$this->textarea( 'css', 'excluded_stylesheets', __( 'Excluded stylesheets', 'gt-performance' ), __( 'Leave matching external stylesheet URLs or inline style IDs untouched and loaded normally.', 'gt-performance' ), $settings, "/checkout.css\nmy-inline-style-css" );
		$this->panelClose();

		$this->panelOpen( __( 'JavaScript exceptions', 'gt-performance' ), __( 'Patterns are matched against script URLs. Transactional cart, checkout, and payment scripts are protected automatically.', 'gt-performance' ) );
		$this->textarea( 'javascript', 'exclusions', __( 'Never optimize scripts', 'gt-performance' ), __( 'Skip minify, defer, and delay for matching scripts.', 'gt-performance' ), $settings, 'interactive-widget.js' );
		$this->textarea( 'javascript', 'delay_patterns', __( 'Scripts to delay', 'gt-performance' ), __( 'Delay only matching third-party scripts when JavaScript delay is enabled.', 'gt-performance' ), $settings, 'googletagmanager.com' );
		$this->panelClose();

		$this->panelOpen( __( 'Media exceptions', 'gt-performance' ), __( 'Selectors let interactive embeds keep their normal rendering behavior.', 'gt-performance' ) );
		$this->textarea( 'media', 'lazy_render_selectors', __( 'Lazy-render selectors', 'gt-performance' ), __( 'CSS selectors for supported embeds or components that may render after interaction.', 'gt-performance' ), $settings, '.video-embed' );
		$this->panelClose();

		$this->panelOpen( __( 'WordPress exceptions', 'gt-performance' ), __( 'Keep Google Maps on URLs that require it while disabling the script everywhere else.', 'gt-performance' ) );
		$this->textarea( 'bloat', 'google_maps_exclusions', __( 'Google Maps path exceptions', 'gt-performance' ), __( 'Enter one path fragment per line, such as /contact/ or /store-locator/.', 'gt-performance' ), $settings, '/contact/' );
		$this->panelClose();

		$this->settingsFormClose();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderCloudflare( array $settings ): void {
		$this->pageIntro( __( 'Cloudflare Free', 'gt-performance' ), __( 'Synchronize one cache rule and targeted purges without requiring APO, Workers, Argo, or a paid Cloudflare plan.', 'gt-performance' ) );
		$this->settingsFormOpen();
		$this->panelOpen( __( 'Connection', 'gt-performance' ), __( 'A scoped token is safer. Global API Key authentication remains available for legacy accounts.', 'gt-performance' ) );
		$this->checkbox( 'cloudflare', 'enabled', __( 'Enable Cloudflare integration', 'gt-performance' ), __( 'Allow GT Performance to purge and maintain its managed cache rule.', 'gt-performance' ), $settings );
		$this->select(
			'cloudflare',
			'auth_mode',
			__( 'Authentication', 'gt-performance' ),
			__( 'Use a token limited to Zone Read, Cache Rules Edit, and Cache Purge when possible.', 'gt-performance' ),
			$settings,
			array(
				'token'  => __( 'Scoped API token (recommended)', 'gt-performance' ),
				'global' => __( 'Global API Key (legacy)', 'gt-performance' ),
			)
		);
		$this->password(
			'cloudflare',
			'api_token',
			__( 'Scoped API token', 'gt-performance' ),
			__( 'Leave blank to keep the encrypted token already saved.', 'gt-performance' ),
			! empty( $settings['cloudflare']['api_token'] ),
			'',
			'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation help link; no asset is loaded from it.
			__( 'Create a Cloudflare API token', 'gt-performance' )
		);
		$this->password(
			'cloudflare',
			'global_api_key',
			__( 'Global API Key', 'gt-performance' ),
			__( 'Requires the Cloudflare account email below. Leave blank to keep the saved key.', 'gt-performance' ),
			! empty( $settings['cloudflare']['global_api_key'] ),
			'',
			'https://developers.cloudflare.com/fundamentals/api/get-started/keys/', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation help link; no asset is loaded from it.
			__( 'Find your Global API Key', 'gt-performance' )
		);
		$this->text( 'cloudflare', 'email', __( 'Cloudflare account email', 'gt-performance' ), __( 'Required only for Global API Key authentication.', 'gt-performance' ), $settings, 'email' );
		$this->text( 'cloudflare', 'domain', __( 'Domain', 'gt-performance' ), __( 'Used to discover the zone automatically when Zone ID is blank.', 'gt-performance' ), $settings, 'text', 'example.com' );
		$this->text(
			'cloudflare',
			'zone_id',
			__( 'Zone ID', 'gt-performance' ),
			__( 'Optional. Direct Zone ID avoids the discovery request.', 'gt-performance' ),
			$settings,
			'text',
			'',
			'',
			'https://developers.cloudflare.com/fundamentals/account/find-account-and-zone-ids/', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation help link; no asset is loaded from it.
			__( 'Find your Zone ID', 'gt-performance' )
		);
		$this->number( 'cloudflare', 'edge_ttl', __( 'Cloudflare edge cache lifetime', 'gt-performance' ), __( 'How long eligible public HTML remains fresh at Cloudflare.', 'gt-performance' ), $settings, 0, 31536000, __( 'seconds', 'gt-performance' ), '1', __( 'A positive value overrides the origin freshness value in the managed Cache Rule. Use 0 to respect the origin Cache-Control header instead.', 'gt-performance' ) );
		$this->panelClose();
		$this->settingsFormClose();
		?>
		<section class="gtp-panel gtp-operation-panel">
			<div>
				<h3><?php esc_html_e( 'Connect and synchronize', 'gt-performance' ); ?></h3>
				<p><?php esc_html_e( 'Save credentials first, then discover the zone if needed and reconcile the managed Cloudflare cache rule.', 'gt-performance' ); ?></p>
			</div>
			<?php $this->actionButton( 'gtperf_cloudflare_sync', __( 'Connect/sync Cloudflare', 'gt-performance' ) ); ?>
		</section>
		<?php $this->renderCloudflareToken( $settings ); ?>
		<?php $this->renderCloudflareDiagnostics(); ?>
		<?php $this->renderCloudflarePlan(); ?>
		<?php
	}

	/**
	 * Routes for obtaining the API token this integration needs.
	 *
	 * Cloudflare has no authorization flow that lets a third-party application sign
	 * in to someone's account, so there are exactly two honest options: create the
	 * token by hand in the dashboard, or have the plugin mint one over the API using
	 * an account-wide Global API Key that is already on file.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderCloudflareToken( array $settings ): void {
		$provisioner = new TokenProvisioner();
		$canProvision = $provisioner->canProvision( $settings );
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Get an API token', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'The connection needs a token carrying exactly these three permissions, scoped to this site\'s zone:', 'gt-performance' ); ?></p>
				</div>
			</div>
			<ul class="gtp-permission-list">
				<?php foreach ( $provisioner->requiredPermissions() as $permission ) : ?>
					<li><code><?php echo esc_html( $permission ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<p class="gtp-panel-note">
				<a class="button button-secondary" href="<?php echo esc_url( $provisioner->templateUrl( $settings ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Create token at Cloudflare', 'gt-performance' ); ?>
				</a>
				<?php esc_html_e( 'Opens the Create Token form with the name and zone filled in. Cloudflare does not currently preselect the permission groups, so pick the three above from the dropdowns, then paste the token into the Scoped API token field and save.', 'gt-performance' ); ?>
			</p>
			<?php if ( $canProvision ) : ?>
				<div class="gtp-operation-panel">
					<div>
						<h4><?php esc_html_e( 'Or create it automatically', 'gt-performance' ); ?></h4>
						<p><?php esc_html_e( 'A Global API Key is on file, so GT Performance can create the zone-scoped token for you and save it. The new token is tested before it replaces the current credentials. Clear the Global API Key afterwards: it grants far more than this plugin needs.', 'gt-performance' ); ?></p>
					</div>
					<?php $this->actionButton( 'gtperf_cloudflare_token', __( 'Create scoped token', 'gt-performance' ) ); ?>
				</div>
			<?php else : ?>
				<p class="gtp-panel-note"><?php esc_html_e( 'Cloudflare does not allow an application to sign in to your account, so the token has to be created in the dashboard. Saving a Global API Key and account email here would let GT Performance mint the scoped token over the API instead.', 'gt-performance' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Show the per-stage connection check so a failure names its own cause.
	 */
	private function renderCloudflareDiagnostics(): void {
		$report = ( new ConnectionDiagnostics() )->last();
		$labels = array(
			'pass' => __( 'Pass', 'gt-performance' ),
			'fail' => __( 'Failed', 'gt-performance' ),
			'warn' => __( 'Warning', 'gt-performance' ),
			'skip' => __( 'Not checked', 'gt-performance' ),
		);
		$tones  = array(
			'pass' => 'success',
			'fail' => 'danger',
			'warn' => 'warning',
			'skip' => 'neutral',
		);
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Connection check', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Walks credentials, authentication, zone lookup, and cache-rule read and write in order, and reports the exact stage and reason for any failure. The write stage rewrites the managed rule with its own current contents, so it changes nothing.', 'gt-performance' ); ?></p>
				</div>
				<?php $this->actionButton( 'gtperf_cloudflare_diagnose', __( 'Run connection check', 'gt-performance' ) ); ?>
			</div>
			<?php if ( null === $report ) : ?>
				<p class="gtp-panel-note"><?php esc_html_e( 'No connection check has been run yet.', 'gt-performance' ); ?></p>
			<?php else : ?>
				<p class="gtp-panel-note"><strong><?php echo esc_html( (string) ( $report['summary'] ?? '' ) ); ?></strong></p>
				<div class="gtp-table-wrap">
					<table class="widefat striped gtp-report-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Stage', 'gt-performance' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'gt-performance' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Detail', 'gt-performance' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( (array) ( $report['steps'] ?? array() ) as $step ) : ?>
							<?php $status = (string) ( $step['status'] ?? 'skip' ); ?>
							<tr>
								<th scope="row"><?php echo esc_html( (string) ( $step['label'] ?? '' ) ); ?></th>
								<td><span class="gtp-status gtp-status--<?php echo esc_attr( $tones[ $status ] ?? 'neutral' ); ?>"><?php echo esc_html( $labels[ $status ] ?? $status ); ?></span></td>
								<td><?php echo esc_html( (string) ( $step['detail'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="gtp-panel-note">
					<?php
					printf(
						/* translators: %s: UTC timestamp of the last connection check. */
						esc_html__( 'Last checked %s UTC.', 'gt-performance' ),
						esc_html( (string) ( $report['checked_at'] ?? '' ) )
					);
					?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderCdn( array $settings ): void {
		$this->pageIntro(
			__( 'Content delivery network', 'gt-performance' ),
			__( 'Serve selected static files from an origin-pull CDN URL while Cloudflare continues to cache public HTML independently.', 'gt-performance' )
		);
		$this->settingsFormOpen();
		$this->panelOpen(
			__( 'Asset CDN', 'gt-performance' ),
			__( 'GT Performance changes same-site asset URLs only. Configure the CDN provider to pull from this WordPress site.', 'gt-performance' )
		);
		$this->checkbox(
			'cdn',
			'enabled',
			__( 'Enable CDN URL rewriting', 'gt-performance' ),
			__( 'Rewrite eligible public asset URLs to the CDN address below.', 'gt-performance' ),
			$settings,
			__( 'This does not upload files or configure a provider account. The CDN must be able to fetch the original WordPress paths.', 'gt-performance' )
		);
		$this->text(
			'cdn',
			'url',
			__( 'CDN URL', 'gt-performance' ),
			__( 'Use the HTTPS hostname or hostname plus path supplied by your CDN provider.', 'gt-performance' ),
			$settings,
			'url',
			'https://cdn.example.com',
			__( 'GT Performance preserves each original asset path, query string, and fragment after this base URL.', 'gt-performance' )
		);
		$this->cdnFileTypes( $settings );
		$this->panelClose();
		$this->settingsFormClose();
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'How this works with Cloudflare', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Cloudflare may cache the HTML page while the browser requests selected static files from the separate CDN hostname.', 'gt-performance' ); ?></p>
				</div>
			</div>
			<div class="gtp-panel-note gtp-panel-note--stacked">
				<p><?php esc_html_e( 'Only URLs hosted by this WordPress site are rewritten. Third-party files, HTML routes, API requests, data URLs, and unselected extensions remain unchanged.', 'gt-performance' ); ?></p>
				<p><?php esc_html_e( 'GT Performance purges its origin page cache and Cloudflare when these settings change. Purge the separate CDN through its provider when replacing a file without changing its URL.', 'gt-performance' ); ?></p>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function cdnFileTypes( array $settings ): void {
		$selected = array_map( 'strval', (array) $settings['cdn']['file_types'] );
		$groups   = array(
			__( 'Images', 'gt-performance' ) => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico' ),
			__( 'Styles and scripts', 'gt-performance' ) => array( 'css', 'js', 'mjs' ),
			__( 'Fonts', 'gt-performance' ) => array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ),
			__( 'Video and audio', 'gt-performance' ) => array( 'mp4', 'webm', 'mp3', 'ogg', 'wav' ),
			__( 'Downloads', 'gt-performance' ) => array( 'pdf', 'zip' ),
		);
		$name = Settings::OPTION . '[cdn][file_types][]';
		?>
		<div class="gtp-field gtp-field--stacked">
			<div>
				<div class="gtp-field__label" id="gtp-cdn-file-types-label"><?php esc_html_e( 'Files served by the CDN', 'gt-performance' ); ?></div>
				<p><?php esc_html_e( 'Select exact extensions. Unselected file types keep their original WordPress URLs.', 'gt-performance' ); ?></p>
			</div>
			<div class="gtp-field__control gtp-cdn-type-groups" role="group" aria-labelledby="gtp-cdn-file-types-label">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="">
				<?php foreach ( $groups as $groupLabel => $types ) : ?>
					<fieldset class="gtp-cdn-type-group">
						<legend><?php echo esc_html( $groupLabel ); ?></legend>
						<div class="gtp-extension-grid">
							<?php foreach ( $types as $type ) : ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $selected, true ) ); ?>>
									<code>.<?php echo esc_html( $type ); ?></code>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function renderCloudflarePlan(): void {
		$plan = get_option( 'gt_performance_cloudflare_plan', array() );
		$plan = is_array( $plan ) ? $plan : array();
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Cloudflare Free rule compiler', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Preview rule usage, managed-rule drift, overlapping host rules, and the exact expression before changing Cloudflare.', 'gt-performance' ); ?></p>
				</div>
				<?php $this->actionButton( 'gtperf_cloudflare_preview', __( 'Preview live plan', 'gt-performance' ) ); ?>
			</div>
			<?php if ( ! $plan ) : ?>
				<p class="gtp-panel-note"><?php esc_html_e( 'No live rule plan has been checked yet.', 'gt-performance' ); ?></p>
			<?php else : ?>
				<dl class="gtp-definition-list">
					<div><dt><?php esc_html_e( 'Planned operation', 'gt-performance' ); ?></dt><dd><?php echo esc_html( ucfirst( (string) ( $plan['operation'] ?? 'unknown' ) ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Free rule budget', 'gt-performance' ); ?></dt><dd><?php echo esc_html( sprintf( '%d / %d', (int) ( $plan['used'] ?? 0 ), (int) ( $plan['limit'] ?? 10 ) ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Managed-rule drift', 'gt-performance' ); ?></dt><dd><?php echo esc_html( ! empty( $plan['drift'] ) ? __( 'Needs reconciliation', 'gt-performance' ) : __( 'In sync', 'gt-performance' ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Potential overlaps', 'gt-performance' ); ?></dt><dd><?php echo esc_html( number_format_i18n( count( (array) ( $plan['conflicts'] ?? array() ) ) ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Custom cache key', 'gt-performance' ); ?></dt><dd><?php echo esc_html( ! empty( $plan['custom_key'] ) ? __( 'Applied', 'gt-performance' ) : __( 'Not supported on this plan, so query-string exclusions are skipped', 'gt-performance' ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Last checked', 'gt-performance' ); ?></dt><dd><?php echo esc_html( (string) ( $plan['checked_at'] ?? __( 'Unknown', 'gt-performance' ) ) ); ?></dd></div>
				</dl>
					<?php if ( ! empty( $plan['conflicts'] ) ) : ?>
						<h4 class="gtp-subhead"><?php esc_html_e( 'Other cache rules that also match this site', 'gt-performance' ); ?></h4>
						<p class="gtp-panel-note"><?php esc_html_e( 'These rules sit in the same phase and can override the managed rule. A rule that never names a hostname applies to every hostname in the zone.', 'gt-performance' ); ?></p>
						<ul class="gtp-conflict-list">
							<?php foreach ( (array) $plan['conflicts'] as $conflict ) : ?>
								<li>
									<strong><?php echo esc_html( (string) ( $conflict['description'] ?? __( 'Untitled rule', 'gt-performance' ) ) ); ?></strong>
									<?php if ( 'every-host' === ( $conflict['scope'] ?? '' ) ) : ?>
										<em><?php esc_html_e( '(matches every hostname)', 'gt-performance' ); ?></em>
									<?php endif; ?>
									<?php if ( ! empty( $conflict['bypasses'] ) ) : ?>
										<em><?php esc_html_e( '(bypasses cache)', 'gt-performance' ); ?></em>
									<?php endif; ?>
									<code><?php echo esc_html( (string) ( $conflict['expression'] ?? '' ) ); ?></code>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<div class="gtp-code-detail">
					<strong><?php esc_html_e( 'Compiled expression', 'gt-performance' ); ?></strong>
					<code><?php echo esc_html( (string) ( $plan['expression'] ?? '' ) ); ?></code>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderIntegrations( array $settings ): void {
		$this->pageIntro( __( 'Integrations', 'gt-performance' ), __( 'Coordinate optimization ownership, protect plugin state, and connect a persistent Redis object cache without overlapping work.', 'gt-performance' ) );
		$this->settingsFormOpen();

		$this->panelOpen( __( 'Optimization ownership', 'gt-performance' ), __( 'GT Performance coordinates known overlap instead of letting two plugins rewrite the same response.', 'gt-performance' ) );
		$this->checkbox( 'integrations', 'auto_protection', __( 'Automatic conflict protection', 'gt-performance' ), __( 'Detect active performance plugins, preserve known dynamic state, and apply supported ownership filters.', 'gt-performance' ), $settings );
		$this->select(
			'integrations',
			'perfmatters_owner',
			__( 'Perfmatters ownership', 'gt-performance' ),
			__( 'Automatic lets GT Performance own only the front-end features enabled here. Page cache, Cloudflare, commerce, and Redis remain independent.', 'gt-performance' ),
			$settings,
			array(
				'automatic'      => __( 'Automatic per feature (recommended)', 'gt-performance' ),
				'gt_performance' => __( 'GT Performance owns front-end optimization', 'gt-performance' ),
				'perfmatters'    => __( 'Perfmatters owns front-end optimization', 'gt-performance' ),
			)
		);
		$this->panelClose();

		$this->panelOpen( __( 'xCloud and Cloudflare Enterprise', 'gt-performance' ), __( 'Connect the xCloud API, detect the separate Cloudflare Enterprise add-on, show its edge traffic snapshot, and keep cache ownership explicit.', 'gt-performance' ) );
		$this->checkbox( 'xcloud', 'enabled', __( 'Enable xCloud cache integration', 'gt-performance' ), __( 'Let GT Performance detect xCloud ownership and purge token-authenticated host cache layers after origin invalidation.', 'gt-performance' ), $settings, __( 'Cloudflare Enterprise is distinct from xCloud\'s free Edge Full Page Cache. The current xCloud Public API has no token-authenticated Enterprise purge, so GT Performance fails closed instead of sending the broad host purge-all request.', 'gt-performance' ) );
		$this->text( 'xcloud', 'domain', __( 'xCloud site domain', 'gt-performance' ), __( 'Exact primary domain used to discover the xCloud site UUID. Leave blank to use this WordPress home domain.', 'gt-performance' ), $settings, 'text', (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$this->text( 'xcloud', 'site_uuid', __( 'xCloud site UUID', 'gt-performance' ), __( 'Optional. Connect/refresh discovers and saves it from the exact domain.', 'gt-performance' ), $settings );
		$this->password( 'xcloud', 'api_token', __( 'xCloud API token', 'gt-performance' ), __( 'Requires read:sites and write:sites scopes. Encrypted in WordPress; leave blank to keep the saved token.', 'gt-performance' ), ! empty( $settings['xcloud']['api_token'] ) );
		$this->panelClose();

		$this->panelOpen( __( 'Private Islands', 'gt-performance' ), __( 'Keep the public page shell cacheable while explicitly registered cart and account fragments render through a private no-store request.', 'gt-performance' ) );
		$this->panelClose();

		$this->panelOpen( __( 'Service safeguards', 'gt-performance' ), __( 'These protections activate only when the matching plugin is active.', 'gt-performance' ) );
		$this->checkbox( 'integrations', 'akismet', __( 'Protect Akismet assets', 'gt-performance' ), __( 'Keep the privacy notice and anti-spam front-end assets during CSS and JavaScript optimization.', 'gt-performance' ), $settings, __( 'This compatibility switch does not classify comments itself. Akismet remains responsible for spam checks; this option prevents optimizations from removing its required front-end output.', 'gt-performance' ) );
		$this->checkbox( 'integrations', 'jetpack', __( 'Jetpack compatibility', 'gt-performance' ), __( 'Protect forms, comments, subscriptions, search, VideoPress, and visitor-state cookies from unsafe optimization or public caching.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Commerce safeguards', 'gt-performance' ), __( 'Only active integrations contribute bypass rules. Custom cache exceptions remain available separately.', 'gt-performance' ) );
		$this->checkbox( 'commerce', 'fluentcart', __( 'FluentCart', 'gt-performance' ), __( 'Protect FluentCart cart, checkout, account, order, and customer-session state.', 'gt-performance' ), $settings );
		$this->checkbox( 'commerce', 'edd', __( 'Easy Digital Downloads', 'gt-performance' ), __( 'Protect EDD checkout, purchase history, receipts, and session state.', 'gt-performance' ), $settings );
		$this->checkbox( 'commerce', 'woocommerce', __( 'WooCommerce', 'gt-performance' ), __( 'Protect WooCommerce cart, checkout, My Account, order, and session state.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Redis object cache', 'gt-performance' ), __( 'Use PhpRedis credentials or leave fields at their local defaults. GT Performance and standard Redis Object Cache constants override generated settings at runtime.', 'gt-performance' ) );
		$this->checkbox( 'redis', 'enabled', __( 'Enable Redis object cache', 'gt-performance' ), __( 'Connect the GT Performance object-cache.php drop-in to Redis. Disable this before migrating to another object-cache owner.', 'gt-performance' ), $settings );
		$this->text( 'redis', 'host', __( 'Redis host or socket', 'gt-performance' ), __( 'Hostname, IP address, or Unix socket path.', 'gt-performance' ), $settings, 'text', '127.0.0.1' );
		$this->number( 'redis', 'port', __( 'Redis port', 'gt-performance' ), __( 'Use 6379 normally, or 0 with a Unix socket.', 'gt-performance' ), $settings, 0, 65535 );
		$this->number( 'redis', 'database', __( 'Redis database number', 'gt-performance' ), __( 'Logical Redis database reserved for this site.', 'gt-performance' ), $settings, 0, 255, '', '1', __( 'Do not share this database with another site unless each installation uses a unique cache key prefix.', 'gt-performance' ) );
		$this->text( 'redis', 'username', __( 'Redis username', 'gt-performance' ), __( 'Optional ACL username. Leave blank for password-only authentication.', 'gt-performance' ), $settings );
		$this->password( 'redis', 'password', __( 'Redis password', 'gt-performance' ), __( 'Encrypted in WordPress. Leave blank to keep the saved password.', 'gt-performance' ), ! empty( $settings['redis']['password'] ) );
		$this->checkbox( 'redis', 'tls', __( 'Use TLS', 'gt-performance' ), __( 'Connect with tls:// when the Redis provider requires encrypted transport.', 'gt-performance' ), $settings );
		$this->checkbox( 'redis', 'persistent', __( 'Reuse Redis connections', 'gt-performance' ), __( 'Keep a PhpRedis connection open between PHP requests when the host supports it.', 'gt-performance' ), $settings, __( 'Persistent connections reduce connection overhead but may be unsuitable on hosts that tightly limit Redis clients.', 'gt-performance' ) );
		$this->text( 'redis', 'prefix', __( 'Cache key prefix', 'gt-performance' ), __( 'Optional. Leave blank for an automatic site-specific prefix.', 'gt-performance' ), $settings, 'text', 'gtperf:site:', __( 'Use a unique prefix whenever multiple WordPress installations share the same Redis database.', 'gt-performance' ) );
		$this->number( 'redis', 'connection_timeout', __( 'Connection timeout', 'gt-performance' ), __( 'Fail back to request-local cache quickly when Redis is unavailable.', 'gt-performance' ), $settings, 0.1, 10, __( 'seconds', 'gt-performance' ), '0.1' );
		$this->number( 'redis', 'read_timeout', __( 'Read timeout', 'gt-performance' ), __( 'Maximum time to wait for a Redis response.', 'gt-performance' ), $settings, 0.1, 10, __( 'seconds', 'gt-performance' ), '0.1' );
		$this->panelClose();

		$this->settingsFormClose();
		$this->renderXcloudStatus( $settings );
		$this->renderRedisConstants();
		$this->renderPluginCompatibilityList( $settings );
		?>
		<section class="gtp-panel gtp-operation-panel">
			<div>
				<h3><?php esc_html_e( 'Test Redis credentials', 'gt-performance' ); ?></h3>
				<p><?php esc_html_e( 'Save first, then run a bounded connection, authentication, database selection, and ping check.', 'gt-performance' ); ?></p>
			</div>
			<?php $this->actionButton( 'gtperf_test_redis', __( 'Test Redis connection', 'gt-performance' ) ); ?>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderXcloudStatus( array $settings ): void {
		$xcloud = (array) ( $settings['xcloud'] ?? array() );
		$purge  = get_option( 'gt_performance_xcloud_last_purge', array() );
		$purge  = is_array( $purge ) ? $purge : array();
		$conflict = ( new EdgeOwnership() )->hasDirectCloudflareConflict();
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'xCloud cache status', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Status is refreshed only on request. Enterprise settings and purge remain owned by the xCloud dashboard; GT Performance detects and reports the add-on.', 'gt-performance' ); ?></p>
				</div>
			</div>
			<dl class="gtp-definition-list">
				<div><dt><?php esc_html_e( 'Site', 'gt-performance' ); ?></dt><dd><?php echo esc_html( (string) ( $xcloud['domain'] ? $xcloud['domain'] : __( 'Not connected', 'gt-performance' ) ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Stack', 'gt-performance' ); ?></dt><dd><?php echo esc_html( (string) ( $xcloud['stack'] ? $xcloud['stack'] : __( 'Unknown', 'gt-performance' ) ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Page cache', 'gt-performance' ); ?></dt><dd><?php echo esc_html( ! empty( $xcloud['page_cache_enabled'] ) ? __( 'Enabled', 'gt-performance' ) : __( 'Disabled', 'gt-performance' ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Redis', 'gt-performance' ); ?></dt><dd><?php echo esc_html( ! empty( $xcloud['redis_enabled'] ) ? __( 'Enabled', 'gt-performance' ) : __( 'Disabled', 'gt-performance' ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Object Cache Pro', 'gt-performance' ); ?></dt><dd><?php echo esc_html( ! empty( $xcloud['object_cache_pro'] ) ? __( 'Enabled', 'gt-performance' ) : __( 'Disabled', 'gt-performance' ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Cloudflare Enterprise', 'gt-performance' ); ?></dt><dd><?php echo esc_html( ! empty( $xcloud['enterprise_available'] ) ? __( 'Active through xCloud', 'gt-performance' ) : __( 'Not detected', 'gt-performance' ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Enterprise edge traffic (12h)', 'gt-performance' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) ( $xcloud['enterprise_edge_requests'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $xcloud['enterprise_requests'] ?? 0 ) ) . ' (' . number_format_i18n( (float) ( $xcloud['enterprise_hit_percent'] ?? 0 ), 1 ) . '%)' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Free xCloud edge cache', 'gt-performance' ); ?></dt><dd><?php echo esc_html( ! empty( $xcloud['free_edge_cache_enabled'] ) ? __( 'Enabled', 'gt-performance' ) : __( 'Disabled', 'gt-performance' ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Last checked', 'gt-performance' ); ?></dt><dd><?php echo esc_html( (string) ( $xcloud['checked_at'] ? $xcloud['checked_at'] : __( 'Never', 'gt-performance' ) ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Last xCloud purge', 'gt-performance' ); ?></dt><dd><?php echo esc_html( (string) ( $purge['created_at'] ?? __( 'Never', 'gt-performance' ) ) ); ?></dd></div>
			</dl>
			<?php if ( $conflict || ! empty( $xcloud['enterprise_available'] ) ) : ?>
				<div class="gtp-guidance-list">
					<?php if ( $conflict ) : ?>
						<div class="gtp-callout gtp-callout--warning" role="note">
							<strong><?php esc_html_e( 'Resolve edge ownership', 'gt-performance' ); ?></strong>
							<p><?php esc_html_e( 'xCloud Cloudflare Enterprise and GT Performance direct Cloudflare are both enabled. xCloud owns purge routing; direct Cloudflare rule synchronization is blocked until only one edge owner remains.', 'gt-performance' ); ?></p>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $xcloud['enterprise_available'] ) ) : ?>
						<div class="gtp-callout gtp-callout--warning" role="note">
							<strong><?php esc_html_e( 'Enterprise purge remains manual', 'gt-performance' ); ?></strong>
							<p><?php esc_html_e( 'Enterprise analytics are available through the API token, but its purge action currently requires an xCloud dashboard session. Automatic Enterprise purge is intentionally disabled until xCloud publishes a token-authenticated endpoint.', 'gt-performance' ); ?></p>
						</div>
						<div class="gtp-callout gtp-callout--danger" role="note">
							<strong><?php esc_html_e( 'Keep commerce HTML out of edge cache', 'gt-performance' ); ?></strong>
							<p><?php esc_html_e( 'Keep xCloud Enterprise Edge Page Caching off unless xCloud provides request-level bypass rules. Live testing found its current page-cache rule overrides origin no-store directives and caches cart, checkout, account, and receipt HTML. Enterprise static caching, WAF, and the other add-on features can remain enabled.', 'gt-performance' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="gtp-panel-actions gtp-panel-actions--split">
				<?php if ( ! empty( $xcloud['dashboard_url'] ) ) : ?>
					<?php $this->fieldHelpLink( (string) $xcloud['dashboard_url'], __( 'Open this site in xCloud', 'gt-performance' ) ); ?>
				<?php endif; ?>
				<?php $this->actionButton( 'gtperf_xcloud_refresh', __( 'Connect/refresh xCloud', 'gt-performance' ) ); ?>
			</div>
		</section>
		<?php
	}

	private function renderRedisConstants(): void {
		$example = <<<'PHP'
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_REDIS_DATABASE', 0 );
define( 'WP_REDIS_PASSWORD', array( 'username', 'replace-with-a-secret' ) );
define( 'WP_REDIS_PREFIX', 'gtperf:site:' );
define( 'WP_REDIS_TIMEOUT', 0.5 );
define( 'WP_REDIS_READ_TIMEOUT', 0.5 );
PHP;

		$this->panelOpen(
			__( 'Compatible wp-config.php overrides', 'gt-performance' ),
			__( 'GT Performance reads the same WP_REDIS_* constants used by Till Krüss Redis Object Cache. GTPERF_REDIS_* constants remain supported and take highest precedence.', 'gt-performance' )
		);
		?>
		<div class="gtp-config-example">
			<pre><code><?php echo esc_html( $example ); ?></code></pre>
			<p><?php esc_html_e( 'Add only the constants you need before the WordPress stop-editing comment. WP_REDIS_HOST or WP_REDIS_PATH enables Redis unless WP_REDIS_DISABLED is true. Existing GTPERF_REDIS_* constants do not need to be changed.', 'gt-performance' ); ?></p>
		</div>
		<?php
		$this->panelClose();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderPluginCompatibilityList( array $settings ): void {
		$detector = new PluginDetector();
		?>
		<section class="gtp-panel gtp-integration-list">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Detected plugin compatibility', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Foreign cache drop-ins are never overwritten. Active optimization owners that need a manual choice are flagged here.', 'gt-performance' ); ?></p>
				</div>
			</div>
			<?php foreach ( $detector->catalog() as $id => $plugin ) : ?>
				<?php
				$active = $detector->active( $id );
				$tone   = 'neutral';
				$label  = __( 'Not active', 'gt-performance' );
				if ( $active ) {
					$tone  = 'success';
					$label = __( 'Protected', 'gt-performance' );
					if ( 'cache' === $plugin['group'] || in_array( $id, array( 'autoptimize', 'jetpack-boost' ), true ) ) {
						$tone  = 'warning';
						$label = __( 'Review ownership', 'gt-performance' );
					} elseif ( 'perfmatters' === $id && 'perfmatters' === (string) $settings['integrations']['perfmatters_owner'] ) {
						$label = __( 'Perfmatters owns optimization', 'gt-performance' );
					}
				}
				?>
				<div class="gtp-integration-row">
					<div>
						<h3><?php echo esc_html( $plugin['name'] ); ?></h3>
						<p><?php echo esc_html( $plugin['protection'] ); ?></p>
					</div>
					<span class="gtp-status gtp-status--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderTools( array $settings ): void {
		$dropin  = ( new DropinInstaller() )->status();
		$wpCache = ( new WpCacheConstant() )->status();
		$redis   = ( new ObjectCacheInstaller() )->status();

		$this->pageIntro( __( 'Tools', 'gt-performance' ), __( 'Runtime drop-in status and bounded database maintenance. The everyday operations live on the dashboard.', 'gt-performance' ) );
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Runtime status', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'GT Performance never overwrites a drop-in owned by another plugin.', 'gt-performance' ); ?></p>
				</div>
			</div>
			<dl class="gtp-definition-list">
				<div><dt><?php esc_html_e( 'Page-cache drop-in', 'gt-performance' ); ?></dt><dd><?php echo esc_html( $this->statusLabel( $dropin ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'WP_CACHE', 'gt-performance' ); ?></dt><dd><?php echo esc_html( $this->statusLabel( $wpCache ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Redis drop-in', 'gt-performance' ); ?></dt><dd><?php echo esc_html( $this->statusLabel( $redis ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Cache directory', 'gt-performance' ); ?></dt><dd><?php echo esc_html( wp_is_writable( Paths::cacheRoot() ) ? __( 'Writable', 'gt-performance' ) : __( 'Not writable', 'gt-performance' ) ); ?></dd></div>
			</dl>
			<div class="gtp-inline-link"><a href="<?php echo esc_url( $this->tabUrl( 'dashboard' ) ); ?>"><?php esc_html_e( 'Install drop-ins, purge, and sync Cloudflare on the dashboard', 'gt-performance' ); ?> <span aria-hidden="true">&rarr;</span></a></div>
		</section>
		<?php
		$this->renderPurgeReceipts();
		$this->renderDatabaseOptimization( $settings );
	}

	/**
	 * Verified purge receipts.
	 *
	 * These used to live on the Safety Lab tab alongside commerce checks that could
	 * not fail by construction. The checks are gone; the receipts are real evidence
	 * and belong next to the runtime status they describe.
	 */
	private function renderPurgeReceipts(): void {
		$receipts = ( new PurgeReceiptRepository() )->recent( 10 );
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Verified purge receipts', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'What a purge actually removed, and what the public response looked like afterwards.', 'gt-performance' ); ?></p>
				</div>
			</div>
			<?php if ( ! $receipts ) : ?>
				<p class="gtp-panel-note"><?php esc_html_e( 'No purge has been verified yet. Use "Purge and verify this URL" in the admin bar.', 'gt-performance' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'URL', 'gt-performance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'gt-performance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Edge', 'gt-performance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'When', 'gt-performance' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $receipts as $receipt ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $receipt['url'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $receipt['status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $receipt['cloudflare'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $receipt['checked_at'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * The operations that are worth reaching in one click.
	 *
	 * These were buried on the Tools tab, which meant the routine jobs — purging
	 * after a content change, reconciling the Cloudflare rule — took a detour,
	 * and the two installers were invisible during setup, exactly when they
	 * matter. Each form remembers its originating tab, so running one from here
	 * returns here.
	 */
	private function renderQuickOperations(): void {
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Operations', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Install drop-ins, clear caches, and reconcile Cloudflare without leaving this screen.', 'gt-performance' ); ?></p>
				</div>
			</div>
			<div class="gtp-tools-grid">
				<?php $this->operation( __( 'Purge GT cache', 'gt-performance' ), __( 'Remove origin HTML and generated asset cache entries managed by GT Performance.', 'gt-performance' ), 'gtperf_purge', __( 'Purge GT cache', 'gt-performance' ) ); ?>
				<?php $this->operation( __( 'Cloudflare rule', 'gt-performance' ), __( 'Discover the zone when needed and reconcile the managed Cloudflare Free cache rule.', 'gt-performance' ), 'gtperf_cloudflare_sync', __( 'Connect/sync Cloudflare', 'gt-performance' ) ); ?>
				<?php $this->operation( __( 'Page cache drop-in', 'gt-performance' ), __( 'Install or refresh GT Performance advanced-cache.php and safely manage WP_CACHE.', 'gt-performance' ), 'gtperf_install_dropin', __( 'Install page-cache drop-in', 'gt-performance' ) ); ?>
				<?php $this->operation( __( 'Redis object cache', 'gt-performance' ), __( 'Test the saved Redis credentials, then install the owned object-cache.php when no other drop-in conflicts.', 'gt-performance' ), 'gtperf_install_redis', __( 'Test and install Redis', 'gt-performance' ) ); ?>
			</div>
		</section>
		<?php
	}

	public function regenerateCss(): never {
		$this->guard( 'gtperf_css_regenerate' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verifies the action nonce above.
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['scope'] ) ) : 'url';
		$maintenance = new Maintenance();
		if ( 'all' === $scope ) {
			$this->redirect( $maintenance->regenerateAll() ? 'css-regenerated-all' : 'css-unavailable', 'optimization' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verifies the action nonce above.
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
		if ( ! Maintenance::eligible( $url ) ) {
			$this->redirect( 'css-regenerate-invalid', 'optimization' );
		}
		$this->redirect( $maintenance->enqueue( $url, true ) ? 'css-regenerated-url' : 'css-unavailable', 'optimization' );
	}

	public function cssReport(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'gtperf_css_report', 'nonce' );
		ob_start();
		$this->renderCssReport();
		wp_send_json_success( array( 'html' => (string) ob_get_clean() ) );
	}

	private function renderCssStatus(): void {
		$this->panelOpen( __( 'Unused CSS status', 'gt-performance' ), __( 'Save settings before running a build. Background jobs use WordPress cron and the saved rollout and exclusions.', 'gt-performance' ) );
		?>
		<div class="gtp-css-status">
		<div class="gtp-css-report" data-gtp-css-report><?php $this->renderCssReport(); ?></div>
		<div class="gtp-css-refresh">
			<button type="button" class="button" data-gtp-css-refresh><?php esc_html_e( 'Refresh status', 'gt-performance' ); ?></button>
			<p data-gtp-css-message role="status" aria-live="polite"></p>
		</div>
		<div class="gtp-css-operations">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gtperf_css_regenerate">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'gtperf_css_regenerate' ) ); ?>">
				<label for="gtp-css-url"><?php esc_html_e( 'Public page URL', 'gt-performance' ); ?></label>
				<div class="gtp-css-url-controls">
				<input type="url" id="gtp-css-url" name="url" required placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
				<button class="button" <?php disabled( ! Maintenance::enabled() ); ?>><?php esc_html_e( 'Force regenerate URL', 'gt-performance' ); ?></button>
				</div>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gtperf_css_regenerate">
				<input type="hidden" name="scope" value="all">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'gtperf_css_regenerate' ) ); ?>">
				<button class="button" <?php disabled( ! Maintenance::enabled() ); ?>><?php esc_html_e( 'Force regenerate all CSS', 'gt-performance' ); ?></button>
				<p><?php esc_html_e( 'Invalidates every CSS result and clears page caches. Rebuilds known eligible URLs in batches, starting with the homepage. Existing files stay available for cached pages.', 'gt-performance' ); ?></p>
			</form>
		</div>
		</div>
		<?php
		$this->panelClose();
	}

	private function renderCssReport(): void {
		$repository = new ReportRepository();
		$stats = $repository->statistics();
		$reports = $repository->recent();
		$removed = $stats['original_bytes'] - $stats['generated_bytes'];
		$percent = $stats['original_bytes'] > 0 ? 100 * $removed / $stats['original_bytes'] : 0;
		$next = wp_next_scheduled( 'gt_performance_run_queue' );
		$cacheReady = defined( 'WP_CACHE' ) && WP_CACHE && 'owned' === ( new DropinInstaller() )->status();
		?>
		<div class="gtp-stat-grid">
			<?php $this->stat( __( 'Generation', 'gt-performance' ), ! $cacheReady ? __( 'Needs cache setup', 'gt-performance' ) : ( Maintenance::enabled() ? __( 'Enabled', 'gt-performance' ) : __( 'Paused / disabled', 'gt-performance' ) ), $cacheReady && Maintenance::enabled() ? 'success' : 'neutral' ); ?>
			<?php
			foreach ( array(
				'total' => __( 'Total results', 'gt-performance' ),
				'queued' => __( 'Queued', 'gt-performance' ),
				'processing' => __( 'Processing', 'gt-performance' ),
				'ready' => __( 'Ready', 'gt-performance' ),
				'stale' => __( 'Stale', 'gt-performance' ),
				'failed' => __( 'Failed', 'gt-performance' ),
				'skipped' => __( 'Skipped', 'gt-performance' ),
			) as $key => $label ) :
				?>
				<?php $this->stat( $label, number_format_i18n( $stats[ $key ] ), 'failed' === $key && $stats[ $key ] > 0 ? 'warning' : 'neutral' ); ?>
			<?php endforeach; ?>
		</div>
		<div class="gtp-css-summary">
		<?php if ( ! $cacheReady ) : ?>
			<p><?php esc_html_e( 'Generation needs the GT Performance page-cache drop-in and WP_CACHE. Open Tools to install or repair the drop-in.', 'gt-performance' ); ?></p>
		<?php endif; ?>
		<p><?php echo esc_html( sprintf( /* translators: 1: original CSS size, 2: generated CSS size, 3: reduction percentage. */ __( 'Current ready results: %1$s original → %2$s generated (%3$s%% reduction). Totals count URL/mode results, not unique files or measured visitor bandwidth.', 'gt-performance' ), size_format( $stats['original_bytes'] ), size_format( $stats['generated_bytes'] ), number_format_i18n( $percent, 1 ) ) ); ?></p>
		<p><?php echo esc_html( sprintf( /* translators: 1: CSS mode, 2: rollout percentage. */ __( 'Saved delivery mode: %1$s. Rollout: %2$s%%.', 'gt-performance' ), (string) Settings::get( 'css.mode', 'file' ), (string) Settings::get( 'css.rollout_percent', 100 ) ) ); ?></p>
		<p><?php echo esc_html( $next ? sprintf( /* translators: %s: UTC cron timestamp. */ __( 'Next queue event: %s UTC. WP-Cron needs site traffic or a server cron runner.', 'gt-performance' ), gmdate( 'Y-m-d H:i:s', $next ) ) : __( 'Queue cron is missing. Reload after WordPress initializes the queue schedule.', 'gt-performance' ) ); ?></p>
		</div>
		<?php if ( ! $reports ) : ?>
			<p><?php esc_html_e( 'No CSS results yet. Queue a public URL below. Generation needs an active page-cache drop-in and an eligible public HTML response.', 'gt-performance' ); ?></p>
		<?php else : ?>
			<div class="gtp-css-results">
			<p><?php esc_html_e( 'Latest 50 results. Counts above include all stored reports. Times are UTC.', 'gt-performance' ); ?></p>
			<div class="gtp-table-wrap"><table class="widefat gtp-report-table">
				<thead><tr><th><?php esc_html_e( 'URL / mode', 'gt-performance' ); ?></th><th><?php esc_html_e( 'Status', 'gt-performance' ); ?></th><th><?php esc_html_e( 'Original / generated', 'gt-performance' ); ?></th><th><?php esc_html_e( 'Last activity / duration', 'gt-performance' ); ?></th><th><?php esc_html_e( 'Details', 'gt-performance' ); ?></th></tr></thead>
				<tbody><?php foreach ( $reports as $report ) : ?>
					<?php $meta = (array) $report['metadata']; ?>
					<tr>
						<td><?php echo esc_html( (string) ( $meta['url'] ?? '' ) ); ?><br><small><?php echo esc_html( (string) $report['mode'] ); ?></small></td>
						<td><?php echo esc_html( (string) $report['status'] ); ?></td>
						<td><?php echo esc_html( isset( $meta['original_bytes'] ) ? size_format( (int) $meta['original_bytes'] ) . ' / ' . size_format( (int) ( $meta['generated_bytes'] ?? 0 ) ) : '—' ); ?></td>
						<td><?php echo esc_html( (string) $report['last_used_at'] ); ?><br><?php echo esc_html( isset( $meta['duration_ms'] ) ? (string) $meta['duration_ms'] . ' ms' : '—' ); ?></td>
						<td>
							<?php echo esc_html( (string) ( $meta['error'] ?? $meta['reason'] ?? $meta['fallback'] ?? '' ) ); ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="gtperf_css_regenerate">
								<input type="hidden" name="url" value="<?php echo esc_attr( (string) ( $meta['url'] ?? '' ) ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'gtperf_css_regenerate' ) ); ?>">
								<button class="button button-small" <?php disabled( ! Maintenance::enabled() || in_array( $report['status'], array( 'queued', 'processing' ), true ) ); ?>><?php esc_html_e( 'Regenerate', 'gt-performance' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?></tbody>
			</table></div>
			</div>
		<?php endif; ?>
		<?php
	}

	private function renderCachePresets(): void {
		$presets = array(
			'maximum' => array(
				'label'          => __( 'Maximum impact', 'gt-performance' ),
				'description'    => __( '1h · 24h · 5m', 'gt-performance' ),
				'fresh_ttl'      => 3600,
				'stale_ttl'      => 86400,
				'stale_if_error' => 86400,
				'browser_ttl'    => 300,
			),
			'balanced' => array(
				'label'          => __( 'Balanced', 'gt-performance' ),
				'description'    => __( '30m · 12h · 5m', 'gt-performance' ),
				'fresh_ttl'      => 1800,
				'stale_ttl'      => 43200,
				'stale_if_error' => 86400,
				'browser_ttl'    => 300,
			),
			'dynamic' => array(
				'label'          => __( 'Frequently updated', 'gt-performance' ),
				'description'    => __( '5m · 1h · 5m', 'gt-performance' ),
				'fresh_ttl'      => 300,
				'stale_ttl'      => 3600,
				'stale_if_error' => 21600,
				'browser_ttl'    => 300,
			),
		);
		?>
		<div class="gtp-presets" data-gtp-cache-presets>
			<div class="gtp-presets__heading">
				<h4><?php esc_html_e( 'One-click presets', 'gt-performance' ); ?></h4>
				<p><?php esc_html_e( 'Times show fresh cache, shared retention, and browser max-age. Apply a preset, review the fields, then save changes.', 'gt-performance' ); ?></p>
			</div>
			<div class="gtp-presets__grid" role="group" aria-label="<?php esc_attr_e( 'Cache lifetime presets', 'gt-performance' ); ?>">
				<?php foreach ( $presets as $key => $preset ) : ?>
					<button
						type="button"
						class="gtp-preset"
						data-gtp-cache-preset="<?php echo esc_attr( $key ); ?>"
						data-fresh-ttl="<?php echo esc_attr( (string) $preset['fresh_ttl'] ); ?>"
						data-stale-ttl="<?php echo esc_attr( (string) $preset['stale_ttl'] ); ?>"
						data-stale-if-error="<?php echo esc_attr( (string) $preset['stale_if_error'] ); ?>"
						data-browser-ttl="<?php echo esc_attr( (string) $preset['browser_ttl'] ); ?>"
						aria-pressed="false"
					>
						<strong><?php echo esc_html( (string) $preset['label'] ); ?></strong>
						<span><?php echo esc_html( (string) $preset['description'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<p class="gtp-presets__status" data-gtp-cache-preset-status aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function cssDeliveryOptions( array $settings ): void {
		$name     = Settings::OPTION . '[css][mode]';
		$selected = (string) $settings['css']['mode'];
		$options  = array(
			'file'   => array(
				'label'       => __( 'Generated file', 'gt-performance' ),
				'description' => __( 'Smallest pages. Browsers can reuse the CSS file between visits.', 'gt-performance' ),
			),
			'inline' => array(
				'label'       => __( 'Inline all used CSS', 'gt-performance' ),
				'description' => __( 'Saves a request, but puts the CSS in every page, so pages are bigger.', 'gt-performance' ),
			),
			'hybrid' => array(
				'label'       => __( 'Critical inline + remaining file', 'gt-performance' ),
				'description' => __( 'Puts the CSS needed for the top of the page in the page itself, and the rest in a file.', 'gt-performance' ),
			),
		);
		?>
		<div class="gtp-field">
			<div>
				<div class="gtp-field__label" id="gtp-css-delivery-label"><?php esc_html_e( 'CSS delivery', 'gt-performance' ); ?></div>
				<p><?php esc_html_e( 'Choose how the reduced, page-specific CSS is added to the response.', 'gt-performance' ); ?></p>
			</div>
			<fieldset class="gtp-radio-options" aria-labelledby="gtp-css-delivery-label">
				<legend class="screen-reader-text"><?php esc_html_e( 'CSS delivery', 'gt-performance' ); ?></legend>
				<?php foreach ( $options as $value => $option ) : ?>
					<label class="gtp-radio-option">
						<span class="gtp-radio-option__control">
							<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $selected, $value ); ?>>
						</span>
						<span class="gtp-radio-option__copy">
							<strong><?php echo esc_html( $option['label'] ); ?></strong>
							<small><?php echo esc_html( $option['description'] ); ?></small>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>
		</div>
		<?php
	}

	private function renderWordPressPresets(): void {
		?>
		<div class="gtp-presets gtp-presets--compact" data-gtp-wordpress-presets>
			<div class="gtp-presets__heading">
				<h4><?php esc_html_e( 'WordPress presets', 'gt-performance' ); ?></h4>
				<p><?php esc_html_e( 'The site baseline matches the active request-removal settings on gauravtiwari.org. It keeps the main feed live and indexable while disabling secondary feeds, and it does not change comments, global styles, Heartbeat, revisions, or REST access.', 'gt-performance' ); ?></p>
			</div>
			<div class="gtp-presets__actions" role="group" aria-label="<?php esc_attr_e( 'WordPress optimization presets', 'gt-performance' ); ?>">
				<button type="button" class="button button-secondary" data-gtp-wordpress-preset="gaurav"><?php esc_html_e( 'Apply site baseline', 'gt-performance' ); ?></button>
				<button type="button" class="button button-secondary" data-gtp-wordpress-preset="clear"><?php esc_html_e( 'Clear quick toggles', 'gt-performance' ); ?></button>
			</div>
			<p class="gtp-presets__status" data-gtp-wordpress-preset-status aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function databaseTaskSettings( array $settings ): void {
		$selected = array_map( 'strval', (array) $settings['database']['tasks'] );
		?>
		<div class="gtp-field gtp-field--stacked">
			<div>
				<span class="gtp-field__label" id="gtp-database-scheduled-tasks"><?php esc_html_e( 'Scheduled tasks', 'gt-performance' ); ?></span>
				<p><?php esc_html_e( 'Choose what automatic maintenance may remove. Clearing all transients is available only as a manual action.', 'gt-performance' ); ?></p>
			</div>
			<div class="gtp-checklist" role="group" aria-labelledby="gtp-database-scheduled-tasks">
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[database][tasks][]' ); ?>" value="">
				<?php foreach ( $this->databaseTaskDefinitions() as $key => $definition ) : ?>
					<?php if ( 'all_transients' === $key ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[database][tasks][]' ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?>>
						<span><strong><?php echo esc_html( $definition['label'] ); ?></strong><?php echo esc_html( $definition['description'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderDatabaseOptimization( array $settings ): void {
		$preview      = ( new Cleaner() )->preview();
		$selected     = array_map( 'strval', (array) $settings['database']['tasks'] );
		$lastResult   = $this->databaseResult();
		$definitions  = $this->databaseTaskDefinitions();
		?>
		<section class="gtp-panel gtp-database-manual">
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Manual database optimization', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Scan results are current. Select only the cleanup tasks you want to run now.', 'gt-performance' ); ?></p>
				</div>
				<span class="gtp-status"><?php esc_html_e( 'Manual control', 'gt-performance' ); ?></span>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gtperf_database_clean">
				<input type="hidden" name="tasks[]" value="">
				<?php wp_nonce_field( 'gtperf_database_clean' ); ?>
				<div class="gtp-database-task-list" role="group" aria-label="<?php esc_attr_e( 'Manual database optimization tasks', 'gt-performance' ); ?>">
					<?php foreach ( $definitions as $key => $definition ) : ?>
						<label class="gtp-database-task">
							<input type="checkbox" name="tasks[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) && 'all_transients' !== $key ); ?>>
							<span class="gtp-database-task__copy">
								<strong><?php echo esc_html( $definition['label'] ); ?></strong>
								<small><?php echo esc_html( $definition['description'] ); ?></small>
							</span>
							<span class="gtp-database-task__count"><?php echo esc_html( number_format_i18n( (int) ( $preview[ $key ] ?? 0 ) ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="gtp-database-actions">
					<p role="note"><?php esc_html_e( 'These changes are permanent. Back up the database before deleting content or clearing all transients.', 'gt-performance' ); ?></p>
					<?php submit_button( __( 'Run selected optimization', 'gt-performance' ), 'secondary', 'submit', false ); ?>
				</div>
			</form>
			<?php if ( $lastResult ) : ?>
				<div class="gtp-database-result">
					<h4><?php esc_html_e( 'Latest manual run', 'gt-performance' ); ?></h4>
					<dl>
						<?php foreach ( $definitions as $key => $definition ) : ?>
							<?php if ( ! isset( $lastResult[ $key ] ) || (int) $lastResult[ $key ] < 1 ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<div><dt><?php echo esc_html( $definition['label'] ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $lastResult[ $key ] ) ); ?></dd></div>
						<?php endforeach; ?>
					</dl>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @return array<string, array{label:string,description:string}>
	 */
	private function databaseTaskDefinitions(): array {
		return array(
			'revisions'          => array(
				'label'       => __( 'Post revisions', 'gt-performance' ),
				'description' => __( 'Delete saved revisions. Manual cleanup removes all selected revisions.', 'gt-performance' ),
			),
			'auto_drafts'        => array(
				'label'       => __( 'Auto-drafts', 'gt-performance' ),
				'description' => __( 'Delete abandoned automatic drafts.', 'gt-performance' ),
			),
			'spam_comments'      => array(
				'label'       => __( 'Spam comments', 'gt-performance' ),
				'description' => __( 'Permanently delete comments marked as spam.', 'gt-performance' ),
			),
			'trashed_posts'      => array(
				'label'       => __( 'Trashed posts', 'gt-performance' ),
				'description' => __( 'Permanently delete posts and pages in Trash.', 'gt-performance' ),
			),
			'trashed_comments'   => array(
				'label'       => __( 'Trashed comments', 'gt-performance' ),
				'description' => __( 'Permanently delete comments in Trash.', 'gt-performance' ),
			),
			'expired_transients' => array(
				'label'       => __( 'Expired transients', 'gt-performance' ),
				'description' => __( 'Remove expired temporary cache entries.', 'gt-performance' ),
			),
			'all_transients'     => array(
				'label'       => __( 'All transients', 'gt-performance' ),
				'description' => __( 'Clear every transient row, including active temporary caches.', 'gt-performance' ),
			),
			'optimize_tables'    => array(
				'label'       => __( 'Database tables', 'gt-performance' ),
				'description' => __( 'Optimize WordPress tables that report reclaimable space.', 'gt-performance' ),
			),
		);
	}

	/**
	 * @return array<string, int>
	 */
	private function databaseResult(): array {
		$result = get_transient( 'gtperf_database_result_' . get_current_user_id() );

		return is_array( $result ) ? array_map( 'intval', $result ) : array();
	}

	private function settingsFormOpen(): void {
		?>
		<form method="post" action="options.php" class="gtp-settings-form">
			<?php settings_fields( 'gt_performance' ); ?>
		<?php
	}

	private function settingsFormClose(): void {
		?>
			<div class="gtp-save-bar"><?php submit_button( __( 'Save changes', 'gt-performance' ), 'primary', 'submit', false ); ?></div>
		</form>
		<?php
	}

	private function pageIntro( string $title, string $description ): void {
		?>
		<div class="gtp-page-heading">
			<div>
				<h2><?php echo esc_html( $title ); ?></h2>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
		</div>
		<?php
	}

	private function panelOpen( string $title, string $description ): void {
		?>
		<section class="gtp-panel">
			<div class="gtp-panel__header">
				<div>
					<h3><?php echo esc_html( $title ); ?></h3>
					<p><?php echo esc_html( $description ); ?></p>
				</div>
			</div>
			<div class="gtp-fields">
		<?php
	}

	private function panelClose(): void {
		?>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function checkbox( string $section, string $key, string $label, string $description, array $settings, string $tooltip = '' ): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		$profile = $this->enableProfile( $section, $key );
		?>
		<div class="gtp-field gtp-field--toggle">
			<div>
				<?php $this->fieldLabel( $id, $label, $tooltip ); ?>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="gtp-field__control">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $settings[ $section ][ $key ] ) ); ?><?php echo '' !== $profile ? ' data-gtp-enable-profile="' . esc_attr( $profile ) . '"' : ''; ?>>
			</div>
		</div>
		<?php
	}

	private function enableProfile( string $section, string $key ): string {
		$profiles = array(
			'cloudflare.enabled'              => 'cloudflare',
			'xcloud.enabled'                 => 'xcloud',
			'cdn.enabled'                    => 'cdn',
			'integrations.auto_protection'   => 'compatibility',
			'redis.enabled'                  => 'redis',
		);

		return $profiles[ $section . '.' . $key ] ?? '';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function checkboxRoot( string $key, string $label, string $description, array $settings, string $tooltip = '' ): void {
		$name = Settings::OPTION . '[' . $key . ']';
		$id   = 'gtp-' . $key;
		?>
		<div class="gtp-field gtp-field--toggle">
			<div>
				<?php $this->fieldLabel( $id, $label, $tooltip ); ?>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="gtp-field__control">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?>>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function number(
		string $section,
		string $key,
		string $label,
		string $description,
		array $settings,
		float|int $min,
		float|int $max,
		string $suffix = '',
		string $step = '1',
		string $tooltip = ''
	): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field">
			<div>
				<?php $this->fieldLabel( $id, $label, $tooltip ); ?>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="gtp-field__control gtp-field__number">
				<input id="<?php echo esc_attr( $id ); ?>" type="number" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="<?php echo esc_attr( $step ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $settings[ $section ][ $key ] ); ?>">
				<?php
				if ( '' !== $suffix ) :
					?>
					<span><?php echo esc_html( $suffix ); ?></span><?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed>    $settings Settings.
	 * @param array<array-key, string> $options Options.
	 */
	private function select( string $section, string $key, string $label, string $description, array $settings, array $options, string $tooltip = '' ): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field">
			<div>
				<?php $this->fieldLabel( $id, $label, $tooltip ); ?>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="gtp-field__control">
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<?php foreach ( $options as $value => $text ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $settings[ $section ][ $key ], $value ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function text(
		string $section,
		string $key,
		string $label,
		string $description,
		array $settings,
		string $type = 'text',
		string $placeholder = '',
		string $tooltip = '',
		string $helpUrl = '',
		string $helpLabel = ''
	): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field">
			<div>
				<?php $this->fieldLabel( $id, $label, $tooltip ); ?>
				<p>
					<?php echo esc_html( $description ); ?>
					<?php $this->fieldHelpLink( $helpUrl, $helpLabel ); ?>
				</p>
			</div>
			<div class="gtp-field__control">
				<input id="<?php echo esc_attr( $id ); ?>" class="regular-text" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $settings[ $section ][ $key ] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
			</div>
		</div>
		<?php
	}

	private function password(
		string $section,
		string $key,
		string $label,
		string $description,
		bool $saved,
		string $tooltip = '',
		string $helpUrl = '',
		string $helpLabel = ''
	): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field">
			<div>
				<?php $this->fieldLabel( $id, $label, $tooltip ); ?>
				<p>
					<?php echo esc_html( $description ); ?>
					<?php $this->fieldHelpLink( $helpUrl, $helpLabel ); ?>
				</p>
			</div>
			<div class="gtp-field__control">
				<input id="<?php echo esc_attr( $id ); ?>" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>" value="" placeholder="<?php echo esc_attr( $saved ? __( 'Saved; leave blank to keep', 'gt-performance' ) : '' ); ?>">
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function textarea( string $section, string $key, string $label, string $description, array $settings, string $placeholder, string $tooltip = '' ): void {
		$name  = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id    = 'gtp-' . $section . '-' . $key;
		$value = implode( "\n", array_map( 'strval', (array) $settings[ $section ][ $key ] ) );
		?>
		<div class="gtp-field gtp-field--textarea">
			<div>
				<?php $this->fieldLabel( $id, $label, $tooltip ); ?>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="gtp-field__control">
				<textarea id="<?php echo esc_attr( $id ); ?>" rows="6" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
			</div>
		</div>
		<?php
	}

	private function fieldLabel( string $id, string $label, string $tooltip = '' ): void {
		?>
		<div class="gtp-field__title">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<?php if ( '' !== $tooltip ) : ?>
				<span class="gtp-tooltip">
					<button
						type="button"
						class="gtp-tooltip__trigger"
						<?php // translators: 1: setting label, 2: brief help text. ?>
						aria-label="<?php echo esc_attr( sprintf( __( 'More information about %1$s: %2$s', 'gt-performance' ), $label, $tooltip ) ); ?>"
					>?</button>
					<span class="gtp-tooltip__content" aria-hidden="true"><?php echo esc_html( $tooltip ); ?></span>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	private function fieldHelpLink( string $url, string $label ): void {
		if ( '' === $url || '' === $label ) {
			return;
		}
		?>
		<a class="gtp-field__help-link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?> <span aria-hidden="true">&nearr;</span></a>
		<?php
	}

	private function stat( string $label, string $value, string $tone ): void {
		?>
		<div class="gtp-stat gtp-stat--<?php echo esc_attr( $tone ); ?>">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
		</div>
		<?php
	}

	private function operation( string $title, string $description, string $action, string $label ): void {
		?>
		<section class="gtp-panel gtp-operation">
			<div>
				<h3><?php echo esc_html( $title ); ?></h3>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<?php $this->actionButton( $action, $label ); ?>
		</section>
		<?php
	}

	private function actionButton( string $action, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php // These operations are reachable from more than one screen, so remember where the visitor started. ?>
			<input type="hidden" name="gtperf_return" value="<?php echo esc_attr( $this->currentTab() ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage GT Performance.', 'gt-performance' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Return to the admin screen after an operation.
	 *
	 * The same operation now runs from either the dashboard or Tools, so an
	 * originating tab supplied by the form wins over the caller's default —
	 * otherwise purging from the dashboard would dump the visitor on Tools.
	 * Every caller verifies its nonce in guard() before reaching this.
	 */
	/**
	 * Redirect after a failure, keeping the reason the upstream service gave.
	 *
	 * The notice map can only translate an error code into a generic sentence. The
	 * specific text -- a Cloudflare permission complaint, an expired token, a DNS
	 * failure -- lives on the WP_Error and is otherwise thrown away at this point.
	 */
	private function redirectError( \WP_Error $error, string $tab ): never {
		$reason = trim( $error->get_error_message() );
		if ( '' !== $reason ) {
			set_transient( self::ERROR_DETAIL_TRANSIENT, $reason, 5 * MINUTE_IN_SECONDS );
		}

		$this->redirect( $error->get_error_code(), $tab );
	}

	private function redirect( string $notice, string $tab ): never {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling action handler.
		$return = isset( $_POST['gtperf_return'] ) ? sanitize_key( (string) wp_unslash( $_POST['gtperf_return'] ) ) : '';
		if ( '' !== $return && in_array( $return, self::tabs(), true ) ) {
			$tab = $return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'tab'        => sanitize_key( $tab ),
					'gtperf_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function currentTab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing against a fixed allowlist; no state changes.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		return in_array( $tab, self::tabs(), true ) ? $tab : 'dashboard';
	}

	private function tabUrl( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => in_array( $tab, self::tabs(), true ) ? $tab : 'dashboard',
			),
			admin_url( 'admin.php' )
		);
	}

	private function statusLabel( string $status ): string {
		$labels = array(
			'owned'    => __( 'Installed by GT Performance', 'gt-performance' ),
			'enabled'  => __( 'Enabled', 'gt-performance' ),
			'disabled' => __( 'Disabled', 'gt-performance' ),
			'missing'  => __( 'Not installed', 'gt-performance' ),
			'conflict' => __( 'Owned by another plugin', 'gt-performance' ),
			'writable' => __( 'Writable', 'gt-performance' ),
		);

		return $labels[ $status ] ?? ucwords( str_replace( '-', ' ', $status ) );
	}

	/**
	 * @return array{message:string,type:string}
	 */
	private function noticeDetails( string $notice ): array {
		if ( 'database-cleaned' === $notice ) {
			$count = array_sum( $this->databaseResult() );

			return array(
				'message' => sprintf(
					/* translators: %s: number of database rows or table operations processed. */
					_n( 'Database optimization processed %s item.', 'Database optimization processed %s items.', $count, 'gt-performance' ),
					number_format_i18n( $count )
				),
				'type'    => 'success',
			);
		}

		if ( str_starts_with( $notice, 'cleaned-' ) ) {
			$count = max( 0, (int) substr( $notice, strlen( 'cleaned-' ) ) );

			return array(
				'message' => sprintf(
					/* translators: %s: number of database records removed. */
					_n( 'Database cleanup removed %s record.', 'Database cleanup removed %s records.', $count, 'gt-performance' ),
					number_format_i18n( $count )
				),
				'type'    => 'success',
			);
		}

		$notices = array(
			'dropin-installed'          => array( __( 'The page-cache drop-in was installed.', 'gt-performance' ), 'success' ),
			'redis-installed'           => array( __( 'The Redis object-cache drop-in was installed.', 'gt-performance' ), 'success' ),
			'redis-connected'           => array( __( 'Redis accepted the saved credentials and passed the connection test.', 'gt-performance' ), 'success' ),
			'cache-purged'              => array( __( 'GT Performance cache was purged.', 'gt-performance' ), 'success' ),
			'cloudflare-synced'         => array( __( 'Cloudflare connected and the managed cache rule was synchronized.', 'gt-performance' ), 'success' ),
			'cloudflare-previewed'      => array( __( 'The live Cloudflare rule plan was checked without changing it.', 'gt-performance' ), 'success' ),
			'cloudflare-diagnosed-ok'   => array( __( 'Every Cloudflare connection stage passed, including writing cache rules.', 'gt-performance' ), 'success' ),
			'cloudflare-token-created'  => array( __( 'A zone-scoped Cloudflare API token was created and saved. The Global API Key is no longer needed here and can be cleared.', 'gt-performance' ), 'success' ),
			'cloudflare-diagnosed-fail' => array( __( 'The Cloudflare connection check stopped at a failing stage. The results below name the exact cause.', 'gt-performance' ), 'error' ),
			'purge-verified'            => array( __( 'The origin artifact was removed and the refreshed public response passed verification.', 'gt-performance' ), 'success' ),
			'purge-warning'             => array( __( 'The purge completed, but one or more verification signals need review.', 'gt-performance' ), 'warning' ),
			'commerce-safety-pass'      => array( __( 'Every active commerce cache-policy check and live protection check passed.', 'gt-performance' ), 'success' ),
			'commerce-safety-review'    => array( __( 'The commerce run completed with warnings or policy failures. Review the Safety Lab history.', 'gt-performance' ), 'warning' ),
			'css-regenerated-url'       => array( __( 'CSS regeneration queued. The report will update when the build finishes.', 'gt-performance' ), 'success' ),
			'css-regenerated-all'       => array( __( 'CSS results invalidated and page caches purged. Known eligible URLs will rebuild in the background; other pages rebuild when visited.', 'gt-performance' ), 'success' ),
			'css-unavailable' => array( __( 'CSS could not be queued. Check that page caching and unused CSS are enabled, rollout includes the URL, and no build is already active.', 'gt-performance' ), 'warning' ),
			'css-regenerate-invalid'    => array( __( 'Enter a valid public URL from this WordPress site.', 'gt-performance' ), 'error' ),
			'fleet-policy-applied'      => array( __( 'The signed fleet policy was verified and applied.', 'gt-performance' ), 'success' ),
			'gtperf_cloudflare_token'      => array( __( 'Enter a Cloudflare API token, save the settings, then connect again.', 'gt-performance' ), 'error' ),
			'gtperf_cloudflare_email'      => array( __( 'Enter the Cloudflare account email used with the Global API Key.', 'gt-performance' ), 'error' ),
			'gtperf_cloudflare_global_key' => array( __( 'Enter a Cloudflare Global API Key, save the settings, then connect again.', 'gt-performance' ), 'error' ),
			'gtperf_cloudflare_zone'       => array( __( 'No active Cloudflare zone matched this domain. Check the domain or enter the Zone ID.', 'gt-performance' ), 'error' ),
			'gtperf_cloudflare_json'       => array( __( 'Cloudflare returned an unreadable response. Try again in a moment.', 'gt-performance' ), 'error' ),
			'gtperf_cloudflare_api'        => array( __( 'Cloudflare rejected the request. Editing cache rules needs an API token with Zone → Cache Rules → Edit (permission group "Cache Settings Write"), plus Zone Read and Cache Purge. Run the connection check for the failing stage.', 'gt-performance' ), 'error' ),
			'gtperf_cloudflare_transport'  => array( __( 'WordPress could not reach the Cloudflare API at all, so this is a network or firewall problem rather than a credential problem.', 'gt-performance' ), 'error' ),
			'gtperf_cloudflare_ruleset'    => array( __( 'Cloudflare did not return the cache ruleset needed to finish setup.', 'gt-performance' ), 'error' ),
			'gtperf_cloudflare_rule_budget' => array( __( 'The Cloudflare Free Cache Rules budget is full. Remove an unused rule or reconnect the existing GT Performance rule.', 'gt-performance' ), 'warning' ),
			'gtperf_xcloud_token'         => array( __( 'Enter an xCloud API token with read:sites and write:sites scopes, save, then connect again.', 'gt-performance' ), 'error' ),
			'gtperf_xcloud_site'          => array( __( 'No exact xCloud site matched this domain or UUID.', 'gt-performance' ), 'error' ),
			'gtperf_xcloud_enterprise_ids' => array( __( 'xCloud did not expose the numeric site identifiers required by the Cloudflare Enterprise add-on. Refresh and try again.', 'gt-performance' ), 'error' ),
			'gtperf_xcloud_cache_settings' => array( __( 'xCloud did not return cache-layer settings for this site.', 'gt-performance' ), 'error' ),
			'gtperf_xcloud_json'          => array( __( 'xCloud returned an unreadable response. Try again in a moment.', 'gt-performance' ), 'error' ),
			'gtperf_xcloud_api'           => array( __( 'xCloud rejected the request. Check the token scopes and team permissions.', 'gt-performance' ), 'error' ),
			'gtperf_xcloud_enterprise_purge_unavailable' => array( __( 'Cloudflare Enterprise purge is not available through the xCloud Public API token. Use the Purge control in the xCloud Enterprise dashboard.', 'gt-performance' ), 'warning' ),
			'gtperf_edge_owner_conflict'  => array( __( 'xCloud Cloudflare Enterprise is the active owner. Disable it or the direct Cloudflare integration before synchronizing another cache rule.', 'gt-performance' ), 'warning' ),
			'xcloud-connected'         => array( __( 'xCloud site, host cache, and Cloudflare Enterprise status refreshed.', 'gt-performance' ), 'success' ),
			'xcloud-edge-conflict'     => array( __( 'xCloud connected, but Cloudflare Enterprise and direct Cloudflare are both enabled. Choose one edge-cache owner before synchronizing rules.', 'gt-performance' ), 'warning' ),
			'gtperf_diagnostic_url'        => array( __( 'Enter a valid URL from this WordPress site.', 'gt-performance' ), 'error' ),
			'gtperf_purge_verification_http' => array( __( 'The purge ran, but GT Performance could not fetch the public page for verification.', 'gt-performance' ), 'warning' ),
			'gtperf_fleet_secret'          => array( __( 'Save the same fleet signing secret on every site before creating or applying fleet policies.', 'gt-performance' ), 'warning' ),
			'gtperf_fleet_json'            => array( __( 'The pasted fleet policy is not valid JSON.', 'gt-performance' ), 'error' ),
			'gtperf_fleet_signature'       => array( __( 'The fleet policy signature is invalid or the five-minute import window expired.', 'gt-performance' ), 'error' ),
			'gtperf_fleet_replay'          => array( __( 'That fleet policy was already applied and cannot be replayed.', 'gt-performance' ), 'warning' ),
			'gtperf_dropin_conflict'       => array( __( 'Another plugin owns advanced-cache.php. Disable or migrate that cache before installing this drop-in.', 'gt-performance' ), 'warning' ),
			'gtperf_dropin_directory'      => array( __( 'The WordPress content directory is not writable, so the page-cache drop-in could not be installed.', 'gt-performance' ), 'error' ),
			'gtperf_dropin_write'          => array( __( 'GT Performance could not write the page-cache drop-in.', 'gt-performance' ), 'error' ),
			'gtperf_dropin_move'           => array( __( 'GT Performance could not publish the page-cache drop-in safely.', 'gt-performance' ), 'error' ),
			'gtperf_wp_config_read'        => array( __( 'GT Performance could not read wp-config.php.', 'gt-performance' ), 'error' ),
			'gtperf_wp_cache_custom'       => array( __( 'wp-config.php contains a custom WP_CACHE declaration. Enable WP_CACHE manually, then try again.', 'gt-performance' ), 'warning' ),
			'gtperf_wp_config_update'      => array( __( 'GT Performance could not add WP_CACHE to wp-config.php.', 'gt-performance' ), 'error' ),
			'gtperf_wp_config_writable'    => array( __( 'wp-config.php is not writable. Add the WP_CACHE constant manually, then try again.', 'gt-performance' ), 'warning' ),
			'gtperf_wp_config_write'       => array( __( 'GT Performance could not write the temporary wp-config.php update.', 'gt-performance' ), 'error' ),
			'gtperf_wp_config_publish'     => array( __( 'GT Performance could not publish the wp-config.php update safely.', 'gt-performance' ), 'error' ),
			'gtperf_redis_extension'       => array( __( 'The PHP Redis extension is not installed on this server.', 'gt-performance' ), 'warning' ),
			'gtperf_redis_disabled'        => array( __( 'Enable Redis object caching and save the settings before testing the connection.', 'gt-performance' ), 'warning' ),
			'gtperf_redis_connect'         => array( __( 'Redis could not be reached with the saved host, TLS, or credentials.', 'gt-performance' ), 'error' ),
			'gtperf_redis_ping'            => array( __( 'Redis accepted the connection but did not answer the health check.', 'gt-performance' ), 'error' ),
			'gtperf_redis_conflict'        => array( __( 'Another plugin owns object-cache.php. Remove that conflict before installing the Redis drop-in.', 'gt-performance' ), 'warning' ),
			'gtperf_redis_install'         => array( __( 'GT Performance could not install the Redis object-cache drop-in.', 'gt-performance' ), 'error' ),
			'quick-action-invalid'      => array( __( 'That quick action is not available.', 'gt-performance' ), 'warning' ),
		);

		/**
		 * Notices for actions this build does not know about.
		 *
		 * A distribution channel adds its own here, so shared admin code carries no
		 * string belonging to a subsystem the package may not contain.
		 *
		 * @param array<string, array{0:string,1:string}> $notices Notice map.
		 */
		$notices = (array) apply_filters( 'gt_performance_admin_notices', $notices );

		if ( isset( $notices[ $notice ] ) && is_array( $notices[ $notice ] ) ) {
			return array(
				'message' => (string) $notices[ $notice ][0],
				'type'    => (string) $notices[ $notice ][1],
			);
		}

		return array(
			'message' => __( 'The requested GT Performance action could not be completed. Check the settings and try again.', 'gt-performance' ),
			'type'    => 'error',
		);
	}

	private function cssModeLabel( string $mode ): string {
		$labels = array(
			'file'   => __( 'Generated file', 'gt-performance' ),
			'inline' => __( 'Fully inline', 'gt-performance' ),
			'hybrid' => __( 'Critical inline + file', 'gt-performance' ),
		);

		return $labels[ $mode ] ?? $mode;
	}
}
