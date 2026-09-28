<?php
/**
 * First-run setup: what the site has, what it needs, and whether caching works.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Setup;

use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Cache\WpCacheConstant;
use GTPerformance\Commerce\Registry;
use GTPerformance\Compatibility\PluginDetector;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\ResponseSnapshot;

/**
 * Every check here is local except the two loopback requests, which fetch this
 * site's own home page and run only when an administrator presses a button.
 */
final class SetupReport {
	public const PROBE_OPTION    = 'gt_performance_setup_probe';
	public const VERIFY_OPTION   = 'gt_performance_setup_verified';
	public const COMPLETE_OPTION = 'gt_performance_setup_completed';

	/**
	 * @param int $retrySeconds Pause between verification attempts. Right after a
	 *                          purge, Cloudflare answered MISS to four back-to-back
	 *                          requests on gatilab.com and HIT once they were a
	 *                          couple of seconds apart.
	 */
	public function __construct( private readonly int $retrySeconds = 2 ) {
	}

	/**
	 * Response headers that name a host page cache, and the cache they name.
	 * Matching is on the header's presence, so a MISS still counts: the cache is
	 * there, it just had not stored this page yet.
	 */
	private const HOST_HEADERS = array(
		'x-litespeed-cache'         => 'LiteSpeed Cache',
		'x-kinsta-cache'            => 'Kinsta',
		'x-hcdn-cache-status'       => 'Hostinger CDN',
		// Seen on gatilab.com: x-site-optimizer: 4.0 with x-cache-status HIT/STALE.
		'x-site-optimizer'          => 'Hostinger Site Optimizer',
		'x-proxy-cache'             => 'SiteGround Dynamic Cache',
		'x-wpe-cached'              => 'WP Engine',
		'x-cacheable'               => 'WP Engine',
		'x-varnish'                 => 'Varnish',
		'x-fastcgi-cache'           => 'Nginx FastCGI cache',
		'x-nginx-cache'             => 'Nginx cache',
		'x-srcache-fetch-status'    => 'Nginx SRCache',
		'x-ac'                      => 'WordPress.com Batcache',
		'x-pantheon-styx-hostname'  => 'Pantheon Global CDN',
		'x-sucuri-cache'            => 'Sucuri',
	);

	/**
	 * Local environment checks. Cheap enough to run on every render.
	 *
	 * @return list<array{id:string,label:string,ok:bool,detail:string}>
	 */
	public function environment(): array {
		$authKey = defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : '';
		$keyOk   = strlen( $authKey ) >= 16 && 'put your unique phrase here' !== $authKey;
		$openssl = function_exists( 'openssl_encrypt' );
		$writes  = wp_is_writable( Paths::cacheRoot() ) || wp_is_writable( dirname( Paths::cacheRoot() ) );
		$dropin  = ( new DropinInstaller() )->status();

		return array(
			array(
				'id'     => 'auth_key',
				'label'  => __( 'WordPress security keys', 'gt-performance' ),
				'ok'     => $keyOk,
				'detail' => $keyOk
					? __( 'AUTH_KEY is set, so the runtime configuration and saved credentials can be encrypted.', 'gt-performance' )
					: __( 'AUTH_KEY is missing or still the placeholder from wp-config-sample.php. Generate real keys (for example with `wp config shuffle-salts`), or GT Performance cannot publish its runtime configuration.', 'gt-performance' ),
			),
			array(
				'id'     => 'openssl',
				'label'  => __( 'OpenSSL', 'gt-performance' ),
				'ok'     => $openssl,
				'detail' => $openssl
					? __( 'Available.', 'gt-performance' )
					: __( 'The PHP OpenSSL extension is missing. Ask your host to enable it; the runtime configuration is encrypted with it.', 'gt-performance' ),
			),
			array(
				'id'     => 'storage',
				'label'  => __( 'Cache directory', 'gt-performance' ),
				'ok'     => $writes,
				'detail' => $writes
					? __( 'Writable.', 'gt-performance' )
					: __( 'wp-content/cache is not writable by WordPress, so nothing can be stored or configured there.', 'gt-performance' ),
			),
			array(
				'id'     => 'dropin_owner',
				'label'  => __( 'advanced-cache.php', 'gt-performance' ),
				'ok'     => 'conflict' !== $dropin,
				'detail' => 'conflict' === $dropin
					? __( 'Another plugin owns advanced-cache.php. Deactivate that cache plugin, or choose optimize only below.', 'gt-performance' )
					: __( 'Free for GT Performance to use.', 'gt-performance' ),
			),
		);
	}

