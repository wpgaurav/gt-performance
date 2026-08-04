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
	private CssSelectorConverter $converter;
	private SelectorSafelist $safelist;

	public function __construct() {
		$this->converter = new CssSelectorConverter();
		$this->safelist  = new SelectorSafelist();
	}

	/**
	 * @param list<string> $safelist Selector fragments.
	 */
	public function prune( string $css, \DOMDocument $document, string $segment = 'used', array $safelist = array(), bool $preserveDynamicStates = true ): string {
		$protected  = $this->protectUnicodeEscapes( $css );
		$stylesheet = ( new Parser( $protected['css'] ) )->parse();
		$xpath      = new \DOMXPath( $document );
		$critical   = $this->criticalPaths( $document );

		$this->pruneList( $stylesheet, $xpath, $segment, $critical, $safelist, $preserveDynamicStates );

		return strtr( $stylesheet->render( OutputFormat::createCompact() ), $protected['escapes'] );
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
		$prefix = '__GTP_CSS_ESCAPE_' . substr( hash( 'sha256', $css ), 0, 12 ) . '_';
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
	 * @param list<string> $stylesheets Independent stylesheet sources.
	 * @param list<string> $safelist Selector fragments.
	 */
	public function pruneMany( array $stylesheets, \DOMDocument $document, string $segment = 'used', array $safelist = array(), bool $preserveDynamicStates = true ): string {
		$output = '';
		foreach ( $stylesheets as $stylesheet ) {
			$output .= $this->prune( $stylesheet, $document, $segment, $safelist, $preserveDynamicStates );
		}

		return $output;
	}

	/**
	 * @param array<string, true> $critical Critical DOM paths.
	 * @param list<string>        $safelist Selector fragments.
	 */
	private function pruneList( CSSList $cssList, \DOMXPath $xpath, string $segment, array $critical, array $safelist, bool $preserveDynamicStates ): void {
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
					$selector = method_exists( $selectorObject, 'getSelector' )
						? (string) $selectorObject->getSelector()
						: (string) $selectorObject;

					$match = $this->matches( $selector, $xpath, $critical, $safelist, $preserveDynamicStates );
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
				$this->pruneList( $item, $xpath, $segment, $critical, $safelist, $preserveDynamicStates );
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
	 * @param array<string, true> $critical Critical DOM paths.
	 * @param list<string>        $safelist Selector fragments.
	 * @return array{used:bool,critical:bool}
	 */
	private function matches( string $selector, \DOMXPath $xpath, array $critical, array $safelist, bool $preserveDynamicStates ): array {
		if ( $this->safelist->matches( $selector, $safelist ) ) {
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

		$testSelector = $preserveDynamicStates ? $this->stripDynamicStates( $selector ) : trim( $selector );
		if ( '' === $testSelector || str_contains( $testSelector, '::' ) ) {
			return array(
				'used'     => true,
				'critical' => false,
			);
		}

		try {
			$query = $this->converter->toXPath( $testSelector );
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

	private function stripDynamicStates( string $selector ): string {
		$selector = preg_replace(
			'/:((?:hover|focus|focus-visible|focus-within|active|visited|checked|disabled|enabled|required|optional|valid|invalid|user-valid|user-invalid|indeterminate|read-only|read-write|target|placeholder-shown|autofill|open|closed|popover-open))(?:\\([^)]*\\))?/i',
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
