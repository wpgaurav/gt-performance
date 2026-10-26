<?php
/**
 * Purges xCloud Cloudflare Enterprise through xCloud's own automatic purge plugin.
 *
 * An Enterprise domain is served from xCloud's own Cloudflare zone, so purging the
 * site owner's zone clears nothing a visitor receives. When the add-on's
 * "Install now" step runs, xCloud drops `xcloud-cfe-cache-purge.php` into
 * mu-plugins with a per-site token pair and the endpoint that accepts it. That
 * file is the only proof GT Performance can see that xCloud owns the edge, and
 * the only credential that can purge it.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\XCloud;

final class EnterprisePurgeHook {
	public const MU_PLUGIN = 'xcloud-cfe-cache-purge.php';

	/**
	 * Matches the mu-plugin's own XCLOUD_CFE_PURGE_MAX_URLS: xCloud accepts this
	 * many URLs in one request.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Past this many URLs one whole-site purge replaces the batches. xCloud merges
	 * a domain's purges over five seconds and drops a merged batch of more than
	 * 300 URLs outright rather than widening it, so a longer list sent in pieces
	 * would purge nothing. A post save on gauravtiwari.org named 508 pages.
	 */
	public const MAX_URLS = 300;

	private const CONSTANTS = array(
		'url'          => 'XCLOUD_CFE_PURGE_API_URL',
		'auth_token'   => 'XCLOUD_CFE_PURGE_AUTH_TOKEN',
		'verify_token' => 'XCLOUD_CFE_PURGE_VERIFY_TOKEN',
		'version'      => 'XCLOUD_CFE_PURGE_VERSION',
	);

	/**
	 * @param array<string, string>|null $credentials Test seam replacing the mu-plugin constants.
	 * @param string|null                $muPluginDir Test seam replacing WPMU_PLUGIN_DIR.
	 */
	public function __construct(
		private readonly ?array $credentials = null,
		private readonly ?string $muPluginDir = null,
	) {
	}

	/**
	 * Whether xCloud's purge plugin is present in mu-plugins.
	 */
	public function installed(): bool {
		return is_file( $this->muPluginPath() );
	}

	/**
	 * Whether purges can go through the plugin's endpoint: the file is installed,
	 * it defined a usable token pair, and no site has opted out.
	 */
	public function available(): bool {
		return $this->installed()
			&& null !== $this->config()
			&& (bool) apply_filters( 'gt_performance_xcloud_enterprise_purge', true );
	}

	public function muPluginPath(): string {
		$dir = $this->muPluginDir ?? ( defined( 'WPMU_PLUGIN_DIR' ) ? (string) constant( 'WPMU_PLUGIN_DIR' ) : WP_CONTENT_DIR . '/mu-plugins' );

		return rtrim( $dir, '/\\' ) . '/' . self::MU_PLUGIN;
	}

	/**
	 * @param list<mixed> $urls URLs whose edge copies changed.
	 * @return array{operation:string,entries:int,requests:int,message:string}|\WP_Error
	 */
	public function purgeUrls( array $urls, bool $blocking ): array|\WP_Error {
		$clean = array();
		foreach ( $urls as $url ) {
			if ( is_string( $url ) && preg_match( '#^https?://#i', $url ) ) {
				$clean[ $url ] = true;
			}
		}
		$clean = array_keys( $clean );
		if ( ! $clean ) {
			return new \WP_Error( 'gtperf_xcloud_enterprise_target', __( 'No absolute URL was given to purge from xCloud Cloudflare Enterprise.', 'gt-performance' ) );
		}

		if ( count( $clean ) > self::MAX_URLS ) {
			return $this->purgeAll( $blocking );
		}

		$requests = 0;
		$message  = '';
		foreach ( array_chunk( $clean, self::BATCH_SIZE ) as $batch ) {
			$result = $this->send( array( 'urls' => $batch ), $blocking );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			++$requests;
			$message = $result;
		}

		return array(
			'operation' => 'urls',
			'entries'   => count( $clean ),
			'requests'  => $requests,
			'message'   => $message,
		);
	}

	/**
	 * @return array{operation:string,entries:int,requests:int,message:string}|\WP_Error
	 */
	public function purgeAll( bool $blocking ): array|\WP_Error {
		$result = $this->send( array( 'purge_all' => true ), $blocking );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'operation' => 'all',
			'entries'   => 0,
			'requests'  => 1,
			'message'   => $result,
		);
	}

	/**
	 * Send one purge in the same shape as the mu-plugin's own request; xCloud
	 * validates the source and version it names.
	 *
	 * A non-blocking send cannot read the reply, so it reports that the request
	 * left. The editor is never made to wait on xCloud.
	 *
	 * @param array<string, mixed> $target `urls` or `purge_all`.
	 * @return string|\WP_Error xCloud's message, or an error.
	 */
	private function send( array $target, bool $blocking ): string|\WP_Error {
		$config = $this->config();
		if ( null === $config ) {
			return new \WP_Error( 'gtperf_xcloud_enterprise_hook', __( 'xCloud automatic cache purge is not installed, so Cloudflare Enterprise cannot be purged from WordPress.', 'gt-performance' ) );
		}

		$response = wp_remote_request(
			$config['url'],
			array(
				'method'      => 'POST',
				'timeout'     => $blocking ? 15 : 2,
				'blocking'    => $blocking,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => (string) wp_json_encode(
					array(
						'auth_token'   => $config['auth_token'],
						'verify_token' => $config['verify_token'],
						'source'       => 'wordpress_plugin',
						'version'      => $config['version'],
					) + $target
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'gtperf_xcloud_enterprise_transport', $response->get_error_message() );
		}
		if ( ! $blocking ) {
			return __( 'Purge request sent to xCloud.', 'gt-performance' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$text = is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] )
			? sanitize_text_field( $data['message'] )
			: '';
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			return new \WP_Error(
				'gtperf_xcloud_enterprise_api',
				'' !== $text
					? $text
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'xCloud rejected the Cloudflare Enterprise purge (HTTP %d).', 'gt-performance' ), $code ),
				array( 'status' => $code )
			);
		}

		return '' !== $text ? $text : __( 'xCloud queued the purge.', 'gt-performance' );
	}

	/**
	 * The token pair and endpoint the mu-plugin defined. The endpoint must be
	 * HTTPS on an xCloud host, so a tampered constant cannot send the tokens
	 * anywhere else.
	 *
	 * @return array{url:string,auth_token:string,verify_token:string,version:string}|null
	 */
	private function config(): ?array {
		$values = array();
		foreach ( self::CONSTANTS as $key => $constant ) {
			$value          = $this->credentials[ $key ] ?? ( defined( $constant ) ? constant( $constant ) : '' );
			$values[ $key ] = is_string( $value ) ? trim( $value ) : '';
		}

		if ( '' === $values['auth_token'] || '' === $values['verify_token'] || '' === $values['url'] ) {
			return null;
		}

		$scheme = wp_parse_url( $values['url'], PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $values['url'], PHP_URL_HOST ) );
		if ( 'https' !== $scheme || ( 'xcloud.host' !== $host && ! str_ends_with( $host, '.xcloud.host' ) ) ) {
			return null;
		}

		return $values;
	}
}
