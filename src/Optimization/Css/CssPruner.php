<?php
/**
 * Conservative AST-based used CSS pruning.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

use Sabberworm\CSS\CSSList\CSSList;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Symfony\Component\CssSelector\CssSelectorConverter;

final class CssPruner {
	// Created on first use: the pruner is built on every request, and creating the
	// converter would load the bundled libraries for pages that never prune.
	private ?CssSelectorConverter $converter = null;
	private SelectorSafelist $safelist;

	public function __construct() {
		$this->safelist = new SelectorSafelist();
	}

	/**
	 * @param list<string>        $safelist Selector fragments.
	 * @param array<string, true> $keep     Selectors to keep whatever the document holds, by canonical() spelling.
	 */
	public function prune( string $css, \DOMDocument $document, string $segment = 'used', array $safelist = array(), bool $preserveDynamicStates = true, array $keep = array() ): string {
		// A layer-order statement (`@layer reset, base;`) is mangled by the bundled
		// parser without always changing the brace count: it drops the next rule's
		// declarations and nests the rest of the sheet inside that rule. Bricks 2 opens
		// its framework CSS with one, so pruned Bricks pages lost their whole base
		// layer. Statements ahead of every rule are lifted out and put back in front;
		// one anywhere else cannot move without changing the layer order.
		$layers = $this->leadingLayerStatements( $css );
		if ( null === $layers ) {
			return 'remaining' === $segment ? '' : $css;
		}

		$protected = $this->protectUnicodeEscapes( $layers['css'] );
		$values    = $this->protectBareGroups( $protected['css'] );
		$protected = array(
			'css'     => $values['css'],
			'escapes' => $protected['escapes'] + $values['escapes'],
		);

		try {
			$stylesheet = ( new Parser( $protected['css'] ) )->parse();
		} catch ( \Throwable ) {
			// Unparseable input is not a licence to guess. Keeping an unused rule costs
			// bytes; dropping a used one breaks the page.
			return 'remaining' === $segment ? '' : $css;
		}

		// The bundled parser silently mangles other constructs it does not model too.
		// Native nesting loses every nested block (`.card{color:red;&:hover{…}}`
		// renders as `.card{color:red}`) without raising an error, so the only safe
		// test is whether a plain round trip preserves the structure. When it does
		// not, this stylesheet is passed through untouched.
		if ( ! $this->roundTripIsFaithful( $protected['css'], $stylesheet ) ) {
			return 'remaining' === $segment ? '' : $css;
		}

		$xpath    = new \DOMXPath( $document );
		$critical = $this->criticalPaths( $document );

		$this->pruneList( $stylesheet, $xpath, $segment, $critical, $safelist, $preserveDynamicStates, $protected['escapes'], $keep );

		$pruned = strtr( $stylesheet->render( OutputFormat::createCompact() ), $protected['escapes'] );
		if ( '' === $layers['statements'] ) {
			return $pruned;
		}

		// The statements go back even when every rule was pruned: they may order
		// layers whose blocks live in another stylesheet. @charset stays first.
		$charset = preg_match( '/^@charset\s+"[^"]*"\s*;/i', $pruned, $matches ) ? $matches[0] : '';

		return $charset . $layers['statements'] . substr( $pruned, strlen( $charset ) );
	}

	/**
	 * Separate the `@layer a, b;` statements that open a stylesheet from its rules.
	 *
	 * @return array{css:string,statements:string}|null Null when a statement follows a rule.
	 */
	private function leadingLayerStatements( string $css ): ?array {
		$pattern = '/@layer\s+[\w.-]+(?:\s*,\s*[\w.-]+)*\s*;/i';
		if ( ! preg_match_all( $pattern, $css, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'css'        => $css,
				'statements' => '',
			);
		}

		$statements = '';
		foreach ( $matches[0] as [ $statement, $offset ] ) {
			// Only comments, @charset, @import and other layer statements may precede it.
			$before = preg_replace(
				array( '#/\*.*?\*/#s', '/@charset\s+[^;]*;/i', '/@import\s+[^;]*;/i', $pattern ),
				'',
				substr( $css, 0, $offset )
			);
			if ( '' !== trim( (string) $before ) ) {
				return null;
			}
			$statements .= trim( $statement );
		}

		return array(
			'css'        => (string) preg_replace( $pattern, '', $css ),
			'statements' => $statements,
		);
	}

	/**
	 * Whether parsing and re-rendering preserved the stylesheet's block structure.
	 *
	 * Brace count is the cheapest signal that survives minification differences but
	 * not lost or invented blocks, which is exactly the failure mode here.
	 */
	private function roundTripIsFaithful( string $source, \Sabberworm\CSS\CSSList\Document $stylesheet ): bool {
		try {
			$rendered = $stylesheet->render( OutputFormat::createCompact() );
		} catch ( \Throwable ) {
			return false;
		}

		return substr_count( $this->withoutComments( $source ), '{' ) === substr_count( $rendered, '{' )
			// A declaration the parser cannot read vanishes without an error and
			// leaves its braces behind: Twenty Twenty-Five's fluid font sizes did,
			// and every heading fell back to the body size.
			&& $this->declarationCount( $this->withoutComments( $source ) ) === $this->declarationCount( $rendered );
	}

	/**
	 * Declarations in innermost blocks, counted the same way for source and output.
	 */
	private function declarationCount( string $css ): int {
		$count = 0;
		if ( preg_match_all( '/\{([^{}]*)\}/', $css, $bodies ) ) {
			foreach ( $bodies[1] as $body ) {
				foreach ( explode( ';', $body ) as $declaration ) {
					if ( preg_match( '/^\s*(?:--|-?[a-zA-Z_])[\w-]*\s*:/', $declaration ) ) {
						++$count;
					}
				}
			}
		}

		return $count;
	}

	/**
	 * Hide declaration values that hold a bare parenthesised group while the
	 * bundled parser runs.
	 *
	 * Sabberworm 9.4 and 9.5 drop any declaration with a group inside a math
	 * function that is not itself a calc(), such as
	 * `clamp(1rem, 1rem + ((1vw - 0.2rem) * 0.196), 1.125rem)`. That is the shape
	 * WordPress generates for every fluid font size, so a pruned block-theme page
	 * lost its type scale. Pruning only reads selectors, so the value can be
	 * opaque in between and is restored byte for byte.
	 *
	 * @return array{css:string,escapes:array<string,string>}
	 */
	private function protectBareGroups( string $css ): array {
		$prefix = '__GTPERF_CSS_VALUE_' . substr( hash( 'sha256', $css ), 0, 12 ) . '_';
		while ( str_contains( $css, $prefix ) ) {
			$prefix .= '_';
		}

		$values    = array();
		$protected = preg_replace_callback(
			'/\{([^{}]*)\}/',
			static function ( array $block ) use ( &$values, $prefix ): string {
				$declarations = explode( ';', $block[1] );
				foreach ( $declarations as $index => $declaration ) {
					if ( ! preg_match( '/^(\s*(?:--|-?[a-zA-Z_])[\w-]*\s*:)(.*)$/s', $declaration, $parts ) ) {
						continue;
					}
					// `(` opening a group rather than a function's arguments: it follows
					// the start of the value, a space, a comma, an operator, or another `(`.
					if ( ! preg_match( '/(?:^|[\s,(+*\/-])\(/', $parts[2] ) ) {
						continue;
					}
					$value                  = rtrim( $parts[2] );
					$token                  = $prefix . count( $values ) . '__';
					$values[ $token ]       = trim( $value );
					$declarations[ $index ] = $parts[1] . $token . substr( $parts[2], strlen( $value ) );
				}

				return '{' . implode( ';', $declarations ) . '}';
			},
			$css
		);

		return array(
			'css'     => is_string( $protected ) ? $protected : $css,
			'escapes' => $values,
		);
	}

	/**
	 * One spelling per selector, so a selector as a browser serialises it
	 * (`a > b`, `[type="x"]`, `::before`) equals the one a stylesheet wrote
	 * (`a>b`, `[type=x]`, `:before`).
	 */
	public static function canonical( string $selector ): string {
		$selector = (string) preg_replace( '/\s+/', ' ', trim( $selector ) );
		$selector = (string) preg_replace( '/\[\s*([\w-]+)\s*([~|^$*]?=)\s*(["\']?)([\w-]+)\3\s*\]/', '[$1$2$4]', $selector );
		$selector = (string) preg_replace( '/\s*([>+~,])\s*/', '$1', $selector );

		return (string) preg_replace( '/(?<![:\\\\]):(before|after|first-line|first-letter)(?![\w-])/i', '::$1', $selector );
	}

	private function withoutComments( string $css ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/**
	 * Keep CSS hexadecimal escapes textual while Sabberworm parses and renders.
	 *
	 * Sabberworm decodes escapes such as `\e800` into their UTF-8 characters.
	 * DOMDocument then serializes those characters inside a style element as HTML
	 * numeric entities, which are literal text in CSS raw-text elements. Temporary
	 * ASCII tokens preserve the original escape syntax through both stages.
	 *
	 * @return array{css:string,escapes:array<string,string>}
	 */
	private function protectUnicodeEscapes( string $css ): array {
		$prefix = '__GTPERF_CSS_ESCAPE_' . substr( hash( 'sha256', $css ), 0, 12 ) . '_';
		while ( str_contains( $css, $prefix ) ) {
			$prefix .= '_';
		}

		$escapes   = array();
		$protected = preg_replace_callback(
			'/\\\\[0-9a-fA-F]{1,6}(?:[ \t\r\n\f])?/',
			static function ( array $matches ) use ( &$escapes, $prefix ): string {
				$token             = $prefix . count( $escapes ) . '__';
				$escapes[ $token ] = $matches[0];

				return $token;
			},
			$css
		);

		return array(
			'css'     => is_string( $protected ) ? $protected : $css,
			'escapes' => $escapes,
		);
	}

	/**
	 * Prune independent stylesheets without allowing a parser-hostile block to
	 * change the interpretation of any stylesheet that follows it.
	 *
	 * @param list<string>        $stylesheets Independent stylesheet sources.
	 * @param list<string>        $safelist    Selector fragments.
	 * @param array<string, true> $keep        Selectors to keep, by canonical() spelling.
	 */
	public function pruneMany( array $stylesheets, \DOMDocument $document, string $segment = 'used', array $safelist = array(), bool $preserveDynamicStates = true, array $keep = array() ): string {
		$output = '';
		foreach ( $stylesheets as $stylesheet ) {
			$output .= $this->prune( $stylesheet, $document, $segment, $safelist, $preserveDynamicStates, $keep );
		}

		return $output;
	}

	/**
	 * @param array<string, true>  $critical Critical DOM paths.
	 * @param list<string>         $safelist Selector fragments.
	 * @param array<string,string> $escapes  Tokenised CSS escapes to restore before matching.
	 * @param array<string, true>  $keep     Selectors to keep, by canonical() spelling.
	 */
	private function pruneList( CSSList $cssList, \DOMXPath $xpath, string $segment, array $critical, array $safelist, bool $preserveDynamicStates, array $escapes = array(), array $keep = array() ): void {
		foreach ( $cssList->getContents() as $item ) {
			if ( $item instanceof DeclarationBlock ) {
				// Custom properties are dependencies, not merely visual rules on the
				// selector that declares them. A variable may be consumed by descendants,
				// pseudo-elements, a later state, or injected markup. Keep the entire
				// defining block in used and critical output so pruning can never leave
				// otherwise-matched declarations with unresolved var() references.
				if ( in_array( $segment, array( 'used', 'critical' ), true ) && $this->definesCustomProperties( $item ) ) {
					continue;
				}

				$kept = array();
				foreach ( $item->getSelectors() as $selectorObject ) {
					$selector = $selectorObject->getSelector();

					$match = $this->matches( $selector, $xpath, $critical, $safelist, $preserveDynamicStates, $escapes, $keep );
					if ( 'used' === $segment && $match['used'] ) {
						$kept[] = $selectorObject;
					} elseif ( 'critical' === $segment && $match['critical'] ) {
						$kept[] = $selectorObject;
					} elseif ( 'remaining' === $segment && $match['used'] && ! $match['critical'] ) {
						$kept[] = $selectorObject;
					}
				}

				if ( $kept ) {
					$item->setSelectors( $kept );
				} else {
					$cssList->remove( $item );
				}
				continue;
			}

			if ( $item instanceof CSSList ) {
				$class = strtolower( $item::class );
				if ( str_contains( $class, 'keyframe' ) ) {
					continue;
				}
				$this->pruneList( $item, $xpath, $segment, $critical, $safelist, $preserveDynamicStates, $escapes, $keep );
				if ( array() === $item->getContents() ) {
					$cssList->remove( $item );
				}
			}
		}
	}

	private function definesCustomProperties( DeclarationBlock $block ): bool {
		foreach ( $block->getRules() as $rule ) {
			if ( str_starts_with( (string) $rule->getRule(), '--' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, true>  $critical Critical DOM paths.
	 * @param list<string>         $safelist Selector fragments.
	 * @param array<string,string> $escapes  Tokenised CSS escapes to restore before matching.
	 * @param array<string, true>  $keep     Selectors to keep, by canonical() spelling.
	 * @return array{used:bool,critical:bool}
	 */
	private function matches( string $selector, \DOMXPath $xpath, array $critical, array $safelist, bool $preserveDynamicStates, array $escapes = array(), array $keep = array() ): array {
		// Escapes were tokenised before parsing so the parser would not decode them.
		// Matching must see the real selector: `.\32 xl\:flex` matches its element,
		// but `.__GTPERF_CSS_ESCAPE_…__xl\:flex` matches nothing, so every escaped
		// utility class — the shape Tailwind generates for every responsive variant —
		// was pruned as unused.
		if ( $escapes ) {
			$selector = strtr( $selector, $escapes );
		}

		if ( $this->safelist->matches( $selector, $safelist ) || ( $keep && isset( $keep[ self::canonical( $selector ) ] ) ) ) {
			return array(
				'used'     => true,
				'critical' => true,
			);
		}

		if ( ':root' === trim( $selector ) || in_array( trim( $selector ), array( 'html', 'body', 'html body', '*' ), true ) ) {
			return array(
				'used'     => true,
				'critical' => true,
			);
		}

		// CSS2 spelled pseudo-elements with one colon, and icon fonts and minifiers
		// still do (`.ion-ios-add:before`). The converter rejects every pseudo-element,
		// so the catch below kept each such rule as unmatchable: all 696 Ionicons rules
		// survived on a Bricks page that shows one icon. Spell them the modern way so
		// they are treated exactly like `::before`. An escaped colon is part of a class
		// name (`.hover\:before\:block`), not a pseudo-element.
		$selector = preg_replace( '/(?<![:\\\\]):(before|after|first-line|first-letter)(?![\w-])/i', '::$1', $selector ) ?? $selector;

		$testSelector = $preserveDynamicStates ? $this->stripDynamicStates( $selector ) : trim( $selector );
		if ( '' === $testSelector || str_contains( $testSelector, '::' ) ) {
			return array(
				'used'     => true,
				'critical' => false,
			);
		}

		try {
			$this->converter ??= new CssSelectorConverter();
			$query             = $this->converter->toXPath( $testSelector );
			$nodes = $xpath->query( $query );
		} catch ( \Throwable ) {
			return array(
				'used'     => true,
				'critical' => false,
			);
		}

		if ( false === $nodes || 0 === $nodes->length ) {
			return array(
				'used'     => false,
				'critical' => false,
			);
		}

		$isCritical = false;
		foreach ( $nodes as $node ) {
			if ( isset( $critical[ $node->getNodePath() ] ) ) {
				$isCritical = true;
				break;
			}
		}

		return array(
			'used'     => true,
			'critical' => $isCritical,
		);
	}

	/**
	 * Remove interaction-state pseudo-classes so a rule is tested against the
	 * element it decorates rather than a state the captured DOM never shows.
	 *
	 * The alternation lists longer names before the shorter names they extend and
	 * is closed with a negative lookahead. Without both, `:focus-visible` matches
	 * the leading `focus` alternative and leaves `-visible` fused to the class
	 * name, producing a selector that matches nothing — which quietly pruned
	 * every keyboard focus style out of the generated CSS.
	 */
	private function stripDynamicStates( string $selector ): string {
		$selector = preg_replace(
			'/:((?:focus-visible|focus-within|user-invalid|user-valid|placeholder-shown|indeterminate|popover-open|read-write|read-only|disabled|required|optional|invalid|visited|checked|enabled|active|target|autofill|closed|hover|focus|valid|open))(?![\w-])(?:\\([^)]*\\))?/i',
			'',
			$selector
		) ?? $selector;
		$selector = preg_replace(
			'/\\[\\s*(?:open|hidden|inert|aria-(?:current|expanded|selected|checked|pressed|disabled|invalid|busy|hidden|modal)|data-(?:theme|state|open|active|visible|expanded|selected|checked|current|mode))(?:\\s*[~|^$*]?=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\]\\s]+))?\\s*\\]/i',
			'',
			$selector
		) ?? $selector;
		$selector = preg_replace( '/::[a-z-]+(?:\\([^)]*\\))?/i', '', $selector ) ?? $selector;

		return trim( $selector );
	}

	/**
	 * @return array<string, true>
	 */
	private function criticalPaths( \DOMDocument $document ): array {
		$paths = array();
		$xpath = new \DOMXPath( $document );
		$headNodes = $xpath->query( '//head//*' );
		$bodyNodes = $xpath->query( '//body//*' );
		if ( false === $headNodes || false === $bodyNodes ) {
			return $paths;
		}

		foreach ( $headNodes as $node ) {
			$paths[ $node->getNodePath() ] = true;
		}
		foreach ( $bodyNodes as $index => $node ) {
			if ( $index >= 160 ) {
				break;
			}
			$paths[ $node->getNodePath() ] = true;
		}

		return $paths;
	}
}
