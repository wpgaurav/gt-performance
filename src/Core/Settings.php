<?php
/**
 * Versioned plugin settings.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class Settings {
	public const OPTION = 'gt_performance_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'generation' => 1,
			'cache'      => array(
				'enabled'              => true,
				'fresh_ttl'            => 3600,
				'stale_ttl'            => 86400,
				'browser_ttl'          => 300,
				'stale_if_error'       => 86400,
				'ignored_query_params' => array(
					'fbclid',
					'gclid',
					'gbraid',
					'msclkid',
					'utm_campaign',
					'utm_content',
					'utm_medium',
					'utm_source',
					'utm_term',
				),
				'bypass_query_params'  => array(
					'add-to-cart',
					'customize_changeset_uuid',
					'elementor-preview',
					'fluent-cart',
					'preview',
					's',
					'wc-ajax',
				),
				'bypass_paths'         => array(
					'/wp-admin/',
					'/wp-login.php',
					'/wp-cron.php',
					'/wp-json/',
					'/xmlrpc.php',
				),
				'bypass_cookies'       => array(
					'comment_author_',
					'wordpress_logged_in_',
					'wordpress_no_cache',
					'wp-postpass_',
				),
				'separate_mobile'      => false,
				'cache_logged_in'      => false,
			),
			'cloudflare' => array(
				'enabled'            => false,
				'auth_mode'          => 'token',
				'zone_id'            => '',
				'domain'             => '',
				'api_token'          => '',
				'global_api_key'     => '',
				'email'              => '',
				'edge_ttl'           => 86400,
				'managed_rule_id'    => '',
				'managed_ruleset_id' => '',
				'drift_hash'         => '',
			),
			'css'        => array(
				'enabled'              => false,
				'mode'                 => 'file',
				'critical_budget'      => 14336,
				'keep_dynamic_states'  => true,
				'safelist'             => array(),
				'excluded_stylesheets' => array(),
			),
			'javascript' => array(
				'minify'         => false,
				'defer'          => false,
				'delay'          => false,
				'delay_patterns' => array(
					'clarity.ms',
					'connect.facebook.net',
					'google-analytics.com',
					'googletagmanager.com',
					'hotjar.com',
				),
				'exclusions'     => array(),
			),
			'media'      => array(
				'lazy_load'             => true,
				'add_dimensions'        => true,
				'critical_images'       => 2,
				'optimize_uploads'      => false,
				'rewrite_variants'      => false,
				'format'                => 'webp',
				'compression'           => 82,
				'youtube_previews'      => false,
				'self_host_gravatar'    => false,
				'lazy_render_selectors' => array(),
			),
			'fonts'      => array(
				'self_host_google' => false,
				'preload'          => true,
				'font_display'     => 'swap',
			),
			'database'   => array(
				'enabled'          => false,
				'schedule'         => 'weekly',
				'retain_revisions' => 5,
				'tasks'            => array(
					'revisions',
					'auto_drafts',
					'spam_comments',
					'trashed_posts',
					'trashed_comments',
					'expired_transients',
					'optimize_tables',
				),
			),
			'bloat'      => array(
				'disable_emojis'                 => false,
				'disable_dashicons'              => false,
				'disable_embeds'                 => false,
				'disable_xmlrpc'                 => false,
				'remove_rsd_link'                => false,
				'remove_jquery_migrate'          => false,
				'hide_wp_version'                => false,
				'remove_shortlink'               => false,
				'disable_rss_feeds'              => false,
				'remove_feed_links'              => false,
				'disable_self_pingbacks'         => false,
				'disable_rest_api'               => 'default',
				'remove_rest_api_links'          => false,
				'disable_google_maps'            => false,
				'google_maps_exclusions'         => array(),
				'disable_password_strength_meter' => false,
				'disable_comments'               => false,
				'remove_comment_urls'            => false,
				'blank_favicon'                  => false,
				'remove_global_styles'           => false,
				'separate_block_styles'          => false,
				'heartbeat_mode'                 => 'reduce',
				'heartbeat_seconds'              => 60,
				'limit_revisions'                => 5,
				'autosave_interval'              => 60,
			),
			'redis'      => array(
				'enabled' => false,
			),
			'commerce'   => array(
				'fluentcart'  => true,
				'edd'         => true,
				'woocommerce' => true,
			),
			'debug'      => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$all   = self::merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		unset( $all['rum'] );

		return $all;
	}

	public static function get( string $path, mixed $fallback = null ): mixed {
		$value = self::all();

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $fallback;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $input Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$current  = self::all();
		$merged   = self::merge( $current, $input );

		$merged['generation'] = max( 1, (int) $current['generation'] + 1 );
		$merged['debug']      = (bool) ( $merged['debug'] ?? false );

		foreach ( array( 'fresh_ttl', 'stale_ttl', 'browser_ttl', 'stale_if_error' ) as $key ) {
			$merged['cache'][ $key ] = max( 0, (int) ( $merged['cache'][ $key ] ?? $defaults['cache'][ $key ] ) );
		}

		$merged['cache']['enabled']         = (bool) ( $merged['cache']['enabled'] ?? false );
		$merged['cache']['separate_mobile'] = (bool) ( $merged['cache']['separate_mobile'] ?? false );
		$merged['cache']['cache_logged_in'] = (bool) ( $merged['cache']['cache_logged_in'] ?? false );

		$authMode                         = (string) ( $merged['cloudflare']['auth_mode'] ?? 'token' );
		$merged['cloudflare']['auth_mode'] = in_array( $authMode, array( 'token', 'global' ), true ) ? $authMode : 'token';
		$merged['cloudflare']['email']     = sanitize_email( (string) ( $merged['cloudflare']['email'] ?? '' ) );
		$merged['cloudflare']['domain']    = self::sanitizeDomain( (string) ( $merged['cloudflare']['domain'] ?? '' ) );
		$merged['cloudflare']['zone_id']   = sanitize_text_field( (string) ( $merged['cloudflare']['zone_id'] ?? '' ) );
		$merged['cloudflare']['edge_ttl']  = max( 0, min( 31536000, (int) ( $merged['cloudflare']['edge_ttl'] ?? 86400 ) ) );

		foreach ( array( 'ignored_query_params', 'bypass_query_params', 'bypass_paths', 'bypass_cookies' ) as $key ) {
			$merged['cache'][ $key ] = self::sanitizeList( $merged['cache'][ $key ] ?? array() );
		}

		$mode                             = (string) ( $merged['css']['mode'] ?? 'file' );
		$merged['css']['mode']            = in_array( $mode, array( 'file', 'inline', 'hybrid' ), true ) ? $mode : 'file';
		$merged['css']['critical_budget'] = max( 2048, min( 51200, (int) ( $merged['css']['critical_budget'] ?? 14336 ) ) );

		$format                     = (string) ( $merged['media']['format'] ?? 'webp' );
		$merged['media']['format'] = in_array( $format, array( 'webp', 'avif' ), true ) ? $format : 'webp';

		$fontDisplay                     = (string) ( $merged['fonts']['font_display'] ?? 'swap' );
		$merged['fonts']['font_display'] = in_array( $fontDisplay, array( 'swap', 'fallback', 'optional', 'block' ), true ) ? $fontDisplay : 'swap';

		$schedule                        = (string) ( $merged['database']['schedule'] ?? 'weekly' );
		$merged['database']['schedule'] = in_array( $schedule, array( 'daily', 'weekly', 'monthly' ), true ) ? $schedule : 'weekly';
		$merged['database']['tasks']    = array_values(
			array_intersect(
				self::sanitizeList( $merged['database']['tasks'] ?? array() ),
				array(
					'revisions',
					'auto_drafts',
					'spam_comments',
					'trashed_posts',
					'trashed_comments',
					'expired_transients',
					'all_transients',
					'optimize_tables',
				)
			)
		);

		$restMode                            = (string) ( $merged['bloat']['disable_rest_api'] ?? 'default' );
		$merged['bloat']['disable_rest_api'] = in_array( $restMode, array( 'default', 'non_admin', 'disabled' ), true ) ? $restMode : 'default';
		$heartbeatMode                       = (string) ( $merged['bloat']['heartbeat_mode'] ?? 'reduce' );
		$merged['bloat']['heartbeat_mode']   = in_array( $heartbeatMode, array( 'default', 'reduce', 'disable_dashboard', 'disabled' ), true ) ? $heartbeatMode : 'reduce';

		$booleanPaths = array(
			array( 'cloudflare', 'enabled' ),
			array( 'css', 'enabled' ),
			array( 'css', 'keep_dynamic_states' ),
			array( 'javascript', 'minify' ),
			array( 'javascript', 'defer' ),
			array( 'javascript', 'delay' ),
			array( 'media', 'lazy_load' ),
			array( 'media', 'add_dimensions' ),
			array( 'media', 'optimize_uploads' ),
			array( 'media', 'rewrite_variants' ),
			array( 'media', 'youtube_previews' ),
			array( 'media', 'self_host_gravatar' ),
			array( 'fonts', 'self_host_google' ),
			array( 'fonts', 'preload' ),
			array( 'database', 'enabled' ),
			array( 'bloat', 'disable_emojis' ),
			array( 'bloat', 'disable_dashicons' ),
			array( 'bloat', 'disable_embeds' ),
			array( 'bloat', 'disable_xmlrpc' ),
			array( 'bloat', 'remove_rsd_link' ),
			array( 'bloat', 'remove_jquery_migrate' ),
			array( 'bloat', 'hide_wp_version' ),
			array( 'bloat', 'remove_shortlink' ),
			array( 'bloat', 'disable_rss_feeds' ),
			array( 'bloat', 'remove_feed_links' ),
			array( 'bloat', 'disable_self_pingbacks' ),
			array( 'bloat', 'remove_rest_api_links' ),
			array( 'bloat', 'disable_google_maps' ),
			array( 'bloat', 'disable_password_strength_meter' ),
			array( 'bloat', 'disable_comments' ),
			array( 'bloat', 'remove_comment_urls' ),
			array( 'bloat', 'blank_favicon' ),
			array( 'bloat', 'remove_global_styles' ),
			array( 'bloat', 'separate_block_styles' ),
			array( 'redis', 'enabled' ),
			array( 'commerce', 'fluentcart' ),
			array( 'commerce', 'edd' ),
			array( 'commerce', 'woocommerce' ),
		);
		foreach ( $booleanPaths as $path ) {
			$merged[ $path[0] ][ $path[1] ] = (bool) ( $merged[ $path[0] ][ $path[1] ] ?? false );
		}

		$merged['media']['compression']           = max( 30, min( 100, (int) ( $merged['media']['compression'] ?? 82 ) ) );
		$merged['media']['critical_images']       = max( 0, min( 10, (int) ( $merged['media']['critical_images'] ?? 2 ) ) );
		$merged['database']['retain_revisions']   = max( 0, min( 100, (int) ( $merged['database']['retain_revisions'] ?? 5 ) ) );
		$merged['bloat']['heartbeat_seconds']     = max( 15, min( 120, (int) ( $merged['bloat']['heartbeat_seconds'] ?? 60 ) ) );
		$merged['bloat']['limit_revisions']       = max( 0, min( 100, (int) ( $merged['bloat']['limit_revisions'] ?? 5 ) ) );
		$merged['bloat']['autosave_interval']      = max( 15, min( 3600, (int) ( $merged['bloat']['autosave_interval'] ?? 60 ) ) );
		$merged['bloat']['google_maps_exclusions'] = self::sanitizeList( $merged['bloat']['google_maps_exclusions'] ?? array() );
		$merged['media']['lazy_render_selectors'] = self::sanitizeList( $merged['media']['lazy_render_selectors'] ?? array() );
		$merged['javascript']['exclusions']       = self::sanitizeList( $merged['javascript']['exclusions'] ?? array() );
		$merged['javascript']['delay_patterns']   = self::sanitizeList( $merged['javascript']['delay_patterns'] ?? array() );
		$merged['css']['safelist']                = self::sanitizeList( $merged['css']['safelist'] ?? array() );
		$merged['css']['excluded_stylesheets']    = self::sanitizeList( $merged['css']['excluded_stylesheets'] ?? array() );
		unset( $merged['rum'] );

		return self::merge( $defaults, $merged );
	}

	/**
	 * @param array<string, mixed> $settings Settings to persist.
	 */
	public static function save( array $settings ): bool {
		$clean = self::sanitize( $settings );
		$saved = update_option( self::OPTION, $clean, false );
		self::compile( $clean );

		return $saved;
	}

	/**
	 * @param array<string, mixed>|null $settings Settings to compile.
	 */
	public static function compile( ?array $settings = null ): bool {
		$settings = $settings ?? self::all();
		$config   = array(
			'generation' => (int) $settings['generation'],
			'cache'      => $settings['cache'],
			'debug'      => (bool) $settings['debug'],
		);
		$config   = apply_filters( 'gt_performance_compiled_config', $config, $settings );

		foreach ( Paths::writableDirectories() as $directory ) {
			if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
				return false;
			}
		}

		$content = "<?php\n// Generated by GT Performance. Do not edit.\nreturn " . var_export( $config, true ) . ";\n";
		$temp    = Paths::config() . '.' . wp_generate_uuid4() . '.tmp';

		if ( false === file_put_contents( $temp, $content, LOCK_EX ) ) {
			return false;
		}

		if ( ! rename( $temp, Paths::config() ) ) {
			@unlink( $temp );
			return false;
		}

		@chmod( Paths::config(), 0640 );

		return true;
	}

	/**
	 * @param array<string, mixed> $defaults Defaults.
	 * @param array<string, mixed> $values Values.
	 * @return array<string, mixed>
	 */
	private static function merge( array $defaults, array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
				continue;
			}

			$defaults[ $key ] = $value;
		}

		return $defaults;
	}

	/**
	 * @param mixed $value Raw list.
	 * @return list<string>
	 */
	private static function sanitizeList( mixed $value ): array {
		if ( is_string( $value ) ) {
			$parts = preg_split( '/[\\r\\n,]+/', $value );
			$value = false === $parts ? array() : $parts;
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private static function sanitizeDomain( string $value ): string {
		$value = trim( strtolower( sanitize_text_field( $value ) ) );
		if ( '' === $value ) {
			return '';
		}

		$url  = str_contains( $value, '://' ) ? $value : 'https://' . $value;
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return trim( strtolower( is_string( $host ) ? $host : '' ), ". \t\n\r\0\x0B" );
	}
}
