<?php
/** Resumable sitemap warming with actual SQL, the real queue runner, and in-process HTTP fixtures. */
declare(strict_types=1);
namespace GTPerformance\Tests\Integration;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\WarmRunRepository;
use GTPerformance\Core\Database;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Queue\JobRepository;
use GTPerformance\Queue\QueueModule;
use PHPUnit\Framework\TestCase;

final class WarmingDatabaseTest extends TestCase {
	/** @var array<string, callable(string, array<string,mixed>):array<string,mixed>> */
	private array $routes = array();

	/** @var list<array{url:string,agent:string,job:int}> */
	private array $requests = array();

	private mixed $savedSettings;

	/** @var callable */
	private $filter;

	protected function setUp(): void {
		global $wpdb;
		Database::install();
		self::assertTrue( Database::queueReady() );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}gtperf_jobs" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}gtperf_warm_targets" );
		delete_option( WarmRunRepository::OPTION );
		delete_option( JobRepository::PAUSE_OPTION );
		$this->savedSettings = get_option( Settings::OPTION );
		$this->settings( array() );

		$this->filter = function ( $pre, array $args, string $url ) {
			$this->requests[] = array(
				'url'   => $url,
				'agent' => (string) ( $args['user-agent'] ?? '' ),
				'job'   => (int) ( \GTPerformance\Queue\JobLease::headers()['X-GT-Job-ID'] ?? 0 ),
			);
			$path = (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$route = $this->routes[ $host . $path ] ?? $this->routes[ $path ] ?? null;
			return null === $route ? $this->response( 404 ) : $route( $url, $args );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->filter, 10 );
		update_option( Settings::OPTION, $this->savedSettings );
	}

	public function test_nested_redirected_cyclic_broken_and_foreign_sitemaps_report_partial_discovery(): void {
		$recent = gmdate( 'c', time() - DAY_IN_SECONDS );
		$this->xml( '/robots.txt', "User-agent: *\r\nSitemap: " . home_url( '/seo-index.xml' ) . "\r\nSitemap: https://other.test/x.xml\r\n" );
		$this->routes['/wp-sitemap.xml'] = fn () => $this->response( 301, '', array( 'location' => '/redirected.xml' ) );
		$this->xml( '/redirected.xml', $this->index( array( '/posts.xml', '/broken.xml', '/wp-sitemap.xml', '/nested-1.xml', 'https://other.test/posts.xml' ) ) );
		$this->xml( '/seo-index.xml', $this->urlset( array( '/a/' => '', '/b/' => $recent, 'https://other.test/c/' => '', '/wp-admin/options.php' => '', '/?s=term' => '' ) ) );
		$this->xml( '/posts.xml', $this->urlset( array( '/a/' => '', '/d/' => '' ) ) );
		$this->routes['/broken.xml'] = fn () => $this->response( 500 );
		for ( $i = 1; $i <= 6; ++$i ) {
			$this->xml( '/nested-' . $i . '.xml', $this->index( array( '/nested-' . ( $i + 1 ) . '.xml' ) ) );
		}
		$this->routes['/'] = fn () => $this->response( 200, '', array( 'x-gt-cache' => 'MISS' ) );
		$this->routes['/a/'] = fn () => $this->response( 200, '', array( 'cf-cache-status' => 'HIT' ) );
		$this->routes['/b/'] = fn () => $this->response( 200, '', array( 'x-gt-cache' => 'DYNAMIC' ) );
		$this->routes['/d/'] = fn () => $this->response( 404 );

		( new CacheWarmer( new Logger() ) )->queue();
		$this->drain();

		$run = ( new CacheWarmer( new Logger() ) )->summary();
		self::assertSame( 'partial', $run['state'] );
		foreach ( array( 'sitemap_failed', 'depth_limit', 'foreign_entries' ) as $warning ) {
			self::assertContains( $warning, $run['warnings'] );
		}
		self::assertSame( array( '/wp-sitemap.xml', '/seo-index.xml' ), $run['sources'] );
		self::assertSame( 1, $run['targets']['sitemap']['failed'] );
		self::assertSame( 1, $run['targets']['sitemap']['redirected'] );
		self::assertSame( 1, $this->fetches( '/wp-sitemap.xml' ), 'A cycle back to a visited sitemap is not refetched.' );
		self::assertSame( 0, $this->fetches( '/nested-6.xml' ), 'Nesting stops five levels below the source.' );
		self::assertSame( 0, $this->hostFetches( 'other.test' ) );

		$targets = $this->urlTargets();
		self::assertSame( array( '/', '/a/', '/b/', '/d/' ), array_keys( $targets ) );
		self::assertSame( 'requested', $targets['/']['status'] );
		self::assertSame( 'edge_observed', $targets['/a/']['status'] );
		self::assertSame( 'requested', $targets['/b/']['status'] );
		self::assertSame( 'gt_dynamic', $targets['/b/']['result'] );
		// A 404 is the page's answer, so it is skipped rather than retried (1.2.0).
		self::assertSame( 'skipped', $targets['/d/']['status'] );
		self::assertSame( 'http_404', $targets['/d/']['result'] );

		$order = array_values( array_filter( array_map( static fn ( array $r ): string => (string) wp_parse_url( $r['url'], PHP_URL_PATH ), $this->requests ), static fn ( string $p ): bool => ! str_ends_with( $p, '.xml' ) && ! str_ends_with( $p, '.txt' ) ) );
		self::assertSame( array( '/', '/b/' ), array_slice( $order, 0, 2 ), 'The home page, then recently modified entries, are warmed first.' );
	}

	public function test_warm_steps_run_ahead_of_a_css_backlog(): void {
		$jobs = new JobRepository();
		for ( $i = 0; $i < 3; ++$i ) {
			$jobs->enqueue( 'generate_css', array( 'url' => home_url( '/css-' . $i . '/' ) ), 70 );
		}
		$warm = ( new CacheWarmer( new Logger() ) )->queue();

		self::assertSame( $warm, (int) $jobs->claim()['id'] );
	}

	public function test_origin_rebuild_is_confirmed_from_the_stored_artifact(): void {
		$this->xml( '/robots.txt', '' );
		$this->xml( '/wp-sitemap.xml', $this->urlset( array( '/fresh/' => '' ) ) );
		$this->routes['/'] = fn () => $this->response( 200, '', array( 'cf-cache-status' => 'MISS' ) );
		$this->routes['/fresh/'] = function ( string $url ) {
			// The origin stores the page while answering, as PageCacheModule::capture() does.
			$hash = (string) ( new \GTPerformance\Diagnostics\CacheInspector() )->inspect( $url )['cache_hash'];
			( new \GTPerformance\Cache\FileStore() )->write(
				$hash,
				'<html></html>',
				array(
					'url'         => $url,
					'stored_at'   => time(),
					'fresh_until' => time() + HOUR_IN_SECONDS,
					'stale_until' => time() + DAY_IN_SECONDS,
				)
			);
			return $this->response( 200, '', array( 'x-gt-cache' => 'MISS', 'cf-cache-status' => 'MISS' ) );
		};

		try {
			( new CacheWarmer( new Logger() ) )->queue();
			$this->drain();

			$targets = $this->urlTargets();
			self::assertSame( 'origin_ready', $targets['/fresh/']['status'] );
			self::assertSame( 'rebuilt', $targets['/fresh/']['result'] );
			self::assertSame( 'requested', $targets['/']['status'], 'An edge MISS with no stored page is not success.' );
			self::assertSame( 'complete', ( new CacheWarmer( new Logger() ) )->summary()['state'] );
		} finally {
			( new \GTPerformance\Cache\FileStore() )->delete( (string) ( new \GTPerformance\Diagnostics\CacheInspector() )->inspect( home_url( '/fresh/' ) )['cache_hash'] );
		}
	}

	public function test_discovery_resumes_after_a_worker_dies_without_duplicate_targets(): void {
		$this->settings( array( 'preload' => false ) );
		$this->xml( '/robots.txt', '' );
		$this->xml( '/wp-sitemap.xml', $this->index( array( '/one.xml', '/two.xml', '/three.xml' ) ) );
		$this->xml( '/one.xml', $this->urlset( array( '/1/' => '', '/2/' => '' ) ) );
		$died = false;
		$this->routes['/two.xml'] = function () use ( &$died ) {
			if ( ! $died ) {
				$died = true;
				throw new \RuntimeException( 'Worker died mid-discovery.' );
			}
			return $this->response( 200, $this->urlset( array( '/2/' => '', '/3/' => '' ) ) );
		};
		$this->xml( '/three.xml', $this->urlset( array( '/4/' => '' ) ) );

		( new CacheWarmer( new Logger() ) )->queue();
		$this->drain();

		self::assertTrue( $died );
		$run = ( new CacheWarmer( new Logger() ) )->summary();
		self::assertSame( 4, $run['targets']['sitemap']['fetched'] );
		self::assertSame( 'partial', $run['state'] );
		self::assertSame( array( 'preload_disabled' ), $run['warnings'], 'Discovery itself completed without loss.' );
		self::assertSame( 1, $this->fetches( '/one.xml' ), 'A sitemap recorded before the crash is not fetched again.' );
		self::assertSame( array( '/', '/1/', '/2/', '/3/', '/4/' ), array_keys( $this->urlTargets() ) );
		self::assertSame( 5, $run['targets']['url']['skipped'] );
	}

	public function test_mobile_variants_count_against_the_entry_budget(): void {
		$this->settings(
			array(
				'separate_mobile'  => true,
				'entry_budget'     => 5,
				'preload_max_urls' => 2,
			)
		);
		$this->xml( '/robots.txt', '' );
		$paths = array();
		for ( $i = 1; $i <= 6; ++$i ) {
			$paths[ '/p' . $i . '/' ] = '';
			$this->routes[ '/p' . $i . '/' ] = fn () => $this->response( 200 );
		}
		$this->xml( '/wp-sitemap.xml', $this->urlset( $paths ) );
		$this->routes['/'] = fn () => $this->response( 200 );

		( new CacheWarmer( new Logger() ) )->queue();
		$this->drain();

		$run = ( new CacheWarmer( new Logger() ) )->summary();
		self::assertSame( 'capacity_limited', $run['state'] );
		self::assertSame( 5, $run['targets']['url']['requested'] );
		self::assertSame( 9, $run['targets']['url']['skipped'] );
		$mobile = array_filter( $this->requests, static fn ( array $r ): bool => str_contains( $r['agent'], 'iPhone' ) );
		self::assertNotEmpty( $mobile );
		self::assertSame( 'capacity_limited', $this->urlTargets( 'mobile' )['/p6/']['result'] );
	}

	public function test_target_cap_bounds_a_ten_times_larger_site_and_memory(): void {
		$this->settings( array( 'entry_budget' => 10 ) );
		$this->xml( '/robots.txt', '' );
		$children = array();
		for ( $i = 1; $i <= 30; ++$i ) {
			$children[] = '/big-' . $i . '.xml';
			$urls       = array();
			for ( $j = 1; $j <= 2000; ++$j ) {
				$urls[ '/s' . $i . '/p' . $j . '/' ] = '';
			}
			$body = $this->urlset( $urls );
			$this->routes[ '/big-' . $i . '.xml' ] = fn () => $this->response( 200, $body );
		}
		$this->xml( '/wp-sitemap.xml', $this->index( $children ) );
		$this->routes['/'] = fn () => $this->response( 200 );

		$before = memory_get_usage();
		( new CacheWarmer( new Logger() ) )->queue();
		$this->drain( 200, array( 'warm_site', 'warm_discover' ) );
		$growth = memory_get_peak_usage() - $before;

		global $wpdb;
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gtperf_warm_targets" );
		self::assertSame( WarmRunRepository::MAX_TARGETS, $rows );
		$run = ( new CacheWarmer( new Logger() ) )->summary();
		self::assertContains( 'target_cap', $run['warnings'] );
		self::assertGreaterThan( 0, $run['targets']['sitemap']['skipped'] );
		self::assertLessThanOrEqual( 2, $this->maxSitemapFetchesPerJob(), 'Discovery stays within two sitemap fetches per job.' );
		self::assertLessThan( 96 * MB_IN_BYTES, $growth );
	}

	public function test_expired_runs_and_targets_are_removed_in_bounded_batches(): void {
		global $wpdb;
		$runs = new WarmRunRepository();
		$old  = $runs->create( array( home_url( '/wp-sitemap.xml' ) ) );
		$runs->add( (string) $old['id'], array( array( 'kind' => 'url', 'url' => home_url( '/x/' ) ) ) );
		$runs->update( (string) $old['id'], array( 'state' => 'complete' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}gtperf_warm_targets SET created_at = %s", gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ) );
		$saved = get_option( WarmRunRepository::OPTION );
		$saved[0]['updated_at'] = time() - 8 * DAY_IN_SECONDS;
		update_option( WarmRunRepository::OPTION, $saved, false );
		$current = $runs->create( array() );

		self::assertSame( 1, $runs->purgeExpired() );
		self::assertSame( array( $current['id'] ), array_column( $runs->all(), 'id' ) );
	}

	/**
	 * Run the real queue runner until no due work remains, making delayed
	 * dispatch and retry jobs due immediately.
	 *
	 * @param list<string> $only Restrict to these job types; others are cancelled.
	 */
	private function drain( int $rounds = 60, array $only = array() ): void {
		global $wpdb;
		$queue = new QueueModule( new Logger() );
		$table = $wpdb->prefix . 'gtperf_jobs';
		for ( $i = 0; $i < $rounds; ++$i ) {
			if ( array() !== $only ) {
				$in = "'" . implode( "','", array_map( 'esc_sql', $only ) ) . "'";
				$wpdb->query( "UPDATE {$table} SET status = 'cancelled', active_key = NULL WHERE status = 'pending' AND type NOT IN ({$in})" );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET available_at = %s WHERE status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
			$processed = $queue->run( 100 );
			if ( 0 === $processed && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ) ) {
				return;
			}
		}
		self::fail( 'The queue did not drain.' );
	}

	private function maxSitemapFetchesPerJob(): int {
		$perJob = array();
		foreach ( $this->requests as $request ) {
			if ( str_ends_with( (string) wp_parse_url( $request['url'], PHP_URL_PATH ), '.xml' ) ) {
				self::assertGreaterThan( 0, $request['job'], 'Sitemaps are fetched only inside a leased job.' );
				$perJob[ $request['job'] ] = ( $perJob[ $request['job'] ] ?? 0 ) + 1;
			}
		}
		return array() === $perJob ? 0 : max( $perJob );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function urlTargets( string $variant = 'public' ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT url, status, result FROM {$wpdb->prefix}gtperf_warm_targets WHERE kind = 'url' AND variant = %s ORDER BY id", $variant ), ARRAY_A );
		$byPath = array();
		foreach ( $rows as $row ) {
			$byPath[ (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ] = $row;
		}
		ksort( $byPath );
		return $byPath;
	}

	private function fetches( string $path ): int {
		return count( array_filter( $this->requests, static fn ( array $r ): bool => home_url( $path ) === $r['url'] ) );
	}

	private function hostFetches( string $host ): int {
		return count( array_filter( $this->requests, static fn ( array $r ): bool => wp_parse_url( $r['url'], PHP_URL_HOST ) === $host ) );
	}

	/** @param array<string, mixed> $cache Cache settings. */
	private function settings( array $cache ): void {
		$settings          = Settings::defaults();
		$settings['cache'] = array_merge(
			$settings['cache'],
			array(
				'preload'          => true,
				'preload_max_urls' => 200,
				'entry_budget'     => 0,
			),
			$cache
		);
		update_option( Settings::OPTION, $settings );
	}

	private function xml( string $path, string $body ): void {
		$this->routes[ $path ] = fn () => $this->response( 200, $body );
	}

	/** @param list<string> $paths Child sitemaps. */
	private function index( array $paths ): string {
		$xml = '<?xml version="1.0"?><sitemapindex>';
		foreach ( $paths as $path ) {
			$xml .= '<sitemap><loc>' . esc_html( str_starts_with( $path, 'http' ) ? $path : home_url( $path ) ) . '</loc></sitemap>';
		}
		return $xml . '</sitemapindex>';
	}

	/** @param array<string, string> $paths Path => lastmod. */
	private function urlset( array $paths ): string {
		$xml = '<?xml version="1.0"?><urlset>';
		foreach ( $paths as $path => $lastmod ) {
			$xml .= '<url><loc>' . esc_html( str_starts_with( $path, 'http' ) ? $path : home_url( $path ) ) . '</loc>' . ( '' === $lastmod ? '' : '<lastmod>' . $lastmod . '</lastmod>' ) . '</url>';
		}
		return $xml . '</urlset>';
	}

	/**
	 * @param array<string, string> $headers Response headers.
	 * @return array<string, mixed>
	 */
	private function response( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
