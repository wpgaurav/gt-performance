<?php
/**
 * Versioned plugin settings.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

use GTPerformance\Cache\ConfigFile;

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
				'post_publish_purge'   => 'related',
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
					'gtperf_verify',
					'gtperf_css_preview',
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
				'preload'              => true,
				'preload_max_urls'     => 200,
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
				'drift_hash'         => '',
			),
			'xcloud'     => array(
				'enabled'                  => false,
				'domain'                   => '',
				'site_uuid'                => '',
				'server_id'                => 0,
				'site_id'                  => 0,
				'api_token'                => '',
				'dashboard_url'             => '',
				'stack'                     => '',
				'page_cache_enabled'        => false,
				'page_cache_source'         => '',
				'redis_enabled'             => false,
				'object_cache_pro'          => false,
				'free_edge_cache_enabled'   => false,
				'enterprise_available'      => false,
				'enterprise_requests'       => 0,
				'enterprise_edge_requests'  => 0,
				'enterprise_hit_percent'    => 0.0,
				'checked_at'                => '',
			),
			'cdn'        => array(
				'enabled'    => false,
				'url'        => '',
				'file_types' => array(
					'css',
					'js',
					'mjs',
					'jpg',
					'jpeg',
					'png',
					'gif',
					'webp',
					'avif',
					'svg',
					'ico',
					'woff',
					'woff2',
					'ttf',
					'otf',
					'eot',
				),
			),
			'css'        => array(
				'enabled'              => false,
				'mode'                 => 'file',
				'critical_budget'      => 14336,
				'keep_dynamic_states'  => true,
				'rollout_percent'       => 100,
				'trained_selectors'     => array(),
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
				'lazy_render_selectors' => array(),
			),
			'fonts'      => array(
				'self_host_google' => false,
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
				'disable_secondary_feeds'        => false,
				'remove_feed_links'              => false,
				'remove_secondary_feed_links'    => false,
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
				'enabled'            => false,
				'host'               => '127.0.0.1',
				'port'               => 6379,
				'database'           => 0,
				'username'           => '',
				'password'           => '',
				'tls'                => false,
				'persistent'         => true,
				'prefix'             => '',
				'connection_timeout' => 0.5,
				'read_timeout'       => 0.5,
			),
			'commerce'   => array(
				'fluentcart'  => true,
				'edd'         => true,
				'woocommerce' => true,
			),
			'private_fragments' => array(
				'enabled'       => false,
				'cart_count'    => true,
				'account_link'  => true,
			),
			'fleet'      => array(
				'enabled'        => false,
				'allow_imports'  => true,
				'signing_secret' => '',
				'policy_modules' => array( 'cache', 'cloudflare', 'cdn', 'css', 'javascript', 'media', 'fonts', 'database', 'bloat', 'commerce', 'integrations', 'private_fragments' ),
			),
			'integrations' => array(
				'auto_protection'   => true,
				'perfmatters_owner' => 'automatic',
				'akismet'           => true,
				'jetpack'           => true,
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

		$merged['cache']['enabled']          = (bool) ( $merged['cache']['enabled'] ?? false );
		$merged['cache']['separate_mobile']  = (bool) ( $merged['cache']['separate_mobile'] ?? false );
		$merged['cache']['preload']          = (bool) ( $merged['cache']['preload'] ?? true );
		$merged['cache']['preload_max_urls'] = max( 0, min( 2000, (int) ( $merged['cache']['preload_max_urls'] ?? 200 ) ) );
		$merged['cache']['post_publish_purge'] = ( new \GTPerformance\Cache\PostPublishPurgePolicy() )->sanitize(
			(string) ( $merged['cache']['post_publish_purge'] ?? 'related' )
		);

		$authMode                         = (string) ( $merged['cloudflare']['auth_mode'] ?? 'token' );
		$merged['cloudflare']['auth_mode'] = in_array( $authMode, array( 'token', 'global' ), true ) ? $authMode : 'token';
		$merged['cloudflare']['email']     = sanitize_email( (string) ( $merged['cloudflare']['email'] ?? '' ) );
		$merged['cloudflare']['domain']    = self::sanitizeDomain( (string) ( $merged['cloudflare']['domain'] ?? '' ) );
		$merged['cloudflare']['zone_id']   = sanitize_text_field( (string) ( $merged['cloudflare']['zone_id'] ?? '' ) );
		$merged['cloudflare']['edge_ttl']  = max( 0, min( 31536000, (int) ( $merged['cloudflare']['edge_ttl'] ?? 86400 ) ) );
		$merged['xcloud']['domain']         = self::sanitizeDomain( (string) ( $merged['xcloud']['domain'] ?? '' ) );
		$siteUuid                           = strtolower( sanitize_text_field( (string) ( $merged['xcloud']['site_uuid'] ?? '' ) ) );
		$merged['xcloud']['site_uuid']      = preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $siteUuid ) ? $siteUuid : '';
		$merged['xcloud']['server_id']      = max( 0, (int) ( $merged['xcloud']['server_id'] ?? 0 ) );
		$merged['xcloud']['site_id']        = max( 0, (int) ( $merged['xcloud']['site_id'] ?? 0 ) );
		$merged['xcloud']['dashboard_url']  = self::sanitizeXcloudDashboardUrl( (string) ( $merged['xcloud']['dashboard_url'] ?? '' ) );
		$merged['xcloud']['stack']          = sanitize_key( (string) ( $merged['xcloud']['stack'] ?? '' ) );
		$merged['xcloud']['page_cache_source'] = sanitize_text_field( (string) ( $merged['xcloud']['page_cache_source'] ?? '' ) );
		$merged['xcloud']['enterprise_requests'] = max( 0, (int) ( $merged['xcloud']['enterprise_requests'] ?? 0 ) );
		$merged['xcloud']['enterprise_edge_requests'] = max( 0, (int) ( $merged['xcloud']['enterprise_edge_requests'] ?? 0 ) );
		$merged['xcloud']['enterprise_hit_percent'] = max( 0.0, min( 100.0, (float) ( $merged['xcloud']['enterprise_hit_percent'] ?? 0.0 ) ) );
		$merged['xcloud']['checked_at']     = sanitize_text_field( (string) ( $merged['xcloud']['checked_at'] ?? '' ) );
		$merged['cdn']['url']               = self::sanitizeCdnUrl( (string) ( $merged['cdn']['url'] ?? '' ) );
		$merged['cdn']['file_types']        = array_values(
			array_intersect(
				array_map(
					static fn( string $type ): string => strtolower( ltrim( $type, '.' ) ),
					self::sanitizeList( $merged['cdn']['file_types'] ?? array() )
				),
				self::allowedCdnFileTypes()
			)
		);

		foreach ( array( 'ignored_query_params', 'bypass_query_params', 'bypass_paths', 'bypass_cookies' ) as $key ) {
			$merged['cache'][ $key ] = self::sanitizeList( $merged['cache'][ $key ] ?? array() );
		}

		$mode                             = (string) ( $merged['css']['mode'] ?? 'file' );
		$merged['css']['mode']            = in_array( $mode, array( 'file', 'inline', 'hybrid' ), true ) ? $mode : 'file';
		$merged['css']['critical_budget'] = max( 2048, min( 51200, (int) ( $merged['css']['critical_budget'] ?? 14336 ) ) );
		$rollout                           = (int) ( $merged['css']['rollout_percent'] ?? 100 );
		$merged['css']['rollout_percent'] = in_array( $rollout, array( 0, 10, 25, 50, 100 ), true ) ? $rollout : 100;

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
		$perfmattersOwner                     = (string) ( $merged['integrations']['perfmatters_owner'] ?? 'automatic' );
		$merged['integrations']['perfmatters_owner'] = in_array( $perfmattersOwner, array( 'automatic', 'gt_performance', 'perfmatters' ), true )
			? $perfmattersOwner
			: 'automatic';

		$booleanPaths = array(
			array( 'cloudflare', 'enabled' ),
			array( 'xcloud', 'enabled' ),
			array( 'xcloud', 'page_cache_enabled' ),
			array( 'xcloud', 'redis_enabled' ),
			array( 'xcloud', 'object_cache_pro' ),
			array( 'xcloud', 'free_edge_cache_enabled' ),
			array( 'xcloud', 'enterprise_available' ),
			array( 'cdn', 'enabled' ),
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
			array( 'fonts', 'self_host_google' ),
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
			array( 'bloat', 'disable_secondary_feeds' ),
			array( 'bloat', 'remove_feed_links' ),
			array( 'bloat', 'remove_secondary_feed_links' ),
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
			array( 'redis', 'tls' ),
			array( 'redis', 'persistent' ),
			array( 'commerce', 'fluentcart' ),
			array( 'commerce', 'edd' ),
			array( 'commerce', 'woocommerce' ),
			array( 'private_fragments', 'enabled' ),
			array( 'private_fragments', 'cart_count' ),
			array( 'private_fragments', 'account_link' ),
			array( 'fleet', 'enabled' ),
			array( 'fleet', 'allow_imports' ),
			array( 'integrations', 'auto_protection' ),
			array( 'integrations', 'akismet' ),
			array( 'integrations', 'jetpack' ),
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
		$merged['redis']['host']                   = self::sanitizeRedisHost( (string) ( $merged['redis']['host'] ?? '127.0.0.1' ) );
		$merged['redis']['port']                   = max( 0, min( 65535, (int) ( $merged['redis']['port'] ?? 6379 ) ) );
		$merged['redis']['database']               = max( 0, min( 255, (int) ( $merged['redis']['database'] ?? 0 ) ) );
		$merged['redis']['username']               = sanitize_text_field( (string) ( $merged['redis']['username'] ?? '' ) );
		$merged['redis']['password']               = (string) ( $merged['redis']['password'] ?? '' );
		$merged['redis']['prefix']                 = preg_replace( '/[^a-zA-Z0-9:_-]/', '', (string) ( $merged['redis']['prefix'] ?? '' ) ) ?? '';
		$merged['redis']['connection_timeout']     = max( 0.1, min( 10.0, (float) ( $merged['redis']['connection_timeout'] ?? 0.5 ) ) );
		$merged['redis']['read_timeout']           = max( 0.1, min( 10.0, (float) ( $merged['redis']['read_timeout'] ?? 0.5 ) ) );
		$merged['bloat']['google_maps_exclusions'] = self::sanitizeList( $merged['bloat']['google_maps_exclusions'] ?? array() );
		$merged['media']['lazy_render_selectors'] = self::sanitizeList( $merged['media']['lazy_render_selectors'] ?? array() );
		$merged['javascript']['exclusions']       = self::sanitizeList( $merged['javascript']['exclusions'] ?? array() );
		$merged['javascript']['delay_patterns']   = self::sanitizeList( $merged['javascript']['delay_patterns'] ?? array() );
		$safelist                                  = \GTPerformance\Optimization\Css\SelectorSafelist::split( $merged['css']['safelist'] ?? array() );
		$safelist                                  = array_map( 'sanitize_text_field', $safelist );
		$merged['css']['safelist']                 = ( new \GTPerformance\Optimization\Css\SelectorSafelist() )->validate( $safelist )['valid'];
		$trained                                   = self::sanitizeList( $merged['css']['trained_selectors'] ?? array() );
		$merged['css']['trained_selectors']        = ( new \GTPerformance\Optimization\Css\SelectorObservation() )->sanitizeMany( $trained );
		$merged['css']['excluded_stylesheets']    = self::sanitizeList( $merged['css']['excluded_stylesheets'] ?? array() );
		$merged['fleet']['signing_secret']         = (string) ( $merged['fleet']['signing_secret'] ?? '' );
		$merged['fleet']['policy_modules']         = array_values(
			array_intersect(
				self::sanitizeList( $merged['fleet']['policy_modules'] ?? array() ),
				array_keys( $defaults )
			)
		);
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
			// The bundled advanced-cache.php drop-in carries no hard-coded paths.
			// It reads this value to locate the runtime classes it loads, so the
			// plugin keeps working from a renamed or relocated plugin directory.
			'plugin_dir' => GTPERF_DIR,
		);
		$config   = apply_filters( 'gt_performance_compiled_config', $config, $settings );

		foreach ( Paths::writableDirectories() as $directory ) {
			if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
				return false;
			}
		}

		Paths::harden();

		if ( ! self::writeConfig( Paths::config(), $config ) ) {
			return false;
		}

		$redis = ( new \GTPerformance\Redis\Configuration() )->runtime( (array) ( $settings['redis'] ?? array() ) );

		return self::writeConfig( Paths::redisConfig(), $redis );
	}

	/**
	 * @param array<string, mixed> $defaults Defaults.
	 * @param array<string, mixed> $values Values.
	 * @return array<string, mixed>
	 */
	private static function merge( array $defaults, array $values ): array {
		foreach ( $values as $key => $value ) {
			// Ignore obsolete or foreign keys. Keeping only the declared schema stops
			// removed controls from surviving forever in the saved option and prevents
			// unsupported settings from being imported through fleet policy bundles.
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			// Deep-merge associative maps (settings sections). List arrays such as
			// bypass lists and database.tasks are replaced wholesale: index-by-index
			// merging would resurrect the tail of a longer saved list whenever a
			// shorter selection is submitted, silently re-enabling deselected items.
			if (
				is_array( $defaults[ $key ] )
				&& is_array( $value )
				&& ! array_is_list( $defaults[ $key ] )
				&& ! array_is_list( $value )
			) {
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

	private static function sanitizeXcloudDashboardUrl( string $value ): string {
		$value = esc_url_raw( trim( $value ) );
		$host  = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );

		return 'https' === $scheme && 'app.xcloud.host' === $host ? $value : '';
	}

	private static function sanitizeRedisHost( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( str_starts_with( $value, '/' ) ) {
			return $value;
		}

		$value = preg_replace( '#^(?:redis|tls)://#i', '', $value ) ?? $value;

		return preg_replace( '/[^a-zA-Z0-9._:-]/', '', $value ) ?? '127.0.0.1';
	}

	/**
	 * @return list<string>
	 */
	public static function allowedCdnFileTypes(): array {
		return array(
			'css',
			'js',
			'mjs',
			'jpg',
			'jpeg',
			'png',
			'gif',
			'webp',
			'avif',
			'svg',
			'ico',
			'woff',
			'woff2',
			'ttf',
			'otf',
			'eot',
			'mp4',
			'webm',
			'mp3',
			'ogg',
			'wav',
			'pdf',
			'zip',
		);
	}

	private static function sanitizeCdnUrl( string $value ): string {
		$value = trim( esc_url_raw( $value, array( 'https' ) ) );
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return '';
		}

		$url = 'https://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$url .= ':' . (int) $parts['port'];
		}
		$path = trim( (string) ( $parts['path'] ?? '' ), '/' );

		return $url . ( '' !== $path ? '/' . $path : '' );
	}

	/**
	 * Publish a runtime configuration file atomically.
	 *
	 * The file is inert JSON behind a fixed guard, read by the drop-ins on every
	 * request and never executed. See ConfigFile for the format.
	 *
	 * @param array<string, mixed> $config Configuration values.
	 */
	private static function writeConfig( string $path, array $config ): bool {
		return ConfigFile::write( $path, $config );
	}
}
