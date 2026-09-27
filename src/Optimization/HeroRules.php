<?php
/**
 * Explicit hero (largest above-the-fold image) rules.
 *
 * Document order is a guess; these are statements. A rule line reads
 * `<scope> => <target> [preload]`:
 *
 * - scope:  `*`, `front_page`, `post_type:<type>`, or `template:<template>`;
 * - target: `attachment:<id>`, `.<class>` on the <img>, or `url:<image URL>`
 *           for a CSS background hero, which is preloaded but never guessed
 *           from stylesheets.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

final class HeroRules {
	public const MAX_RULES = 30;

	/**
	 * @param list<string> $lines Rule lines.
	 * @return list<array{scope:string,value:string,target:string,ref:string,preload:bool}>
	 */
	public static function parse( array $lines ): array {
		$rules = array();
		foreach ( array_slice( $lines, 0, self::MAX_RULES ) as $line ) {
			$rule = self::parseLine( (string) $line );
			if ( null !== $rule ) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * @return array{scope:string,value:string,target:string,ref:string,preload:bool}|null
	 */
	public static function parseLine( string $line ): ?array {
		if ( ! preg_match( '/^\s*(\*|front_page|post_type:[a-z0-9_-]+|template:[A-Za-z0-9_.\/-]+)\s*=>\s*(attachment:\d+|\.[A-Za-z0-9_-]+|url:https?:\/\/\S+)(\s+preload)?\s*$/', $line, $m ) ) {
			return null;
		}
		list( $scope, $value ) = array_pad( explode( ':', $m[1], 2 ), 2, '' );
		if ( str_starts_with( $m[2], 'attachment:' ) ) {
			$target = 'attachment';
			$ref    = substr( $m[2], 11 );
		} elseif ( str_starts_with( $m[2], 'url:' ) ) {
			$target = 'url';
			$ref    = substr( $m[2], 4 );
		} else {
			$target = 'class';
			$ref    = substr( $m[2], 1 );
		}

		return array(
			'scope'   => $scope,
			'value'   => $value,
			'target'  => $target,
			'ref'     => $ref,
			'preload' => ! empty( $m[3] ) || 'url' === $target,
		);
	}

	/**
	 * First rule matching the request context.
	 *
	 * @param list<array{scope:string,value:string,target:string,ref:string,preload:bool}> $rules   Rules.
	 * @param array{front_page:bool,post_type:string,template:string}                      $context Request.
	 * @return array{scope:string,value:string,target:string,ref:string,preload:bool}|null
	 */
	public static function match( array $rules, array $context ): ?array {
		foreach ( $rules as $rule ) {
			$matches = match ( $rule['scope'] ) {
				'*'          => true,
				'front_page' => $context['front_page'],
				'post_type'  => '' !== $context['post_type'] && $rule['value'] === $context['post_type'],
				'template'   => '' !== $context['template'] && ( $context['template'] === $rule['value'] || basename( $context['template'], '.php' ) === $rule['value'] ),
				default      => false,
			};
			if ( $matches ) {
				return $rule;
			}
		}

		return null;
	}
}
