<?php
/**
 * Bounded history of non-secret settings.
 *
 * Each revision is the non-secret projection a save replaced. Credentials and
 * encrypted blobs never enter it, so restoring one cannot bring back a revoked
 * token. History is one non-autoloaded option: at most 20 revisions, none older
 * than 90 days, 512 KB in total.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Configuration;

use GTPerformance\Core\PublicSettings;
use GTPerformance\Core\Settings;

final class RevisionRepository {
	public const OPTION = 'gt_performance_settings_history';

	public const MAX_REVISIONS = 20;

	public const MAX_AGE = 90 * DAY_IN_SECONDS;

	public const MAX_BYTES = 512 * 1024;

	private static string $source = '';

	public function register(): void {
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'record' ), 5, 2 );
	}

	/**
	 * Label the next recorded revision, for example "restore" or "import".
	 */
	public static function source( string $source ): void {
		self::$source = sanitize_key( $source );
	}

	/**
	 * Record what a save replaced. Saves that change only credentials or the
	 * generation counter record nothing.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 */
	public function record( mixed $old, mixed $new ): void {
		if ( ! is_array( $old ) || ! is_array( $new ) ) {
			return;
		}
		$before = PublicSettings::view( Settings::merged( $old ) );
		$after  = PublicSettings::view( Settings::merged( $new ) );
		if ( array() === Diff::between( $before, $after ) ) {
			return;
		}

		$revisions = $this->all();
		array_unshift(
			$revisions,
			array(
				'id'       => wp_generate_uuid4(),
				'at'       => time(),
				'user_id'  => get_current_user_id(),
				'source'   => '' !== self::$source ? self::$source : self::context(),
				'hash'     => PublicSettings::hash( $before ),
				'settings' => $before,
			)
		);
		self::$source = '';
		$this->save( $revisions );
	}

	/**
	 * @return list<array{id:string,at:int,user_id:int,source:string,hash:string,settings:array<string,mixed>}>
	 */
	public function all(): array {
		$saved     = get_option( self::OPTION, array() );
		$revisions = array();
		foreach ( is_array( $saved ) ? $saved : array() as $revision ) {
			if ( is_array( $revision ) && isset( $revision['id'], $revision['settings'] ) && is_array( $revision['settings'] ) ) {
				$revisions[] = array(
					'id'       => (string) $revision['id'],
					'at'       => (int) ( $revision['at'] ?? 0 ),
					'user_id'  => (int) ( $revision['user_id'] ?? 0 ),
					'source'   => (string) ( $revision['source'] ?? '' ),
					'hash'     => (string) ( $revision['hash'] ?? '' ),
					'settings' => $revision['settings'],
				);
			}
		}

		return $revisions;
	}

	/**
	 * @return array{id:string,at:int,user_id:int,source:string,hash:string,settings:array<string,mixed>}|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $revision ) {
			if ( $revision['id'] === $id ) {
				return $revision;
			}
		}

		return null;
	}

	/**
	 * @param list<array<string, mixed>> $revisions Newest first.
	 */
	private function save( array $revisions ): void {
		$cutoff    = time() - self::MAX_AGE;
		$revisions = array_values( array_filter( $revisions, static fn ( array $revision ): bool => (int) $revision['at'] >= $cutoff ) );
		$revisions = array_slice( $revisions, 0, self::MAX_REVISIONS );
		$over = strlen( (string) wp_json_encode( $revisions ) ) > self::MAX_BYTES;
		while ( $over && array_key_exists( 1, $revisions ) ) {
			array_pop( $revisions );
			$over = strlen( (string) wp_json_encode( $revisions ) ) > self::MAX_BYTES;
		}
		update_option( self::OPTION, $revisions, false );
	}

	private static function context(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		return is_admin() ? 'admin' : 'system';
	}
}
