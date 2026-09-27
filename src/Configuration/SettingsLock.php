<?php
/**
 * The one exclusive lock around every settings write.
 *
 * Admin form saves, Settings::save() callers (CLI, Cloudflare/xCloud connect),
 * restore, and import all take it, so a stale read can never overwrite a newer
 * save. It is reentrant within a request: a restore that holds it can call
 * Settings::save() without deadlocking on itself.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Configuration;

use GTPerformance\Core\NamedLock;

final class SettingsLock {
	private const NAME = 'settings';

	/** A crashed holder's SQLite row expires after this; MySQL frees it on disconnect. */
	private const TTL = 60;

	private const WAIT = 10;

	private static int $depth = 0;

	private static bool $releaseOnShutdown = false;

	public static function acquire(): bool {
		if ( self::$depth > 0 ) {
			++self::$depth;
			return true;
		}
		if ( ! NamedLock::acquire( self::NAME, self::TTL, self::WAIT ) ) {
			return false;
		}
		self::$depth = 1;
		// A value read before the lock may be stale: another writer may have saved
		// while this one waited. Without a persistent object cache, get_option()
		// would otherwise keep answering from this request's copy.
		wp_cache_delete( \GTPerformance\Core\Settings::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return true;
	}

	public static function release(): void {
		if ( self::$depth <= 0 ) {
			return;
		}
		--self::$depth;
		if ( 0 === self::$depth ) {
			NamedLock::release( self::NAME );
		}
	}

	/**
	 * For the admin form: WordPress writes the option after the sanitize callback
	 * returns, and skips its update hooks when nothing changed, so the lock taken
	 * in sanitize is released after the write or, failing that, at shutdown.
	 */
	public static function holdUntilWritten(): bool {
		if ( ! self::acquire() ) {
			return false;
		}
		if ( ! self::$releaseOnShutdown ) {
			self::$releaseOnShutdown = true;
			register_shutdown_function( array( self::class, 'releaseAll' ) );
		}

		return true;
	}

	public static function releaseAll(): void {
		if ( self::$depth > 0 ) {
			self::$depth = 1;
			self::release();
		}
	}

	public static function held(): bool {
		return self::$depth > 0;
	}
}
