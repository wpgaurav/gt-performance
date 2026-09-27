<?php
/**
 * Restore, import, export, and preview for settings.
 *
 * Every change goes through apply(): take the settings lock, reject a stale
 * expected hash, change only portable values, and save through Settings::save()
 * so sanitizing, runtime publication, and invalidation are exactly those of an
 * ordinary save. Only local settings change. Cloudflare rules are reported as
 * needing a sync, never as restored.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Configuration;

use GTPerformance\Core\PublicSettings;
use GTPerformance\Core\Settings;

final class ConfigurationService {
	public const EXPORT_FORMAT = 'gt-performance-settings';

	public const EXPORT_SCHEMA = 1;

	/**
	 * Never restored or imported: the cache generation, agent and adviser access, Redis
	 * (drop-in ownership and connection), xCloud (provider state), and
	 * Cloudflare identity and ownership. Credentials never enter the projection.
	 *
	 * @var list<string>
	 */
	public const PROTECTED = array(
		'generation',
		'agents',
		'advisor',
		'redis',
		'xcloud',
		'cloudflare.enabled',
		'cloudflare.auth_mode',
		'cloudflare.domain',
		'cloudflare.zone_id',
	);

	public function __construct(
		private readonly RevisionRepository $revisions = new RevisionRepository(),
	) {
	}

	public function currentHash(): string {
		return PublicSettings::hash( PublicSettings::view() );
	}

	/**
	 * The projection without protected values, as exported and restored.
	 *
	 * @param array<string, mixed> $view Output of PublicSettings::view().
	 * @return array<string, mixed>
	 */
	public static function portable( array $view ): array {
		$portable = array();
		foreach ( Diff::flatten( $view ) as $path => $value ) {
			if ( Diff::ignored( $path, self::PROTECTED ) ) {
				continue;
			}
			$parts = explode( '.', $path, 2 );
			if ( 2 === count( $parts ) ) {
				$portable[ $parts[0] ][ $parts[1] ] = $value;
			} else {
				$portable[ $path ] = $value;
			}
		}

		return $portable;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function export(): array {
		$view = PublicSettings::view();

		return array(
			'format'         => self::EXPORT_FORMAT,
			'schema_version' => self::EXPORT_SCHEMA,
			'plugin_version' => GTPERF_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'settings_hash'  => PublicSettings::hash( $view ),
			'settings'       => self::portable( $view ),
		);
	}

	/**
	 * What applying these portable values would change, without changing it.
	 *
	 * @param array<string, mixed> $portable Portable values.
	 * @return array{changes:list<array{path:string,from:mixed,to:mixed}>,skipped:list<string>,settings_hash:string,external:array<string,string>}
	 */
	public function preview( array $portable ): array {
		$current = self::portable( PublicSettings::view() );
		list( $target, $skipped ) = $this->overlay( $current, $portable );
		$changes                  = Diff::between( $current, $target );

		return array(
			'changes'       => $changes,
			'skipped'       => $skipped,
			'settings_hash' => $this->currentHash(),
			'external'      => self::external( $changes ),
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore( string $revisionId, string $expectedHash ): array|\WP_Error {
		$revision = $this->revisions->find( $revisionId );
		if ( null === $revision ) {
			return new \WP_Error( 'gtperf_revision_missing', __( 'That settings revision no longer exists.', 'gt-performance' ), array( 'status' => 404 ) );
		}

		return $this->apply( self::portable( $revision['settings'] ), $expectedHash, 'restore' );
	}

	/**
	 * Validate an export file. Unknown or protected keys, a foreign format, or an
	 * unsupported schema version reject the whole file.
	 *
	 * @param mixed $payload Decoded JSON.
	 * @return array<string, mixed>|\WP_Error Portable values.
	 */
	public function parseImport( mixed $payload ): array|\WP_Error {
		if ( ! is_array( $payload ) || self::EXPORT_FORMAT !== ( $payload['format'] ?? '' ) || ! is_array( $payload['settings'] ?? null ) ) {
			return new \WP_Error( 'gtperf_import_format', __( 'This is not a GT Performance settings export.', 'gt-performance' ), array( 'status' => 400 ) );
		}
		if ( self::EXPORT_SCHEMA !== (int) ( $payload['schema_version'] ?? 0 ) ) {
			return new \WP_Error( 'gtperf_import_schema', __( 'This settings export uses an unsupported schema version.', 'gt-performance' ), array( 'status' => 400 ) );
		}

		$allowed = Diff::flatten( self::portable( PublicSettings::view() ) );
		$invalid = array();
		foreach ( Diff::flatten( $payload['settings'] ) as $path => $value ) {
			if ( ! array_key_exists( $path, $allowed ) || is_array( $allowed[ $path ] ) !== is_array( $value ) ) {
				$invalid[] = $path;
			}
		}
		if ( array() !== $invalid ) {
			return new \WP_Error(
				'gtperf_import_keys',
				/* translators: %s: comma-separated setting paths. */
				sprintf( __( 'The export contains settings this site does not accept: %s', 'gt-performance' ), implode( ', ', array_slice( $invalid, 0, 10 ) ) ),
				array(
					'status' => 400,
					'paths'  => array_slice( $invalid, 0, 50 ),
				)
			);
		}

		return $payload['settings'];
	}

	/**
	 * Apply portable values atomically with respect to other settings writes.
	 *
	 * @param array<string, mixed> $portable     Values to apply.
	 * @param string               $expectedHash Current hash the caller previewed; '' skips the check.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply( array $portable, string $expectedHash, string $source ): array|\WP_Error {
		if ( ! SettingsLock::acquire() ) {
			return new \WP_Error( 'gtperf_settings_busy', __( 'Another settings change is in progress. Try again in a moment.', 'gt-performance' ), array( 'status' => 409 ) );
		}
		try {
			$before = $this->currentHash();
			if ( '' !== $expectedHash && ! hash_equals( $before, $expectedHash ) ) {
				return new \WP_Error( 'gtperf_stale_settings', __( 'Settings changed after this preview. Review the current settings and try again.', 'gt-performance' ), array( 'status' => 409 ) );
			}

			$settings = Settings::all();
			$current  = self::portable( PublicSettings::view( $settings ) );
			list( $target, $skipped ) = $this->overlay( $current, $portable );
			$changes                  = Diff::between( $current, $target );
			if ( array() === $changes ) {
				return array(
					'applied'       => false,
					'changes'       => array(),
					'skipped'       => $skipped,
					'settings_hash' => $before,
					'external'      => array(),
				);
			}
			foreach ( $changes as $change ) {
				$parts = explode( '.', $change['path'], 2 );
				if ( 2 === count( $parts ) ) {
					$settings[ $parts[0] ][ $parts[1] ] = $change['to'];
				} else {
					$settings[ $change['path'] ] = $change['to'];
				}
			}

			RevisionRepository::source( $source );
			if ( ! Settings::save( $settings ) ) {
				RevisionRepository::source( '' );
				return new \WP_Error( 'gtperf_config_write', Settings::configurationError(), array( 'status' => 500 ) );
			}

			return array(
				'applied'       => true,
				'changes'       => $changes,
				'skipped'       => $skipped,
				'settings_hash' => $this->currentHash(),
				'external'      => self::external( $changes ),
			);
		} finally {
			SettingsLock::release();
		}
	}

	/**
	 * Local settings that feed the managed Cloudflare rule change only locally.
	 *
	 * @param list<array{path:string,from:mixed,to:mixed}> $changes Changes.
	 * @return array<string, string>
	 */
	private static function external( array $changes ): array {
		if ( ! (bool) Settings::get( 'cloudflare.enabled', false ) ) {
			return array();
		}
		foreach ( $changes as $change ) {
			if ( str_starts_with( $change['path'], 'cache.' ) || str_starts_with( $change['path'], 'cloudflare.' ) ) {
				return array( 'cloudflare' => 'sync_required' );
			}
		}

		return array();
	}

	/**
	 * Values from $portable on top of $current, for paths $current has and that
	 * are not protected. Anything else is reported as skipped.
	 *
	 * @param array<string, mixed> $current  Current portable values.
	 * @param array<string, mixed> $portable Incoming values.
	 * @return array{0:array<string, mixed>,1:list<string>} Values and skipped paths.
	 */
	private function overlay( array $current, array $portable ): array {
		$skipped = array();
		$flat    = Diff::flatten( $current );
		foreach ( Diff::flatten( $portable ) as $path => $value ) {
			if ( Diff::ignored( $path, self::PROTECTED ) || ! array_key_exists( $path, $flat ) || is_array( $flat[ $path ] ) !== is_array( $value ) ) {
				$skipped[] = $path;
				continue;
			}
			$parts = explode( '.', $path, 2 );
			if ( 2 === count( $parts ) ) {
				$current[ $parts[0] ][ $parts[1] ] = $value;
			} else {
				$current[ $path ] = $value;
			}
		}

		return array( $current, $skipped );
	}
}
