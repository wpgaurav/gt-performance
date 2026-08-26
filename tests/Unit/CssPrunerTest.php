<?php
/**
 * CSS pruning tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\CssPruner;
use PHPUnit\Framework\TestCase;

final class CssPrunerTest extends TestCase {
	private function document(): \DOMDocument {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<!doctype html><html><head></head><body><header class="hero"><a class="button">Go</a></header><main><p class="copy">Text</p><details><summary>More</summary></details></main></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $document;
	}

	public function test_unused_selectors_are_removed(): void {
		$output = ( new CssPruner() )->prune(
			'.hero{display:block}.unused{display:none}.button:hover{color:red}',
			$this->document()
		);

		self::assertStringContainsString( '.hero', $output );
		self::assertStringContainsString( '.button:hover', $output );
		self::assertStringNotContainsString( '.unused', $output );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function dynamicStateProvider(): array {
		$states = array(
			'hover',
			'focus',
			'focus-visible',
			'focus-within',
			'active',
			'visited',
			'target',
			'disabled',
			'enabled',
			'required',
			'optional',
			'valid',
			'invalid',
			'user-valid',
			'user-invalid',
			'checked',
			'indeterminate',
			'read-only',
			'read-write',
			'placeholder-shown',
			'autofill',
			'open',
			'closed',
			'popover-open',
		);

		$cases = array();
		foreach ( $states as $state ) {
			$cases[ $state ] = array( $state );
		}

		return $cases;
	}

	/**
	 * A state pseudo-class must be stripped whole. Leaving a fragment such as
	 * `-visible` fused to the class name produces a selector that matches
	 * nothing, which silently removes the rule from the generated CSS.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'dynamicStateProvider' )]
	public function test_dynamic_state_rules_survive_pruning( string $state ): void {
		$output = ( new CssPruner() )->prune(
			'.button:' . $state . '{color:red}',
			$this->document()
		);

		self::assertStringContainsString( '.button:' . $state, $output );
	}

	public function test_focus_states_in_a_selector_list_all_survive(): void {
		$output = ( new CssPruner() )->prune(
			'.button:hover,.button:focus-visible,.button:focus-within{outline:2px solid}',
			$this->document()
		);

		self::assertStringContainsString( '.button:hover', $output );
		self::assertStringContainsString( '.button:focus-visible', $output );
		self::assertStringContainsString( '.button:focus-within', $output );
	}

	public function test_hybrid_segments_do_not_duplicate_normal_rules(): void {
		$pruner    = new CssPruner();
		$critical  = $pruner->prune( '.hero{display:block}.missing{display:none}', $this->document(), 'critical' );
		$remaining = $pruner->prune( '.hero{display:block}.missing{display:none}', $this->document(), 'remaining' );

		self::assertStringContainsString( '.hero', $critical );
		self::assertStringNotContainsString( '.hero', $remaining );
	}

	public function test_custom_property_definitions_are_preserved_as_dependencies(): void {
		$pruner = new CssPruner();
		$css    = '.theme-contract{--card-bg:#fff;display:block}.hero{background:var(--card-bg)}';

		$used     = $pruner->prune( $css, $this->document(), 'used' );
		$critical = $pruner->prune( $css, $this->document(), 'critical' );

		self::assertStringContainsString( '.theme-contract', $used );
		self::assertStringContainsString( '--card-bg', $used );
		self::assertStringContainsString( '.theme-contract', $critical );
	}

	public function test_independent_stylesheets_are_pruned_in_source_order(): void {
		$output = ( new CssPruner() )->pruneMany(
			array(
				'.missing{display:none}.theme-contract{--card-bg:#fff}',
				'.hero{background:var(--card-bg)}',
			),
			$this->document()
		);

		self::assertStringNotContainsString( '.missing', $output );
		self::assertStringContainsString( '.theme-contract', $output );
		self::assertStringContainsString( '.hero', $output );
		self::assertLessThan( strpos( $output, '.hero' ), strpos( $output, '.theme-contract' ) );
	}

	public function test_dynamic_state_preservation_can_be_disabled(): void {
		$output = ( new CssPruner() )->prune(
			'.button:hover{color:red}.missing:hover{color:blue}',
			$this->document(),
			'used',
			array(),
			false
		);

		self::assertStringNotContainsString( '.button:hover', $output );
		self::assertStringNotContainsString( '.missing:hover', $output );
	}

	public function test_dynamic_state_attributes_match_their_stable_elements(): void {
		$output = ( new CssPruner() )->prune(
			'details[open] summary::after{content:"-"}'
			. '[data-theme="dark"] .copy{color:white}'
			. '.button[aria-expanded="true"]{color:red}'
			. '.missing[data-state="open"]{display:block}',
			$this->document()
		);

		self::assertStringContainsString( 'details[open] summary::after', $output );
		self::assertStringContainsString( '[data-theme="dark"] .copy', $output );
		self::assertStringContainsString( '.button[aria-expanded="true"]', $output );
		self::assertStringNotContainsString( '.missing[data-state="open"]', $output );
	}

	public function test_safelist_plain_text_uses_partial_selector_matching(): void {
		$output = ( new CssPruner() )->prune(
			'.dialog.is-open{display:block}.unused{display:none}',
			$this->document(),
			'used',
			array( 'is-open' )
		);

		self::assertStringContainsString( '.dialog.is-open', $output );
		self::assertStringNotContainsString( '.unused', $output );
	}

	public function test_safelist_accepts_delimited_regular_expressions(): void {
		$output = ( new CssPruner() )->prune(
			'.modal--visible{display:block}.modal-idle{display:none}',
			$this->document(),
			'used',
			array( '/^\\.modal--(?:visible|open)$/i' )
		);

		self::assertStringContainsString( '.modal--visible', $output );
		self::assertStringNotContainsString( '.modal-idle', $output );
	}

	public function test_invalid_safelist_regular_expressions_are_ignored(): void {
		$output = ( new CssPruner() )->prune(
			'.missing{display:none}',
			$this->document(),
			'used',
			array( '/[invalid/' )
		);

		self::assertStringNotContainsString( '.missing', $output );
	}

	public function test_icon_font_unicode_escapes_survive_inline_html_serialization(): void {
		$output = ( new CssPruner() )->prune(
			'.md-icon-twitter::before{content:\'\\e800\'}'
			. '.menu-toggle::after{content:"\\f0e1"}',
			$this->document(),
			'used',
			array( 'md-icon', 'menu-toggle' )
		);

		self::assertStringContainsString( 'content:"\\e800"', $output );
		self::assertStringContainsString( 'content:"\\f0e1"', $output );
		self::assertStringNotContainsString( '&#', $output );

		$document = new \DOMDocument();
		$style    = $document->createElement( 'style' );
		$style->appendChild( $document->createTextNode( $output ) );
		$document->appendChild( $style );
		$html = $document->saveHTML();

		self::assertIsString( $html );
		self::assertStringContainsString( 'content:"\\e800"', $html );
		self::assertStringContainsString( 'content:"\\f0e1"', $html );
		self::assertStringNotContainsString( '&#', $html );
	}
}
