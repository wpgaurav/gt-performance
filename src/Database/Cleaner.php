<?php
/**
 * Safe database cleanup tasks.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Database;

final class Cleaner {
	/**
	 * @return array<string, int>
	 */
	public function preview(): array {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		return array(
			'old_revisions'      => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
					$cutoff
				)
			),
			'auto_drafts'        => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified_gmt < %s",
					$cutoff
				)
			),
			'trash_posts'        => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified_gmt < %s",
					$cutoff
				)
			),
			'expired_transients' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					time()
				)
			),
		);
	}

	/**
	 * @return array<string, int>
	 */
	public function run(): array {
		global $wpdb;

		$expiredCount = $this->preview()['expired_transients'];
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ((post_type = 'revision') OR (post_status IN ('auto-draft','trash')))
				AND post_modified_gmt < %s
				LIMIT 1000",
				$cutoff
			)
		);

		$deleted = 0;
		foreach ( $ids as $id ) {
			$deleted += false !== wp_delete_post( (int) $id, true ) ? 1 : 0;
		}

		delete_expired_transients( true );

		return array(
			'posts'      => $deleted,
			'transients' => $expiredCount,
		);
	}
}
