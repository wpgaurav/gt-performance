<?php
/**
 * Installed plugin detection for compatibility reporting.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Compatibility;

final class PluginDetector {
	/**
	 * @return array<string, array{name:string,files:list<string>,group:string,protection:string,javascript_exclusions?:list<string>}>
	 */
	public function catalog(): array {
		return array(
			'perfmatters'     => array(
				'name'       => 'Perfmatters',
				'files'      => array( 'perfmatters/perfmatters.php' ),
				'group'      => 'optimization',
				'protection' => __( 'Coordinates unused CSS, JavaScript, and front-end optimization ownership.', 'gt-performance' ),
			),
			'flyingpress'     => array(
				'name'       => 'FlyingPress',
				'files'      => array( 'flying-press/flying-press.php', 'flyingpress/flyingpress.php' ),
				'group'      => 'cache',
				'protection' => __( 'Detected as another full-page cache and optimization owner.', 'gt-performance' ),
			),
			'wp-rocket'       => array(
				'name'       => 'WP Rocket',
				'files'      => array( 'wp-rocket/wp-rocket.php' ),
				'group'      => 'cache',
				'protection' => __( 'Detected as another full-page cache and optimization owner.', 'gt-performance' ),
			),
			'litespeed-cache' => array(
				'name'       => 'LiteSpeed Cache',
				'files'      => array( 'litespeed-cache/litespeed-cache.php' ),
				'group'      => 'cache',
				'protection' => __( 'Detected as another full-page cache and optimization owner.', 'gt-performance' ),
			),
			'wp-super-cache'  => array(
				'name'       => 'WP Super Cache',
				'files'      => array( 'wp-super-cache/wp-cache.php' ),
				'group'      => 'cache',
				'protection' => __( 'Detected as another full-page cache owner.', 'gt-performance' ),
			),
			'w3-total-cache'  => array(
				'name'       => 'W3 Total Cache',
				'files'      => array( 'w3-total-cache/w3-total-cache.php' ),
				'group'      => 'cache',
				'protection' => __( 'Detected as another page and object cache owner.', 'gt-performance' ),
			),
			'autoptimize'     => array(
				'name'       => 'Autoptimize',
				'files'      => array( 'autoptimize/autoptimize.php' ),
				'group'      => 'optimization',
				'protection' => __( 'Detected as another CSS and JavaScript optimization owner.', 'gt-performance' ),
			),
			'jetpack-boost'   => array(
				'name'       => 'Jetpack Boost',
				'files'      => array( 'jetpack-boost/jetpack-boost.php' ),
				'group'      => 'optimization',
				'protection' => __( 'Detected because its page cache and critical CSS can overlap.', 'gt-performance' ),
			),
			'akismet'         => array(
				'name'       => 'Akismet Anti-spam',
				'files'      => array( 'akismet/akismet.php' ),
				'group'      => 'service',
				'protection' => __( 'Preserves the comment-form privacy notice and anti-spam assets.', 'gt-performance' ),
			),
			'jetpack'         => array(
				'name'       => 'Jetpack',
				'files'      => array( 'jetpack/jetpack.php' ),
				'group'      => 'service',
				'protection' => __( 'Protects forms, comments, subscriptions, search, media, and visitor-state cookies.', 'gt-performance' ),
			),
			'core-forms'      => array(
				'name'       => 'Core Forms',
				'files'      => array( 'core-forms/core-forms.php' ),
				'group'      => 'service',
				'protection' => __( 'Keeps poll voter identity only on pages that contain polls.', 'gt-performance' ),
			),
			'independent-analytics' => array(
				'name'                  => 'Independent Analytics',
				'files'                 => array( 'independent-analytics/iawp.php', 'independent-analytics-pro/iawp.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps view and click tracking available without JavaScript deferral, delay, or rewriting.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/independent-analytics/', '/plugins/independent-analytics-pro/' ),
			),
			'burst-statistics' => array(
				'name'                  => 'Burst Statistics',
				'files'                 => array( 'burst-statistics/burst.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps Burst tracking scripts out of JavaScript optimization and delay.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/burst-statistics/' ),
			),
			'koko-analytics' => array(
				'name'                  => 'Koko Analytics',
				'files'                 => array( 'koko-analytics/koko-analytics.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps Koko tracking scripts out of JavaScript optimization and delay.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/koko-analytics/' ),
			),
			'matomo-analytics' => array(
				'name'                  => 'Matomo Analytics',
				'files'                 => array( 'matomo/matomo.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps local Matomo and legacy Piwik trackers out of JavaScript optimization and delay.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/matomo/', 'matomo.js', 'piwik.js' ),
			),
			'wp-statistics' => array(
				'name'                  => 'WP Statistics',
				'files'                 => array( 'wp-statistics/wp-statistics.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps WP Statistics tracking scripts out of JavaScript optimization and delay.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/wp-statistics/' ),
			),
			'google-site-kit' => array(
				'name'                  => 'Site Kit by Google',
				'files'                 => array( 'google-site-kit/google-site-kit.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps Site Kit and its Google Analytics tags available at their intended load time.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/google-site-kit/', 'google-analytics.com', 'googletagmanager.com' ),
			),
			'monsterinsights' => array(
				'name'                  => 'MonsterInsights',
				'files'                 => array( 'googleanalytics/googleanalytics.php', 'googleanalytics-premium/googleanalytics-premium.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps MonsterInsights and its Google Analytics tags out of JavaScript optimization and delay.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/googleanalytics/', '/plugins/googleanalytics-premium/', 'google-analytics.com', 'googletagmanager.com' ),
			),
			'exactmetrics' => array(
				'name'                  => 'ExactMetrics',
				'files'                 => array( 'google-analytics-dashboard-for-wp/gadwp.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps ExactMetrics and its Google Analytics tags out of JavaScript optimization and delay.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/google-analytics-dashboard-for-wp/', 'google-analytics.com', 'googletagmanager.com' ),
			),
			'pixelyoursite' => array(
				'name'                  => 'PixelYourSite',
				'files'                 => array( 'pixelyoursite/pixelyoursite.php', 'pixelyoursite-pro/pixelyoursite-pro.php' ),
				'group'                 => 'analytics',
				'protection'            => __( 'Keeps analytics and advertising pixels out of JavaScript optimization and delay.', 'gt-performance' ),
				'javascript_exclusions' => array( '/plugins/pixelyoursite/', '/plugins/pixelyoursite-pro/', 'connect.facebook.net', 'googletagmanager.com' ),
			),
		);
	}

	/**
	 * Return protected script URL fragments for the supplied plugin basenames.
	 *
	 * @param list<string> $active  Active site plugins.
	 * @param list<string> $network Network-active plugins.
	 * @return list<string>
	 */
	public function javascriptExclusionsForPlugins( array $active, array $network = array() ): array {
		$exclusions = array();

		foreach ( $this->catalog() as $plugin ) {
			if ( ! array_intersect( $plugin['files'], array_merge( $active, $network ) ) ) {
				continue;
			}

			$exclusions = array_merge( $exclusions, $plugin['javascript_exclusions'] ?? array() );
		}

		return array_values( array_unique( array_map( 'strval', $exclusions ) ) );
	}

	/**
	 * Return protected script URL fragments for analytics plugins active now.
	 *
	 * @return list<string>
	 */
	public function activeJavascriptExclusions(): array {
		$active  = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		$network = is_multisite() ? array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) : array();

		return $this->javascriptExclusionsForPlugins( $active, $network );
	}

	public function active( string $id ): bool {
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		return $this->detected( $id, array_map( 'strval', $active ), array_map( 'strval', $network ) );
	}

	/**
	 * @param list<string> $active Active site plugins.
	 * @param list<string> $network Active network plugins.
	 */
	public function detected( string $id, array $active, array $network = array() ): bool {
		$catalog = $this->catalog();
		if ( ! isset( $catalog[ $id ] ) ) {
			return false;
		}

		$installed = array_merge( $active, $network );

		return (bool) array_intersect( $catalog[ $id ]['files'], $installed );
	}
}
