<?php
/** Dependency recording and invalidation with real WordPress posts, terms, queries, and blocks. */
declare(strict_types=1);
namespace GTPerformance\Tests\Integration;

use GTPerformance\Cache\DependencyIndex;
use GTPerformance\Cache\DependencyInvalidator;
use GTPerformance\Cache\DependencyRecorder;
use GTPerformance\Cache\PurgePreview;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Database;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class DependenciesTest extends TestCase {
	private mixed $savedSettings;

	/** @var list<int> */
	private array $posts = array();

	/** @var list<int> */
	private array $terms = array();

	/** @var list<string> */
	private array $purged = array();

	private ?DependencyInvalidator $invalidator = null;

	private \Closure $spy;

	protected function setUp(): void {
		global $wpdb;
		Database::install();
		self::assertTrue( Database::queueReady() );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}gtperf_dependencies" );
		$this->savedSettings = get_option( Settings::OPTION );
		$settings            = Settings::all();
		$settings['cache']['enabled']            = true;
		$settings['cache']['post_publish_purge'] = 'related';
		update_option( Settings::OPTION, $settings );

		$this->spy = function ( array $urls ): void {
			array_push( $this->purged, ...$urls );
		};
		add_action( 'gt_performance_purged_urls', $this->spy );
		add_action( 'gt_performance_enqueue_purge', $this->spy );
		$this->invalidator = new DependencyInvalidator();
		$this->invalidator->register();
		if ( ! post_type_exists( 'gtp_product' ) ) {
			register_post_type( 'gtp_product', array( 'public' => true, 'taxonomies' => array( 'category' ) ) );
		}
	}

	protected function tearDown(): void {
		remove_action( 'gt_performance_purged_urls', $this->spy );
		remove_action( 'gt_performance_enqueue_purge', $this->spy );
		foreach ( array( 'transition_post_status', 'post_updated', 'set_object_terms', 'edit_terms', 'edited_term', 'save_post', 'stick_post', 'unstick_post' ) as $hook ) {
			remove_all_actions( $hook );
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->terms as $id ) {
			wp_delete_term( $id, 'category' );
		}
		update_option( Settings::OPTION, $this->savedSettings );
		$GLOBALS['wp_query'] = new \WP_Query();
	}

	private function term( string $name ): int {
		$term          = wp_insert_term( $name . '-' . wp_generate_password( 6, false ), 'category' );
		$this->terms[] = (int) $term['term_id'];
		return (int) $term['term_id'];
	}

	/** @param array<string, mixed> $args Post fields. */
	private function post( string $title, array $args = array() ): int {
		$id            = (int) wp_insert_post( $args + array( 'post_title' => $title, 'post_status' => 'publish', 'post_type' => 'post' ) );
		$this->posts[] = $id;
		return $id;
	}

	/**
	 * Build one cacheable page the way PageCacheModule does: record while rendering,
	 * then persist when the page is stored.
	 *
	 * @param callable():void $render Queries and blocks the page runs.
	 */
	private function page( string $path, callable $render, ?\WP_Query $main = null ): string {
		$recorder = new DependencyRecorder();
		$recorder->start();
		$GLOBALS['wp_query'] = $main ?? new \WP_Query( array( 'page_id' => 0, 'post_type' => 'page', 'posts_per_page' => 1 ) );
		$render();
		$url = home_url( $path );
		do_action( 'gt_performance_cache_stored', RequestContext::fromUrl( $url ), 'hash' );
		return $url;
	}

	private function dependents( int $postId, bool $membership ): array {
		return array_keys( $this->invalidator->forPost( get_post( $postId ), $membership ) );
	}

	public function test_a_new_post_invalidates_a_term_listing_it_never_appeared_on_and_unrelated_edits_do_not(): void {
		$news    = $this->term( 'news' );
		$other   = $this->term( 'other' );
		$shown   = $this->post( 'shown', array( 'post_category' => array( $news ) ) );
		$landing = $this->page( '/landing/', static fn () => new \WP_Query( array( 'cat' => $news, 'posts_per_page' => 3 ) ) );
		$about   = $this->page( '/about/', static fn () => null );

		$fresh = $this->post( 'fresh', array( 'post_category' => array( $news ) ) );
		self::assertContains( $landing, $this->dependents( $fresh, true ), 'Joining the listed term invalidates the listing.' );
		self::assertNotContains( $about, $this->dependents( $fresh, true ) );

		$elsewhere = $this->post( 'elsewhere', array( 'post_category' => array( $other ) ) );
		self::assertNotContains( $landing, $this->dependents( $elsewhere, true ), 'A post in an unrelated term leaves the listing cached.' );
		self::assertNotContains( $landing, $this->dependents( $fresh, false ), 'A content edit to a post the page never showed does not purge it.' );
		self::assertContains( $landing, $this->dependents( $shown, false ), 'A content edit to a shown post purges the page showing it.' );
	}

	public function test_withdrawal_and_pagination_reach_every_page_of_a_listing(): void {
		$news   = $this->term( 'news' );
		$shown  = $this->post( 'shown', array( 'post_category' => array( $news ) ) );
		$first  = $this->page( '/category/news/', static fn () => null, new \WP_Query( array( 'cat' => $news ) ) );
		$second = $this->page( '/category/news/page/2/', static fn () => null, new \WP_Query( array( 'cat' => $news, 'paged' => 2 ) ) );

		$dependents = $this->dependents( $shown, true );
		self::assertContains( $first, $dependents );
		self::assertContains( $second, $dependents, 'Page two shifts when an item leaves page one.' );
	}

	public function test_reassignment_purges_the_term_the_post_left_and_its_listings(): void {
		$from    = $this->term( 'from' );
		$to      = $this->term( 'to' );
		$post    = $this->post( 'moving', array( 'post_category' => array( $from ) ) );
		$listing = $this->page( '/from-listing/', static fn () => new \WP_Query( array( 'cat' => $from ) ) );
		$this->purged = array();

		wp_set_post_categories( $post, array( $to ) );

		self::assertContains( get_term_link( $from, 'category' ), $this->purged, 'The archive the post left is purged.' );
		self::assertContains( get_term_link( $to, 'category' ), $this->purged );
		self::assertContains( $listing, $this->purged );
	}

	public function test_renaming_a_term_purges_its_old_and_new_archive_and_listings(): void {
		$term    = $this->term( 'rename-me' );
		$this->post( 'in term', array( 'post_category' => array( $term ) ) );
		$old     = get_term_link( $term, 'category' );
		$listing = $this->page( '/term-listing/', static fn () => new \WP_Query( array( 'cat' => $term ) ) );
		$this->purged = array();

		wp_update_term( $term, 'category', array( 'slug' => 'renamed-' . wp_generate_password( 6, false ) ) );

		self::assertContains( $old, $this->purged );
		self::assertContains( get_term_link( $term, 'category' ), $this->purged );
		self::assertContains( $listing, $this->purged );
	}

	public function test_get_posts_and_reusable_blocks_are_recorded_and_a_block_edit_purges_its_pages(): void {
		$block = $this->post( 'Reusable', array( 'post_type' => 'wp_block', 'post_content' => '<!-- wp:paragraph --><p>v1</p><!-- /wp:paragraph -->' ) );
		$item  = $this->post( 'latest' );
		$page  = $this->page(
			'/embeds/',
			static function () use ( $block ): void {
				get_posts( array( 'numberposts' => 5 ) );
				do_blocks( '<!-- wp:block {"ref":' . $block . '} /-->' );
			}
		);

		self::assertContains( $page, $this->dependents( $item, false ), 'get_posts() suppresses filters but is still recorded.' );
		$this->purged = array();
		wp_update_post( array( 'ID' => $block, 'post_content' => '<!-- wp:paragraph --><p>v2</p><!-- /wp:paragraph -->' ) );
		self::assertContains( $page, $this->purged );
	}

	public function test_stock_change_purges_listings_that_show_the_product(): void {
		$product = $this->post( 'widget', array( 'post_type' => 'gtp_product' ) );
		$shop    = $this->page( '/shop/', static fn () => new \WP_Query( array( 'post_type' => 'gtp_product' ) ) );
		$this->purged = array();

		( new \GTPerformance\Commerce\CommerceModule() )->purgeCommerceObject( $product );

		self::assertContains( get_permalink( $product ), $this->purged );
		self::assertContains( $shop, $this->purged );
	}

	public function test_dependencies_from_an_older_settings_generation_never_drive_a_purge(): void {
		$post    = $this->post( 'item' );
		$listing = $this->page( '/old-generation/', static fn () => new \WP_Query( array( 'post_type' => 'post' ) ) );
		self::assertContains( $listing, $this->dependents( $post, true ) );

		$settings                = Settings::all();
		$settings['generation']  = (int) $settings['generation'] + 1;
		update_option( Settings::OPTION, $settings );

		self::assertNotContains( $listing, $this->dependents( $post, true ) );
		self::assertGreaterThan( 0, ( new DependencyIndex() )->prune() );
	}

	public function test_related_urls_never_include_another_sites_address(): void {
		$post   = $this->post( 'by an author with an external profile', array( 'post_author' => 1 ) );
		$filter = static fn (): string => 'https://another-site.example/about/';
		add_filter( 'author_link', $filter );
		try {
			$urls = \GTPerformance\Cache\RelatedUrls::forPost( get_post( $post ) );
		} finally {
			remove_filter( 'author_link', $filter );
		}
		self::assertArrayNotHasKey( 'https://another-site.example/about/', $urls );
		self::assertArrayHasKey( get_permalink( $post ), $urls );
	}

	public function test_preview_lists_reasons_and_completeness_without_purging(): void {
		$news    = $this->term( 'news' );
		$post    = $this->post( 'preview me', array( 'post_category' => array( $news ) ) );
		$landing = $this->page( '/preview-landing/', static fn () => new \WP_Query( array( 'cat' => $news ) ) );
		$this->purged = array();

		$preview = ( new PurgePreview() )->forPost( $post, true );
		$byUrl   = array_column( $preview['urls'], 'reasons', 'url' );

		self::assertSame( array(), $this->purged );
		self::assertSame( 'related', $preview['policy'] );
		self::assertContains( 'permalink', $byUrl[ get_permalink( $post ) ] );
		self::assertContains( 'recorded dependency: lists term ' . $news, $byUrl[ $landing ] );
		self::assertGreaterThanOrEqual( 1, $preview['completeness']['indexed_pages'] );
	}
}
