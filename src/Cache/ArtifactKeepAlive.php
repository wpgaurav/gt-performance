<?php
/**
 * The signal that a page still links a generated file.
 *
 * Garbage collection reclaims generated CSS, JavaScript and font files by
 * modification time. Access time cannot answer the question: a nightly backup
 * reads every file, which refreshes access time under relatime, so a collector
 * that trusted it reclaimed nothing. On a live server, seven orphaned scripts
 * written on 2026-08-29 showed an access time of 03:22 on 2026-10-14. Reading a
 * file never changes its modification time, so every render that links a file
 * refreshes that instead, at most once an interval so a busy page does not write
 * on every request.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class ArtifactKeepAlive {
	/**
	 * How often a linked file's modification time is refreshed. After a render, the
	 * file is never more than this much older than the render that linked it.
	 */
	public const INTERVAL = DAY_IN_SECONDS;

	/**
	 * Mark a file a render is about to link as in use. Best effort: a file that
	 * cannot be touched ages as if nothing linked it.
	 */
	public static function refresh( string $path ): void {
		$modified = @filemtime( $path );
		if ( false !== $modified && time() - $modified > self::INTERVAL ) {
			@touch( $path );
		}
	}
}
