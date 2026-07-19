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
use GTPerformance\Cloudflare\ClientFactory;
use GTPerformance\Cloudflare\RuleManager;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Database\Cleaner;
use GTPerformance\Optimization\Css\ReportRepository;
use GTPerformance\Redis\ObjectCacheInstaller;

final class AdminModule implements Module {
	private const PAGE_SLUG = 'gt-performance';

	/**
	 * @var list<string>
	 */
	private const TABS = array(
		'dashboard',
		'cache',
		'optimization',
		'exceptions',
		'cloudflare',
		'integrations',
		'css-reports',
		'tools',
	);

	private string $pageHook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_init', array( $this, 'legacyRedirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'afterSettingsUpdate' ), 10, 2 );
		add_action( 'admin_post_gtp_install_dropin', array( $this, 'installDropin' ) );
		add_action( 'admin_post_gtp_install_redis', array( $this, 'installRedis' ) );
		add_action( 'admin_post_gtp_purge', array( $this, 'purge' ) );
		add_action( 'admin_post_gtp_cloudflare_sync', array( $this, 'cloudflareSync' ) );
		add_action( 'admin_post_gtp_database_clean', array( $this, 'databaseClean' ) );
		add_action( 'wp_ajax_gtp_css_report', array( $this, 'cssReport' ) );
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

	public function legacyRedirect(): void {
		global $pagenow;

		if (
			'options-general.php' !== $pagenow ||
			! isset( $_GET['page'] ) ||
			self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ||
			! current_user_can( 'manage_options' )
		) {
			return;
		}

		$args = array( 'page' => self::PAGE_SLUG );
		foreach ( array( 'tab', 'gtp_notice' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) );
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function enqueueAssets( string $hook ): void {
		if ( $this->pageHook !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'gt-performance-admin',
			plugins_url( 'assets/admin.css', GTP_FILE ),
			array( 'dashicons' ),
			GTP_VERSION
		);
		wp_enqueue_script(
			'gt-performance-admin',
			plugins_url( 'assets/admin.js', GTP_FILE ),
			array(),
			GTP_VERSION,
			true
		);
		wp_localize_script(
			'gt-performance-admin',
			'gtPerformanceAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gtp_css_report' ),
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
		$cipher              = new TokenCipher();

		foreach ( array( 'api_token', 'global_api_key' ) as $secretKey ) {
			$secret = trim( (string) ( $input['cloudflare'][ $secretKey ] ?? '' ) );
			if ( '' === $secret ) {
				$input['cloudflare'][ $secretKey ] = (string) $current['cloudflare'][ $secretKey ];
			} elseif ( ! str_starts_with( $secret, 'sodium:' ) && ! str_starts_with( $secret, 'openssl:' ) ) {
				$input['cloudflare'][ $secretKey ] = $cipher->encrypt( $secret );
			}
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
		unset( $old );
		if ( is_array( $new ) ) {
			Settings::compile( $new );
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
					case 'integrations':
						$this->renderIntegrations( $settings );
						break;
					case 'css-reports':
						$this->renderCssReports();
						break;
					case 'tools':
						$this->renderTools();
						break;
					default:
						$this->renderDashboard( $settings );
				}
				?>
			</main>
		</div>
		<?php
	}

	public function cssReport(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to view this report.', 'gt-performance' ) ), 403 );
		}
		check_ajax_referer( 'gtp_css_report', 'nonce' );

		$repository = new ReportRepository();
		$reports    = $repository->recent();

		wp_send_json_success(
			array(
				'rows'    => $this->reportRows( $reports ),
				'summary' => $repository->summary( $reports ),
			)
		);
	}

	public function installDropin(): void {
		$this->guard( 'gtp_install_dropin' );
		$result = ( new DropinInstaller() )->install();
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'dropin-installed', 'tools' );
	}

	public function installRedis(): void {
		$this->guard( 'gtp_install_redis' );
		$result = ( new ObjectCacheInstaller() )->install();
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'redis-installed', 'tools' );
	}

	public function purge(): void {
		$this->guard( 'gtp_purge' );
		( new Purger() )->purgeAll();
		$this->redirect( 'cache-purged', 'tools' );
	}

	public function cloudflareSync(): void {
		$this->guard( 'gtp_cloudflare_sync' );
		$settings = Settings::all();
		$factory  = new ClientFactory();
		$client   = $factory->create( $settings );
		if ( is_wp_error( $client ) ) {
			$this->redirect( $client->get_error_code(), 'cloudflare' );
		}

		$zoneId = (string) $settings['cloudflare']['zone_id'];
		if ( '' === $zoneId ) {
			$zone = $client->zoneByName( $factory->domain( $settings ) );
			if ( is_wp_error( $zone ) ) {
				$this->redirect( $zone->get_error_code(), 'cloudflare' );
			}
			$zoneId                            = (string) ( $zone['id'] ?? '' );
			$settings['cloudflare']['zone_id'] = $zoneId;
		}

		$cache  = apply_filters( 'gt_performance_cache_policy', (array) $settings['cache'] );
		$result = ( new RuleManager( $client ) )->sync( $zoneId, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $cache );
		if ( is_wp_error( $result ) ) {
			$this->redirect( $result->get_error_code(), 'cloudflare' );
		}

		$settings['cloudflare']['enabled']     = true;
		$settings['cloudflare']['drift_hash'] = hash( 'sha256', (string) wp_json_encode( $cache ) );
		Settings::save( $settings );
		$this->redirect( 'cloudflare-synced', 'cloudflare' );
	}

	public function databaseClean(): void {
		$this->guard( 'gtp_database_clean' );
		$result = ( new Cleaner() )->run();
		$this->redirect( 'cleaned-' . array_sum( $result ), 'tools' );
	}

	private function renderHeader( string $tab ): void {
		$tabs = array(
			'dashboard'    => __( 'Dashboard', 'gt-performance' ),
			'cache'        => __( 'Cache', 'gt-performance' ),
			'optimization' => __( 'Optimization', 'gt-performance' ),
			'exceptions'   => __( 'Exceptions', 'gt-performance' ),
			'cloudflare'   => __( 'Cloudflare', 'gt-performance' ),
			'integrations' => __( 'Integrations', 'gt-performance' ),
			'css-reports'  => __( 'CSS Reports', 'gt-performance' ),
			'tools'        => __( 'Tools', 'gt-performance' ),
		);
		?>
		<header class="gtp-admin__header">
			<div>
				<p class="gtp-admin__eyebrow"><?php esc_html_e( 'GT Performance', 'gt-performance' ); ?></p>
				<h1><?php esc_html_e( 'Performance control center', 'gt-performance' ); ?></h1>
				<p class="gtp-admin__lede"><?php esc_html_e( 'Origin caching, server-side optimization, Cloudflare Free, and commerce-safe delivery in one place.', 'gt-performance' ); ?></p>
			</div>
			<span class="gtp-version"><?php echo esc_html( 'Version ' . GTP_VERSION ); ?></span>
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
		$notice = isset( $_GET['gtp_notice'] ) ? sanitize_key( wp_unslash( $_GET['gtp_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$details = $this->noticeDetails( $notice );
		?>
		<div class="notice notice-<?php echo esc_attr( $details['type'] ); ?> is-dismissible gtp-notice"><p><?php echo esc_html( $details['message'] ); ?></p></div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderDashboard( array $settings ): void {
		$dropin     = ( new DropinInstaller() )->status();
		$wpCache    = ( new WpCacheConstant() )->status();
		$redis      = ( new ObjectCacheInstaller() )->status();
		$reports    = ( new ReportRepository() )->recent();
		$cssReady   = count( array_filter( $reports, static fn( array $report ): bool => 'ready' === $report['status'] ) );
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
			<?php $this->stat( __( 'Unused CSS', 'gt-performance' ), ! empty( $settings['css']['enabled'] ) ? __( 'Enabled', 'gt-performance' ) : __( 'Disabled', 'gt-performance' ), ! empty( $settings['css']['enabled'] ) ? 'success' : 'neutral' ); ?>
			<?php $this->stat( __( 'CSS files ready', 'gt-performance' ), number_format_i18n( $cssReady ), $cssReady > 0 ? 'success' : 'neutral' ); ?>
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
					<div><dt><?php esc_html_e( 'Cache directory', 'gt-performance' ); ?></dt><dd><?php echo esc_html( is_writable( Paths::cacheRoot() ) ? __( 'Writable', 'gt-performance' ) : __( 'Not writable', 'gt-performance' ) ); ?></dd></div>
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
					<p><?php esc_html_e( 'Install the page-cache drop-in, enable origin caching, then verify a public page before adding more optimizations.', 'gt-performance' ); ?></p>
					<a class="button button-secondary" href="<?php echo esc_url( $this->tabUrl( 'tools' ) ); ?>"><?php esc_html_e( 'Open cache tools', 'gt-performance' ); ?></a>
				<?php elseif ( empty( $settings['cloudflare']['enabled'] ) ) : ?>
					<p><?php esc_html_e( 'Origin caching is ready. Connect Cloudflare Free to cache eligible HTML closer to visitors.', 'gt-performance' ); ?></p>
					<a class="button button-secondary" href="<?php echo esc_url( $this->tabUrl( 'cloudflare' ) ); ?>"><?php esc_html_e( 'Configure Cloudflare', 'gt-performance' ); ?></a>
				<?php elseif ( ! empty( $settings['css']['enabled'] ) && 0 === $cssReady ) : ?>
					<p><?php esc_html_e( 'Unused CSS is enabled but no ready result exists yet. Visit a public page, then watch the CSS report.', 'gt-performance' ); ?></p>
					<a class="button button-secondary" href="<?php echo esc_url( $this->tabUrl( 'css-reports' ) ); ?>"><?php esc_html_e( 'Open CSS Reports', 'gt-performance' ); ?></a>
				<?php else : ?>
					<p><?php esc_html_e( 'The core layers are configured. Review exceptions before enabling aggressive JavaScript or media transformations.', 'gt-performance' ); ?></p>
					<a class="button button-secondary" href="<?php echo esc_url( $this->tabUrl( 'exceptions' ) ); ?>"><?php esc_html_e( 'Review exceptions', 'gt-performance' ); ?></a>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderCache( array $settings ): void {
		$this->pageIntro( __( 'Page cache', 'gt-performance' ), __( 'Control origin HTML caching, browser behavior, and cache variants. Commerce and account pages remain protected by integrations and exceptions.', 'gt-performance' ) );
		$this->settingsFormOpen();
		$this->panelOpen( __( 'Origin cache', 'gt-performance' ), __( 'Keep safe public HTML ready on disk so WordPress does less work.', 'gt-performance' ) );
		$this->checkbox( 'cache', 'enabled', __( 'Enable origin page cache', 'gt-performance' ), __( 'Cache eligible public GET requests after WordPress renders them once.', 'gt-performance' ), $settings );
		$this->checkbox( 'cache', 'separate_mobile', __( 'Separate mobile cache', 'gt-performance' ), __( 'Create a separate cache variant for mobile user agents. Use only when the HTML differs by device.', 'gt-performance' ), $settings );
		$this->checkbox( 'cache', 'cache_logged_in', __( 'Cache logged-in users', 'gt-performance' ), __( 'Advanced and normally unsafe. Personalized pages can leak or become stale.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Cache lifetime', 'gt-performance' ), __( 'Use shorter fresh lifetimes for frequently changing sites and longer stale retention for resilience.', 'gt-performance' ) );
		$this->number( 'cache', 'fresh_ttl', __( 'Fresh TTL', 'gt-performance' ), __( 'Seconds before a cached page needs regeneration.', 'gt-performance' ), $settings, 0, 604800, __( 'seconds', 'gt-performance' ) );
		$this->number( 'cache', 'stale_ttl', __( 'Stale retention', 'gt-performance' ), __( 'How long an expired page remains available for safe stale delivery.', 'gt-performance' ), $settings, 0, 2592000, __( 'seconds', 'gt-performance' ) );
		$this->number( 'cache', 'stale_if_error', __( 'Stale if error', 'gt-performance' ), __( 'How long stale HTML may be used when regeneration fails.', 'gt-performance' ), $settings, 0, 2592000, __( 'seconds', 'gt-performance' ) );
		$this->number( 'cache', 'browser_ttl', __( 'Browser TTL', 'gt-performance' ), __( 'Client-side HTML cache duration. Leave at zero for safer instant purges.', 'gt-performance' ), $settings, 0, 604800, __( 'seconds', 'gt-performance' ) );
		$this->panelClose();
		$this->settingsFormClose();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderOptimization( array $settings ): void {
		$this->pageIntro( __( 'Optimization', 'gt-performance' ), __( 'Server-side CSS processing plus conservative JavaScript, media, font, database, and WordPress cleanup controls.', 'gt-performance' ) );
		$this->settingsFormOpen();

		$this->panelOpen( __( 'Unused CSS', 'gt-performance' ), __( 'Analyze rendered HTML on this server and deliver only matching selectors.', 'gt-performance' ) );
		$this->checkbox( 'css', 'enabled', __( 'Remove unused CSS', 'gt-performance' ), __( 'Generate page-specific CSS when an eligible page is rendered.', 'gt-performance' ), $settings );
		$this->select(
			'css',
			'mode',
			__( 'CSS delivery', 'gt-performance' ),
			__( 'Hybrid keeps the most important CSS inline and writes the remainder to an immutable file.', 'gt-performance' ),
			$settings,
			array(
				'file'   => __( 'Generated file', 'gt-performance' ),
				'inline' => __( 'Fully inline', 'gt-performance' ),
				'hybrid' => __( 'Critical inline + remaining file', 'gt-performance' ),
			)
		);
		$this->number( 'css', 'critical_budget', __( 'Critical CSS budget', 'gt-performance' ), __( 'Maximum critical CSS size before hybrid mode safely falls back.', 'gt-performance' ), $settings, 2048, 51200, __( 'bytes', 'gt-performance' ) );
		$this->checkbox( 'css', 'keep_dynamic_states', __( 'Preserve dynamic states', 'gt-performance' ), __( 'Keep selectors used for hover, focus, open, checked, and other interactive states.', 'gt-performance' ), $settings );
		$this->inlineLink( __( 'See generated CSS files and processing status', 'gt-performance' ), $this->tabUrl( 'css-reports' ) );
		$this->panelClose();

		$this->panelOpen( __( 'JavaScript', 'gt-performance' ), __( 'Apply transformations only to scripts that are not excluded and do not appear transactional.', 'gt-performance' ) );
		$this->checkbox( 'javascript', 'minify', __( 'Minify local JavaScript', 'gt-performance' ), __( 'Create immutable minified copies of eligible local scripts.', 'gt-performance' ), $settings );
		$this->checkbox( 'javascript', 'defer', __( 'Defer safe JavaScript', 'gt-performance' ), __( 'Add defer to eligible external scripts.', 'gt-performance' ), $settings );
		$this->checkbox( 'javascript', 'delay', __( 'Delay third-party JavaScript', 'gt-performance' ), __( 'Wait for interaction or five seconds before loading matching third-party scripts.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Images and embeds', 'gt-performance' ), __( 'Reduce offscreen work and generate modern image variants on this server.', 'gt-performance' ) );
		$this->checkbox( 'media', 'lazy_load', __( 'Lazy-load non-critical images', 'gt-performance' ), __( 'Keep the first critical images eager and lazy-load later images.', 'gt-performance' ), $settings );
		$this->checkbox( 'media', 'add_dimensions', __( 'Add missing image dimensions', 'gt-performance' ), __( 'Reduce layout shifts when attachment dimensions are known.', 'gt-performance' ), $settings );
		$this->number( 'media', 'critical_images', __( 'Critical image count', 'gt-performance' ), __( 'Number of early images excluded from lazy loading.', 'gt-performance' ), $settings, 0, 10, __( 'images', 'gt-performance' ) );
		$this->checkbox( 'media', 'optimize_uploads', __( 'Generate optimized variants', 'gt-performance' ), __( 'Create the selected modern format when attachments are generated.', 'gt-performance' ), $settings );
		$this->checkbox( 'media', 'rewrite_variants', __( 'Serve generated variants', 'gt-performance' ), __( 'Rewrite eligible image URLs to generated variants.', 'gt-performance' ), $settings );
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
		$this->checkbox( 'media', 'self_host_gravatar', __( 'Self-host Gravatar images', 'gt-performance' ), __( 'Reserve local delivery for supported Gravatar responses.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->panelOpen( __( 'Fonts', 'gt-performance' ), __( 'Keep font requests predictable and reduce render blocking.', 'gt-performance' ) );
		$this->checkbox( 'fonts', 'self_host_google', __( 'Self-host Google Fonts', 'gt-performance' ), __( 'Download eligible Google Fonts stylesheets and font files to this site.', 'gt-performance' ), $settings );
		$this->checkbox( 'fonts', 'preload', __( 'Preload local fonts', 'gt-performance' ), __( 'Preload detected local font resources when supported.', 'gt-performance' ), $settings );
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
			)
		);
		$this->panelClose();

		$this->panelOpen( __( 'WordPress and database', 'gt-performance' ), __( 'Reduce background work and clean old data conservatively.', 'gt-performance' ) );
		$this->checkbox( 'database', 'enabled', __( 'Schedule database cleanup', 'gt-performance' ), __( 'Run the safe cleanup routine on the selected schedule.', 'gt-performance' ), $settings );
		$this->select(
			'database',
			'schedule',
			__( 'Cleanup schedule', 'gt-performance' ),
			__( 'Scheduled cleanup processes a bounded batch each run.', 'gt-performance' ),
			$settings,
			array(
				'daily' => __( 'Daily', 'gt-performance' ),
				'weekly' => __( 'Weekly', 'gt-performance' ),
			)
		);
		$this->number( 'database', 'retain_revisions', __( 'Revisions to retain', 'gt-performance' ), __( 'Preferred revision retention for cleanup and editor history.', 'gt-performance' ), $settings, 0, 100, __( 'revisions', 'gt-performance' ) );
		$this->checkbox( 'bloat', 'disable_emojis', __( 'Disable WordPress emoji assets', 'gt-performance' ), __( 'Remove the legacy emoji detection script and styles.', 'gt-performance' ), $settings );
		$this->checkbox( 'bloat', 'disable_embeds', __( 'Disable WordPress embeds', 'gt-performance' ), __( 'Remove oEmbed discovery and the frontend embed script.', 'gt-performance' ), $settings );
		$this->number( 'bloat', 'heartbeat_seconds', __( 'Heartbeat interval', 'gt-performance' ), __( 'Slow the admin Heartbeat API without disabling autosave locks.', 'gt-performance' ), $settings, 15, 120, __( 'seconds', 'gt-performance' ) );
		$this->number( 'bloat', 'limit_revisions', __( 'WordPress revision limit', 'gt-performance' ), __( 'Filter the number of revisions WordPress retains for each post.', 'gt-performance' ), $settings, 0, 100, __( 'revisions', 'gt-performance' ) );
		$this->panelClose();

		$this->panelOpen( __( 'Core Web Vitals', 'gt-performance' ), __( 'Collect a small, first-party sample of real-user performance measurements.', 'gt-performance' ) );
		$this->checkbox( 'rum', 'enabled', __( 'Collect sampled Web Vitals', 'gt-performance' ), __( 'Record LCP, INP, and CLS without loading a third-party analytics library.', 'gt-performance' ), $settings );
		$this->number( 'rum', 'sample_rate', __( 'Sample rate', 'gt-performance' ), __( 'A value from 0 to 1. For example, 0.05 samples about five percent of visits.', 'gt-performance' ), $settings, 0, 1, '', '0.01' );
		$this->number( 'rum', 'retention', __( 'Data retention', 'gt-performance' ), __( 'Automatically remove older field data.', 'gt-performance' ), $settings, 1, 365, __( 'days', 'gt-performance' ) );
		$this->checkboxRoot( 'debug', __( 'Diagnostic logging', 'gt-performance' ), __( 'Write redacted plugin errors to the GT Performance log directory.', 'gt-performance' ), $settings );
		$this->panelClose();

		$this->settingsFormClose();
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderExceptions( array $settings ): void {
		$this->pageIntro( __( 'Exceptions', 'gt-performance' ), __( 'Protect dynamic URLs and scripts, and preserve selectors that server-side analysis cannot discover from the initial HTML.', 'gt-performance' ) );
		$this->settingsFormOpen();

		$this->panelOpen( __( 'Cache exceptions', 'gt-performance' ), __( 'Enter one path, cookie prefix, or parameter per line. Partial cookie matches and exact query parameter names are supported.', 'gt-performance' ) );
		$this->textarea( 'cache', 'bypass_paths', __( 'Never cache paths', 'gt-performance' ), __( 'Examples: /account/ or /members/. Core WordPress paths are included by default.', 'gt-performance' ), $settings, '/account/' );
		$this->textarea( 'cache', 'bypass_cookies', __( 'Never cache cookies', 'gt-performance' ), __( 'Bypass when a request contains one of these cookie-name fragments.', 'gt-performance' ), $settings, 'membership_session_' );
		$this->textarea( 'cache', 'bypass_query_params', __( 'Never cache query parameters', 'gt-performance' ), __( 'Bypass the cache whenever one of these parameters is present.', 'gt-performance' ), $settings, 'preview' );
		$this->textarea( 'cache', 'ignored_query_params', __( 'Ignore marketing parameters', 'gt-performance' ), __( 'Remove these parameters from the origin cache key so campaign URLs share public HTML.', 'gt-performance' ), $settings, 'utm_source' );
		$this->panelClose();

		$this->panelOpen( __( 'Unused CSS exceptions', 'gt-performance' ), __( 'Use fragments, classes, IDs, or stylesheet URLs. Add the smallest stable pattern that protects the dynamic component.', 'gt-performance' ) );
		$this->textarea( 'css', 'safelist', __( 'Selector safelist', 'gt-performance' ), __( 'Always retain selectors containing these fragments, even if they are absent from initial HTML.', 'gt-performance' ), $settings, '.is-open' );
		$this->textarea( 'css', 'excluded_stylesheets', __( 'Excluded stylesheets', 'gt-performance' ), __( 'Leave matching stylesheets untouched and loaded normally.', 'gt-performance' ), $settings, '/checkout.css' );
		$this->panelClose();

		$this->panelOpen( __( 'JavaScript exceptions', 'gt-performance' ), __( 'Patterns are matched against script URLs. Transactional cart, checkout, and payment scripts are protected automatically.', 'gt-performance' ) );
		$this->textarea( 'javascript', 'exclusions', __( 'Never optimize scripts', 'gt-performance' ), __( 'Skip minify, defer, and delay for matching scripts.', 'gt-performance' ), $settings, 'interactive-widget.js' );
		$this->textarea( 'javascript', 'delay_patterns', __( 'Scripts to delay', 'gt-performance' ), __( 'Delay only matching third-party scripts when JavaScript delay is enabled.', 'gt-performance' ), $settings, 'googletagmanager.com' );
		$this->panelClose();

		$this->panelOpen( __( 'Media exceptions', 'gt-performance' ), __( 'Selectors let interactive embeds keep their normal rendering behavior.', 'gt-performance' ) );
		$this->textarea( 'media', 'lazy_render_selectors', __( 'Lazy-render selectors', 'gt-performance' ), __( 'CSS selectors for supported embeds or components that may render after interaction.', 'gt-performance' ), $settings, '.video-embed' );
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
		$this->password( 'cloudflare', 'api_token', __( 'Scoped API token', 'gt-performance' ), __( 'Leave blank to keep the encrypted token already saved.', 'gt-performance' ), ! empty( $settings['cloudflare']['api_token'] ) );
		$this->password( 'cloudflare', 'global_api_key', __( 'Global API Key', 'gt-performance' ), __( 'Requires the Cloudflare account email below. Leave blank to keep the saved key.', 'gt-performance' ), ! empty( $settings['cloudflare']['global_api_key'] ) );
		$this->text( 'cloudflare', 'email', __( 'Cloudflare account email', 'gt-performance' ), __( 'Required only for Global API Key authentication.', 'gt-performance' ), $settings, 'email' );
		$this->text( 'cloudflare', 'domain', __( 'Domain', 'gt-performance' ), __( 'Used to discover the zone automatically when Zone ID is blank.', 'gt-performance' ), $settings, 'text', 'example.com' );
		$this->text( 'cloudflare', 'zone_id', __( 'Zone ID', 'gt-performance' ), __( 'Optional. Direct Zone ID avoids the discovery request.', 'gt-performance' ), $settings );
		$this->number( 'cloudflare', 'edge_ttl', __( 'Edge TTL', 'gt-performance' ), __( 'How long eligible public HTML may remain fresh at Cloudflare.', 'gt-performance' ), $settings, 0, 31536000, __( 'seconds', 'gt-performance' ) );
		$this->panelClose();
		$this->settingsFormClose();
		?>
		<section class="gtp-panel gtp-operation-panel">
			<div>
				<h3><?php esc_html_e( 'Connect and synchronize', 'gt-performance' ); ?></h3>
				<p><?php esc_html_e( 'Save credentials first, then discover the zone if needed and reconcile the managed Cloudflare cache rule.', 'gt-performance' ); ?></p>
			</div>
			<?php $this->actionButton( 'gtp_cloudflare_sync', __( 'Connect/sync Cloudflare', 'gt-performance' ) ); ?>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function renderIntegrations( array $settings ): void {
		$this->pageIntro( __( 'Integrations', 'gt-performance' ), __( 'Automatically add cart, checkout, account, receipt, session-cookie, and transactional query exceptions for active commerce plugins.', 'gt-performance' ) );
		$this->settingsFormOpen();
		$this->panelOpen( __( 'Commerce safeguards', 'gt-performance' ), __( 'Only active integrations contribute bypass rules. Custom cache exceptions remain available separately.', 'gt-performance' ) );
		$this->checkbox( 'commerce', 'fluentcart', __( 'FluentCart', 'gt-performance' ), __( 'Protect FluentCart cart, checkout, account, order, and customer-session state.', 'gt-performance' ), $settings );
		$this->checkbox( 'commerce', 'edd', __( 'Easy Digital Downloads', 'gt-performance' ), __( 'Protect EDD checkout, purchase history, receipts, and session state.', 'gt-performance' ), $settings );
		$this->checkbox( 'commerce', 'woocommerce', __( 'WooCommerce', 'gt-performance' ), __( 'Protect WooCommerce cart, checkout, My Account, order, and session state.', 'gt-performance' ), $settings );
		$this->panelClose();
		$this->settingsFormClose();
		?>
		<section class="gtp-panel">
			<div class="gtp-integration-row">
				<div>
					<h3><?php esc_html_e( 'Core Forms', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Poll voter cookies are removed only on pages without polls. Real poll pages keep voter identity and bypass public cache.', 'gt-performance' ); ?></p>
				</div>
				<span class="gtp-status gtp-status--success"><?php esc_html_e( 'Automatic', 'gt-performance' ); ?></span>
			</div>
		</section>
		<?php
	}

	private function renderCssReports(): void {
		$repository = new ReportRepository();
		$reports    = $repository->recent();
		$summary    = $repository->summary( $reports );

		$this->pageIntro( __( 'Unused CSS reports', 'gt-performance' ), __( 'Live generation status for page-specific CSS. This screen refreshes while it is open, so processing and failures are visible.', 'gt-performance' ) );
		?>
		<section class="gtp-stat-grid gtp-report-summary" aria-label="<?php esc_attr_e( 'CSS generation summary', 'gt-performance' ); ?>">
			<?php $this->reportStat( 'processing', __( 'Processing', 'gt-performance' ), $summary['processing'], 'warning' ); ?>
			<?php $this->reportStat( 'ready', __( 'Ready', 'gt-performance' ), $summary['ready'], 'success' ); ?>
			<?php $this->reportStat( 'stale', __( 'Stale', 'gt-performance' ), $summary['stale'], 'neutral' ); ?>
			<?php $this->reportStat( 'failed', __( 'Failed', 'gt-performance' ), $summary['failed'], 'danger' ); ?>
		</section>
		<section class="gtp-panel gtp-report-panel" data-gtp-css-report>
			<div class="gtp-panel__header">
				<div>
					<h3><?php esc_html_e( 'Generated pages', 'gt-performance' ); ?></h3>
					<p><?php esc_html_e( 'Ready entries are current for the saved settings generation. Stale entries will regenerate on the next uncached visit.', 'gt-performance' ); ?></p>
				</div>
				<span class="gtp-live-indicator"><span aria-hidden="true"></span><?php esc_html_e( 'Live', 'gt-performance' ); ?></span>
			</div>
			<div class="gtp-table-wrap">
				<table class="widefat striped gtp-report-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Page', 'gt-performance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'gt-performance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Delivery', 'gt-performance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Output', 'gt-performance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Updated', 'gt-performance' ); ?></th>
						</tr>
					</thead>
					<tbody data-gtp-css-report-rows>
						<?php echo $this->reportRows( $reports ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by escaped renderer below. ?>
					</tbody>
				</table>
			</div>
			<p class="gtp-report-note" aria-live="polite" data-gtp-report-note><?php esc_html_e( 'Waiting for CSS generation activity.', 'gt-performance' ); ?></p>
		</section>
		<?php
	}

	private function renderTools(): void {
		$dropin  = ( new DropinInstaller() )->status();
		$wpCache = ( new WpCacheConstant() )->status();
		$redis   = ( new ObjectCacheInstaller() )->status();
		$cleaner = new Cleaner();
		$preview = $cleaner->preview();

		$this->pageIntro( __( 'Tools', 'gt-performance' ), __( 'Install owned drop-ins, purge caches, synchronize Cloudflare, and run bounded database maintenance.', 'gt-performance' ) );
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
				<div><dt><?php esc_html_e( 'Cache directory', 'gt-performance' ); ?></dt><dd><?php echo esc_html( is_writable( Paths::cacheRoot() ) ? __( 'Writable', 'gt-performance' ) : __( 'Not writable', 'gt-performance' ) ); ?></dd></div>
			</dl>
		</section>
		<section class="gtp-tools-grid">
			<?php $this->operation( __( 'Page cache drop-in', 'gt-performance' ), __( 'Install or refresh GT Performance advanced-cache.php and safely manage WP_CACHE.', 'gt-performance' ), 'gtp_install_dropin', __( 'Install page-cache drop-in', 'gt-performance' ) ); ?>
			<?php $this->operation( __( 'Redis object cache', 'gt-performance' ), __( 'Install the owned object-cache.php only when PhpRedis is available and no other drop-in conflicts.', 'gt-performance' ), 'gtp_install_redis', __( 'Install Redis drop-in', 'gt-performance' ) ); ?>
			<?php $this->operation( __( 'Purge GT cache', 'gt-performance' ), __( 'Remove origin HTML and generated asset cache entries managed by GT Performance.', 'gt-performance' ), 'gtp_purge', __( 'Purge GT cache', 'gt-performance' ) ); ?>
			<?php $this->operation( __( 'Cloudflare rule', 'gt-performance' ), __( 'Discover the zone when needed and reconcile the managed Cloudflare Free cache rule.', 'gt-performance' ), 'gtp_cloudflare_sync', __( 'Connect/sync Cloudflare', 'gt-performance' ) ); ?>
			<?php
			$total = array_sum( $preview );
			$this->operation(
				__( 'Database cleanup', 'gt-performance' ),
				sprintf(
					/* translators: %s: number of cleanup candidates. */
					_n( '%s current cleanup candidate.', '%s current cleanup candidates.', $total, 'gt-performance' ),
					number_format_i18n( $total )
				),
				'gtp_database_clean',
				__( 'Run database cleanup', 'gt-performance' )
			);
			?>
		</section>
		<?php
	}

	/**
	 * @param list<array<string, mixed>> $reports Reports.
	 */
	private function reportRows( array $reports ): string {
		ob_start();
		if ( ! $reports ) {
			?>
			<tr><td colspan="5"><?php esc_html_e( 'No CSS generation has run yet. Enable unused CSS and visit a public page to create the first report.', 'gt-performance' ); ?></td></tr>
			<?php
			return (string) ob_get_clean();
		}

		foreach ( $reports as $report ) {
			$metadata = is_array( $report['metadata'] ?? null ) ? $report['metadata'] : array();
			$url      = (string) ( $metadata['url'] ?? home_url( '/' ) );
			$status   = (string) ( $report['status'] ?? 'failed' );
			$bytes    = (int) ( $metadata['generated_bytes'] ?? 0 );
			$updated  = (string) ( $report['last_used_at'] ?? '' );
			?>
			<tr>
				<td>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->displayUrl( $url ) ); ?></a>
					<?php if ( ! empty( $metadata['error'] ) ) : ?>
						<span class="gtp-report-error"><?php echo esc_html( (string) $metadata['error'] ); ?></span>
					<?php elseif ( ! empty( $metadata['reason'] ) ) : ?>
						<span class="gtp-report-detail"><?php echo esc_html( (string) $metadata['reason'] ); ?></span>
					<?php endif; ?>
				</td>
				<td><span class="gtp-status gtp-status--<?php echo esc_attr( $this->statusTone( $status ) ); ?><?php echo 'processing' === $status ? ' is-processing' : ''; ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
				<td><?php echo esc_html( $this->cssModeLabel( (string) ( $report['mode'] ?? 'file' ) ) ); ?></td>
				<td>
					<?php echo $bytes > 0 ? esc_html( size_format( $bytes ) ) : '&ndash;'; ?>
					<?php if ( isset( $metadata['original_bytes'] ) && (int) $metadata['original_bytes'] > 0 ) : ?>
						<span class="gtp-report-detail"><?php echo esc_html( $this->savingsLabel( (int) $metadata['original_bytes'], $bytes ) ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo '' !== $updated ? esc_html( human_time_diff( strtotime( $updated . ' UTC' ), time() ) . ' ' . __( 'ago', 'gt-performance' ) ) : '&ndash;'; ?></td>
			</tr>
			<?php
		}

		return (string) ob_get_clean();
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
	private function checkbox( string $section, string $key, string $label, string $description, array $settings ): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field gtp-field--toggle">
			<div>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="gtp-field__control">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $settings[ $section ][ $key ] ) ); ?>>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function checkboxRoot( string $key, string $label, string $description, array $settings ): void {
		$name = Settings::OPTION . '[' . $key . ']';
		$id   = 'gtp-' . $key;
		?>
		<div class="gtp-field gtp-field--toggle">
			<div>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
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
		string $step = '1'
	): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field">
			<div>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
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
	 * @param array<string, mixed>  $settings Settings.
	 * @param array<string, string> $options Options.
	 */
	private function select( string $section, string $key, string $label, string $description, array $settings, array $options ): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field">
			<div>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
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
		string $placeholder = ''
	): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field">
			<div>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="gtp-field__control">
				<input id="<?php echo esc_attr( $id ); ?>" class="regular-text" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $settings[ $section ][ $key ] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
			</div>
		</div>
		<?php
	}

	private function password( string $section, string $key, string $label, string $description, bool $saved ): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id   = 'gtp-' . $section . '-' . $key;
		?>
		<div class="gtp-field">
			<div>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<p><?php echo esc_html( $description ); ?></p>
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
	private function textarea( string $section, string $key, string $label, string $description, array $settings, string $placeholder ): void {
		$name  = Settings::OPTION . '[' . $section . '][' . $key . ']';
		$id    = 'gtp-' . $section . '-' . $key;
		$value = implode( "\n", array_map( 'strval', (array) $settings[ $section ][ $key ] ) );
		?>
		<div class="gtp-field gtp-field--textarea">
			<div>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="gtp-field__control">
				<textarea id="<?php echo esc_attr( $id ); ?>" rows="6" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
			</div>
		</div>
		<?php
	}

	private function inlineLink( string $label, string $url ): void {
		?>
		<div class="gtp-inline-link"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?> <span aria-hidden="true">&rarr;</span></a></div>
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

	private function reportStat( string $key, string $label, int $value, string $tone ): void {
		?>
		<div class="gtp-stat gtp-stat--<?php echo esc_attr( $tone ); ?>">
			<span><?php echo esc_html( $label ); ?></span>
			<strong data-gtp-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
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

	private function redirect( string $notice, string $tab ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'tab'        => sanitize_key( $tab ),
					'gtp_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function currentTab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		return in_array( $tab, self::TABS, true ) ? $tab : 'dashboard';
	}

	private function tabUrl( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => in_array( $tab, self::TABS, true ) ? $tab : 'dashboard',
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
			'cache-purged'              => array( __( 'GT Performance cache was purged.', 'gt-performance' ), 'success' ),
			'cloudflare-synced'         => array( __( 'Cloudflare connected and the managed cache rule was synchronized.', 'gt-performance' ), 'success' ),
			'gtp_cloudflare_token'      => array( __( 'Enter a Cloudflare API token, save the settings, then connect again.', 'gt-performance' ), 'error' ),
			'gtp_cloudflare_email'      => array( __( 'Enter the Cloudflare account email used with the Global API Key.', 'gt-performance' ), 'error' ),
			'gtp_cloudflare_global_key' => array( __( 'Enter a Cloudflare Global API Key, save the settings, then connect again.', 'gt-performance' ), 'error' ),
			'gtp_cloudflare_zone'       => array( __( 'No active Cloudflare zone matched this domain. Check the domain or enter the Zone ID.', 'gt-performance' ), 'error' ),
			'gtp_cloudflare_json'       => array( __( 'Cloudflare returned an unreadable response. Try again in a moment.', 'gt-performance' ), 'error' ),
			'gtp_cloudflare_api'        => array( __( 'Cloudflare rejected the request. Check the credentials and required zone permissions.', 'gt-performance' ), 'error' ),
			'gtp_cloudflare_ruleset'    => array( __( 'Cloudflare did not return the cache ruleset needed to finish setup.', 'gt-performance' ), 'error' ),
			'gtp_dropin_conflict'       => array( __( 'Another plugin owns advanced-cache.php. Disable or migrate that cache before installing this drop-in.', 'gt-performance' ), 'warning' ),
			'gtp_dropin_directory'      => array( __( 'The WordPress content directory is not writable, so the page-cache drop-in could not be installed.', 'gt-performance' ), 'error' ),
			'gtp_dropin_write'          => array( __( 'GT Performance could not write the page-cache drop-in.', 'gt-performance' ), 'error' ),
			'gtp_dropin_move'           => array( __( 'GT Performance could not publish the page-cache drop-in safely.', 'gt-performance' ), 'error' ),
			'gtp_wp_config_read'        => array( __( 'GT Performance could not read wp-config.php.', 'gt-performance' ), 'error' ),
			'gtp_wp_cache_custom'       => array( __( 'wp-config.php contains a custom WP_CACHE declaration. Enable WP_CACHE manually, then try again.', 'gt-performance' ), 'warning' ),
			'gtp_wp_config_update'      => array( __( 'GT Performance could not add WP_CACHE to wp-config.php.', 'gt-performance' ), 'error' ),
			'gtp_wp_config_writable'    => array( __( 'wp-config.php is not writable. Add the WP_CACHE constant manually, then try again.', 'gt-performance' ), 'warning' ),
			'gtp_wp_config_write'       => array( __( 'GT Performance could not write the temporary wp-config.php update.', 'gt-performance' ), 'error' ),
			'gtp_wp_config_publish'     => array( __( 'GT Performance could not publish the wp-config.php update safely.', 'gt-performance' ), 'error' ),
			'gtp_redis_extension'       => array( __( 'The PHP Redis extension is not installed on this server.', 'gt-performance' ), 'warning' ),
			'gtp_redis_conflict'        => array( __( 'Another plugin owns object-cache.php. Remove that conflict before installing the Redis drop-in.', 'gt-performance' ), 'warning' ),
			'gtp_redis_install'         => array( __( 'GT Performance could not install the Redis object-cache drop-in.', 'gt-performance' ), 'error' ),
		);

		if ( isset( $notices[ $notice ] ) ) {
			return array(
				'message' => $notices[ $notice ][0],
				'type'    => $notices[ $notice ][1],
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

	private function statusTone( string $status ): string {
		return match ( $status ) {
			'ready' => 'success',
			'processing' => 'warning',
			'failed' => 'danger',
			default => 'neutral',
		};
	}

	private function displayUrl( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return $host . ( '' === $path ? '/' : $path );
	}

	private function savingsLabel( int $original, int $generated ): string {
		if ( $original <= 0 || $generated <= 0 || $generated >= $original ) {
			return '';
		}

		$percent = (int) round( ( 1 - $generated / $original ) * 100 );

		return sprintf(
			/* translators: %d: percentage smaller than source CSS. */
			__( '%d%% smaller', 'gt-performance' ),
			$percent
		);
	}
}
