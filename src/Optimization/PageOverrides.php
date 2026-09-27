<?php
/**
 * Per-page optimization choices stored on the post being viewed.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

final class PageOverrides {
	public const JS_META   = '_gtperf_javascript';
	public const HERO_META = '_gtperf_hero';

	/** @var list<string> */
	public const JS_MODES = array( '', 'no_delay', 'off' );

	/**
	 * '' (site settings), 'no_delay', or 'off' for the post being viewed.
	 */
	public static function javascript(): string {
		$id = self::postId();
		if ( 0 === $id ) {
			return '';
		}
		$mode = (string) get_post_meta( $id, self::JS_META, true );

		return in_array( $mode, self::JS_MODES, true ) ? $mode : '';
	}

	/**
	 * The hero rule line saved on the post being viewed, if any.
	 */
	public static function hero(): string {
		$id = self::postId();

		return 0 === $id ? '' : (string) get_post_meta( $id, self::HERO_META, true );
	}

	/**
	 * @return array{front_page:bool,post_type:string,template:string}
	 */
	public static function context(): array {
		$id       = self::postId();
		$template = 0 === $id ? '' : (string) get_page_template_slug( $id );
		global $_wp_current_template_id;
		if ( '' === $template && is_string( $_wp_current_template_id ?? null ) ) {
			// Block themes: "theme//front-page" becomes "front-page".
			$template = (string) substr( (string) strrchr( '/' . $_wp_current_template_id, '/' ), 1 );
		}

		return array(
			'front_page' => function_exists( 'is_front_page' ) && is_front_page(),
			'post_type'  => 0 === $id ? '' : (string) get_post_type( $id ),
			'template'   => $template,
		);
	}

	private static function postId(): int {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return 0;
		}

		return (int) get_queried_object_id();
	}
}
