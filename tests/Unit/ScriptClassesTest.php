<?php
/**
 * Script-built class learning tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\Css\ScriptClasses;
use GTPerformance\Optimization\Css\SelectorSafelist;
use PHPUnit\Framework\TestCase;

final class ScriptClassesTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['gtperf_test_options'][ ScriptClasses::OPTION ], $GLOBALS['gtperf_test_options'][ ScriptClasses::PENDING_OPTION ], $GLOBALS['gtperf_test_singular'] );
	}

	protected function tearDown(): void {
		$this->setUp();
	}

	private function keeps( string $pattern, string $selector ): bool {
		return ( new SelectorSafelist() )->matches( $selector, array( $pattern ) );
	}

	public function test_a_learned_token_protects_its_own_rules_and_nothing_else(): void {
		$classes = new ScriptClasses();
		$classes->save( array( 'post' => array( 'classes' => array( 'level-3', 'aca-rail' ), 'ids' => array( 'toc-live' ), 'pages' => 3 ) ), array( 'post', 'page' ) );
		$pattern = $classes->pattern( 'post' );

		self::assertSame( array( 'valid' => array( $pattern ), 'invalid' => array() ), ( new SelectorSafelist() )->validate( array( $pattern ) ) );
		self::assertTrue( $this->keeps( $pattern, '.sp-toc a.level-3' ) );
		self::assertTrue( $this->keeps( $pattern, '.level-3:hover' ) );
		self::assertTrue( $this->keeps( $pattern, 'body.aca-has-rail .aca-rail' ) );
		self::assertTrue( $this->keeps( $pattern, '#toc-live li' ) );
		self::assertFalse( $this->keeps( $pattern, '.level-30' ) );
		self::assertFalse( $this->keeps( $pattern, '.level-3x' ) );
		self::assertFalse( $this->keeps( $pattern, '.aca-has-rail' ) );
		self::assertFalse( $this->keeps( $pattern, '.toc-live' ), 'An ID is not a class.' );
		self::assertSame( '', $classes->pattern( 'page' ) );
	}

	public function test_only_plain_names_are_stored_and_the_plugins_own_are_ignored(): void {
		$classes = new ScriptClasses();
		$classes->save(
			array(
				'post' => array(
					'classes' => array( 'ok', '-ok-too', '_under', 'md:flex', '2col', 'a b', '<script>', 'gtp-panel', 'gtperf-used', 'gtps--left', str_repeat( 'x', 81 ), 42, null ),
					'ids'     => 'not-a-list',
				),
			),
			array( 'post' )
		);

		self::assertSame( array( '-ok-too', '_under', 'gtps--left', 'ok' ), $classes->all()['post']['classes'] );
		self::assertSame( array(), $classes->all()['post']['ids'] );
	}

	public function test_selectors_are_stored_in_one_spelling_and_served_for_their_post_type(): void {
		$classes = new ScriptClasses();
		$changed = $classes->save(
			array(
				'post' => array(
					'selectors' => array( '.sp-toc > a', '.sp-toc>a', '.ion-add:before', '[class^="ion-"]', '.x{color:red}', 'a;b', str_repeat( 'a', 301 ), 7 ),
				),
			),
			array( 'post', 'page' )
		);

		self::assertTrue( $changed );
		self::assertSame( array( '.ion-add::before', '.sp-toc>a', '[class^=ion-]' ), $classes->all()['post']['selectors'] );
		self::assertSame( array(), $classes->selectorsForRequest(), 'Non-singular views get nothing.' );

		$GLOBALS['gtperf_test_singular'] = 'post';
		self::assertSame( array( '.ion-add::before' => true, '.sp-toc>a' => true, '[class^=ion-]' => true ), $classes->selectorsForRequest() );
		self::assertFalse( $classes->save( array( 'post' => array( 'selectors' => array( '.sp-toc > a', '.ion-add::before', '[class^=ion-]' ) ) ), array( 'post', 'page' ) ), 'The same selectors spelled differently are no change.' );
	}

	public function test_a_scan_replaces_the_types_it_covered_and_reports_changes(): void {
		$classes = new ScriptClasses();
		ScriptClasses::requestScan();
		self::assertTrue( ScriptClasses::pending() );

		self::assertTrue( $classes->save( array( 'post' => array( 'classes' => array( 'a' ) ), 'page' => array( 'classes' => array( 'b' ) ) ), array( 'post', 'page' ) ) );
		self::assertFalse( ScriptClasses::pending(), 'A finished scan clears the request.' );

		// Same tokens in another order, and a type the scan could not reach.
		self::assertFalse( $classes->save( array( 'post' => array( 'classes' => array( 'a' ), 'pages' => 2 ) ), array( 'post', 'page' ) ) );
		self::assertSame( array( 'b' ), $classes->all()['page']['classes'], 'A type missing from a scan keeps what it had.' );

		self::assertTrue( $classes->save( array( 'post' => array( 'classes' => array( 'a', 'c' ) ) ), array( 'post', 'page' ) ) );
		self::assertTrue( $classes->save( array(), array( 'post' ) ), 'A post type that no longer exists is dropped.' );
		self::assertArrayNotHasKey( 'page', $classes->all() );

		self::assertFalse( $classes->save( array( 'product' => array( 'classes' => array( 'x' ) ) ), array( 'post' ) ), 'Unknown post types are ignored.' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}>
	 */
	public static function settingsChanges(): array {
		$on  = array( 'enabled' => true, 'mode' => 'file', 'safelist' => array() );
		$off = array( 'enabled' => false ) + $on;

		return array(
			'safelist edited'        => array( $on, array( 'safelist' => array( '.is-open' ) ) + $on, true ),
			'turned on'              => array( $off, $on, true ),
			'turned off'             => array( $on, $off, false ),
			'edited while off'       => array( $off, array( 'mode' => 'inline' ) + $off, false ),
			'other settings changed' => array( $on, $on, false ),
		);
	}

	/**
	 * @param array<string, mixed> $before Unused CSS settings before the save.
	 * @param array<string, mixed> $after  Unused CSS settings after it.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'settingsChanges' )]
	public function test_changing_unused_css_settings_asks_for_a_scan( array $before, array $after, bool $scan ): void {
		// Same generation and CDN, so the handler does not purge the cache here.
		( new \GTPerformance\Admin\AdminModule() )->afterSettingsUpdate(
			array( 'generation' => 4, 'css' => $before, 'javascript' => array( 'defer' => false ) ),
			array( 'generation' => 4, 'css' => $after, 'javascript' => array( 'defer' => true ) )
		);

		self::assertSame( $scan, ScriptClasses::pending() );
	}

	public function test_the_pattern_follows_the_post_type_being_rendered(): void {
		$classes = new ScriptClasses();
		$classes->save( array( 'post' => array( 'classes' => array( 'level-3' ) ) ), array( 'post', 'page' ) );

		self::assertSame( '', $classes->patternForRequest(), 'Archives and other non-singular views get nothing.' );

		$GLOBALS['gtperf_test_singular'] = 'page';
		self::assertSame( '', $classes->patternForRequest() );

		$GLOBALS['gtperf_test_singular'] = 'post';
		self::assertSame( $classes->pattern( 'post' ), $classes->patternForRequest() );
	}
}
