<?php
/**
 * Which cached pages depend on which posts, terms, and query sets.
 *
 * Rows live in the plugin's own gtperf_dependencies table, so every access is a
 * direct query. A page's rows are replaced whenever it is stored again and are
 * bound to the settings generation it was stored under: rows from an older
 * generation describe pages that can no longer be served and never drive a
 * purge. Table names interpolate only the trusted WordPress table prefix.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Database;
use GTPerformance\Core\Settings;

final class DependencyIndex {
	/** Past this many, a page records one broad dependency instead. */
	public const MAX_PER_PAGE = 300;

	/** Upper bound on URLs one change can add to a purge. */
	public const MAX_DEPENDENTS = 500;

	/**
	 * Replace a stored page's dependencies.
	 *
	 * @param list<string> $signatures Entity signatures such as post:12 or term:4.
	 */
	public function replace( string $url, string $variant, array $signatures ): bool {
		global $wpdb;

		if ( ! Database::queueReady() ) {
			return false;
		}
		$table      = $wpdb->prefix . 'gtperf_dependencies';
		$hash       = hash( 'sha256', $url );
		$generation = (int) Settings::get( 'generation', 1 );
		$signatures = array_values( array_unique( $signatures ) );
		if ( count( $signatures ) > self::MAX_PER_PAGE ) {
			$signatures = array( 'broad:*' );
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE url_hash = %s AND variant = %s", $hash, $variant ) );
		if ( array() === $signatures ) {
			return true;
		}

		$rows   = array();
		$values = array();
		$now    = current_time( 'mysql', true );
		foreach ( $signatures as $signature ) {
			list( $type, $id ) = array_pad( explode( ':', $signature, 2 ), 2, '' );
			$rows[]            = '(%s, %s, %s, %s, %s, %d, %s)';
			array_push( $values, substr( sanitize_key( $type ), 0, 32 ), substr( $id, 0, 191 ), $url, $hash, $variant, $generation, $now );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder group per row above.
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (entity_type, entity_id, cache_url, url_hash, variant, generation, updated_at) VALUES " . implode( ', ', $rows ), $values ) );

		return false !== $result;
	}

	/**
	 * Cached URLs that depend on any of these signatures, current generation only.
	 * Pages that recorded a broad dependency are included for any change.
	 *
	 * @param list<string> $signatures Signatures.
	 * @return array<string, list<string>> URL => the signatures that matched it.
	 */
	public function dependents( array $signatures ): array {
		global $wpdb;

		if ( ! Database::queueReady() || array() === $signatures ) {
			return array();
		}
		$table   = $wpdb->prefix . 'gtperf_dependencies';
		$clauses = array();
		foreach ( array_unique( array_merge( $signatures, array( 'broad:*' ) ) ) as $signature ) {
			list( $type, $id ) = array_pad( explode( ':', $signature, 2 ), 2, '' );
			// Each pair is prepared on its own, so every prepare() call has a fixed
			// placeholder count; the (entity_type, entity_id) index still applies.
			$clauses[] = $wpdb->prepare( '(entity_type = %s AND entity_id = %s)', $type, $id );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Clauses are prepared individually above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT cache_url, entity_type, entity_id FROM {$table} WHERE (" . implode( ' OR ', $clauses ) . ') AND generation = %d LIMIT %d', (int) Settings::get( 'generation', 1 ), self::MAX_DEPENDENTS * 4 ), ARRAY_A );

		$urls = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$url = (string) $row['cache_url'];
			if ( ! isset( $urls[ $url ] ) && count( $urls ) >= self::MAX_DEPENDENTS ) {
				continue;
			}
			$urls[ $url ][] = $row['entity_type'] . ':' . $row['entity_id'];
		}

		return array_map( static fn ( array $matched ): array => array_values( array_unique( $matched ) ), $urls );
	}

	/**
	 * Distinct pages indexed under the current generation.
	 */
	public function indexedPages(): int {
		global $wpdb;

		if ( ! Database::queueReady() ) {
			return 0;
		}
		$table = $wpdb->prefix . 'gtperf_dependencies';

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT url_hash) FROM {$table} WHERE generation = %d", (int) Settings::get( 'generation', 1 ) ) );
	}

	/**
	 * Remove rows from older generations and rows older than any page could live.
	 */
	public function prune( int $limit = 2000 ): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'gtperf_dependencies';
		$maxAge = max( 0, (int) Settings::get( 'cache.fresh_ttl', 3600 ) ) + max( 0, (int) Settings::get( 'cache.stale_ttl', 86400 ) ) + DAY_IN_SECONDS;
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE generation <> %d OR updated_at < %s LIMIT %d",
				(int) Settings::get( 'generation', 1 ),
				gmdate( 'Y-m-d H:i:s', time() - $maxAge ),
				max( 1, $limit )
			)
		);

		return is_int( $result ) ? $result : 0;
	}
}
