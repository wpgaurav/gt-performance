<?php
/**
 * Corruption-safe HTML round-trip shared by the DOM-based optimizers.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

/**
 * The libxml HTML parser silently truncates inline <script> content at any
 * "</" sequence (e.g. a "</b>" inside a JS string or a JSON-LD template) and
 * re-emits the UTF-8 encoding hint as a stray <?xml ?> processing instruction
 * that the naive start-anchored cleanup never removed. Every optimizer that
 * round-trips the page through DOMDocument inherited both defects and cached
 * the corrupted markup.
 *
 * This helper masks each script body with an inert placeholder before parsing,
 * restores it verbatim after serialization, and removes the encoding PI at the
 * DOM level so unrelated markup containing "<?xml" (such as an SVG data URI) is
 * never disturbed.
 *
 * It does NOT fix the two defects that matter most. libxml lowercases every
 * camelCase name in inline SVG (`viewBox` becomes `viewbox`, `clipPath` becomes
 * `clippath`), which silently stops those elements rendering, and saveHTML()
 * entity-encodes all non-ASCII, which turns a CSS `content:"->"` into literal
 * text and inflates a non-Latin page by roughly 3x.
 *
 * As of 1.1.0 the font, embed and CDN optimizers use WP_HTML_Tag_Processor and
 * never reserialise the document. The unused-CSS engine is the ONLY remaining
 * consumer, because matching CSS selectors requires a real DOM, and that engine
 * ships disabled behind the GTPERF_UNUSED_CSS constant. HtmlDocumentBoundaryTest
 * pins that: if a second consumer appears, the corruption becomes reachable again
 * and the test fails.
 */
final class HtmlDocument {
	/**
	 * @var array<string, string>
	 */
	private array $scriptMasks = array();

	private int $maskIndex = 0;

	public function load( string $html ): ?\DOMDocument {
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $document->loadHTML(
			'<?xml encoding="utf-8" ?>' . $this->maskScripts( $html ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		if ( ! $loaded ) {
			return null;
		}

		foreach ( iterator_to_array( $document->childNodes ) as $child ) {
			if ( $child instanceof \DOMProcessingInstruction && 'xml' === $child->target ) {
				$document->removeChild( $child );
			}
		}

		return $document;
	}

	public function save( \DOMDocument $document ): ?string {
		$output = $document->saveHTML();
		if ( ! is_string( $output ) || '' === trim( $output ) ) {
			return null;
		}

		return array() === $this->scriptMasks ? $output : strtr( $output, $this->scriptMasks );
	}

	private function maskScripts( string $html ): string {
		return (string) preg_replace_callback(
			'#(<script\b[^>]*>)(.*?)(</script\s*>)#is',
			function ( array $matches ): string {
				if ( '' === $matches[2] ) {
					return $matches[0];
				}

				$token                       = '/*gtperf:mask:' . ( $this->maskIndex++ ) . '*/';
				$this->scriptMasks[ $token ] = $matches[2];

				return $matches[1] . $token . $matches[3];
			},
			$html
		);
	}
}
