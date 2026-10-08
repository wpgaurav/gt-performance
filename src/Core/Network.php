<?php
/**
 * WordPress multisite support.
 *
 * Every network site keeps its own settings (the option is per site already),
 * its own compiled configuration, and its own page store under
 * cache/gt-performance/sites/{blog_id}/. What is shared stays network-owned: the
 * advanced-cache.php and object-cache.php drop-ins, WP_CACHE in wp-config.php,
 * the object-cache configuration, and sites.json, the host-and-path map the
 * drop-in uses to pick a site before WordPress has loaded.
 *
 * A site's configuration is only ever compiled inside a request for that site.
 * switch_to_blog() changes the database tables but not the loaded plugins, so a
 * configuration compiled for site B from site A's request would carry site A's
 * commerce bypass rules: one store's checkout rules on another's pages. Network
 * operations therefore bump a revision, and each site recompiles itself on its
 * next request.
 *
 * Single-site installs never construct this module, and every helper falls back
 * to the single-site behavior.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

use GTPerformance\Cache\ConfigFile;
use GTPerformance\Cache\FileStore;
use GTPerformance\Contracts\Module;

final class Network implements Module {
	/** Network option: advanced whenever every site must recompile. */
	public const REVISION = 'gt_performance_network_revision';

	/** Site option: the network revision this site last compiled against. */
	public const SITE_REVISION = 'gt_performance_site_revision';

	/** Network option: settings a site inherits until it saves its own. */
	public const DEFAULTS = 'gt_performance_network_defaults';

	public const PAGE_SLUG = 'gt-performance-network';

	/**
	 * Sections a network default may set. Credentials, edge connections, the
	 * object cache (network-wide already), and per-site bookkeeping are excluded.
	 */
	public const DEFAULT_SECTIONS = array( 'cache', 'cdn', 'css', 'javascript', 'media', 'fonts', 'database', 'bloat', 'commerce', 'integrations', 'speculation', 'debug' );

	public function register(): void {
		add_action( 'init', array( self::class, 'syncSite' ), 1 );
		add_filter( 'gt_performance_cache_policy', array( self::class, 'scopePolicy' ), 1 );
		add_action( 'wp_initialize_site', array( self::class, 'siteAdded' ), 100 );
		add_action( 'wp_update_site', array( self::class, 'siteUpdated' ), 10, 2 );
		add_action( 'wp_delete_site', array( self::class, 'siteRemoved' ), 10 );
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'network_admin_edit_gtperf_network', array( $this, 'handle' ) );
	}

	/**
	 * Whether the current user, or this CLI run, may change files and settings
	 * the whole network shares.
	 */
	public static function canManageNetwork(): bool {
		if ( ! is_multisite() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}

		return current_user_can( 'manage_network_options' );
	}

	/**
	 * The path WordPress routes this site under: "/" for a subdomain or mapped
	 * domain, "/shop/" for a subfolder site.
	 */
	public static function sitePath(): string {
		if ( ! is_multisite() ) {
			return '/';
		}
		$site = get_site();

		return $site instanceof \WP_Site ? strtolower( '/' . trim( (string) $site->path, '/' ) . '/' ) : '/';
	}

	/**
	 * Tell a cache policy which path its site answers under, so bypass paths
	 * written for the site root also cover the subfolder.
	 *
	 * @param array<string, mixed> $policy Cache policy.
	 * @return array<string, mixed>
	 */
	public static function scopePolicy( array $policy ): array {
		$path = self::sitePath();
		if ( '/' !== $path ) {
			$policy['site_path'] = $path;
		}

		return $policy;
	}

	/**
	 * Option helpers for state that belongs to the whole install: who owns the
	 * drop-ins and WP_CACHE. Per-site options on a network would let a site that
	 * never installed anything decide to remove them.
	 */
	public static function getOption( string $name, mixed $fallback = false ): mixed {
		return is_multisite() ? get_site_option( $name, $fallback ) : get_option( $name, $fallback );
	}

	public static function updateOption( string $name, mixed $value ): bool {
		return is_multisite() ? update_site_option( $name, $value ) : update_option( $name, $value, false );
	}

	public static function deleteOption( string $name ): bool {
		return is_multisite() ? delete_site_option( $name ) : delete_option( $name );
	}

	/**
	 * Write sites.json: domain, path, and status of every site, plus the plugin
	 * directory the drop-in loads its runtime from.
	 */
	public static function writeSiteMap(): bool {
		if ( ! is_multisite() || ! Paths::cacheRootIsSafe() ) {
			return false;
		}
		if ( ! is_dir( Paths::cacheRoot() ) && ! wp_mkdir_p( Paths::cacheRoot() ) ) {
			return false;
		}

		$sites = array();
		foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
			if ( ! $site instanceof \WP_Site ) {
				continue;
			}
			$sites[] = array(
				'id'     => (int) $site->blog_id,
				'domain' => strtolower( (string) $site->domain ),
				'path'   => strtolower( '/' . trim( (string) $site->path, '/' ) . '/' ),
				// A suspended site still owns its path; it must not fall through to
				// the site below it and be answered from that site's store.
				'active' => ! (int) $site->archived && ! (int) $site->deleted && ! (int) $site->spam,
			);
		}

		return ConfigFile::write(
			Paths::siteMap(),
			array(
				'plugin_dir' => GTPERF_DIR,
				'sites'      => $sites,
			)
		);
	}

	/**
	 * Provision and compile this site in its own request when it is new to the
	 * plugin, or when the network asked every site to recompile.
	 */
	public static function syncSite(): void {
		if ( ! is_multisite() ) {
			return;
		}
		$want = (string) get_site_option( self::REVISION, '0' );
		$have = get_option( self::SITE_REVISION, false );
		if ( $have === $want ) {
			return;
		}

		// The first requests after network activation arrive together; one of them
		// provisions the site and the rest carry on uncached.
		if ( ! NamedLock::acquire( 'site_sync', 5 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		try {
			self::provision( $have, $want );
		} finally {
			NamedLock::release( 'site_sync' );
		}
	}

	private static function provision( mixed $have, string $want ): void {
		$inherits = false === get_option( Settings::OPTION, false );
		if ( false === $have ) {
			Activator::activateSite();
		} else {
			Settings::compile();
			// Network defaults decide what an inheriting site's pages look like, and
			// its generation never moved, so its stored pages are from the old ones.
			if ( $inherits ) {
				( new FileStore() )->purgeAll();
			}
		}
		if ( ! is_file( Paths::siteMap() ) ) {
			self::writeSiteMap();
		}
		update_option( self::SITE_REVISION, $want, true );
	}

	/** Ask every site to recompile itself on its next request. */
	public static function bumpRevision(): void {
		update_site_option( self::REVISION, (string) ( (int) get_site_option( self::REVISION, '0' ) + 1 ) );
	}

	/**
	 * Settings a site inherits until it saves its own.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		if ( ! is_multisite() ) {
			return array();
		}
		// Settings::defaults() asks on every settings read; one network value per request.
		if ( null === self::$defaults ) {
			$saved          = get_site_option( self::DEFAULTS, array() );
			self::$defaults = is_array( $saved ) ? array_intersect_key( $saved, array_flip( self::DEFAULT_SECTIONS ) ) : array();
		}

		return self::$defaults;
	}

	/** @var array<string, mixed>|null */
	private static ?array $defaults = null;

	/**
	 * Copy a site's saved settings as the network defaults, or clear them.
	 */
	public static function setDefaultsFrom( int $blogId ): bool {
		if ( $blogId <= 0 ) {
			delete_site_option( self::DEFAULTS );
			self::$defaults = null;
			self::bumpRevision();
			return true;
		}
		if ( ! get_site( $blogId ) instanceof \WP_Site ) {
			return false;
		}
		switch_to_blog( $blogId );
		try {
			$settings = Settings::all();
		} finally {
			restore_current_blog();
		}
		update_site_option( self::DEFAULTS, array_intersect_key( $settings, array_flip( self::DEFAULT_SECTIONS ) ) );
		self::$defaults = null;
		self::bumpRevision();

		return true;
	}

	/**
	 * Empty every site's page store. Origin only: edge purges stay with each site.
	 *
	 * @return int Files removed.
	 */
	public static function purgeAllSites(): int {
		$count = 0;
		foreach ( get_sites(
			array(
				'number' => 0,
				'fields' => 'ids',
			)
		) as $blogId ) {
			switch_to_blog( (int) $blogId );
			try {
				if ( is_dir( Paths::pages() ) ) {
					$count += ( new FileStore() )->purgeAll();
				}
				// LiteSpeed keeps its own copies of a site that hands pages to it.
				if ( Settings::get( 'cache.litespeed', false ) ) {
					\GTPerformance\Cache\LiteSpeedCache::purgeAll();
				}
			} finally {
				restore_current_blog();
			}
		}
		do_action( 'gt_performance_purged_network', $count );

		return $count;
	}

	public static function siteAdded(): void {
		self::writeSiteMap();
		// A new subfolder site takes its paths away from its parent's rules.
		\GTPerformance\Cache\ServerRules::sync();
	}

	/**
	 * A changed domain or path moves the site's URLs; its stored pages, keyed by
	 * the old ones, can never be read again. A status change only needs the map.
	 */
	public static function siteUpdated( mixed $new, mixed $old ): void {
		self::writeSiteMap();
		\GTPerformance\Cache\ServerRules::sync();
		if ( $new instanceof \WP_Site && $old instanceof \WP_Site && ( $new->domain !== $old->domain || $new->path !== $old->path ) ) {
			switch_to_blog( (int) $new->blog_id );
			try {
				( new FileStore() )->purgeAll();
			} finally {
				restore_current_blog();
			}
		}
	}

	public static function siteRemoved( mixed $site ): void {
		if ( $site instanceof \WP_Site ) {
			self::removeSiteDirectory( (int) $site->blog_id );
		}
		self::writeSiteMap();
		\GTPerformance\Cache\ServerRules::sync();
	}

	/**
	 * Delete one site's directory: pages, generated assets, and compiled config.
	 */
	public static function removeSiteDirectory( int $blogId ): void {
		if ( $blogId <= 0 || ! Paths::cacheRootIsSafe() ) {
			return;
		}
		$root      = realpath( Paths::sitesRoot() );
		$directory = realpath( Paths::siteRootFor( $blogId ) );
		if ( false === $root || false === $directory || dirname( $directory ) !== $root || is_link( Paths::siteRootFor( $blogId ) ) ) {
			return;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $entries as $entry ) {
			if ( $entry->isLink() || $entry->isFile() ) {
				@unlink( $entry->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent removal is expected.
			} elseif ( $entry->isDir() ) {
				@rmdir( $entry->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent removal is expected.
			}
		}
		@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent removal is expected.
	}

	public function menu(): void {
		add_menu_page(
			__( 'GT Performance', 'gt-performance' ),
			__( 'GT Performance', 'gt-performance' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-performance',
			80
		);
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage GT Performance for this network.', 'gt-performance' ) );
		}
		check_admin_referer( 'gtperf_network' );

		$notice = 'invalid';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$operation = isset( $_POST['gtperf_operation'] ) ? sanitize_key( wp_unslash( $_POST['gtperf_operation'] ) ) : '';
		if ( 'purge' === $operation ) {
			self::purgeAllSites();
			$notice = 'purged';
		} elseif ( 'defaults' === $operation ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$source = isset( $_POST['gtperf_source'] ) ? absint( wp_unslash( $_POST['gtperf_source'] ) ) : 0;
			$notice = self::setDefaultsFrom( $source ) ? ( $source > 0 ? 'defaults-saved' : 'defaults-cleared' ) : 'invalid';
		} elseif ( 'map' === $operation ) {
			$notice = self::writeSiteMap() ? 'map-written' : 'map-failed';
		} elseif ( 'rules-add' === $operation ) {
			$notice = is_wp_error( \GTPerformance\Cache\ServerRules::enable() ) ? 'rules-failed' : 'rules-added';
		} elseif ( 'rules-remove' === $operation ) {
			$notice = is_wp_error( \GTPerformance\Cache\ServerRules::disable() ) ? 'rules-failed' : 'rules-removed';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'gtperf_notice' => $notice,
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$notices = array(
			'purged'           => __( 'Every site\'s page cache was emptied.', 'gt-performance' ),
			'defaults-saved'   => __( 'Network defaults saved. Sites that never saved their own settings pick them up on their next request.', 'gt-performance' ),
			'defaults-cleared' => __( 'Network defaults cleared.', 'gt-performance' ),
			'map-written'      => __( 'Site map rebuilt.', 'gt-performance' ),
			'map-failed'       => __( 'The site map could not be written. Check the cache directory permissions, PHP OpenSSL, and AUTH_KEY.', 'gt-performance' ),
			'rules-added'      => __( 'Server rules added for every site that keeps web-server copies.', 'gt-performance' ),
			'rules-removed'    => __( 'Server rules removed.', 'gt-performance' ),
			'rules-failed'     => __( '.htaccess could not be updated. Check its file permissions.', 'gt-performance' ),
			'invalid'          => __( 'Nothing was changed.', 'gt-performance' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice   = isset( $_GET['gtperf_notice'] ) ? sanitize_key( wp_unslash( $_GET['gtperf_notice'] ) ) : '';
		$defaults = get_site_option( self::DEFAULTS, array() );
		$action   = network_admin_url( 'edit.php?action=gtperf_network' );
		$dropin   = ( new \GTPerformance\Cache\DropinInstaller() )->status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GT Performance: network', 'gt-performance' ); ?></h1>
			<?php if ( isset( $notices[ $notice ] ) ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Each site keeps its own settings, bypass rules, and page cache. Configure a site from its own dashboard. The page-cache drop-in and WP_CACHE are shared by the network and are managed here or with WP-CLI.', 'gt-performance' ); ?></p>
			<p>
				<?php
				/* translators: %s: drop-in status: owned, missing, or conflict. */
				echo esc_html( sprintf( __( 'Page-cache drop-in: %s', 'gt-performance' ), $dropin ) );
				?>
				&middot;
				<?php
				/* translators: %s: yes or no. */
				echo esc_html( sprintf( __( 'Site map: %s', 'gt-performance' ), is_file( Paths::siteMap() ) ? __( 'written', 'gt-performance' ) : __( 'missing', 'gt-performance' ) ) );
				?>
			</p>

			<h2><?php esc_html_e( 'Sites', 'gt-performance' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'ID', 'gt-performance' ); ?></th><th><?php esc_html_e( 'Site', 'gt-performance' ); ?></th><th><?php esc_html_e( 'Configuration', 'gt-performance' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( get_sites( array( 'number' => 200 ) ) as $site ) : ?>
					<tr>
						<td><?php echo (int) $site->blog_id; ?></td>
						<td><?php echo esc_html( $site->domain . $site->path ); ?></td>
						<td><?php echo esc_html( is_file( Paths::siteRootFor( (int) $site->blog_id ) . '/config.json' ) ? __( 'Compiled', 'gt-performance' ) : __( 'Not compiled yet (compiles on the site\'s next request)', 'gt-performance' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Purge', 'gt-performance' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<?php wp_nonce_field( 'gtperf_network' ); ?>
				<input type="hidden" name="gtperf_operation" value="purge" />
				<p><?php esc_html_e( 'Empty the stored pages of every site on the network. CDN and edge caches are purged per site.', 'gt-performance' ); ?></p>
				<?php submit_button( __( 'Purge all sites', 'gt-performance' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Network defaults', 'gt-performance' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<?php wp_nonce_field( 'gtperf_network' ); ?>
				<input type="hidden" name="gtperf_operation" value="defaults" />
				<p><?php esc_html_e( 'Sites that have never saved GT Performance settings use these defaults. Cloudflare, xCloud, Redis, and credentials are never copied.', 'gt-performance' ); ?></p>
				<p>
					<label for="gtperf-source"><?php esc_html_e( 'Copy settings from', 'gt-performance' ); ?></label>
					<select id="gtperf-source" name="gtperf_source">
						<option value="0"><?php esc_html_e( 'None (built-in defaults)', 'gt-performance' ); ?></option>
						<?php foreach ( get_sites( array( 'number' => 200 ) ) as $site ) : ?>
							<option value="<?php echo (int) $site->blog_id; ?>"><?php echo esc_html( $site->domain . $site->path ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><?php echo esc_html( is_array( $defaults ) && array() !== $defaults ? __( 'Network defaults are set.', 'gt-performance' ) : __( 'No network defaults are set.', 'gt-performance' ) ); ?></p>
				<?php submit_button( __( 'Save network defaults', 'gt-performance' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Server rules', 'gt-performance' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<?php wp_nonce_field( 'gtperf_network' ); ?>
				<input type="hidden" name="gtperf_operation" value="<?php echo \GTPerformance\Cache\ServerRules::enabled() ? 'rules-remove' : 'rules-add'; ?>" />
				<p><?php esc_html_e( 'One .htaccess block serves every site that turned on "Keep copies the web server can serve", each with its own hosts, path, and bypass cookies. It is rewritten when a site\'s settings change and removed on network deactivation.', 'gt-performance' ); ?></p>
				<?php submit_button( \GTPerformance\Cache\ServerRules::enabled() ? __( 'Remove server rules', 'gt-performance' ) : __( 'Add server rules', 'gt-performance' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php $gtperf_nginx = ( new \GTPerformance\Cache\ServerRules() )->nginxSnippet(); ?>
			<?php if ( '' !== $gtperf_nginx ) : ?>
				<p><label for="gtperf-network-nginx"><?php esc_html_e( 'Nginx configuration', 'gt-performance' ); ?></label></p>
				<textarea id="gtperf-network-nginx" class="large-text code" rows="12" readonly><?php echo esc_textarea( $gtperf_nginx ); ?></textarea>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Site map', 'gt-performance' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<?php wp_nonce_field( 'gtperf_network' ); ?>
				<input type="hidden" name="gtperf_operation" value="map" />
				<p><?php esc_html_e( 'The page-cache drop-in reads this map to find which site a request belongs to. It is rebuilt whenever a site is added, moved, suspended, or deleted.', 'gt-performance' ); ?></p>
				<?php submit_button( __( 'Rebuild site map', 'gt-performance' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
