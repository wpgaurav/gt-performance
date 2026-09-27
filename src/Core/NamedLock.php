<?php
/**
 * Per-site advisory locks for schema upgrades and the queue runner.
 *
 * MySQL and MariaDB use GET_LOCK, which the server releases when the owning
 * connection dies. The SQLite integration used by WordPress Studio and
 * Playground answers GET_LOCK without locking anything, so there the lock is a
 * row in the options table holding its expiry. INSERT IGNORE against the unique
 * option name admits one creator; add_option() cannot, because it upserts. A
 * holder that dies leaves the row until it expires, and only the caller whose
 * conditional delete removes that exact expired row may take it over.
 *
 * Lock rows bypass the options API and its caches on purpose.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Core;

final class NamedLock {
	public const OPTION_PREFIX = 'gtperf_lock_';

	/**
	 * @param int $wait Seconds to wait for a busy lock; 0 fails immediately.
	 */
	public static function acquire( string $name, int $ttl, int $wait = 0 ): bool {
		global $wpdb;

		$key = self::key( $name );
		if ( ! Database::isSqlite() ) {
			return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, max( 0, $wait ) ) );
		}

		$deadline = microtime( true ) + max( 0, $wait );
		do {
			if ( self::acquireRow( $key, $ttl ) ) {
				return true;
			}
			if ( microtime( true ) < $deadline ) {
				usleep( 100000 );
			}
		} while ( microtime( true ) < $deadline );

		return false;
	}

	private static function acquireRow( string $key, int $ttl ): bool {
		global $wpdb;

		$option  = self::option( $key );
		$expires = (string) ( time() + max( 1, $ttl ) );
		if ( self::insert( $option, $expires ) ) {
			return true;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		if ( null !== $current && (int) $current >= time() ) {
			return false;
		}
		if ( null !== $current ) {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, $current ) );
			if ( 1 !== (int) $deleted ) {
				return false;
			}
		}

		return self::insert( $option, $expires );
	}

	public static function release( string $name ): void {
		global $wpdb;

		$key = self::key( $name );
		if ( ! Database::isSqlite() ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			return;
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::option( $key ) ) );
	}

	private static function insert( string $option, string $expires ): bool {
		global $wpdb;

		$errors = $wpdb->suppress_errors();
		try {
			$inserted = $wpdb->query(
				$wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $option, $expires )
			);
		} finally {
			$wpdb->suppress_errors( $errors );
		}

		return 1 === (int) $inserted;
	}

	private static function option( string $key ): string {
		return self::OPTION_PREFIX . substr( hash( 'sha256', $key ), 0, 40 );
	}

	/**
	 * Lock names are scoped to this database and table prefix, so network sites
	 * and installs sharing a server never contend.
	 */
	private static function key( string $name ): string {
		global $wpdb;

		return 'gtperf_' . sanitize_key( $name ) . '_' . substr( hash( 'sha256', $wpdb->dbname . '|' . $wpdb->prefix ), 0, 40 );
	}
}
