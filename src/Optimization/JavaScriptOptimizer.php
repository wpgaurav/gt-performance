<?php
/**
 * Conservative JavaScript loading optimization.
 *
 * Decisions come from ScriptPlan, which reads WordPress's script registry
 * (dependencies and inline code) rather than guessing from URLs. Registered
 * scripts are matched to their tags by the `{handle}-js` id WordPress prints.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Core\Settings;

final class JavaScriptOptimizer {
	/** Sources whose names signal checkout or payment code. */
	private const COMMERCE = array( 'checkout', 'payment', 'cart' );

	/** @var array<string, array{action:string,reason:string}> */
	private array $lastPlan = array();

	public function optimize( string $html ): string {
		$override = PageOverrides::javascript();
		$defer    = (bool) Settings::get( 'javascript.defer', false ) && 'off' !== $override;
		$delay    = (bool) Settings::get( 'javascript.delay', false ) && ! in_array( $override, array( 'off', 'no_delay' ), true );
		$minify   = (bool) Settings::get( 'javascript.minify', false );
		if ( ( ! $defer && ! $delay && ! $minify ) || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$exclusions = array_map( 'strval', (array) apply_filters( 'gt_performance_javascript_exclusions', array_map( 'strval', (array) Settings::get( 'javascript.exclusions', array() ) ) ) );
		$patterns   = array_map( 'strval', (array) Settings::get( 'javascript.delay_patterns', array() ) );
		$excluded   = fn ( string $src ): bool => $this->matches( $src, $exclusions ) || $this->matches( $src, self::COMMERCE );
		$selected   = fn ( string $src ): bool => $this->matches( $src, $patterns );
		$plan       = ScriptPlan::build( self::registry(), $defer, $delay, $excluded, $selected );

		$this->lastPlan = $plan;
		$processor      = new \WP_HTML_Tag_Processor( $html );
		$hasDelayed     = false;
		$unregistered   = 0;

		while ( $processor->next_tag( array( 'tag_name' => 'SCRIPT' ) ) ) {
			$src  = (string) $processor->get_attribute( 'src' );
			$type = strtolower( (string) $processor->get_attribute( 'type' ) );
			if ( '' === $src || ! in_array( $type, array( '', 'text/javascript', 'application/javascript' ), true ) ) {
				continue;
			}

			$id     = (string) $processor->get_attribute( 'id' );
			$handle = str_ends_with( $id, '-js' ) ? substr( $id, 0, -3 ) : '';
			if ( isset( $plan[ $handle ] ) ) {
				$action = $plan[ $handle ]['action'];
			} else {
				// Not registered with WordPress: ordering unknown. Delay only on explicit selection.
				$action = $delay && $selected( $src ) && ! $excluded( $src ) ? ScriptPlan::DELAY : ScriptPlan::KEEP;
				++$unregistered;
			}
			if ( $excluded( $src ) && ScriptPlan::KEEP === $action ) {
				continue;
			}

			if ( $minify && null === $processor->get_attribute( 'integrity' ) ) {
				$minified = ( new JavaScriptMinifier() )->minifiedUrl( $src );
				if ( null !== $minified ) {
					$src = $minified;
					$processor->set_attribute( 'src', $src );
				}
			}

			if ( ScriptPlan::DELAY === $action ) {
				$processor->set_attribute( 'data-gtp-src', $src );
				$processor->set_attribute( 'type', 'text/gtp-delayed' );
				$processor->remove_attribute( 'src' );
				$processor->remove_attribute( 'defer' );
				$hasDelayed = true;
			} elseif ( ScriptPlan::DEFER === $action && null === $processor->get_attribute( 'async' ) ) {
				$processor->set_attribute( 'defer', '' );
			}
		}

		$output = $processor->get_updated_html();
		if ( $hasDelayed ) {
			$output = self::beforeBodyEnd( $output, BufferedAssets::script( 'delay' ) );
		}
		if ( (bool) Settings::get( 'debug', false ) ) {
			$output = self::beforeBodyEnd( $output, self::explanation( $plan, $unregistered, $override ) );
		}

		return $output;
	}

	/**
	 * The last decisions made, for diagnostics.
	 *
	 * @return array<string, array{action:string,reason:string}>
	 */
	public function lastPlan(): array {
		return $this->lastPlan;
	}

	/**
	 * Printed registered scripts in print order.
	 *
	 * @return array<string, array{src:string,deps:list<string>,after:bool}>
	 */
	private static function registry(): array {
		$scripts  = wp_scripts();
		$registry = array();
		foreach ( (array) $scripts->done as $handle ) {
			$handle = (string) $handle;
			$item   = $scripts->registered[ $handle ] ?? null;
			if ( ! $item instanceof \_WP_Dependency ) {
				continue;
			}
			// Aliases such as `jquery` have no source and no tag of their own, but they
			// carry dependency edges and inline code: `jquery-js-after` runs right after
			// jquery-core and jquery-migrate, so neither may be deferred past it.
			$registry[ $handle ] = array(
				'src'  => is_string( $item->src ) ? $item->src : '',
				'deps' => array_values( array_map( 'strval', (array) $item->deps ) ),
				'after' => array() !== array_filter( (array) $scripts->get_data( $handle, 'after' ) ),
			);
		}

		return $registry;
	}

	/**
	 * @param array<string, array{action:string,reason:string}> $plan Plan.
	 */
	private static function explanation( array $plan, int $unregistered, string $override ): string {
		$lines = array( 'GT Performance JavaScript decisions' . ( '' !== $override ? ' (page override: ' . $override . ')' : '' ) . ':' );
		foreach ( $plan as $handle => $decision ) {
			$lines[] = $handle . ': ' . $decision['action'] . ' - ' . $decision['reason'];
		}
		if ( $unregistered > 0 ) {
			$lines[] = $unregistered . ' script(s) not registered with WordPress were not deferred; delayed only if selected.';
		}

		return "\n<!-- " . str_replace( '--', '- -', implode( "\n", $lines ) ) . " -->\n";
	}

	private static function beforeBodyEnd( string $html, string $insert ): string {
		$position = strripos( $html, '</body>' );

		return false === $position ? $html . $insert : substr( $html, 0, $position ) . $insert . substr( $html, $position );
	}

	/**
	 * @param list<string> $needles Substrings.
	 */
	private function matches( string $src, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && str_contains( $src, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