	/**
	 * Active plugins that run their own page cache.
	 *
	 * @return list<string>
	 */
	public function cachePlugins(): array {
		$detector = new PluginDetector();
		$names    = array();
		foreach ( $detector->catalog() as $id => $plugin ) {
			if ( 'cache' === $plugin['group'] && $detector->active( $id ) ) {
				$names[] = $plugin['name'];
			}
		}

		return $names;
	}

	/**
	 * Host page caches this server declares without a request.
	 *
	 * @return list<string>
	 */
	public function hostCachesFromEnvironment(): array {
		$found = array();
		if ( defined( 'KINSTA_CACHE_ZONE' ) || isset( $_SERVER['KINSTA_CACHE_ZONE'] ) ) {
			$found[] = 'Kinsta';
		}
		if ( class_exists( 'WpeCommon' ) ) {
			$found[] = 'WP Engine';
		}
		if ( defined( 'PANTHEON_ENVIRONMENT' ) ) {
			$found[] = 'Pantheon Global CDN';
		}
		if ( (bool) Settings::get( 'xcloud.page_cache_enabled', false ) ) {
			$found[] = 'xCloud';
		}

		return $found;
	}

	/**
	 * Host page caches named by a response's headers.
	 *
	 * @param array<string, string|list<string>> $headers Response headers.
	 * @return list<string>
	 */
	public static function hostCachesFromHeaders( array $headers ): array {
		$found = array();
		foreach ( $headers as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( isset( self::HOST_HEADERS[ $name ] ) ) {
				$found[] = self::HOST_HEADERS[ $name ];
			}
			if ( 'platform' === $name && str_contains( strtolower( is_array( $value ) ? implode( ',', $value ) : (string) $value ), 'hostinger' ) ) {
				$found[] = 'Hostinger';
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * The mode to suggest: a host that already stores pages gets optimize only.
	 *
	 * @param list<string> $hostCaches Detected host caches.
	 */
	public static function recommendedMode( array $hostCaches ): string {
		return array() === $hostCaches ? 'store' : 'optimize';
	}

	/**
	 * Fetch the home page once as a visitor and record which host cache answered.
	 *
	 * @return array{checked_at:int,host_caches:list<string>,status:int,error:string}
	 */
	public function probe(): array {
		$response = $this->fetchHome();
		$result   = array(
			'checked_at'  => time(),
			'host_caches' => $this->hostCachesFromEnvironment(),
			'status'      => 0,
			'error'       => '',
		);
		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
		} else {
			$result['status']      = (int) wp_remote_retrieve_response_code( $response );
			$result['host_caches'] = array_values( array_unique( array_merge( $result['host_caches'], self::hostCachesFromHeaders( self::headers( $response ) ) ) ) );
		}
		update_option( self::PROBE_OPTION, $result, false );

		return $result;
	}

	/**
	 * Commerce and language or currency plugins that change what may be cached.
	 *
	 * @return array{commerce:list<string>,variation:list<string>}
	 */
	public function sensitivePlugins(): array {
		$names = array(
			'woocommerce' => 'WooCommerce',
			'edd'         => 'Easy Digital Downloads',
			'fluentcart'  => 'FluentCart',
		);
		$commerce = array();
		foreach ( ( new Registry() )->active() as $adapter ) {
			$commerce[] = $names[ $adapter->id() ] ?? $adapter->id();
		}

		return array(
			'commerce'  => $commerce,
			'variation' => ( new PluginDetector() )->activeVisitorVariation(),
		);
	}

	/**
	 * Whether store mode is ready to serve: the owned drop-in and WP_CACHE.
	 */
	public function storageReady(): bool {
		return 'owned' === ( new DropinInstaller() )->status() && 'enabled' === ( new WpCacheConstant() )->status();
	}

	/**
	 * Request the home page as a visitor until the cache answers, then record it.
	 *
	 * Store mode passes when GT Performance serves a HIT. Cloudflare's answer is
	 * reported next to it but does not decide the result: on gatilab.com, right
	 * after a full purge, the edge kept answering MISS for longer than any
	 * reasonable wait while the origin was already serving HITs, so failing on it
	 * reported a broken setup that was working. Optimize-only mode passes on a
	 * public 200, and reports whether the host's cache answered.
	 *
	 * @return array{checked_at:int,mode:string,passed:bool,origin:string,edge:string,host_caches:list<string>,status:int,detail:string}
	 */
	public function verify(): array {
		$optimize   = Settings::optimizeOnly();
		$cloudflare = (bool) Settings::get( 'cloudflare.enabled', false );
		$snapshots  = new ResponseSnapshot();
		$last       = null;
		$hostCaches = $this->hostCachesFromEnvironment();

		// A cold page takes one request to store at the origin and another to fill
		// the edge, so four attempts leave room for both without looping forever.
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			if ( $attempt > 0 && $this->retrySeconds > 0 ) {
				sleep( $this->retrySeconds );
			}
			$response = $this->fetchHome();
			if ( is_wp_error( $response ) ) {
				return $this->recordVerification( $optimize, false, '', '', $hostCaches, 0, $response->get_error_message() );
			}
			$last       = $snapshots->fromWordPressResponse( $response );
			$hostCaches = array_values( array_unique( array_merge( $hostCaches, self::hostCachesFromHeaders( self::headers( $response ) ) ) ) );
			if ( is_wp_error( $last ) ) {
				return $this->recordVerification( $optimize, false, '', '', $hostCaches, 0, $last->get_error_message() );
			}
			$originHit = in_array( (string) $last['gt_cache_status'], array( 'HIT', 'STALE' ), true );
			$edgeHit   = 'HIT' === (string) $last['cf_cache_status'];
			if ( $optimize ? 200 === (int) $last['status'] : ( $originHit && ( ! $cloudflare || $edgeHit ) ) ) {
				break;
			}
		}

		$status = (int) ( $last['status'] ?? 0 );
		$origin = (string) ( $last['gt_cache_status'] ?? '' );
		$edge   = (string) ( $last['cf_cache_status'] ?? '' );
		if ( $optimize ) {
			$passed = 200 === $status && ! (bool) ( $last['private'] ?? true );
			$detail = $passed
				? __( 'The home page is public and eligible. Your host\'s cache stores the optimized version.', 'gt-performance' )
				: __( 'The home page did not come back as a public 200 response, so neither this plugin nor your host will cache it. Check for a login wall, a maintenance mode, or a cookie the page sets.', 'gt-performance' );
		} else {
			// A cached edge copy never reaches PHP, so a Cloudflare HIT alone also
			// proves the origin stored the page at some point.
			$edgeHit = $cloudflare && 'HIT' === $edge;
			$passed  = in_array( $origin, array( 'HIT', 'STALE' ), true ) || $edgeHit;
			$detail  = match ( true ) {
				$passed && ( ! $cloudflare || $edgeHit ) => __( 'Verified: the home page was served from the cache.', 'gt-performance' ),
				$passed                  => __( 'Verified: GT Performance served the home page from its cache. Cloudflare has not answered HIT yet; right after a purge that can take a minute or two, so verify again shortly. If it never does, sync Cloudflare and check that the DNS record is proxied.', 'gt-performance' ),
				200 !== $status          => __( 'The home page did not return 200, so it cannot be cached.', 'gt-performance' ),
				(bool) ( $last['private'] ?? false ) => __( 'The home page is sent as private or sets a cookie, so it is never stored. Open Explain this page on Tools to see which rule applies.', 'gt-performance' ),
				default                  => __( 'The page was not served from the origin cache. Check that the drop-in and WP_CACHE are installed, then verify again.', 'gt-performance' ),
			};
		}

		return $this->recordVerification( $optimize, $passed, $origin, $edge, $hostCaches, $status, $detail );
	}

	/**
	 * @param list<string> $hostCaches Detected host caches.
	 * @return array{checked_at:int,mode:string,passed:bool,origin:string,edge:string,host_caches:list<string>,status:int,detail:string}
	 */
	private function recordVerification( bool $optimize, bool $passed, string $origin, string $edge, array $hostCaches, int $status, string $detail ): array {
		$result = array(
			'checked_at'  => time(),
			'mode'        => $optimize ? 'optimize' : 'store',
			'passed'      => $passed,
			'origin'      => $origin,
			'edge'        => $edge,
			'host_caches' => $hostCaches,
			'status'      => $status,
			'detail'      => $detail,
		);
		update_option( self::VERIFY_OPTION, $result, false );
		if ( $passed ) {
			update_option( self::COMPLETE_OPTION, time(), false );
		}

		return $result;
	}

	/**
	 * A visitor's request: no cookies, so nothing signed-in bypasses the cache.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function fetchHome(): array|\WP_Error {
		return wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'cookies'     => array(),
			)
		);
	}

	/**
	 * @param array<string, mixed> $response HTTP response.
	 * @return array<string, string|list<string>>
	 */
	private static function headers( array $response ): array {
		$headers = wp_remote_retrieve_headers( $response );

		return is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
	}
}
