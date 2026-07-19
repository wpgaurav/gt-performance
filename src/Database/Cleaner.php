<?php
/**
 * Manual and scheduled database optimization tasks.
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
	 * @param list<string>|null $tasks Tasks to run. Saved tasks are used when omitted.
	 * @return array<string, int>
	 */
	public function run( ?array $tasks = null, bool $respectRevisionRetention = false ): array {
		$tasks = $this->sanitizeTasks( $tasks ?? (array) Settings::get( 'database.tasks', array() ) );
		$result = array_fill_keys( self::TASKS, 0 );

		foreach ( $tasks as $task ) {
			$result[ $task ] = match ( $task ) {
				'revisions' => $this->deleteRevisions( $respectRevisionRetention ),
				'auto_drafts' => $this->deletePostsByStatus( 'auto-draft' ),
				'spam_comments' => $this->deleteCommentsByStatus( 'spam' ),
				'trashed_posts' => $this->deletePostsByStatus( 'trash' ),
				'trashed_comments' => $this->deleteCommentsByStatus( 'trash' ),
				'expired_transients' => $this->deleteExpiredTransients(),
				'all_transients' => $this->deleteAllTransients(),
				'optimize_tables' => $this->optimizeTables(),
				default => 0,
			};
		}

		return $result;
	}

	/**
	 * @param list<string> $tasks Tasks.
	 * @return list<string>
	 */
	public function sanitizeTasks( array $tasks ): array {
		$tasks = array_map( 'sanitize_key', $tasks );

		return array_values( array_intersect( self::TASKS, array_unique( $tasks ) ) );
	}

	private function deleteRevisions( bool $respectRetention ): int {
		global $wpdb;

		$retain = $respectRetention ? max( 0, (int) Settings::get( 'database.retain_revisions', 5 ) ) : 0;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent
				FROM {$wpdb->posts}
				WHERE post_type = 'revision'
				ORDER BY post_parent ASC, post_modified_gmt DESC
				LIMIT %d",
				max( self::BATCH_SIZE, self::BATCH_SIZE * ( $retain + 1 ) )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$seen = array();
		$ids  = array();
		foreach ( $rows as $row ) {
			$parent = (int) ( $row['post_parent'] ?? 0 );
			$seen[ $parent ] = (int) ( $seen[ $parent ] ?? 0 ) + 1;
			if ( $seen[ $parent ] <= $retain ) {
				continue;
			}

			$ids[] = (int) ( $row['ID'] ?? 0 );
			if ( count( $ids ) >= self::BATCH_SIZE ) {
				break;
			}
		}

		return $this->deletePostIds( $ids );
	}

	private function deletePostsByStatus( string $status ): int {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s ORDER BY ID ASC LIMIT %d",
				$status,
				self::BATCH_SIZE
			)
		);

		return $this->deletePostIds( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
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

	private function deleteCommentsByStatus( string $status ): int {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s ORDER BY comment_ID ASC LIMIT %d",
				$status,
				self::BATCH_SIZE
			)
		);
		$deleted = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
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

	private function optimizeTables(): int {
		global $wpdb;

		$optimized = 0;
		foreach ( $this->optimizableTables() as $table ) {
			if ( ! str_starts_with( $table, $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}

			// The table name comes from SHOW TABLE STATUS and is restricted to the current WordPress prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false !== $wpdb->query( "OPTIMIZE TABLE `{$table}`" ) ) {
				++$optimized;
			}
		}

		return $optimized;
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
