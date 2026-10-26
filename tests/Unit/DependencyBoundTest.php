<?php
/**
 * Pages with long listings stay specific instead of depending on everything.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\DependencyIndex;
use GTPerformance\Cache\DependencyRecorder;
use GTPerformance\Cache\QueryClassifier;
use PHPUnit\Framework\TestCase;

final class DependencyBoundTest extends TestCase {
	public function testPagesUnderTheLimitKeepEveryListedPost(): void {
		$signatures = array( 'post:1', 'post:2', 'pt:post', 'term:4' );

		self::assertSame( $signatures, DependencyRecorder::bounded( $signatures, array( 'post:1' => 'post', 'post:2' => 'post' ) ) );
	}

	public function testLongListingsCollapseToTheirPostTypes(): void {
		$listed = array();
		foreach ( range( 1, 427 ) as $id ) {
			$listed[ 'post:' . $id ] = 'csp_doc';
		}
		$listed['post:900'] = 'post';
		$listed['post:901'] = '';
		$signatures         = array_merge( array_keys( $listed ), array( 'post:5000', 'pt:csp_doc', 'term:12' ) );

		$bounded = DependencyRecorder::bounded( $signatures, $listed );

		self::assertEqualsCanonicalizing( array( 'ptv:csp_doc', 'ptv:post', 'ptv:*', 'post:5000', 'pt:csp_doc', 'term:12' ), $bounded, 'The page\'s own post (5000) is not in the collapsible set.' );
		self::assertLessThanOrEqual( DependencyIndex::MAX_PER_PAGE, count( $bounded ) );
	}

	public function testAnEditToAnotherPostTypeNoLongerReachesTheCollapsedPage(): void {
		$listed = array();
		foreach ( range( 1, 400 ) as $id ) {
			$listed[ 'post:' . $id ] = 'csp_doc';
		}
		$bounded = DependencyRecorder::bounded( array_keys( $listed ), $listed );

		self::assertSame( array(), array_intersect( $bounded, QueryClassifier::affected( 7000, 'post', false, array() ) ), 'A blog post edit leaves the docs page alone.' );
		self::assertNotSame( array(), array_intersect( $bounded, QueryClassifier::affected( 12, 'csp_doc', false, array() ) ), 'An edit to a listed doc still purges it.' );
		self::assertNotSame( array(), array_intersect( $bounded, QueryClassifier::affected( 9000, 'csp_doc', true, array() ) ), 'A newly published doc still purges it.' );
	}
}
