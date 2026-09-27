<?php
/**
 * Per-page GT Performance options in the post editor.
 *
 * A classic meta box, so it appears in both editors without a build step.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Admin;

use GTPerformance\Optimization\HeroRules;
use GTPerformance\Optimization\PageOverrides;

final class PageOptionsBox {
	private const NONCE = 'gtperf_page_options';

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	public function add( string $postType ): void {
		if ( ! is_post_type_viewable( $postType ) ) {
			return;
		}
		add_meta_box( 'gtperf-page-options', __( 'GT Performance', 'gt-performance' ), array( $this, 'render' ), $postType, 'side', 'low' );
	}

	public function render( \WP_Post $post ): void {
		$mode = (string) get_post_meta( $post->ID, PageOverrides::JS_META, true );
		$hero = (string) get_post_meta( $post->ID, PageOverrides::HERO_META, true );
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label for="gtperf-js-mode"><strong><?php esc_html_e( 'JavaScript on this page', 'gt-performance' ); ?></strong></label><br>
			<select id="gtperf-js-mode" name="gtperf_javascript">
				<option value="" <?php selected( $mode, '' ); ?>><?php esc_html_e( 'Site settings', 'gt-performance' ); ?></option>
				<option value="no_delay" <?php selected( $mode, 'no_delay' ); ?>><?php esc_html_e( 'Do not delay scripts', 'gt-performance' ); ?></option>
				<option value="off" <?php selected( $mode, 'off' ); ?>><?php esc_html_e( 'Do not defer or delay scripts', 'gt-performance' ); ?></option>
			</select>
		</p>
		<p>
			<label for="gtperf-hero"><strong><?php esc_html_e( 'Hero image', 'gt-performance' ); ?></strong></label><br>
			<input type="text" id="gtperf-hero" name="gtperf_hero" class="widefat" value="<?php echo esc_attr( $hero ); ?>" placeholder="attachment:123 preload">
			<small><?php esc_html_e( 'attachment:<ID>, .<image class>, or url:<background image URL>; add "preload" to preload it. Leave empty to use site rules.', 'gt-performance' ); ?></small>
		</p>
		<p><small><?php esc_html_e( 'Changes apply to newly cached copies of this page.', 'gt-performance' ); ?></small></p>
		<?php
	}

	public function save( int $postId, \WP_Post $post ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce values are compared, not stored.
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), self::NONCE ) || wp_is_post_revision( $postId ) || ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}
		unset( $post );
		$mode = isset( $_POST['gtperf_javascript'] ) ? sanitize_key( wp_unslash( $_POST['gtperf_javascript'] ) ) : '';
		$hero = isset( $_POST['gtperf_hero'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['gtperf_hero'] ) ) ) : '';

		in_array( $mode, PageOverrides::JS_MODES, true ) && '' !== $mode
			? update_post_meta( $postId, PageOverrides::JS_META, $mode )
			: delete_post_meta( $postId, PageOverrides::JS_META );
		null !== HeroRules::parseLine( '* => ' . $hero )
			? update_post_meta( $postId, PageOverrides::HERO_META, $hero )
			: delete_post_meta( $postId, PageOverrides::HERO_META );
	}
}
