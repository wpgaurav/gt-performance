<?php
/**
 * Network site resolution before WordPress loads.
 *
 * The advanced-cache.php drop-in runs before WordPress decides which network site a request
 * belongs to, but every site keeps its own configuration and page store, so the
 * drop-in has to make the same decision first. This mirrors get_site_by_path():
 * the domain must match (with or without a leading www.), and among that domain's
 * sites the longest path that prefixes the request path wins, compared on segment
 * boundaries and without regard to case, as MySQL compares them.
 *
 * A request that resolves to no site, or to an archived, deleted, or spam site,
 * is left to WordPress. Getting this wrong cannot serve one site's page to
 * another: a stored page only exists in the directory of the site that rendered
 * it, so a disagreement costs a miss, never a cross-site hit.
 *
 * Runs inside advanced-cache.php, so it uses no WordPress functions.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

final class SiteResolver {
	/**
	 * @param array<mixed> $sites Entries of {id, domain, path, active} from the site map.
	 * @param string       $host  Sanitized request host, possibly with a port.
	 * @param string       $path  Normalized request path.
	 * @return int Blog id, or 0 when the request belongs to no cacheable site.
	 */
	public static function resolve( array $sites, string $host, string $path ): int {
		$host = strtolower( $host );
		// WordPress strips only the default ports before it looks a domain up; a site
		// on any other port stores the port as part of its domain.
		if ( str_ends_with( $host, ':80' ) ) {
			$host = substr( $host, 0, -3 );
		} elseif ( str_ends_with( $host, ':443' ) ) {
			$host = substr( $host, 0, -4 );
		}
		if ( '' === $host ) {
			return 0;
		}

		$domains = array( $host );
		if ( str_starts_with( $host, 'www.' ) ) {
			$domains[] = substr( $host, 4 );
		}

		// "/uranium" is the site at "/uranium/", as it is for WordPress.
		$path = strtolower( rtrim( $path, '/' ) . '/' );

		$best       = null;
		$bestPath   = -1;
		$bestDomain = -1;
		foreach ( $sites as $site ) {
			if ( ! is_array( $site ) ) {
				continue;
			}
			$domain   = strtolower( (string) ( $site['domain'] ?? '' ) );
			$sitePath = strtolower( (string) ( $site['path'] ?? '' ) );
			if ( '' === $domain || '' === $sitePath || ! in_array( $domain, $domains, true ) ) {
				continue;
			}
			$sitePath = rtrim( $sitePath, '/' ) . '/';
			if ( ! str_starts_with( $path, $sitePath ) ) {
				continue;
			}
			// Longest path first, then the longer (exact rather than www-less) domain.
			if ( strlen( $sitePath ) > $bestPath || ( strlen( $sitePath ) === $bestPath && strlen( $domain ) > $bestDomain ) ) {
				$best       = $site;
				$bestPath   = strlen( $sitePath );
				$bestDomain = strlen( $domain );
			}
		}

		if ( null === $best || true !== ( $best['active'] ?? false ) ) {
			return 0;
		}

		return max( 0, (int) ( $best['id'] ?? 0 ) );
	}
}
