<?php
/**
 * Native, accessible GT Performance administration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Admin;

use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\WpCacheConstant;
use GTPerformance\Cloudflare\ApiClient;
use GTPerformance\Cloudflare\RuleManager;
use GTPerformance\Cloudflare\TokenCipher;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Database\Cleaner;
use GTPerformance\Redis\ObjectCacheInstaller;

final class AdminModule implements Module {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'afterSettingsUpdate' ), 10, 2 );
		add_action( 'admin_post_gtp_install_dropin', array( $this, 'installDropin' ) );
		add_action( 'admin_post_gtp_install_redis', array( $this, 'installRedis' ) );
		add_action( 'admin_post_gtp_purge', array( $this, 'purge' ) );
		add_action( 'admin_post_gtp_cloudflare_sync', array( $this, 'cloudflareSync' ) );
		add_action( 'admin_post_gtp_database_clean', array( $this, 'databaseClean' ) );
	}

	public function menu(): void {
		add_options_page(
			__( 'GT Performance', 'gt-performance' ),
			__( 'GT Performance', 'gt-performance' ),
			'manage_options',
			'gt-performance',
			array( $this, 'render' )
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
	 * @param mixed $input Submitted settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = Settings::all();
		$token   = trim( (string) ( $input['cloudflare']['api_token'] ?? '' ) );

		if ( '' === $token ) {
			$input['cloudflare']['api_token'] = (string) $current['cloudflare']['api_token'];
		} elseif ( ! str_starts_with( $token, 'sodium:' ) && ! str_starts_with( $token, 'openssl:' ) ) {
			$input['cloudflare']['api_token'] = ( new TokenCipher() )->encrypt( $token );
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
		$dropin   = ( new DropinInstaller() )->status();
		$wpCache  = ( new WpCacheConstant() )->status();
		$redis    = ( new ObjectCacheInstaller() )->status();
		$notice   = isset( $_GET['gtp_notice'] ) ? sanitize_key( wp_unslash( $_GET['gtp_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GT Performance', 'gt-performance' ); ?></h1>
			<p><?php esc_html_e( 'Safe origin caching, server-side optimization, Cloudflare Free orchestration, and commerce-aware bypasses.', 'gt-performance' ); ?></p>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( str_replace( '-', ' ', $notice ) ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width:900px;margin:1rem 0">
				<tbody>
					<tr><th><?php esc_html_e( 'Page-cache drop-in', 'gt-performance' ); ?></th><td><?php echo esc_html( $dropin ); ?></td></tr>
					<tr><th><?php esc_html_e( 'WP_CACHE', 'gt-performance' ); ?></th><td><?php echo esc_html( $wpCache ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Redis drop-in', 'gt-performance' ); ?></th><td><?php echo esc_html( $redis ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Cache directory', 'gt-performance' ); ?></th><td><?php echo esc_html( is_writable( Paths::cacheRoot() ) ? 'writable' : 'not writable' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Cloudflare', 'gt-performance' ); ?></th><td><?php echo esc_html( $settings['cloudflare']['enabled'] ? 'enabled' : 'disabled' ); ?></td></tr>
				</tbody>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( 'gt_performance' ); ?>
				<h2><?php esc_html_e( 'Caching and integrations', 'gt-performance' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( 'cache', 'enabled', __( 'Enable origin page cache', 'gt-performance' ), $settings );
					$this->number( 'cache', 'fresh_ttl', __( 'Fresh TTL (seconds)', 'gt-performance' ), $settings, 0, 604800 );
					$this->number( 'cache', 'stale_ttl', __( 'Stale retention (seconds)', 'gt-performance' ), $settings, 0, 2592000 );
					$this->checkbox( 'commerce', 'fluentcart', __( 'FluentCart safeguards', 'gt-performance' ), $settings );
					$this->checkbox( 'commerce', 'edd', __( 'Easy Digital Downloads safeguards', 'gt-performance' ), $settings );
					$this->checkbox( 'commerce', 'woocommerce', __( 'WooCommerce safeguards', 'gt-performance' ), $settings );
					?>
				</table>

				<h2><?php esc_html_e( 'Server-side optimization', 'gt-performance' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( 'css', 'enabled', __( 'Remove unused CSS', 'gt-performance' ), $settings );
					$this->select(
						'css',
						'mode',
						__( 'CSS delivery', 'gt-performance' ),
						$settings,
						array(
							'file'   => 'File',
							'inline' => 'Inline',
							'hybrid' => 'Critical inline + remaining file',
						)
					);
					$this->checkbox( 'javascript', 'defer', __( 'Defer safe JavaScript', 'gt-performance' ), $settings );
					$this->checkbox( 'media', 'lazy_load', __( 'Lazy-load non-critical images', 'gt-performance' ), $settings );
					$this->checkbox( 'media', 'add_dimensions', __( 'Add missing image dimensions', 'gt-performance' ), $settings );
					$this->checkbox( 'media', 'optimize_uploads', __( 'Generate optimized image variants', 'gt-performance' ), $settings );
					$this->checkbox( 'media', 'rewrite_variants', __( 'Serve generated variants', 'gt-performance' ), $settings );
					$this->checkbox( 'media', 'youtube_previews', __( 'Use lightweight YouTube previews', 'gt-performance' ), $settings );
					$this->checkbox( 'fonts', 'self_host_google', __( 'Self-host Google Fonts', 'gt-performance' ), $settings );
					$this->checkbox( 'database', 'enabled', __( 'Schedule database cleanup', 'gt-performance' ), $settings );
					$this->checkbox( 'rum', 'enabled', __( 'Collect sampled Core Web Vitals', 'gt-performance' ), $settings );
					?>
				</table>

				<h2><?php esc_html_e( 'Cloudflare Free', 'gt-performance' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $this->checkbox( 'cloudflare', 'enabled', __( 'Enable Cloudflare integration', 'gt-performance' ), $settings ); ?>
					<tr>
						<th><label for="gtp-cf-token"><?php esc_html_e( 'API token', 'gt-performance' ); ?></label></th>
						<td><input id="gtp-cf-token" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( Settings::OPTION ); ?>[cloudflare][api_token]" value="" placeholder="<?php echo esc_attr( $settings['cloudflare']['api_token'] ? 'Saved; leave blank to keep' : '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="gtp-cf-zone"><?php esc_html_e( 'Zone ID', 'gt-performance' ); ?></label></th>
						<td><input id="gtp-cf-zone" class="regular-text" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[cloudflare][zone_id]" value="<?php echo esc_attr( (string) $settings['cloudflare']['zone_id'] ); ?>"></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'gt-performance' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Operations', 'gt-performance' ); ?></h2>
			<div style="display:flex;gap:.75rem;flex-wrap:wrap">
				<?php $this->actionButton( 'gtp_install_dropin', __( 'Install page-cache drop-in', 'gt-performance' ) ); ?>
				<?php $this->actionButton( 'gtp_install_redis', __( 'Install Redis drop-in', 'gt-performance' ) ); ?>
				<?php $this->actionButton( 'gtp_purge', __( 'Purge GT cache', 'gt-performance' ) ); ?>
				<?php $this->actionButton( 'gtp_cloudflare_sync', __( 'Connect/sync Cloudflare', 'gt-performance' ) ); ?>
				<?php $this->actionButton( 'gtp_database_clean', __( 'Run database cleanup', 'gt-performance' ) ); ?>
			</div>
		</div>
		<?php
	}

	public function installDropin(): void {
		$this->guard( 'gtp_install_dropin' );
		$result = ( new DropinInstaller() )->install();
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'dropin-installed' );
	}

	public function installRedis(): void {
		$this->guard( 'gtp_install_redis' );
		$result = ( new ObjectCacheInstaller() )->install();
		$this->redirect( is_wp_error( $result ) ? $result->get_error_code() : 'redis-installed' );
	}

	public function purge(): void {
		$this->guard( 'gtp_purge' );
		( new Purger() )->purgeAll();
		$this->redirect( 'cache-purged' );
	}

	public function cloudflareSync(): void {
		$this->guard( 'gtp_cloudflare_sync' );
		$settings = Settings::all();
		$token    = ( new TokenCipher() )->decrypt( (string) $settings['cloudflare']['api_token'] );
		if ( '' === $token ) {
			$this->redirect( 'cloudflare-token-missing' );
		}

		$client = new ApiClient( $token );
		$zoneId = (string) $settings['cloudflare']['zone_id'];
		if ( '' === $zoneId ) {
			$zone = $client->zoneByName( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			if ( is_wp_error( $zone ) ) {
				$this->redirect( $zone->get_error_code() );
			}
			$zoneId                            = (string) ( $zone['id'] ?? '' );
			$settings['cloudflare']['zone_id'] = $zoneId;
		}

		$cache  = apply_filters( 'gt_performance_cache_policy', (array) $settings['cache'] );
		$result = ( new RuleManager( $client ) )->sync( $zoneId, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $cache );
		if ( is_wp_error( $result ) ) {
			$this->redirect( $result->get_error_code() );
		}

		$settings['cloudflare']['enabled']    = true;
		$settings['cloudflare']['drift_hash'] = hash( 'sha256', (string) wp_json_encode( $cache ) );
		Settings::save( $settings );
		$this->redirect( 'cloudflare-synced' );
	}

	public function databaseClean(): void {
		$this->guard( 'gtp_database_clean' );
		$result = ( new Cleaner() )->run();
		$this->redirect( 'cleaned-' . array_sum( $result ) );
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function checkbox( string $section, string $key, string $label, array $settings ): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $settings[ $section ][ $key ] ) ); ?>> <?php esc_html_e( 'Enabled', 'gt-performance' ); ?></label>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function number( string $section, string $key, string $label, array $settings, int $min, int $max ): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		?>
		<tr>
			<th><label for="<?php echo esc_attr( "gtp-{$section}-{$key}" ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input id="<?php echo esc_attr( "gtp-{$section}-{$key}" ); ?>" type="number" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $settings[ $section ][ $key ] ); ?>"></td>
		</tr>
		<?php
	}

	/**
	 * @param array<string, mixed>  $settings Settings.
	 * @param array<string, string> $options Options.
	 */
	private function select( string $section, string $key, string $label, array $settings, array $options ): void {
		$name = Settings::OPTION . '[' . $section . '][' . $key . ']';
		?>
		<tr>
			<th><label for="<?php echo esc_attr( "gtp-{$section}-{$key}" ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><select id="<?php echo esc_attr( "gtp-{$section}-{$key}" ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( $options as $value => $text ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $settings[ $section ][ $key ], $value ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select></td>
		</tr>
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

	private function redirect( string $notice ): never {
		wp_safe_redirect( add_query_arg( 'gtp_notice', sanitize_key( $notice ), admin_url( 'options-general.php?page=gt-performance' ) ) );
		exit;
	}
}
