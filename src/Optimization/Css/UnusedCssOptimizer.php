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
		private readonly ReportRepository $reports = new ReportRepository(),
	) {
	}

	public function optimize( string $html ): string {
		if ( ! (bool) Settings::get( 'css.enabled', false ) ) {
			return $html;
		}

		$mode        = (string) Settings::get( 'css.mode', 'file' );
		$url         = $this->requestUrl();
		$fingerprint = $this->reports->begin( $url, $mode );
		$started     = microtime( true );
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
				$this->reports->complete(
					$fingerprint,
					$mode,
					'skipped',
					'',
					array(
						'url'         => $url,
						'stylesheets' => 0,
						'reason'      => 'No eligible stylesheets were found.',
						'duration_ms' => $this->duration( $started ),
					)
				);
				return $html;
			}

			$css = '';
			foreach ( $collected['stylesheets'] as $stylesheet ) {
				$css .= "\n@media " . $stylesheet->media . " {\n" . $stylesheet->css . "\n}\n";
			}

			$safelist             = array_map( 'strval', (array) Settings::get( 'css.safelist', array() ) );
			$safelist             = apply_filters( 'gt_performance_css_safelist', $safelist, $url );
			$preserveDynamicStates = (bool) Settings::get( 'css.keep_dynamic_states', true );
			$used                 = $this->pruner->prune( $css, $document, 'used', $safelist, $preserveDynamicStates );
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

			$outputs  = array();
			$fallback = '';
			if ( 'inline' === $mode ) {
				$outputs[] = $this->appendInline( $document, $head, $used, 'used' );
			} elseif ( 'hybrid' === $mode ) {
				$critical  = $this->pruner->prune( $css, $document, 'critical', $safelist, $preserveDynamicStates );
				$remaining = $this->pruner->prune( $css, $document, 'remaining', $safelist, $preserveDynamicStates );
				$budget    = (int) Settings::get( 'css.critical_budget', 14336 );

				if ( strlen( $critical ) > $budget ) {
					$outputs[] = $this->appendFile( $document, $head, $used, 'used' );
					$fallback  = 'critical_budget_exceeded';
				} else {
					if ( '' !== trim( $critical ) ) {
						$outputs[] = $this->appendInline( $document, $head, $critical, 'critical' );
					}
					if ( '' !== trim( $remaining ) ) {
						$outputs[] = $this->appendFile( $document, $head, $remaining, 'remaining' );
					}
				}
			} else {
				$outputs[] = $this->appendFile( $document, $head, $used, 'used' );
			}

			$output = $document->saveHTML();
			if ( ! is_string( $output ) || '' === trim( $output ) ) {
				throw new \RuntimeException( 'The optimized HTML result was empty.' );
			}

			$files = array_values(
				array_filter(
					$outputs,
					static fn( array $item ): bool => 'file' === $item['delivery']
				)
			);
			$this->reports->complete(
				$fingerprint,
				$mode,
				'ready',
				isset( $files[0]['path'] ) ? (string) $files[0]['path'] : '',
				array(
					'url'             => $url,
					'stylesheets'     => count( $collected['stylesheets'] ),
					'original_bytes'  => strlen( $css ),
					'generated_bytes' => array_sum( array_column( $outputs, 'bytes' ) ),
					'outputs'         => $outputs,
					'fallback'        => $fallback,
					'duration_ms'     => $this->duration( $started ),
				)
			);

			return preg_replace( '/^<\\?xml[^>]+>\\s*/', '', $output ) ?? $output;
		} catch ( \Throwable $throwable ) {
			$this->reports->fail( $fingerprint, $mode, $url, $throwable->getMessage() );
			$this->logger->log( 'error', 'Unused CSS optimization failed; original HTML returned', array( 'error' => $throwable->getMessage() ) );

			return $html;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * @return array{delivery:string,kind:string,bytes:int}
	 */
	private function appendInline( \DOMDocument $document, \DOMElement $head, string $css, string $kind ): array {
		$style = $document->createElement( 'style' );
		$style->setAttribute( 'data-gt-performance', $kind );
		$style->appendChild( $document->createTextNode( $css ) );
		$head->appendChild( $style );

		return array(
			'delivery' => 'inline',
			'kind'     => $kind,
			'bytes'    => strlen( $css ),
		);
	}

	/**
	 * @return array{delivery:string,kind:string,bytes:int,path:string,url:string}
	 */
	private function appendFile( \DOMDocument $document, \DOMElement $head, string $css, string $kind ): array {
		$artifact = $this->artifacts->write( $css, $kind );
		$binary   = hex2bin( $artifact['hash'] );
		$link     = $document->createElement( 'link' );
		$link->setAttribute( 'rel', 'stylesheet' );
		$link->setAttribute( 'href', $artifact['url'] );
		$link->setAttribute( 'data-gt-performance', $kind );
		$link->setAttribute( 'integrity', 'sha256-' . base64_encode( false === $binary ? '' : $binary ) );
		$link->setAttribute( 'crossorigin', 'anonymous' );
		$head->appendChild( $link );

		return array(
			'delivery' => 'file',
			'kind'     => $kind,
			'bytes'    => strlen( $css ),
			'path'     => $artifact['path'],
			'url'      => $artifact['url'],
		);
	}

	private function requestUrl(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = is_string( $path ) ? $path : '/';

		return esc_url_raw( home_url( $path ) );
	}

	private function duration( float $started ): int {
		return max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}
}
