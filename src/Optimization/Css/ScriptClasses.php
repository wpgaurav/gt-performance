<?php
/**
 * What page scripts add in the browser, learned per post type.
 *
 * The generator reads server HTML, so anything a script builds after load (a table
 * of contents, an ad rail, a slider's state classes) looks unused and its rules
 * were pruned. An administrator's browser renders sample pages of each post type
 * and compares the live DOM with the same page's server HTML. It reports the
 * classes and IDs scripts added, and the selectors that match only once they ran,
 * which covers rules such as `.toc li` that name no class a script added. Builds
 * for that post type keep every rule that names one of those tokens or selectors.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

final class ScriptClasses {
	public const OPTION         = 'gt_performance_css_script_classes';
	public const PENDING_OPTION = 'gt_performance_css_script_scan';

	/** Pages rendered per post type; two let an ID prove it is not per-page. */
	private const SAMPLES = 2;

	/** Post types checked per scan, those with the most entries first, so one scan stays short. */
	private const MAX_TYPES = 8;

	/** Builder template types: public for previews, never what visitors read. */
	private const SKIPPED_TYPES = array( 'attachment', 'bricks_template', 'elementor_library', 'et_pb_layout', 'ct_template', 'fl-builder-template', 'breakdance_template' );

	/** Tokens kept per post type and kind. */
	private const MAX_TOKENS = 400;

	/** Selectors kept per post type. */
	private const MAX_SELECTORS = 500;

	/**
	 * Ask the next administrator visit to GT Performance to run a scan.
	 */
	public static function requestScan(): void {
		update_option( self::PENDING_OPTION, time(), false );
	}

	public static function pending(): bool {
		return false !== get_option( self::PENDING_OPTION, false );
	}

	/**
	 * Recent public pages to render, grouped by post type.
	 *
	 * @return array<string, array{label:string,urls:list<string>}>
	 */
	public function targets(): array {
		$targets = array();
		$front   = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$home    = home_url( '/' );

		// Only what visitors can open and find. Types hidden from search are builder
		// templates or items shown inside other pages, not pages a visitor browses.
		$types = array_filter(
			array_diff_key( get_post_types( array( 'public' => true ), 'objects' ), array_flip( self::SKIPPED_TYPES ) ),
			static fn( \WP_Post_Type $object ): bool => is_post_type_viewable( $object ) && ! $object->exclude_from_search
		);
		$sizes = array_map( static fn( string $type ): int => (int) ( wp_count_posts( $type )->publish ?? 0 ), array_combine( array_keys( $types ), array_keys( $types ) ) );
		arsort( $sizes );
		// Pages hold the front page and landing pages, so they never lose their place.
		$order = array_unique( array_merge( array_intersect( array( 'page', 'post' ), array_keys( $sizes ) ), array_keys( $sizes ) ) );

		foreach ( $order as $type ) {
			$object = $types[ $type ];
			if ( count( $targets ) >= self::MAX_TYPES ) {
				break;
			}

			$ids = array_map(
				'intval',
				get_posts(
					array(
						'post_type'    => $type,
						'post_status'  => 'publish',
						'has_password' => false,
						'numberposts'  => self::SAMPLES,
						'orderby'      => 'modified',
						'order'        => 'DESC',
						'fields'       => 'ids',
					)
				)
			);
			// The front page is usually the most distinctive page a site has.
			if ( 'page' === $type && $front > 0 ) {
				$ids = array_slice( array_values( array_unique( array_merge( array( $front ), $ids ) ) ), 0, self::SAMPLES );
			}

			$urls = array();
			foreach ( $ids as $id ) {
				$url = get_permalink( $id );
				if ( is_string( $url ) && str_starts_with( $url, $home ) ) {
					$urls[] = $url;
				}
			}
			if ( $urls ) {
				$targets[ (string) $type ] = array(
					'label' => html_entity_decode( (string) $object->labels->name, ENT_QUOTES ),
					'urls'  => $urls,
				);
			}
		}

		return $targets;
	}

	/**
	 * Store one scan, replacing each post type it covered.
	 *
	 * @param mixed        $scan  Decoded browser report: post type => {classes, ids, pages}.
	 * @param list<string> $types Post types that still exist; stored data for any other is dropped.
	 * @return bool Whether the learned tokens changed.
	 */
	public function save( mixed $scan, array $types ): bool {
		$before = $this->all();
		$stored = array_intersect_key( $before, array_flip( $types ) );

		foreach ( is_array( $scan ) ? $scan : array() as $type => $found ) {
			if ( ! in_array( $type, $types, true ) || ! is_array( $found ) ) {
				continue;
			}
			$stored[ $type ] = array(
				'classes'   => $this->tokens( $found['classes'] ?? array() ),
				'ids'       => $this->tokens( $found['ids'] ?? array() ),
				'selectors' => $this->selectors( $found['selectors'] ?? array() ),
				'pages'     => max( 0, min( 100, (int) ( $found['pages'] ?? 0 ) ) ),
				'scanned'   => time(),
			);
		}
		ksort( $stored );

		update_option( self::OPTION, $stored, false );
		delete_option( self::PENDING_OPTION );

		return $this->tokensOnly( $before ) !== $this->tokensOnly( $stored );
	}

	/**
	 * @return array<string, array{classes:list<string>,ids:list<string>,selectors:list<string>,pages:int,scanned:int}>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$all    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $type => $found ) {
			if ( ! is_string( $type ) || ! is_array( $found ) ) {
				continue;
			}
			$all[ $type ] = array(
				'classes'   => $this->tokens( $found['classes'] ?? array() ),
				'ids'       => $this->tokens( $found['ids'] ?? array() ),
				'selectors' => $this->selectors( $found['selectors'] ?? array() ),
				'pages'     => (int) ( $found['pages'] ?? 0 ),
				'scanned'   => (int) ( $found['scanned'] ?? 0 ),
			);
		}

		return $all;
	}

	/**
	 * Safelist pattern for the post type being rendered, or '' when there is none.
	 */
	public function patternForRequest(): string {
		$type = $this->requestType();

		return null === $type ? '' : $this->pattern( $type );
	}

	/**
	 * Selectors to keep for the post type being rendered, by CssPruner::canonical() spelling.
	 *
	 * @return array<string, true>
	 */
	public function selectorsForRequest(): array {
		$type = $this->requestType();

		return null === $type ? array() : array_fill_keys( $this->all()[ $type ]['selectors'] ?? array(), true );
	}

	private function requestType(): ?string {
		if ( ! is_singular() ) {
			return null;
		}
		$type = get_post_type( get_queried_object_id() );

		return is_string( $type ) ? $type : null;
	}

	/**
	 * One expression that matches a selector naming any learned token exactly:
	 * `level-3` protects `.level-3` and `.level-3:hover`, never `.level-30`.
	 */
	public function pattern( string $type ): string {
		$found = $this->all()[ $type ] ?? null;
		if ( null === $found ) {
			return '';
		}

		$quote = static fn( string $token ): string => preg_quote( $token, '/' );
		$parts = array();
		if ( $found['classes'] ) {
			$parts[] = '\.(?:' . implode( '|', array_map( $quote, $found['classes'] ) ) . ')';
		}
		if ( $found['ids'] ) {
			$parts[] = '#(?:' . implode( '|', array_map( $quote, $found['ids'] ) ) . ')';
		}

		return $parts ? '/(?:' . implode( '|', $parts ) . ')(?![\w-])/' : '';
	}

	/**
	 * Names a selector can spell without escapes, minus GT Performance's own.
	 *
	 * @return list<string>
	 */
	private function tokens( mixed $values ): array {
		$tokens = array();
		foreach ( is_array( $values ) ? $values : array() as $value ) {
			if ( count( $tokens ) >= self::MAX_TOKENS ) {
				break;
			}
			if ( is_string( $value ) && 1 === preg_match( '/^-?[_a-zA-Z][_a-zA-Z0-9-]{0,79}$/', $value )
				&& ! str_starts_with( $value, 'gtp-' ) && ! str_starts_with( $value, 'gtperf' ) ) {
				$tokens[ $value ] = true;
			}
		}
		$tokens = array_map( 'strval', array_keys( $tokens ) );
		sort( $tokens );

		return $tokens;
	}

	/**
	 * Selectors in their canonical spelling. They are only ever compared, never
	 * output, so anything that could not be a single selector is simply dropped.
	 *
	 * @return list<string>
	 */
	private function selectors( mixed $values ): array {
		$selectors = array();
		foreach ( is_array( $values ) ? $values : array() as $value ) {
			if ( count( $selectors ) >= self::MAX_SELECTORS ) {
				break;
			}
			if ( is_string( $value ) && strlen( $value ) <= 300 && 1 !== preg_match( '/[{};<]|\/\*/', $value ) ) {
				$selector = CssPruner::canonical( $value );
				if ( '' !== $selector ) {
					$selectors[ $selector ] = true;
				}
			}
		}
		$selectors = array_map( 'strval', array_keys( $selectors ) );
		sort( $selectors );

		return $selectors;
	}

	/**
	 * @param array<string, array{classes:list<string>,ids:list<string>,selectors:list<string>,pages:int,scanned:int}> $stored Stored scans.
	 * @return array<string, array{0:list<string>,1:list<string>,2:list<string>}>
	 */
	private function tokensOnly( array $stored ): array {
		return array_map( static fn( array $found ): array => array( $found['classes'], $found['ids'], $found['selectors'] ), $stored );
	}
}
