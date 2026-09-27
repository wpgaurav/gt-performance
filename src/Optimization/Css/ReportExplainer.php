<?php
/**
 * Plain-language readings of unused CSS reports.
 *
 * Reports store short machine reasons (`critical_budget_exceeded`, "No completed
 * build…"). The status screen showed them verbatim, so a successful Hybrid build
 * delivered as one file looked like an error. Each reading here says what
 * visitors get for that page and, when something needs doing, what to do.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

final class ReportExplainer {
	public const FALLBACK_BUDGET = 'critical_budget_exceeded';

	/** Largest Hybrid inline limit the setting accepts. */
	private const MAX_BUDGET = 51200;

	/**
	 * @param array<string, mixed> $report Report with decoded metadata and effective status.
	 * @return array{label:string,tone:string}
	 */
	public static function status( array $report ): array {
		$status = (string) ( $report['status'] ?? '' );
		if ( 'ready' === $status && self::FALLBACK_BUDGET === ( $report['metadata']['fallback'] ?? '' ) ) {
			return array(
				'label' => __( 'Ready, one file', 'gt-performance' ),
				'tone'  => 'neutral',
			);
		}

		return match ( $status ) {
			'ready' => array(
				'label' => __( 'Ready', 'gt-performance' ),
				'tone'  => 'success',
			),
			'stale' => array(
				'label' => __( 'Out of date', 'gt-performance' ),
				'tone'  => 'neutral',
			),
			'queued' => array(
				'label' => __( 'Queued', 'gt-performance' ),
				'tone'  => 'neutral',
			),
			'processing' => array(
				'label' => __( 'Building', 'gt-performance' ),
				'tone'  => 'neutral',
			),
			'skipped' => array(
				'label' => __( 'Skipped', 'gt-performance' ),
				'tone'  => 'neutral',
			),
			default => array(
				'label' => __( 'Failed', 'gt-performance' ),
				'tone'  => 'warning',
			),
		};
	}

	/**
	 * What this result means for the page's visitors, and what to do if anything.
	 *
	 * @param array<string, mixed> $report Report with decoded metadata and effective status.
	 */
	public static function explain( array $report ): string {
		$meta   = (array) ( $report['metadata'] ?? array() );
		$status = (string) ( $report['status'] ?? '' );
		$reason = (string) ( $meta['reason'] ?? '' );
		$error  = (string) ( $meta['error'] ?? '' );

		switch ( $status ) {
			case 'queued':
				return __( 'Waiting in the background queue. Visitors get the original CSS until the build finishes.', 'gt-performance' );
			case 'processing':
				return __( 'Building now. Visitors get the original CSS until it finishes.', 'gt-performance' );
			case 'stale':
				return __( 'Settings, the plugin, or the CSS changed after this build. Visitors get the original CSS until the next visit rebuilds it.', 'gt-performance' );
			case 'ready':
				return self::FALLBACK_BUDGET === ( $meta['fallback'] ?? '' ) ? self::budgetFallback( $meta ) : self::ready( $meta );
			case 'skipped':
				if ( str_contains( $reason, 'No eligible stylesheets' ) ) {
					return __( 'Nothing to reduce: every stylesheet on this page is excluded, on another domain, or unreadable, so it keeps its original CSS.', 'gt-performance' );
				}
				if ( str_contains( $reason, 'time budget' ) ) {
					return __( 'Reducing this page\'s CSS took longer than 20 seconds, so it keeps its original CSS. Excluding its largest stylesheet usually fixes this.', 'gt-performance' );
				}
				if ( str_contains( $reason, 'paused or this URL is excluded' ) ) {
					return __( 'Not built: unused CSS is paused, the staged rollout leaves this page out, or a cache exception excludes it. It keeps its original CSS.', 'gt-performance' );
				}

				return '' !== $reason ? $reason : __( 'Not built. This page keeps its original CSS.', 'gt-performance' );
		}

		$keeps = __( 'Visitors still get the original CSS, so nothing is broken.', 'gt-performance' );
		if ( str_contains( $error, 'No completed build' ) ) {
			return __( 'The page was requested, but no build was recorded. Usually the site cannot request its own pages (loopback), the page-cache drop-in is missing, or the page is excluded from caching.', 'gt-performance' ) . ' ' . $keeps;
		}
		if ( preg_match( '/returned HTTP (\d{3})/', $error, $match ) ) {
			$code = (int) $match[1];
			$hint = 404 === $code || 410 === $code
				? __( 'The page no longer exists; the result goes away on its own.', 'gt-performance' )
				: ( $code >= 500 ? __( 'The page returned a server error. Open it to check.', 'gt-performance' ) : __( 'Only pages that answer 200 can be built.', 'gt-performance' ) );

			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Requesting the page returned HTTP %d.', 'gt-performance' ), $code ) . ' ' . $hint . ' ' . $keeps;
		}
		if ( str_contains( $error, 'result was empty' ) ) {
			return __( 'No CSS rule matched the page, which usually means its markup could not be read.', 'gt-performance' ) . ' ' . $keeps;
		}

		return ( '' !== $error ? rtrim( $error, '.' ) . '. ' : '' ) . $keeps;
	}

	/**
	 * Advice for the Hybrid inline limit from the builds that measured their size.
	 *
	 * @param list<int> $sizes     Critical CSS bytes of current Hybrid builds that recorded one.
	 * @param int       $fallbacks Current Hybrid builds delivered as one file.
	 * @param int       $builds    Current Hybrid builds.
	 */
	public static function budgetAdvice( array $sizes, int $fallbacks, int $builds, int $budget ): string {
		if ( 0 === $fallbacks || 0 === $builds ) {
			return '';
		}
		$share = sprintf(
			/* translators: 1: builds delivered as one file, 2: all builds, 3: current limit. */
			__( '%1$s of %2$s Hybrid builds put everything in one file because the CSS for the top of the page was over the %3$s inline limit. Those pages are styled correctly; they load the same way as Generated file mode.', 'gt-performance' ),
			number_format_i18n( $fallbacks ),
			number_format_i18n( $builds ),
			size_format( $budget )
		);
		if ( array() === $sizes ) {
			return $share . ' ' . __( 'These builds did not record their size. Force regenerate all, and this note will suggest a limit.', 'gt-performance' );
		}

		sort( $sizes );
		$typical = $sizes[ (int) floor( ( count( $sizes ) - 1 ) * 0.75 ) ];
		$suggest = (int) ceil( $typical / 1024 ) * 1024;
		if ( $suggest > self::MAX_BUDGET ) {
			return $share . ' ' . sprintf(
				/* translators: %s: typical critical CSS size. */
				__( 'The top of a typical page needs about %s of CSS, more than the 50 KB that can be inlined. Choose Generated file: it gives these pages the same result without trying.', 'gt-performance' ),
				size_format( $typical )
			);
		}

		return $share . ' ' . sprintf(
			/* translators: %s: suggested limit. */
			__( 'A limit of %s would fit three in four of these pages. Inlined CSS is added to every page\'s HTML, so a higher limit makes each page heavier; Generated file is the simpler choice if that is not worth it.', 'gt-performance' ),
			size_format( $suggest )
		);
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 */
	private static function ready( array $meta ): string {
		$original  = (int) ( $meta['original_bytes'] ?? 0 );
		$generated = (int) ( $meta['generated_bytes'] ?? 0 );
		$text      = sprintf(
			/* translators: 1: original size, 2: generated size. */
			__( 'Visitors get %2$s of CSS instead of %1$s.', 'gt-performance' ),
			size_format( $original ),
			size_format( $generated )
		);
		$inline = 0;
		foreach ( (array) ( $meta['outputs'] ?? array() ) as $output ) {
			if ( 'inline' === ( $output['delivery'] ?? '' ) ) {
				$inline += (int) ( $output['bytes'] ?? 0 );
			}
		}
		if ( $inline > 0 && $inline < $generated ) {
			/* translators: %s: inlined size. */
			$text .= ' ' . sprintf( __( '%s of it is inlined for the top of the page; the rest loads as a file.', 'gt-performance' ), size_format( $inline ) );
		}

		return $text;
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 */
	private static function budgetFallback( array $meta ): string {
		$base = sprintf(
			/* translators: 1: original size, 2: generated size. */
			__( 'Visitors get %2$s of CSS instead of %1$s, as one file.', 'gt-performance' ),
			size_format( (int) ( $meta['original_bytes'] ?? 0 ) ),
			size_format( (int) ( $meta['generated_bytes'] ?? 0 ) )
		);
		if ( isset( $meta['critical_bytes'], $meta['critical_budget'] ) ) {
			return $base . ' ' . sprintf(
				/* translators: 1: critical CSS size, 2: inline limit. */
				__( 'Hybrid did not inline anything: the top of this page needs %1$s of CSS, over the %2$s inline limit.', 'gt-performance' ),
				size_format( (int) $meta['critical_bytes'] ),
				size_format( (int) $meta['critical_budget'] )
			);
		}

		return $base . ' ' . __( 'Hybrid did not inline anything: the top of this page needs more CSS than the inline limit allows.', 'gt-performance' );
	}
}
