<?php
/**
 * Server-side unused CSS optimizer and delivery modes.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;

final class UnusedCssOptimizer {
	public function __construct(
		private readonly Logger $logger,
		private readonly StylesheetCollector $collector = new StylesheetCollector(),
		private readonly CssPruner $pruner = new CssPruner(),
		private readonly ArtifactStore $artifacts = new ArtifactStore(),
	) {
	}

	public function optimize( string $html ): string {
		if ( ! (bool) Settings::get( 'css.enabled', false ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );

		try {
			$document = new \DOMDocument( '1.0', 'UTF-8' );
			$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			if ( ! $loaded ) {
				throw new \RuntimeException( 'The HTML document could not be parsed.' );
			}

			$collected = $this->collector->collect(
				$document,
				(array) Settings::get( 'css.excluded_stylesheets', array() )
			);
			if ( ! $collected['stylesheets'] ) {
				return $html;
			}

			$css = '';
			foreach ( $collected['stylesheets'] as $stylesheet ) {
				$css .= "\n@media " . $stylesheet->media . " {\n" . $stylesheet->css . "\n}\n";
			}

			$safelist = array_map( 'strval', (array) Settings::get( 'css.safelist', array() ) );
			$used     = $this->pruner->prune( $css, $document, 'used', $safelist );
			if ( '' === trim( $used ) ) {
				throw new \RuntimeException( 'The used CSS result was empty.' );
			}

			foreach ( $collected['nodes'] as $node ) {
				$node->parentNode?->removeChild( $node );
			}

			$head = $document->getElementsByTagName( 'head' )->item( 0 );
			if ( ! $head instanceof \DOMElement ) {
				throw new \RuntimeException( 'The HTML document has no head element.' );
			}

			$mode = (string) Settings::get( 'css.mode', 'file' );
			if ( 'inline' === $mode ) {
				$this->appendInline( $document, $head, $used, 'used' );
			} elseif ( 'hybrid' === $mode ) {
				$critical  = $this->pruner->prune( $css, $document, 'critical', $safelist );
				$remaining = $this->pruner->prune( $css, $document, 'remaining', $safelist );
				$budget    = (int) Settings::get( 'css.critical_budget', 14336 );

				if ( strlen( $critical ) > $budget ) {
					$critical  = $used;
					$remaining = '';
				}

				$this->appendInline( $document, $head, $critical, 'critical' );
				if ( '' !== trim( $remaining ) ) {
					$this->appendFile( $document, $head, $remaining, 'remaining' );
				}
			} else {
				$this->appendFile( $document, $head, $used, 'used' );
			}

			$output = $document->saveHTML();
			if ( ! is_string( $output ) || '' === trim( $output ) ) {
				throw new \RuntimeException( 'The optimized HTML result was empty.' );
			}

			return preg_replace( '/^<\\?xml[^>]+>\\s*/', '', $output ) ?? $output;
		} catch ( \Throwable $throwable ) {
			$this->logger->log( 'error', 'Unused CSS optimization failed; original HTML returned', array( 'error' => $throwable->getMessage() ) );

			return $html;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	private function appendInline( \DOMDocument $document, \DOMElement $head, string $css, string $kind ): void {
		$style = $document->createElement( 'style' );
		$style->setAttribute( 'data-gt-performance', $kind );
		$style->appendChild( $document->createTextNode( $css ) );
		$head->appendChild( $style );
	}

	private function appendFile( \DOMDocument $document, \DOMElement $head, string $css, string $kind ): void {
		$artifact = $this->artifacts->write( $css, $kind );
		$binary   = hex2bin( $artifact['hash'] );
		$link     = $document->createElement( 'link' );
		$link->setAttribute( 'rel', 'stylesheet' );
		$link->setAttribute( 'href', $artifact['url'] );
		$link->setAttribute( 'data-gt-performance', $kind );
		$link->setAttribute( 'integrity', 'sha256-' . base64_encode( false === $binary ? '' : $binary ) );
		$link->setAttribute( 'crossorigin', 'anonymous' );
		$head->appendChild( $link );
	}
}
