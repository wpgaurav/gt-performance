<?php
/**
 * Cooperative cancellation and publication fencing for one bounded worker unit.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Queue;

final class JobLease {
	private static int $id = 0;
	private static string $token = '';
	private static string $prefix = '';

	public static function start( int $id, string $token ): void {
		global $wpdb;
		self::$id = $id;
		self::$token = $token;
		self::$prefix = $wpdb->prefix;
	}

	public static function end(): void {
		self::$id = 0;
		self::$token = '';
		self::$prefix = '';
	}

	/** Local loopback requests inherit ownership so a late response cannot publish. */
	public static function fromRequest(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Random lease token is checked against the queue row before any write.
		if ( isset( $_SERVER['HTTP_X_GT_JOB_ID'], $_SERVER['HTTP_X_GT_JOB_TOKEN'] ) ) {
			self::start( max( 1, absint( $_SERVER['HTTP_X_GT_JOB_ID'] ) ), sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_GT_JOB_TOKEN'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/** @return array<string,string> */
	public static function headers(): array {
		return self::$id > 0 ? array(
			'X-GT-Job-ID' => (string) self::$id,
			'X-GT-Job-Token' => self::$token,
		) : array();
	}

	public static function checkpoint(): void {
		global $wpdb;
		if ( self::$id > 0 && ( self::$prefix !== $wpdb->prefix || ! ( new JobRepository() )->renew( self::$id, self::$token ) ) ) {
			throw new \RuntimeException( 'Job cancelled or its worker lease was superseded.' );
		}
	}

	/**
	 * Hold the job row only for the short final publication, never for generation
	 * or HTTP. A competing claim cannot replace the token between check and rename.
	 *
	 * @param callable():bool $publish Atomic publication callback.
	 */
	public static function publish( callable $publish ): bool {
		if ( 0 === self::$id ) {
			return $publish();
		}
		global $wpdb;
		if ( self::$prefix !== $wpdb->prefix ) {
			return false;
		}
		$table = $wpdb->prefix . 'gtperf_jobs';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		try {
			$owned = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE id = %d AND lock_token = %s AND status = 'running'
				AND cancel_requested = 0 AND lease_expires_at >= UTC_TIMESTAMP() FOR UPDATE",
					$table,
					self::$id,
					self::$token
				)
			);
			if ( ! $owned || ! $publish() ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			return false !== $wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
