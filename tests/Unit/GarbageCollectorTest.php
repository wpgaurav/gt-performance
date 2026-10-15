<?php
/**
 * Reclamation of everything the plugin writes to disk.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\ArtifactKeepAlive;
use GTPerformance\Cache\FileStore;
use GTPerformance\Cache\GarbageCollector;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Optimization\Css\ArtifactStore;
use GTPerformance\Optimization\Css\UnusedCssOptimizer;
use GTPerformance\Optimization\FontOptimizer;
use PHPUnit\Framework\TestCase;

final class GarbageCollectorTest extends TestCase {
	private FileStore $store;

	protected function setUp(): void {
		$this->store = new FileStore();
		foreach ( Paths::writableDirectories() as $directory ) {
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0o777, true );
			}
		}
		$this->store->purgeAll();
		// Renders have refreshed the files they link for long enough that only age decides.
		$GLOBALS['gtperf_test_options'][ GarbageCollector::KEEPALIVE_SINCE_OPTION ] = time() - 90 * DAY_IN_SECONDS;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['gtperf_test_options'][ GarbageCollector::KEEPALIVE_SINCE_OPTION ],
			$GLOBALS['gtperf_test_options']['gt_performance_settings']
		);
	}

	/**
	 * Write a generated file last modified $age seconds ago and read a moment ago,
	 * the way a nightly backup leaves it under relatime. Never under js/: other tests
	 * prove the WordPress.org build never creates that directory.
	 */
	private function artifact( string $relative, int $age, string $body = 'x' ): string {
		$path = Paths::assets() . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0o777, true );
		}
		file_put_contents( $path, $body );
		touch( $path, time() - $age, time() );
		clearstatcache( true, $path );

		return $path;
	}

	private static function hex( string $seed ): string {
		return hash( 'sha256', $seed . uniqid( '', true ) );
	}

	/**
	 * @param array<string, int|string> $overrides Metadata overrides.
	 */
	private function seed( string $hash, array $overrides = array() ): void {
		$now = time();
		$this->store->write(
			$hash,
			'<html><body>x</body></html>',
			$overrides + array(
				'stored_at'   => $now,
				'fresh_until' => $now + 3600,
				'stale_until' => $now + 86400,
				'url'         => 'https://example.com/' . $hash . '/',
				'generation'  => 1,
			)
		);
	}

	public function test_entries_past_their_stale_window_are_removed(): void {
		$this->seed( str_repeat( 'a', 64 ), array( 'stale_until' => time() - 1 ) );
		$this->seed( str_repeat( 'b', 64 ) );

		$stats = ( new GarbageCollector() )->collect();

		self::assertSame( 1, $stats['expired'] );
		self::assertFileDoesNotExist( $this->store->pagePath( str_repeat( 'a', 64 ) ) );
		self::assertFileExists( $this->store->pagePath( str_repeat( 'b', 64 ) ) );
	}

	/**
	 * The generation is part of the cache key, so an entry written under an older one
	 * can never be recomputed and is dead weight forever. This is what turned 33
	 * reachable entries into 2,162 files on a real site.
	 */
	public function test_entries_from_an_earlier_generation_are_removed(): void {
		$this->seed( str_repeat( 'c', 64 ), array( 'generation' => 0 ) );
		$this->seed( str_repeat( 'd', 64 ), array( 'generation' => 1 ) );

		$stats = ( new GarbageCollector() )->collect();

		self::assertSame( 1, $stats['orphaned'] );
		self::assertFileDoesNotExist( $this->store->pagePath( str_repeat( 'c', 64 ) ) );
		self::assertFileExists( $this->store->pagePath( str_repeat( 'd', 64 ) ) );
	}

	public function test_the_guard_files_are_never_collected(): void {
		$this->seed( str_repeat( 'e', 64 ), array( 'stale_until' => time() - 1 ) );

		( new GarbageCollector() )->collect();

		self::assertFileExists( Paths::pages() . '/.htaccess' );
		self::assertFileExists( Paths::pages() . '/index.html' );
	}

	/**
	 * Shared hosting bills inodes. The store writes two files per variant, so an
	 * unbounded cache can suspend the account it was installed to speed up.
	 */
	public function test_the_oldest_entries_are_evicted_once_the_budget_is_exceeded(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array(
			'generation' => 1,
			'cache'      => array( 'entry_budget' => 2 ),
		);

		foreach ( array( 'f', '0', '1' ) as $index => $prefix ) {
			$this->seed( str_repeat( $prefix, 64 ), array( 'stored_at' => time() - ( 100 - $index ) ) );
		}

		$stats = ( new GarbageCollector() )->collect();

		self::assertSame( 1, $stats['evicted'], 'Three entries against a budget of two evicts one.' );
		self::assertSame( 2, $stats['remaining'] );
		self::assertFileDoesNotExist( $this->store->pagePath( str_repeat( 'f', 64 ) ), 'The oldest entry goes first.' );

		unset( $GLOBALS['gtperf_test_options']['gt_performance_settings'] );
	}

	/**
	 * The collector was scheduled inside a branch that only ran when the queue event
	 * was missing, so an upgraded site with a healthy queue never armed it, which is
	 * the common upgrade path rather than a rare one. Caught on a live site.
	 */
	public function test_each_scheduled_event_is_armed_independently(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Queue/QueueModule.php' );

		self::assertMatchesRegularExpression(
			'/if \( ! wp_next_scheduled\( \x27gt_performance_run_queue\x27 \) \) \{/',
			$source,
			'Scheduling the queue must not be an early return that skips everything after it.'
		);
		self::assertStringNotContainsString(
			"if ( wp_next_scheduled( 'gt_performance_run_queue' ) ) {\n\t\t\treturn;",
			$source
		);
	}

	public function test_abandoned_temporary_files_are_cleaned_up(): void {
		$temp = Paths::pages() . '/aa/orphan.html.abandoned.tmp';
		if ( ! is_dir( dirname( $temp ) ) ) {
			mkdir( dirname( $temp ), 0o777, true );
		}
		file_put_contents( $temp, 'half a page' );
		touch( $temp, time() - 7200 );

		( new GarbageCollector() )->collect();

		self::assertFileDoesNotExist( $temp, 'A crashed write must not leave a permanent orphan.' );
	}

	/**
	 * A nightly backup read every artifact, so access time said they were all in use
	 * and nothing was reclaimed: seven scripts orphaned on 2026-08-29 were still on a
	 * live server on 2026-10-15, read at 03:22 the night before.
	 */
	public function test_an_artifact_a_backup_read_last_night_is_still_reclaimed(): void {
		$orphan = $this->artifact( 'css/' . self::hex( 'orphan' ) . '-used.css', 47 * DAY_IN_SECONDS );

		$stats = ( new GarbageCollector() )->collect();

		self::assertFileDoesNotExist( $orphan, 'Access time must not keep a file no render links.' );
		self::assertGreaterThanOrEqual( 1, $stats['artifacts'] );
	}

	public function test_an_artifact_a_render_refreshed_within_the_window_is_kept(): void {
		$css    = $this->artifact( 'css/' . self::hex( 'css' ) . '-used.css', 13 * DAY_IN_SECONDS );
		$fresh  = $this->artifact( 'css/' . self::hex( 'fresh' ) . '-used.css', DAY_IN_SECONDS );

		( new GarbageCollector() )->collect();

		self::assertFileExists( $css );
		self::assertFileExists( $fresh );
	}

	public function test_the_directory_guards_are_never_reclaimed(): void {
		$guard = $this->artifact( 'css/index.html', 400 * DAY_IN_SECONDS, '' );

		( new GarbageCollector() )->collect();

		self::assertFileExists( $guard );
	}

	/**
	 * Files written before renders refreshed them have only their creation time, so
	 * on upgrade every one, including those a cached page still links, would look
	 * abandoned. The first pass starts the clock and reclaims nothing.
	 */
	public function test_files_from_before_the_upgrade_count_from_the_first_collection(): void {
		unset( $GLOBALS['gtperf_test_options'][ GarbageCollector::KEEPALIVE_SINCE_OPTION ] );
		$linked = $this->artifact( 'css/' . self::hex( 'linked' ) . '-used.css', 60 * DAY_IN_SECONDS );

		( new GarbageCollector() )->collect();

		self::assertFileExists( $linked, 'The first pass after an upgrade must not reclaim what pages still link.' );
		$since = (int) ( $GLOBALS['gtperf_test_options'][ GarbageCollector::KEEPALIVE_SINCE_OPTION ] ?? 0 );
		self::assertGreaterThan( time() - HOUR_IN_SECONDS, $since );

		$GLOBALS['gtperf_test_options'][ GarbageCollector::KEEPALIVE_SINCE_OPTION ] = time() - 13 * DAY_IN_SECONDS;
		( new GarbageCollector() )->collect();
		self::assertFileExists( $linked, 'Pages get the full window to refresh their own files.' );

		$GLOBALS['gtperf_test_options'][ GarbageCollector::KEEPALIVE_SINCE_OPTION ] = time() - 15 * DAY_IN_SECONDS;
		( new GarbageCollector() )->collect();
		self::assertFileDoesNotExist( $linked, 'A file no render refreshed in the window is reclaimed.' );
	}

	/**
	 * A cached page can link a file for as long as the store, a browser and the
	 * Cloudflare edge keep that page, so a site that keeps pages longer than the
	 * default window keeps their files longer too.
	 */
	public function test_long_page_lifetimes_lengthen_the_window(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array(
			'cache'      => array( 'stale_ttl' => 20 * DAY_IN_SECONDS ),
			'cloudflare' => array(
				'enabled'  => true,
				'edge_ttl' => 10 * DAY_IN_SECONDS,
			),
		);
		$kept    = $this->artifact( 'css/' . self::hex( 'kept' ) . '-used.css', 25 * DAY_IN_SECONDS );
		$expired = $this->artifact( 'css/' . self::hex( 'gone' ) . '-used.css', 40 * DAY_IN_SECONDS );

		( new GarbageCollector() )->collect();

		self::assertFileExists( $kept, 'A page served from the edge 30 days after its render still links this file.' );
		self::assertFileDoesNotExist( $expired );

		$GLOBALS['gtperf_test_options']['gt_performance_settings']['cloudflare']['enabled'] = false;
		( new GarbageCollector() )->collect();
		self::assertFileDoesNotExist( $kept, 'An edge lifetime only counts while the edge caches pages.' );
	}

	/**
	 * Pages link a localized font stylesheet, never the font files it names, so no
	 * render refreshes a font file. It lives as long as a stylesheet names it.
	 */
	public function test_font_files_live_as_long_as_a_kept_stylesheet_names_them(): void {
		$named    = self::hex( 'named' ) . '.woff2';
		$unnamed  = self::hex( 'unnamed' ) . '.woff2';
		$retired  = self::hex( 'retired' ) . '.woff';
		$base     = Paths::assetsUrl( 'fonts/' );
		$live     = $this->artifact( 'fonts/' . self::hex( 'live' ) . '.css', DAY_IN_SECONDS, '@font-face{src:url("' . $base . $named . '")}' );
		$dead     = $this->artifact( 'fonts/' . self::hex( 'dead' ) . '.css', 30 * DAY_IN_SECONDS, '@font-face{src:url("' . $base . $retired . '")}' );
		$namedOne = $this->artifact( 'fonts/' . $named, 200 * DAY_IN_SECONDS );
		$loose    = $this->artifact( 'fonts/' . $unnamed, 200 * DAY_IN_SECONDS );
		$orphaned = $this->artifact( 'fonts/' . $retired, 200 * DAY_IN_SECONDS );

		( new GarbageCollector() )->collect();

		self::assertFileExists( $live );
		self::assertFileExists( $namedOne, 'A font file a kept stylesheet names stays, however old.' );
		self::assertFileDoesNotExist( $loose );
		self::assertFileDoesNotExist( $dead );
		self::assertFileDoesNotExist( $orphaned, 'A font file only a reclaimed stylesheet named goes with it.' );
	}

	public function test_a_refresh_writes_at_most_once_an_interval(): void {
		$path = $this->artifact( 'css/' . self::hex( 'refresh' ) . '-used.css', 3 * DAY_IN_SECONDS );
		ArtifactKeepAlive::refresh( $path );
		clearstatcache( true, $path );
		self::assertGreaterThan( time() - HOUR_IN_SECONDS, filemtime( $path ) );

		$recent = time() - HOUR_IN_SECONDS;
		touch( $path, $recent );
		ArtifactKeepAlive::refresh( $path );
		clearstatcache( true, $path );
		self::assertSame( $recent, filemtime( $path ), 'A file refreshed within the interval is left alone.' );

		ArtifactKeepAlive::refresh( Paths::assets() . '/css/missing.css' );
		self::assertFileDoesNotExist( Paths::assets() . '/css/missing.css', 'A refresh never creates a file.' );
	}

	public function test_a_css_build_that_arrives_at_an_existing_file_refreshes_it(): void {
		$css    = '.a{color:red}' . uniqid();
		$stored = ( new ArtifactStore() )->write( $css );
		touch( $stored['path'], time() - 5 * DAY_IN_SECONDS );

		( new ArtifactStore() )->write( $css );
		clearstatcache( true, $stored['path'] );

		self::assertGreaterThan( time() - HOUR_IN_SECONDS, filemtime( $stored['path'] ) );
		unlink( $stored['path'] );
	}

	public function test_serving_a_reused_css_build_refreshes_its_files(): void {
		$path = $this->artifact( 'css/' . self::hex( 'reused' ) . '-used.css', 5 * DAY_IN_SECONDS );
		$key      = self::hex( 'key' );
		$previous = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/keep-alive/';
		set_transient(
			'gtperf_css_shapes_' . hash( 'sha256', 'https://example.com/keep-alive/' ),
			array(
				$key => array(
					'placements' => array(),
					'markers'    => array(),
					'files'      => array( $path ),
				),
			),
			WEEK_IN_SECONDS
		);

		try {
			$reuse = new \ReflectionMethod( UnusedCssOptimizer::class, 'reusableOutput' );
			$html  = (string) $reuse->invoke( new UnusedCssOptimizer( new Logger() ), '<html><head></head><body></body></html>', $key );
		} finally {
			if ( null === $previous ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous;
			}
		}
		clearstatcache( true, $path );

		self::assertStringContainsString( '<body>', $html );
		self::assertGreaterThan( time() - HOUR_IN_SECONDS, filemtime( $path ) );
		UnusedCssOptimizer::forget( 'https://example.com/keep-alive/' );
	}

	public function test_linking_a_localized_font_stylesheet_refreshes_it(): void {
		$GLOBALS['gtperf_test_options']['gt_performance_settings'] = array( 'fonts' => array( 'self_host_google' => true ) );
		$href = 'https://fonts.googleapis.com/css2?family=Keep' . uniqid();
		$path = $this->artifact( 'fonts/' . hash( 'sha256', $href ) . '.css', 5 * DAY_IN_SECONDS, '@font-face{}' );

		$html = ( new FontOptimizer( new Logger() ) )->optimize( '<link rel="stylesheet" href="' . $href . '">' );
		clearstatcache( true, $path );

		self::assertStringContainsString( Paths::assetsUrl( 'fonts/' . basename( $path ) ), $html );
		self::assertGreaterThan( time() - HOUR_IN_SECONDS, filemtime( $path ) );
		unlink( $path );
	}
}
