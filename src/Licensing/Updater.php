<?php
/**
 * WordPress plugin update integration backed by FluentCart.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Licensing;

final class Updater {
	private const CACHE_TTL = 3 * HOUR_IN_SECONDS;

	/**
	 * Re-entry guards for the three hooked entry points.
	 *
	 * Each one runs on a WordPress hook and then performs an operation that can
	 * fire that same hook again: clearCache() deletes a transient from inside a
	 * transient-deletion hook, and injectUpdate()/metadata() do a remote call
	 * from inside the update-transient filter. Without a guard, one listener
	 * elsewhere on the generic deleted_site_transient / deleted_option hooks is
	 * enough to bounce the two hooks off each other until PHP exhausts the VM
	 * stack, which surfaces as "Allowed memory size exhausted" on whichever call
	 * happened to need the next stack page.
	 */
	private static bool $clearingCache = false;

	private static bool $injectingUpdate = false;

	private static bool $fetchingMetadata = false;

	public function __construct(
		private readonly LicenseManager $licenses = new LicenseManager(),
		private readonly FluentCartClient $client = new FluentCartClient(),
	) {
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'injectUpdate' ) );
		add_filter( 'plugins_api', array( $this, 'pluginInformation' ), 20, 3 );
		add_action( 'delete_site_transient_update_plugins', array( self::class, 'clearCache' ) );
	}

	/**
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function injectUpdate( mixed $transient ): mixed {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || self::$injectingUpdate ) {
			return $transient;
		}

		self::$injectingUpdate = true;

		try {
			$metadata = $this->metadata();
			if ( is_wp_error( $metadata ) || null === $metadata ) {
				return $transient;
			}

			$entry = $this->updateEntry( $metadata );
			if (
				'valid' === $metadata['license_status'] &&
				'' !== $metadata['package'] &&
				version_compare( $metadata['new_version'], GTP_VERSION, '>' )
			) {
				$transient->response[ GTP_BASENAME ] = $entry;
				unset( $transient->no_update[ GTP_BASENAME ] );
			} else {
				$transient->no_update[ GTP_BASENAME ] = $entry;
				unset( $transient->response[ GTP_BASENAME ] );
			}

			return $transient;
		} finally {
			self::$injectingUpdate = false;
		}
	}

	/**
	 * @param mixed  $result Existing API result.
	 * @param string $action API action.
	 * @param object $args   API arguments.
	 * @return mixed
	 */
	public function pluginInformation( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action || 'gt-performance' !== (string) ( $args->slug ?? '' ) ) {
			return $result;
		}

		$metadata = $this->metadata();
		if ( is_wp_error( $metadata ) || null === $metadata ) {
			return $result;
		}

		return (object) array(
			'name'          => $metadata['name'],
			'slug'          => 'gt-performance',
			'version'       => $metadata['new_version'],
			'author'        => '<a href="https://gauravtiwari.org/">Gaurav Tiwari</a>',
			'homepage'      => $metadata['homepage'],
			'requires'      => $metadata['requires'],
			'tested'        => $metadata['tested'],
			'requires_php'  => $metadata['requires_php'],
			'last_updated'  => $metadata['last_updated'],
			'sections'      => $metadata['sections'],
			'banners'       => $metadata['banners'],
			'icons'         => $metadata['icons'],
			'download_link' => 'valid' === $metadata['license_status'] ? $metadata['package'] : '',
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function metadata( bool $force = false ): array|\WP_Error|null {
		if ( ! $this->licenses->repository()->hasCredentials() ) {
			return null;
		}

		$key = self::cacheKey();
		if ( ! $force ) {
			$cached = get_site_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// The transient is only written once the remote call returns, so a
		// nested fetch would repeat the request instead of reusing it.
		if ( self::$fetchingMetadata ) {
			return null;
		}

		self::$fetchingMetadata = true;

		try {
			$credentials = $this->licenses->credentials();
			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}

			$response = $this->client->request( 'get_license_version', $credentials );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$metadata = UpdateResponse::normalize( $response );
			if ( null === $metadata ) {
				return new \WP_Error( 'gtp_license_update_response', __( 'The license server returned incomplete update details.', 'gt-performance' ) );
			}

			set_site_transient( $key, $metadata, self::CACHE_TTL );

			return $metadata;
		} finally {
			self::$fetchingMetadata = false;
		}
	}

	public static function clearCache(): void {
		if ( self::$clearingCache ) {
			return;
		}

		self::$clearingCache = true;

		try {
			delete_site_transient( self::cacheKey() );
		} finally {
			self::$clearingCache = false;
		}
	}

	/**
	 * @param array<string, mixed> $metadata Update metadata.
	 */
	private function updateEntry( array $metadata ): object {
		return (object) array(
			'id'           => 'https://gauravtiwari.org/product/gt-performance/',
			'slug'         => 'gt-performance',
			'plugin'       => GTP_BASENAME,
			'new_version'  => $metadata['new_version'],
			'url'          => $metadata['homepage'],
			'package'      => 'valid' === $metadata['license_status'] ? $metadata['package'] : '',
			'icons'        => $metadata['icons'],
			'banners'      => $metadata['banners'],
			'requires'     => $metadata['requires'],
			'tested'       => $metadata['tested'],
			'requires_php' => $metadata['requires_php'],
		);
	}

	private static function cacheKey(): string {
		$itemId = ( new Configuration() )->itemId();

		return 'gtp_fluentcart_update_' . md5( GTP_BASENAME . '|' . (string) $itemId );
	}
}
