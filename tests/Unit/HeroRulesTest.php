<?php
/**
 * Explicit hero rules.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Optimization\HeroRules;
use PHPUnit\Framework\TestCase;

final class HeroRulesTest extends TestCase {
	public function test_parses_scopes_targets_and_preload(): void {
		self::assertSame( array( 'scope' => 'post_type', 'value' => 'product', 'target' => 'class', 'ref' => 'wp-post-image', 'preload' => true ), HeroRules::parseLine( 'post_type:product => .wp-post-image preload' ) );
		self::assertSame( array( 'scope' => '*', 'value' => '', 'target' => 'attachment', 'ref' => '42', 'preload' => false ), HeroRules::parseLine( '* => attachment:42' ) );
		self::assertTrue( HeroRules::parseLine( 'front_page => url:https://example.com/bg.jpg' )['preload'], 'A background hero exists only to be preloaded.' );
		foreach ( array( '', 'nonsense', '* => div.hero', '* => url:javascript:alert(1)', 'post_type:page => attachment:x', '* => .hero img' ) as $bad ) {
			self::assertNull( HeroRules::parseLine( $bad ), $bad );
		}
	}

	public function test_first_matching_rule_wins(): void {
		$rules   = HeroRules::parse( array( 'template:landing => .landing-hero', 'post_type:page => attachment:5', '* => .fallback' ) );
		$context = array( 'front_page' => false, 'post_type' => 'page', 'template' => 'templates/landing.php' );

		self::assertSame( 'landing-hero', HeroRules::match( $rules, $context )['ref'] );
		self::assertSame( '5', HeroRules::match( $rules, array( 'template' => '' ) + $context )['ref'] );
		self::assertSame( 'fallback', HeroRules::match( $rules, array( 'front_page' => false, 'post_type' => '', 'template' => '' ) )['ref'] );
		self::assertNull( HeroRules::match( array(), $context ) );
	}
}
