<?php
/**
 * Bounded manual CSS regeneration using the existing background queue.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\SafeMode;
use GTPerformance\Core\Settings;
use GTPerformance\Queue\JobRepository;

final class Maintenance {
	public const BATCH_JOB = 'regenerate_css_batch';

	public static function enabled(): bool {
		return UnusedCssOptimizer::available() && ! SafeMode::active()
			&& (bool) Settings::get( 'cache.enabled', true )
			&& (int) Settings::get( 'css.rollout_percent', 100 ) > 0
			&& (bool) apply_filters( 'gt_performance_optimize_stage', true, 'css' );
	}

	public static function eligible( string $url ): bool {
		$parts = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] )
			|| ( $parts['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' )
			|| strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) )
			|| ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return false;
		}
		$request = RequestContext::fromUrl( $url );
		$config = (array) apply_filters( 'gt_performance_cache_policy', (array) Settings::get( 'cache', array() ) );
		return null !== $request && ( new Eligibility() )->decide( $request, $config )->cacheable
			&& ( new Rollout() )->allows( $url, (int) Settings::get( 'css.rollout_percent', 100 ) );
	}

	public function enqueue( string $url, bool $force = false ): bool {
		if ( ! self::enabled() || ! self::eligible( $url ) ) {
			return false;
		}
		$jobs = new JobRepository();
		if ( $jobs->hasActive( UnusedCssOptimizer::JOB_TYPE, array( 'url' => $url ) ) ) {
			return false;
		}
		$reports = new ReportRepository();
		if ( $force ) {
			$reports->invalidateUrl( $url );
			( new Purger() )->purgeUrl( $url );
		}
		$id = $jobs->enqueue( UnusedCssOptimizer::JOB_TYPE, array( 'url' => $url ), 70 );
		if ( $id <= 0 ) {
			return false;
		}
		$mode = (string) Settings::get( 'css.mode', 'file' );
		$fingerprint = $reports->begin( $url, $mode );
		$reports->complete( $fingerprint, $mode, 'queued', '', array( 'url' => $url ) );
		return true;
	}

	public function regenerateAll(): bool {
		$jobs = new JobRepository();
		if ( ! self::enabled() || $jobs->hasActive( self::BATCH_JOB ) ) {
			return false;
		}
		if ( $jobs->enqueue( self::BATCH_JOB, array(), 65 ) <= 0 ) {
			return false;
		}
		update_option( 'gtperf_css_revision', wp_generate_uuid4(), false );
		( new Purger() )->purgeAll();
		return true;
	}

	/** @param array<string, mixed> $payload Cursor. */
	public function runBatch( array $payload ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$rows = ( new ReportRepository() )->batch( (int) ( $payload['after_id'] ?? 0 ) );
		foreach ( $rows as $row ) {
			$metadata = json_decode( (string) $row['metadata'], true );
			$this->enqueue( (string) ( $metadata['url'] ?? '' ) );
		}
		if ( 100 === count( $rows ) ) {
			( new JobRepository() )->enqueue( self::BATCH_JOB, array( 'after_id' => (int) end( $rows )['id'] ), 65 );
		}
		if ( 0 === (int) ( $payload['after_id'] ?? 0 ) ) {
			$this->enqueue( home_url( '/' ) );
		}
	}
}
