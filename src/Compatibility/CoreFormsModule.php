<?php
/**
 * Core Forms page-cache compatibility.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Compatibility;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class CoreFormsModule implements Module {
	private const VOTER_COOKIE = 'cf_poll_voter';

	public function register(): void {
		add_action( 'send_headers', array( $this, 'stripGlobalPollCookie' ), 9998 );
	}

	public function stripGlobalPollCookie(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if (
			! (bool) Settings::get( 'cache.enabled', false )
			|| 'GET' !== $method
			|| is_user_logged_in()
			|| is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| $this->currentPageContainsPoll()
		) {
			return;
		}

		$result = ( new SetCookieHeaders() )->removeCookie( headers_list(), self::VOTER_COOKIE );
		if ( ! $result['removed'] ) {
			return;
		}

		header_remove( 'Set-Cookie' );
		foreach ( $result['kept'] as $header ) {
			header( $header, false );
		}
	}

	private function currentPageContainsPoll(): bool {
		global $post, $wp_query;

		$posts = array();
		if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
			$posts = $wp_query->posts;
		}
		if ( $post instanceof \WP_Post && ! in_array( $post, $posts, true ) ) {
			$posts[] = $post;
		}

		$found = false;
		foreach ( $posts as $candidate ) {
			if ( ! $candidate instanceof \WP_Post ) {
				continue;
			}

			$content = (string) $candidate->post_content;
			if (
				( function_exists( 'has_block' ) && has_block( 'core-forms/poll', $candidate ) )
				|| ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'cf_poll' ) )
				|| str_contains( $content, '<!-- wp:core-forms/poll' )
				|| str_contains( $content, '[cf_poll' )
			) {
				$found = true;
				break;
			}
		}

		return (bool) apply_filters( 'gt_performance_core_forms_poll_detected', $found, $posts );
	}
}
