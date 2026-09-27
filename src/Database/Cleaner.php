<?php
/**
 * Manual and scheduled database optimization tasks.
 *
 * Database maintenance is the feature itself: counting and deleting revisions,
 * spam, transients, and reclaimable space requires direct queries that no
 * WordPress API expresses, and their one-shot results must not be cached.
 * Table names interpolate only the trusted WordPress table prefix.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Database;

use GTPerformance\Core\Settings;

final class Cleaner {
	/**
	 * @var list<string>
	 */
	public const TASKS = array(
		'revisions',
		'auto_drafts',
		'spam_comments',
		'trashed_posts',
		'trashed_comments',
		'expired_transients',
		'all_transients',
		'optimize_tables',
	);

	private const BATCH_SIZE = 1000;

	/**
	 * @return array<string, int>
	 */
	public function preview(): array {
		global $wpdb;

		$now = time();

		return array(
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
			'expired_transients' => $this->expiredTransientCount( $now ),
			'all_transients'     => $this->allTransientCount(),
			'optimize_tables'    => count( $this->optimizableTables() ),
		);
	}

	/**
	 * Run tasks to completion in one request. WP-CLI uses this; the admin and the
	 * schedule go through CleanupRun, which spreads the same slices over the queue.
	 *
	 * @param list<string>|null $tasks Tasks to run. Saved tasks are used when omitted.
	 * @return array<string, int>
	 */
	public function run( ?array $tasks = null, bool $respectRevisionRetention = false ): array {
		$tasks  = $this->sanitizeTasks( $tasks ?? (array) Settings::get( 'database.tasks', array() ) );
		$result = array_fill_keys( self::TASKS, 0 );

		foreach ( $tasks as $task ) {
			$cursor = array();
			do {
				$slice            = $this->slice( $task, self::BATCH_SIZE, $respectRevisionRetention, $cursor );
				$result[ $task ] += $slice['count'];
				$cursor           = $slice['cursor'];
			} while ( ! $slice['done'] );
		}

		return $result;
	}

	/**
	 * Run one bounded piece of a task.
	 *
	 * A slice deletes at most $limit rows or optimizes one table. It reports done
	 * when nothing more remains, or when it removed nothing, so a row WordPress
	 * refuses to delete cannot keep a run going forever.
	 *
	 * @param array<string, mixed> $cursor State carried from the previous slice of this task.
	 * @return array{count:int,done:bool,cursor:array<string,mixed>}
	 */
	public function slice( string $task, int $limit, bool $respectRevisionRetention = false, array $cursor = array() ): array {
		$limit = max( 1, $limit );

		if ( 'optimize_tables' === $task ) {
			// OPTIMIZE often leaves InnoDB reporting free space, so the list is fixed
			// on the first slice rather than re-read, or the task would never end.
			$tables = array_values( array_map( 'strval', (array) ( $cursor['tables'] ?? $this->optimizableTables() ) ) );
			$table  = array_shift( $tables );
			$count  = null !== $table && $this->optimizeTable( $table ) ? 1 : 0;

			return array(
				'count'  => $count,
				'done'   => array() === $tables,
				'cursor' => array( 'tables' => $tables ),
			);
		}

		if ( in_array( $task, array( 'expired_transients', 'all_transients' ), true ) ) {
			return array(
				'count'  => 'expired_transients' === $task ? $this->deleteExpiredTransients() : $this->deleteAllTransients(),
				'done'   => true,
				'cursor' => array(),
			);
		}

		$retain = $respectRevisionRetention ? max( 0, (int) Settings::get( 'database.retain_revisions', 5 ) ) : 0;
		$ids    = match ( $task ) {
			'revisions' => $this->revisionIds( $retain, $limit ),
			'auto_drafts' => $this->postIdsByStatus( 'auto-draft', $limit ),
			'trashed_posts' => $this->postIdsByStatus( 'trash', $limit ),
			'spam_comments' => $this->commentIdsByStatus( 'spam', $limit ),
			'trashed_comments' => $this->commentIdsByStatus( 'trash', $limit ),
			default => array(),
		};
		$count = str_ends_with( $task, '_comments' ) ? $this->deleteCommentIds( $ids ) : $this->deletePostIds( $ids );

		// A short revision slice can still leave posts beyond its parent window.
		$exhausted = 'revisions' === $task ? array() === $this->revisionIds( $retain, 1 ) : count( $ids ) < $limit;

		return array(
			'count'  => $count,
			'done'   => $exhausted || 0 === $count,
			'cursor' => array(),
		);
	}

	/**
	 * @param list<string> $tasks Tasks.
	 * @return list<string>
	 */
	public function sanitizeTasks( array $tasks ): array {
		$tasks = array_map( 'sanitize_key', $tasks );

		return array_values( array_intersect( self::TASKS, array_unique( $tasks ) ) );
	}

	/**
	 * Revisions beyond the newest $retain of each post.
	 *
	 * Grouping first finds every post that has too many, wherever it sorts, so a
	 * run with retention cannot stop early on a window of posts that are within it.
	 *
	 * @return list<int>
	 */
	private function revisionIds( int $retain, int $limit ): array {
		global $wpdb;

		$parents = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > %d LIMIT %d",
				$retain,
				$limit
			)
		);

		$ids = array();
		foreach ( is_array( $parents ) ? $parents : array() as $parent ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_modified_gmt DESC, ID DESC LIMIT %d OFFSET %d",
					(int) $parent,
					$limit - count( $ids ),
					$retain
				)
			);
			$ids = array_merge( $ids, array_map( 'intval', is_array( $rows ) ? $rows : array() ) );
			if ( count( $ids ) >= $limit ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * @return list<int>
	 */
	private function postIdsByStatus( string $status, int $limit ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s ORDER BY ID ASC LIMIT %d",
				$status,
				$limit
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * @param list<int> $ids Post IDs.
	 */
	private function deletePostIds( array $ids ): int {
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( $id > 0 && false !== wp_delete_post( $id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * @return list<int>
	 */
	private function commentIdsByStatus( string $status, int $limit ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s ORDER BY comment_ID ASC LIMIT %d",
				$status,
				$limit
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * @param list<int> $ids Comment IDs.
	 */
	private function deleteCommentIds( array $ids ): int {
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( $id > 0 && wp_delete_comment( $id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	private function deleteExpiredTransients(): int {
		$count = $this->expiredTransientCount( time() );
		delete_expired_transients( true );

		return $count;
	}

	private function deleteAllTransients(): int {
		global $wpdb;

		$count = $this->allTransientCount();
		$like  = array(
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like[0],
				$like[1]
			)
		);

		if ( is_multisite() ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( '_site_transient_' ) . '%'
				)
			);
		}

		wp_cache_flush();

		return $count;
	}

	private function optimizeTable( string $table ): bool {
		global $wpdb;

		if ( ! str_starts_with( $table, $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return false;
		}

		// The table name comes from SHOW TABLE STATUS and is restricted to the current WordPress prefix.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $wpdb->query( "OPTIMIZE TABLE `{$table}`" );
	}

	private function expiredTransientCount( int $now ): int {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				WHERE (option_name LIKE %s OR option_name LIKE %s)
				AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				$now
			)
		);

		if ( is_multisite() ) {
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->sitemeta}
					WHERE meta_key LIKE %s AND meta_value < %d",
					$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
					$now
				)
			);
		}

		return $count;
	}

	private function allTransientCount(): int {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			)
		);

		if ( is_multisite() ) {
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( '_site_transient_' ) . '%'
				)
			);
		}

		return $count;
	}

	/**
	 * @return list<string>
	 */
	private function optimizableTables(): array {
		global $wpdb;

		// SHOW TABLE STATUS has no user-controlled fragments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$tables = array();
		foreach ( $rows as $row ) {
			$name = (string) ( $row['Name'] ?? '' );
			if (
				str_starts_with( $name, $wpdb->prefix ) &&
				(int) ( $row['Data_free'] ?? 0 ) > 0 &&
				in_array( strtolower( (string) ( $row['Engine'] ?? '' ) ), array( 'innodb', 'myisam', 'aria' ), true )
			) {
				$tables[] = $name;
			}
		}

		return $tables;
	}
}
