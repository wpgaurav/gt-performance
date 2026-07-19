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
		$document->loadHTML( '<!doctype html><html><head></head><body><header class="hero"><a class="button">Go</a></header><main><p class="copy">Text</p></main></body></html>' );
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

	public function test_hybrid_segments_do_not_duplicate_normal_rules(): void {
		$pruner    = new CssPruner();
		$critical  = $pruner->prune( '.hero{display:block}.missing{display:none}', $this->document(), 'critical' );
		$remaining = $pruner->prune( '.hero{display:block}.missing{display:none}', $this->document(), 'remaining' );

		self::assertStringContainsString( '.hero', $critical );
		self::assertStringNotContainsString( '.hero', $remaining );
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
}
