<?php
/**
 * Query subscriptions and the signatures a post change affects.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\QueryClassifier;
use PHPUnit\Framework\TestCase;

final class QueryClassifierTest extends TestCase {
	/**
	 * @param list<int> $terms Terms.
	 * @return array{taxonomy:string,terms:list<int>,operator:string}
	 */
	private function tax( array $terms, string $operator = 'IN' ): array {
		return array( 'taxonomy' => 'category', 'terms' => $terms, 'operator' => $operator );
	}

	public function test_listing_types(): void {
		self::assertSame( array( 'pt:post' ), QueryClassifier::signatures( array( 'post_type' => '' ), array() ) );
		self::assertSame( array( 'pt:product' ), QueryClassifier::signatures( array( 'post_type' => 'product' ), array() ) );
		self::assertSame( array( 'pt:*' ), QueryClassifier::signatures( array( 'post_type' => array( 'post', 'page' ) ), array() ) );
		self::assertSame( array( 'pt:*' ), QueryClassifier::signatures( array( 'post_type' => 'any' ), array() ) );
		self::assertSame( array( 'pt:page' ), QueryClassifier::signatures( array( 'post_type' => array( 'page' ) ), array() ) );
	}

	public function test_term_limited_queries_subscribe_to_terms_only_when_every_clause_is_an_in(): void {
		self::assertSame( array( 'term:4', 'term:9' ), QueryClassifier::signatures( array( 'post_type' => 'post' ), array( $this->tax( array( 4, 9 ) ) ) ) );
		self::assertSame( array( 'pt:post' ), QueryClassifier::signatures( array( 'post_type' => 'post' ), array( $this->tax( array( 4 ), 'NOT IN' ) ) ) );
		self::assertSame( array( 'term:4', 'pt:*' ), QueryClassifier::signatures( array(), array( $this->tax( array( 4 ) ), $this->tax( array( 5 ), 'AND' ) ) ) );
		self::assertSame( array( 'pt:post' ), QueryClassifier::signatures( array( 'post_type' => 'post' ), array( $this->tax( array() ) ) ) );
	}

	public function test_explicit_ids_depend_only_on_those_posts_and_volatile_orders_on_every_edit(): void {
		self::assertSame( array(), QueryClassifier::signatures( array( 'post_type' => 'post', 'post__in' => array( 3, 7 ) ), array() ) );
		self::assertSame( array( 'ptv:post', 'pt:post' ), QueryClassifier::signatures( array( 'post_type' => 'post', 'orderby' => 'modified' ), array() ) );
		self::assertSame( array( 'ptv:product', 'pt:product' ), QueryClassifier::signatures( array( 'post_type' => 'product', 'orderby' => array( 'meta_value_num' => 'ASC' ) ), array() ) );
		self::assertSame( array( 'pt:post' ), QueryClassifier::signatures( array( 'post_type' => 'post', 'orderby' => 'date' ), array() ) );
	}

	public function test_a_content_edit_reaches_items_and_volatile_listings_but_membership_reaches_sets(): void {
		self::assertSame( array( 'post:12', 'ptv:post', 'ptv:*' ), QueryClassifier::affected( 12, 'post', false, array( 4 ) ) );
		self::assertSame( array( 'post:12', 'ptv:post', 'ptv:*', 'pt:post', 'pt:*', 'term:4', 'term:9' ), QueryClassifier::affected( 12, 'post', true, array( 4, 9, 4 ) ) );
	}
}
