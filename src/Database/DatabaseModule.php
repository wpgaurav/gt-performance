<?php
/**
 * Database maintenance and WordPress optimization controls.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Database;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class DatabaseModule implements Module {
	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'gt_performance_database_cleanup', array( $this, 'cleanup' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settingsUpdated' ), 20, 2 );
		add_action( 'init', array( $this, 'applyBloatControls' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeueFrontendAssets' ), PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', array( $this, 'dequeueAdminAssets' ), PHP_INT_MAX );
		add_action( 'admin_menu', array( $this, 'removeCommentsMenu' ), PHP_INT_MAX );
		add_action( 'template_redirect', array( $this, 'blockFeeds' ), 1 );
		add_action( 'wp_head', array( $this, 'blankFavicon' ), 1 );

		add_filter( 'heartbeat_settings', array( $this, 'heartbeat' ) );
		add_filter( 'wp_revisions_to_keep', array( $this, 'revisions' ) );
		add_filter( 'xmlrpc_enabled', array( $this, 'xmlrpcEnabled' ) );
		add_action( 'wp_default_scripts', array( $this, 'removeJqueryMigrate' ) );
		add_filter( 'the_generator', array( $this, 'generator' ) );
		add_filter( 'script_loader_src', array( $this, 'assetVersion' ), 20 );
		add_filter( 'style_loader_src', array( $this, 'assetVersion' ), 20 );
		add_action( 'pre_ping', array( $this, 'removeSelfPingbacks' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'restAuthentication' ), 99 );
		add_filter( 'get_comment_author_url', array( $this, 'commentAuthorUrl' ) );
		add_filter( 'preprocess_comment', array( $this, 'preprocessComment' ) );
		add_filter( 'comments_open', array( $this, 'commentsOpen' ), 20 );
		add_filter( 'pings_open', array( $this, 'commentsOpen' ), 20 );
		add_filter( 'comments_array', array( $this, 'commentsArray' ), 20 );
		add_filter( 'should_load_separate_core_block_assets', array( $this, 'separateBlockStyles' ) );
	}

	public function schedule(): void {
		$enabled = (bool) Settings::get( 'database.enabled', false );
		$saved   = (string) Settings::get( 'database.schedule', 'weekly' );
		$target  = match ( $saved ) {
			'daily' => 'daily',
			'monthly' => 'gtperf_monthly',
			default => 'gtperf_weekly',
		};
		$event   = wp_get_scheduled_event( 'gt_performance_database_cleanup' );

		if ( ! $enabled ) {
			if ( false !== $event ) {
				wp_clear_scheduled_hook( 'gt_performance_database_cleanup' );
			}
			return;
		}

		if ( false !== $event && $target !== $event->schedule ) {
			wp_clear_scheduled_hook( 'gt_performance_database_cleanup' );
			$event = false;
		}

		if ( false === $event ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $target, 'gt_performance_database_cleanup' );
		}
	}

	/**
	 * @param mixed $old Old settings.
	 * @param mixed $new New settings.
	 */
	public function settingsUpdated( mixed $old, mixed $new ): void {
		unset( $old, $new );
		$this->schedule();
	}

	public function cleanup(): void {
		if ( (bool) Settings::get( 'database.enabled', false ) ) {
			( new Cleaner() )->run( null, true );
		}
	}

	public function applyBloatControls(): void {
		if ( (bool) Settings::get( 'bloat.disable_emojis', false ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		}

		if ( (bool) Settings::get( 'bloat.disable_embeds', false ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
		}

		if ( (bool) Settings::get( 'bloat.remove_rsd_link', false ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( (bool) Settings::get( 'bloat.remove_shortlink', false ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		$feedPolicy      = new FeedPolicy();
		$removeAllLinks  = (bool) Settings::get( 'bloat.remove_feed_links', false );
		$removeExtraOnly = (bool) Settings::get( 'bloat.remove_secondary_feed_links', false );

		if ( $feedPolicy->removesAllLinks( $removeAllLinks ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		} elseif ( $feedPolicy->removesSecondaryLinksOnly( $removeAllLinks, $removeExtraOnly ) ) {
			// Keep the main site feed discoverable and drop the comment, term, author,
			// and post type discovery links that feed_links_extra() prints.
			remove_action( 'wp_head', 'feed_links_extra', 3 );
			add_filter( 'feed_links_show_comments_feed', '__return_false' );
		}

		if ( (bool) Settings::get( 'bloat.remove_rest_api_links', false ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
			remove_action( 'xmlrpc_rsd_apis', 'rest_output_rsd' );
		}

		if ( (bool) Settings::get( 'bloat.disable_comments', false ) ) {
			foreach ( get_post_types( array(), 'names' ) as $postType ) {
				if ( post_type_supports( $postType, 'comments' ) ) {
					remove_post_type_support( $postType, 'comments' );
					remove_post_type_support( $postType, 'trackbacks' );
				}
			}
		}

		$autosave = (int) Settings::get( 'bloat.autosave_interval', 60 );
		if ( ! defined( 'AUTOSAVE_INTERVAL' ) && 60 !== $autosave ) {
			define( 'AUTOSAVE_INTERVAL', $autosave );
		}
	}

	public function dequeueFrontendAssets(): void {
		if ( (bool) Settings::get( 'bloat.disable_dashicons', false ) && ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
			wp_deregister_style( 'dashicons' );
		}

		if ( (bool) Settings::get( 'bloat.remove_global_styles', false ) ) {
			foreach ( array( 'global-styles', 'classic-theme-styles' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
		}

		if ( (bool) Settings::get( 'bloat.disable_password_strength_meter', false ) ) {
			wp_dequeue_script( 'password-strength-meter' );
			wp_dequeue_script( 'zxcvbn-async' );
		}

		if ( (bool) Settings::get( 'bloat.disable_google_maps', false ) && ! $this->googleMapsExcluded() ) {
			$this->dequeueMatchingScripts( array( 'maps.googleapis.com', 'maps.google.com' ) );
		}

		if ( 'disabled' === Settings::get( 'bloat.heartbeat_mode', 'reduce' ) ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	public function dequeueAdminAssets(): void {
		$mode = (string) Settings::get( 'bloat.heartbeat_mode', 'reduce' );
		if ( 'disabled' === $mode || ( 'disable_dashboard' === $mode && ! $this->isEditorScreen() ) ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	public function removeCommentsMenu(): void {
		if ( (bool) Settings::get( 'bloat.disable_comments', false ) ) {
			remove_menu_page( 'edit-comments.php' );
		}
	}

	public function blockFeeds(): void {
		if ( ! is_feed() ) {
			return;
		}

		$policy = new FeedPolicy();
		$main   = $policy->isMainFeed( is_comment_feed(), is_singular(), is_archive(), is_search() );

		$blocked = $policy->blocksFeed(
			(bool) Settings::get( 'bloat.disable_rss_feeds', false ),
			(bool) Settings::get( 'bloat.disable_secondary_feeds', false ),
			$main
		);

		if ( ! $blocked ) {
			return;
		}

		wp_die(
			esc_html__( 'This feed is disabled.', 'gt-performance' ),
			esc_html__( 'Feed unavailable', 'gt-performance' ),
			array( 'response' => 404 )
		);
	}

	public function blankFavicon(): void {
		if ( (bool) Settings::get( 'bloat.blank_favicon', false ) && ! has_site_icon() ) {
			echo '<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 1 1%22></svg>">' . "\n";
		}
	}

	/**
	 * @param array<string, mixed> $settings Heartbeat settings.
	 * @return array<string, mixed>
	 */
	public function heartbeat( array $settings ): array {
		if ( 'reduce' === Settings::get( 'bloat.heartbeat_mode', 'reduce' ) ) {
			$settings['interval'] = max( 15, min( 120, (int) Settings::get( 'bloat.heartbeat_seconds', 60 ) ) );
		}

		return $settings;
	}

	public function revisions( int $number ): int {
		return max( 0, (int) Settings::get( 'bloat.limit_revisions', $number ) );
	}

	public function xmlrpcEnabled( bool $enabled ): bool {
		return (bool) Settings::get( 'bloat.disable_xmlrpc', false ) ? false : $enabled;
	}

	public function removeJqueryMigrate( \WP_Scripts $scripts ): void {
		if ( ! (bool) Settings::get( 'bloat.remove_jquery_migrate', false ) || is_admin() || ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}

		$jquery       = $scripts->registered['jquery'];
		$jquery->deps = array_values( array_diff( (array) $jquery->deps, array( 'jquery-migrate' ) ) );
	}

	public function generator( string $generator ): string {
		return (bool) Settings::get( 'bloat.hide_wp_version', false ) ? '' : $generator;
	}

	public function assetVersion( string $src ): string {
		if ( ! (bool) Settings::get( 'bloat.hide_wp_version', false ) ) {
			return $src;
		}

		$query = (string) wp_parse_url( $src, PHP_URL_QUERY );
		parse_str( $query, $params );
		if ( isset( $params['ver'] ) && get_bloginfo( 'version' ) === (string) $params['ver'] ) {
			// Replace the version instead of dropping it. An unversioned URL never changes
			// across a core update, so long-lived browser and CDN caches keep serving the
			// pre-update copy of every core script and stylesheet.
			return add_query_arg( 'ver', self::obfuscatedVersion( (string) $params['ver'] ), $src );
		}

		return $src;
	}

	/**
	 * Site-specific, stable stand-in for a core version string.
	 */
	private static function obfuscatedVersion( string $version ): string {
		return substr( wp_hash( 'gtp-asset-version|' . $version ), 0, 12 );
	}

	/**
	 * @param list<string> $links URLs to ping.
	 */
	public function removeSelfPingbacks( array &$links ): void {
		if ( ! (bool) Settings::get( 'bloat.disable_self_pingbacks', false ) ) {
			return;
		}

		$host  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$links = array_values(
			array_filter(
				$links,
				static fn( string $link ): bool => strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) ) !== $host
			)
		);
	}

	public function restAuthentication( mixed $result ): mixed {
		if ( null !== $result ) {
			return $result;
		}

		$mode = (string) Settings::get( 'bloat.disable_rest_api', 'default' );
		if ( 'default' === $mode || ( 'non_admin' === $mode && current_user_can( 'manage_options' ) ) ) {
			return $result;
		}

		if ( 'non_admin' === $mode || 'disabled' === $mode ) {
			return new \WP_Error(
				'gtperf_rest_disabled',
				__( 'The REST API is disabled by GT Performance.', 'gt-performance' ),
				array( 'status' => 403 )
			);
		}

		return $result;
	}

	public function commentAuthorUrl( string $url ): string {
		return (bool) Settings::get( 'bloat.remove_comment_urls', false ) ? '' : $url;
	}

	/**
	 * @param array<string, mixed> $comment Comment data.
	 * @return array<string, mixed>
	 */
	public function preprocessComment( array $comment ): array {
		if ( (bool) Settings::get( 'bloat.remove_comment_urls', false ) ) {
			$comment['comment_author_url'] = '';
		}

		return $comment;
	}

	public function commentsOpen( bool $open ): bool {
		return (bool) Settings::get( 'bloat.disable_comments', false ) ? false : $open;
	}

	/**
	 * @param array<int, \WP_Comment> $comments Comments.
	 * @return array<int, \WP_Comment>
	 */
	public function commentsArray( array $comments ): array {
		return (bool) Settings::get( 'bloat.disable_comments', false ) ? array() : $comments;
	}

	public function separateBlockStyles( bool $enabled ): bool {
		return (bool) Settings::get( 'bloat.separate_block_styles', false ) ? true : $enabled;
	}

	private function googleMapsExcluded(): bool {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		foreach ( (array) Settings::get( 'bloat.google_maps_exclusions', array() ) as $exclusion ) {
			if ( '' !== (string) $exclusion && str_contains( $path, (string) $exclusion ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $patterns URL fragments.
	 */
	private function dequeueMatchingScripts( array $patterns ): void {
		$scripts = wp_scripts();
		foreach ( $scripts->registered as $handle => $script ) {
			$src = (string) $script->src;
			foreach ( $patterns as $pattern ) {
				if ( str_contains( $src, $pattern ) ) {
					wp_dequeue_script( (string) $handle );
					wp_deregister_script( (string) $handle );
					break;
				}
			}
		}
	}

	private function isEditorScreen(): bool {
		global $pagenow;

		return in_array( $pagenow, array( 'post.php', 'post-new.php' ), true );
	}
}
