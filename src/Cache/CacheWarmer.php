<?php
/**
 * Resumable sitemap-driven cache warming.
 *
 * A warm run is three kinds of bounded queue job. `warm_site` starts the run
 * and seeds its sitemap sources. Each `warm_discover` fetches at most two
 * sitemaps and records what they list. Each `warm_dispatch` queues one batch of
 * preloads, then waits for their outcomes before queueing the next. Progress
 * lives in the warm-targets table, so a worker that dies resumes where it
 * stopped instead of starting over or leaving the run half-reported.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Queue\JobLease;
use GTPerformance\Queue\JobRepository;

final class CacheWarmer {
	public const START_JOB    = 'warm_site';
	public const DISCOVER_JOB = 'warm_discover';
	public const DISPATCH_JOB = 'warm_dispatch';

	public const MAX_SOURCES = 10;

	private const MAX_DEPTH       = 5;
	private const FETCHES_PER_JOB = 2;
	private const MAX_BYTES       = 2 * MB_IN_BYTES;
	private const ROBOTS_BYTES    = 64 * 1024;

	/** Dispatch checks before a batch without outcomes is written off. */
	private const MAX_WAIT_STEPS = 30;

	/** Sitemap entries modified this recently are warmed first. */
	private const RECENT_SECONDS = 7 * DAY_IN_SECONDS;

	/**
	 * Level with preloads and ahead of CSS generation (70). A site is cold after a
	 * full purge; at 80, a steady CSS backlog left each run step waiting for aging.
	 */
	public const JOB_PRIORITY = 50;

	public function __construct(
		private readonly Logger $logger,
		private readonly SitemapReader $reader = new SitemapReader(),
		private readonly WarmRunRepository $runs = new WarmRunRepository(),
		private readonly JobRepository $jobs = new JobRepository(),
	) {
	}

	/**
	 * Queue a new warm run. Returns the job ID, or 0 when the queue is not ready.
	 */
	public function queue(): int {
		// Warming stores pages; in optimize-only mode nothing here stores them.
		if ( Settings::optimizeOnly() ) {
			return 0;
		}

		return $this->jobs->enqueue( self::START_JOB, array(), self::JOB_PRIORITY );
	}

	/**
	 * Start a run: record its sources, seed the home page, and begin discovery.
	 */
	public function start(): string {
		$sources = $this->sources();
		$run     = $this->runs->create( $sources );
		$id      = (string) $run['id'];

		$targets = $this->urlTargets( home_url( '/' ), 0 );
		foreach ( $sources as $source ) {
			$targets[] = array(
				'kind'     => 'sitemap',
				'url'      => $source,
				'depth'    => 0,
				'priority' => 0,
			);
		}
		$this->runs->add( $id, $targets );
		$this->next( self::DISCOVER_JOB, $id, 1 );

		return $id;
	}

	/**
	 * Fetch up to two pending sitemaps for a run and record their entries.
	 */
	public function discover( string $runId, int $step ): void {
		$run = $this->runs->find( $runId );
		if ( null === $run || 'discovering' !== $run['state'] ) {
			return;
		}

		foreach ( $this->runs->pending( $runId, 'sitemap', self::FETCHES_PER_JOB ) as $sitemap ) {
			JobLease::checkpoint();
			if ( $this->visit( $runId, $sitemap ) ) {
				$this->runs->warn( $runId, 'target_cap' );
				$this->runs->transition( $runId, 'sitemap', 'pending', 'skipped', 'target_cap' );
				break;
			}
		}

		if ( array() !== $this->runs->pending( $runId, 'sitemap', 1 ) ) {
			// Touch the run so a long discovery is not mistaken for a stalled one.
			$this->runs->update( $runId, array() );
			$this->next( self::DISCOVER_JOB, $runId, $step + 1 );
			return;
		}

		$this->runs->update( $runId, array( 'state' => 'warming' ) );
		$this->next( self::DISPATCH_JOB, $runId, 1 );
	}

	/**
	 * Queue the next batch of preloads once the previous batch has reported.
	 */
	public function dispatch( string $runId, int $step ): void {
		$run = $this->runs->find( $runId );
		if ( null === $run || 'warming' !== $run['state'] ) {
			return;
		}

		$counts = $this->runs->counts( $runId )['url'];
		if ( ( $counts['queued'] ?? 0 ) > 0 ) {
			if ( (int) $run['wait_steps'] < self::MAX_WAIT_STEPS ) {
				$this->runs->update( $runId, array( 'wait_steps' => (int) $run['wait_steps'] + 1 ) );
				$this->next( self::DISPATCH_JOB, $runId, $step + 1, MINUTE_IN_SECONDS );
				return;
			}
			// Cancelled or lost preload jobs never report. Say so rather than wait forever.
			$this->runs->transition( $runId, 'url', 'queued', 'failed', 'no_result' );
			$this->runs->warn( $runId, 'lost_results' );
		}
		$this->runs->update( $runId, array( 'wait_steps' => 0 ) );

		$batch = max( 0, min( 2000, (int) Settings::get( 'cache.preload_max_urls', 200 ) ) );
		if ( 0 === $batch || ! (bool) Settings::get( 'cache.preload', true ) ) {
			$this->runs->transition( $runId, 'url', 'pending', 'skipped', 'preload_disabled' );
			$this->runs->warn( $runId, 'preload_disabled' );
			$this->finish( $runId, 'partial' );
			return;
		}

		$budget = max( 0, (int) Settings::get( 'cache.entry_budget', 5000 ) );
		if ( $budget > 0 ) {
			$room = $budget - $this->used( $counts );
			if ( $room <= 0 ) {
				if ( $this->runs->transition( $runId, 'url', 'pending', 'skipped', 'capacity_limited' ) > 0 ) {
					$this->finish( $runId, 'capacity_limited' );
					return;
				}
				$this->finish( $runId );
				return;
			}
			$batch = min( $batch, $room );
		}

		$targets = $this->runs->pending( $runId, 'url', $batch );
		if ( array() === $targets ) {
			$this->finish( $runId );
			return;
		}

		$queued = array();
		foreach ( $targets as $target ) {
			$payload = array( 'url' => (string) $target['url'] );
			if ( 'mobile' === $target['variant'] ) {
				$payload['variant'] = 'mobile';
			}
			if ( $this->jobs->enqueue( 'preload_url', $payload, 50 ) > 0 ) {
				$queued[] = (int) $target['id'];
			}
		}
		$this->runs->markQueued( $queued );
		$this->next( self::DISPATCH_JOB, $runId, $step + 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Attribute a preload outcome to the latest unfinished run.
	 *
	 * @param array{status:string,detail:string,http:int} $outcome Preload outcome.
	 */
	public function record( string $url, string $variant, array $outcome ): void {
		$run = $this->runs->latest();
		if ( null === $run || in_array( $run['state'], WarmRunRepository::TERMINAL, true ) ) {
			return;
		}

		$this->runs->record( (string) $run['id'], $url, 'mobile' === $variant ? 'mobile' : 'public', $outcome['status'], $outcome['detail'] );
	}

	/**
	 * Run summary for admin, CLI, and the health report.
	 *
	 * @return array<string, mixed>|null
	 */
	public function summary(): ?array {
		$run = $this->runs->latest();
		if ( null === $run ) {
			return null;
		}

		return array(
			'id'          => (string) $run['id'],
			'state'       => (string) $run['state'],
			'sources'     => (array) $run['sources'],
			'started_at'  => (int) $run['started_at'],
			'updated_at'  => (int) $run['updated_at'],
			'finished_at' => (int) $run['finished_at'],
			'warnings'    => (array) $run['warnings'],
			'targets'     => $this->runs->counts( (string) $run['id'] ),
		);
	}

	/**
	 * Explicit sources win. Otherwise use core's sitemap plus same-origin
	 * declarations from robots.txt, which is where SEO plugins announce theirs.
	 *
	 * @return list<string>
	 */
	public function sources(): array {
		$home     = home_url( '/' );
		$explicit = array_values( array_filter( (array) Settings::get( 'cache.preload_sitemaps', array() ), 'is_string' ) );
		$sources  = array() !== $explicit ? $explicit : array( home_url( '/wp-sitemap.xml' ) );

		if ( array() === $explicit ) {
			$robots = $this->request( home_url( '/robots.txt' ), self::ROBOTS_BYTES );
			if ( 'ok' === $robots['state'] ) {
				$sources = array_merge( $sources, $this->reader->robotsSitemaps( $robots['body'] ) );
			}
		}

		$unique = array();
		foreach ( $sources as $source ) {
			if ( $this->isSameOrigin( $source, $home ) ) {
				$unique[ WarmRunRepository::normalize( $source ) ] = $source;
			}
		}

		return array_slice( array_values( $unique ), 0, self::MAX_SOURCES );
	}

	/**
	 * Fetch one sitemap and record its entries. Returns true when the run's
	 * target cap was reached.
	 *
	 * @param array<string, mixed> $sitemap Pending sitemap target.
	 */
	private function visit( string $runId, array $sitemap ): bool {
		$id       = (int) $sitemap['id'];
		$url      = (string) $sitemap['url'];
		$depth    = (int) $sitemap['depth'];
		$home     = home_url( '/' );
		$response = $this->request( $url, self::MAX_BYTES );

		if ( 'redirect' === $response['state'] ) {
			// A hop counts as a level, so a redirect chain is bounded like nesting.
			if ( ! $this->isSameOrigin( $response['location'], $home ) || $depth + 1 > self::MAX_DEPTH ) {
				$this->runs->mark( $id, 'skipped', $depth + 1 > self::MAX_DEPTH ? 'depth_limit' : 'foreign_redirect' );
				$this->runs->warn( $runId, 'sitemap_skipped' );
				return false;
			}
			$capped = $this->runs->add(
				$runId,
				array(
					array(
						'kind'     => 'sitemap',
						'url'      => $response['location'],
						'depth'    => $depth + 1,
						'priority' => 0,
					),
				)
			)['capped'];
			$this->runs->mark( $id, 'redirected' );
			return $capped;
		}

		if ( 'ok' !== $response['state'] ) {
			$this->runs->mark( $id, 'failed', $response['detail'] );
			$this->runs->warn( $runId, 'sitemap_failed' );
			$this->logger->log( 'debug', 'Cache warm sitemap fetch failed', array( 'url' => $url ) );
			return false;
		}

		if ( strlen( $response['body'] ) >= self::MAX_BYTES ) {
			$this->runs->warn( $runId, 'sitemap_truncated' );
		}

		$entries = $this->reader->entries( $response['body'] );
		$targets = array();
		$foreign = false;

		if ( $this->reader->isIndex( $response['body'] ) ) {
			if ( $depth + 1 > self::MAX_DEPTH ) {
				$this->runs->mark( $id, 'fetched', 'depth_limit' );
				$this->runs->warn( $runId, 'depth_limit' );
				return false;
			}
			foreach ( array_map( 'strval', array_keys( $entries ) ) as $child ) {
				if ( ! $this->isSameOrigin( $child, $home ) ) {
					$foreign = true;
					continue;
				}
				$targets[] = array(
					'kind'     => 'sitemap',
					'url'      => $child,
					'depth'    => $depth + 1,
					'priority' => 0,
				);
			}
		} else {
			$eligible = new Eligibility();
			$policy   = $this->policy();
			$recent   = time() - self::RECENT_SECONDS;
			foreach ( $entries as $location => $modified ) {
				$location = (string) $location;
				if ( ! $this->isSameOrigin( $location, $home ) ) {
					$foreign = true;
					continue;
				}
				$request = RequestContext::fromUrl( $location );
				if ( null === $request || ! $eligible->decide( $request, $policy )->cacheable ) {
					continue;
				}
				$targets = array_merge( $targets, $this->urlTargets( $location, $modified >= $recent ? 10 : 50 ) );
			}
		}

		if ( $foreign ) {
			$this->runs->warn( $runId, 'foreign_entries' );
		}

		// Record entries before marking the sitemap fetched: a worker that dies in
		// between revisits it, and already-recorded entries are ignored.
		$capped = $this->runs->add( $runId, $targets )['capped'];
		$this->runs->mark( $id, 'fetched' );

		return $capped;
	}

	/**
	 * One target per cache variant the site stores.
	 *
	 * @return list<array{kind:string,url:string,variant:string,priority:int}>
	 */
	private function urlTargets( string $url, int $priority ): array {
		$targets = array(
			array(
				'kind'     => 'url',
				'url'      => $url,
				'variant'  => 'public',
				'priority' => $priority,
			),
		);
		if ( (bool) Settings::get( 'cache.separate_mobile', false ) ) {
			$targets[] = array(
				'kind'     => 'url',
				'url'      => $url,
				'variant'  => 'mobile',
				'priority' => $priority,
			);
		}

		return $targets;
	}

	/**
	 * Entries this run already occupies or tried to.
	 *
	 * @param array<string, int> $counts URL target counts by status.
	 */
	private function used( array $counts ): int {
		unset( $counts['pending'], $counts['skipped'] );

		return array_sum( $counts );
	}

	private function finish( string $runId, string $state = '' ): void {
		if ( '' === $state ) {
			$run      = $this->runs->find( $runId );
			$sitemaps = $this->runs->counts( $runId )['sitemap'];
			$warnings = null === $run ? array() : (array) $run['warnings'];
			$state    = ( $sitemaps['failed'] ?? 0 ) > 0 || array_intersect( $warnings, array( 'sitemap_truncated', 'depth_limit', 'target_cap', 'sitemap_skipped', 'lost_results' ) )
				? 'partial'
				: 'complete';
		}

		$this->runs->update(
			$runId,
			array(
				'state'       => $state,
				'finished_at' => time(),
			)
		);
	}

	private function next( string $type, string $runId, int $step, int $delay = 0 ): void {
		$this->jobs->enqueue(
			$type,
			array(
				'run'  => $runId,
				'step' => $step,
			),
			self::JOB_PRIORITY,
			$delay
		);
	}

	/**
	 * Fetch a sitemap or robots.txt without following redirects, so each hop is
	 * checked for origin and recorded as its own visited target.
	 *
	 * @return array{state:string,body:string,location:string,detail:string}
	 */
	private function request( string $url, int $limit ): array {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => $limit,
				'user-agent'          => 'GT-Performance-Warmer/' . GTPERF_VERSION,
			)
		);
		$result = array(
			'state'    => 'failed',
			'body'     => '',
			'location' => '',
			'detail'   => 'request_error',
		);
		if ( is_wp_error( $response ) ) {
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( '' !== $location && str_starts_with( $location, '/' ) && ! str_starts_with( $location, '//' ) ) {
				$location = home_url( $location );
			}
			$result['state']    = 'redirect';
			$result['location'] = esc_url_raw( $location );
			return $result;
		}
		if ( 200 !== $status ) {
			$result['detail'] = 'http_' . $status;
			return $result;
		}

		$result['state'] = 'ok';
		$result['body']  = (string) wp_remote_retrieve_body( $response );
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function policy(): array {
		return (array) apply_filters( 'gt_performance_cache_policy', array( 'hosts' => Settings::canonicalHosts() ) + (array) Settings::get( 'cache', array() ) );
	}

	private function isSameOrigin( string $url, string $home ): bool {
		$urlParts  = wp_parse_url( $url );
		$homeParts = wp_parse_url( $home );
		if ( ! is_array( $urlParts ) || ! is_array( $homeParts ) ) {
			return false;
		}

		$urlScheme  = strtolower( (string) ( $urlParts['scheme'] ?? '' ) );
		$homeScheme = strtolower( (string) ( $homeParts['scheme'] ?? '' ) );
		$urlHost    = strtolower( (string) ( $urlParts['host'] ?? '' ) );
		$homeHost   = strtolower( (string) ( $homeParts['host'] ?? '' ) );
		$urlPort    = (int) ( $urlParts['port'] ?? ( 'https' === $urlScheme ? 443 : 80 ) );
		$homePort   = (int) ( $homeParts['port'] ?? ( 'https' === $homeScheme ? 443 : 80 ) );

		return in_array( $urlScheme, array( 'http', 'https' ), true )
			&& $urlScheme === $homeScheme
			&& '' !== $urlHost
			&& $urlHost === $homeHost
			&& $urlPort === $homePort
			&& empty( $urlParts['user'] )
			&& empty( $urlParts['pass'] );
	}
}
