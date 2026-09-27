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

	/**
	 * Icon fonts ship one `.icon:before` rule per glyph. Before the single-colon form
	 * was recognised, every one of them was kept, whether or not the page used it.
	 */
	public function test_single_colon_pseudo_elements_are_pruned_like_double_colon_ones(): void {
		$output = ( new CssPruner() )->prune(
			'.button:before{content:"a"}.ion-ios-add:before{content:"b"}'
			. '.copy:after{content:"c"}.ion-ios-alarm:after{content:"d"}'
			. '.copy:first-letter{font-size:2em}.missing:first-line{color:red}'
			. '.button:hover:before{color:blue}.missing:hover:before{color:blue}',
			$this->document()
		);

		self::assertStringContainsString( '.button:before', $output );
		self::assertStringContainsString( '.copy:after', $output );
		self::assertStringContainsString( '.copy:first-letter', $output );
		self::assertStringContainsString( '.button:hover:before', $output );
		self::assertStringNotContainsString( 'ion-ios-add', $output );
		self::assertStringNotContainsString( 'ion-ios-alarm', $output );
		self::assertStringNotContainsString( '.missing', $output );
	}

	/**
	 * Bricks 2 opens its framework CSS with a layer-order statement. The parser read
	 * it into the next rule and nested the rest of the sheet inside that rule with an
	 * unchanged brace count, so the round-trip check let the broken CSS through.
	 */
	public function test_leading_layer_statements_keep_the_sheet_structure(): void {
		$output = ( new CssPruner() )->prune(
			'@charset "UTF-8";@layer bricks.reset, bricks.icons;'
			. '.aligncenter{display:block;margin:.5em auto}.alignright{float:right}'
			. '@layer bricks{:root{--brx-gap:1rem}.hero{display:flex}.unused{color:red}}',
			$this->document()
		);

		self::assertSame( '@charset "UTF-8";@layer bricks.reset, bricks.icons;@layer bricks{:root{--brx-gap:1rem}.hero{display:flex}}', $output );
	}

	public function test_layer_statements_survive_when_every_rule_is_pruned(): void {
		self::assertSame( '@layer reset, base;', ( new CssPruner() )->prune( '@layer reset, base;.unused{color:red}', $this->document() ) );
	}

	public function test_a_layer_statement_after_a_rule_leaves_the_sheet_untouched(): void {
		$css = '.hero{color:red}@layer a, b;.unused{color:blue}';

		self::assertSame( $css, ( new CssPruner() )->prune( $css, $this->document() ) );
		self::assertSame( '', ( new CssPruner() )->prune( $css, $this->document(), 'remaining' ) );
	}

	/**
	 * A browser serialises selectors its own way; a kept selector must still find
	 * the rule the stylesheet spelled differently.
	 */
	public function test_kept_selectors_match_however_the_stylesheet_spells_them(): void {
		$keep   = array_fill_keys( array_map( array( CssPruner::class, 'canonical' ), array( '.sp-toc > li', '.sp-toc a::before', '[data-slot="rail"] .ad' ) ), true );
		$output = ( new CssPruner() )->prune(
			'.sp-toc>li{margin:0}.sp-toc a:before{content:""}[data-slot=rail] .ad{width:1px}.sp-toc li a{color:red}.unused{color:blue}',
			$this->document(),
			'used',
			array(),
			true,
			$keep
		);

		self::assertStringContainsString( '.sp-toc>li', $output );
		self::assertStringContainsString( '.sp-toc a:before', $output );
		self::assertStringContainsString( '[data-slot=rail] .ad', $output );
		self::assertStringNotContainsString( '.sp-toc li a', $output, 'Only the exact selector is kept.' );
		self::assertStringNotContainsString( '.unused', $output );
	}

	public function test_an_escaped_colon_in_a_class_name_is_not_a_pseudo_element(): void {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<!doctype html><html><body><p class="hover:before:block">Text</p></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$output = ( new CssPruner() )->prune(
			'.hover\\:before\\:block{display:block}.hover\\:before\\:hidden{display:none}',
			$document
		);

		self::assertStringContainsString( 'block{display:block}', $output );
		self::assertStringNotContainsString( 'hidden', $output );
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
