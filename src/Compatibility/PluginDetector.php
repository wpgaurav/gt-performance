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
	 * @return array<string, array{name:string,files:list<string>,group:string,protection:string}>
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
		);
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
