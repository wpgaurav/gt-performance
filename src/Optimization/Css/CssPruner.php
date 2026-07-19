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
		$stylesheet = ( new Parser( $css ) )->parse();
		$xpath      = new \DOMXPath( $document );
		$critical   = $this->criticalPaths( $document );

		$this->pruneList( $stylesheet, $xpath, $segment, $critical, $safelist, $preserveDynamicStates );

		return $stylesheet->render( OutputFormat::createCompact() );
	}

	/**
	 * @param array<string, true> $critical Critical DOM paths.
	 * @param list<string>        $safelist Selector fragments.
	 */
	private function pruneList( CSSList $cssList, \DOMXPath $xpath, string $segment, array $critical, array $safelist, bool $preserveDynamicStates ): void {
		foreach ( $cssList->getContents() as $item ) {
			if ( $item instanceof DeclarationBlock ) {
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

		$testSelector = $preserveDynamicStates ? $this->stripDynamicPseudo( $selector ) : trim( $selector );
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

	private function stripDynamicPseudo( string $selector ): string {
		$selector = preg_replace(
			'/:((?:hover|focus|focus-visible|focus-within|active|visited|checked|disabled|enabled|required|optional|valid|invalid|target|placeholder-shown|autofill|open|closed))(?:\\([^)]*\\))?/i',
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
