<?php
/**
 * Cross-plugin optimization and visitor-state compatibility.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Compatibility;

use GTPerformance\Commerce\CommerceAdapter;
use GTPerformance\Commerce\Registry;
use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class CompatibilityModule implements Module {
	public function __construct(
		private readonly PluginDetector $plugins = new PluginDetector(),
		private readonly FeatureOwnership $ownership = new FeatureOwnership(),
		private readonly Registry $commerce = new Registry(),
	) {
	}

	public function register(): void {
		add_filter( 'gt_performance_optimize_stage', array( $this, 'allowGtOptimization' ), 10, 2 );
		add_filter( 'gt_performance_css_safelist', array( $this, 'cssSafelist' ) );
		add_filter( 'gt_performance_css_stylesheet_exclusions', array( $this, 'stylesheetExclusions' ) );
		add_filter( 'gt_performance_javascript_exclusions', array( $this, 'javascriptExclusions' ) );
		add_filter( 'gt_performance_cache_policy', array( $this, 'cachePolicy' ), 20 );
		add_filter( 'gt_performance_compiled_config', array( $this, 'compiledConfig' ), 20 );

		add_filter( 'perfmatters_remove_unused_css', array( $this, 'perfmattersUnusedCss' ), PHP_INT_MAX );
		add_filter( 'perfmatters_minify_css', array( $this, 'perfmattersUnusedCss' ), PHP_INT_MAX );
		add_filter( 'perfmatters_defer_js', array( $this, 'perfmattersDeferJavaScript' ), PHP_INT_MAX );
		add_filter( 'perfmatters_delay_js', array( $this, 'perfmattersDelayJavaScript' ), PHP_INT_MAX );
		add_filter( 'perfmatters_minify_js', array( $this, 'perfmattersMinifyJavaScript' ), PHP_INT_MAX );

		add_action( 'activated_plugin', array( $this, 'refreshCompiledConfig' ) );
		add_action( 'deactivated_plugin', array( $this, 'refreshCompiledConfig' ) );
	}

	public function allowGtOptimization( bool $allowed, string $stage ): bool {
		unset( $stage );
		if ( ! $allowed || ! $this->protectionEnabled() || ! $this->plugins->active( 'perfmatters' ) ) {
			return $allowed;
		}

		return $this->ownership->gtOwns( $this->perfmattersMode(), true );
	}

	/**
	 * @param list<string> $safelist Selector patterns.
	 * @return list<string>
	 */
	public function cssSafelist( array $safelist ): array {
		if ( ! $this->protectionEnabled() ) {
			return $safelist;
		}

		if ( (bool) Settings::get( 'integrations.akismet', true ) && $this->plugins->active( 'akismet' ) ) {
			$safelist[] = '.akismet_comment_form_privacy_notice';
		}
		if ( (bool) Settings::get( 'integrations.jetpack', true ) && $this->plugins->active( 'jetpack' ) ) {
			$safelist = array_merge(
				$safelist,
				array(
					'.wp-block-jetpack',
					'.jetpack',
					'.grunion',
					'.contact-form',
				)
			);
		}

		return array_values( array_unique( $safelist ) );
	}

	/**
	 * Keep client-rendered commerce application styles outside server-side
	 * pruning. Their modal, cart, validation, and checkout states do not all
	 * exist in the initial HTML and cannot be exercised safely during training.
	 *
	 * @param list<string> $exclusions Stylesheet URL fragments.
	 * @return list<string>
	 */
	public function stylesheetExclusions( array $exclusions ): array {
		if ( ! $this->protectionEnabled() ) {
			return $exclusions;
		}

		$active = array_map(
			static fn( CommerceAdapter $adapter ): string => $adapter->id(),
			$this->commerce->active()
		);

		return $this->stylesheetExclusionsForCommerce( $exclusions, $active );
	}

	/**
	 * @param list<string> $exclusions Stylesheet URL fragments.
	 * @param list<string> $commerceIds Active commerce adapter IDs.
	 * @return list<string>
	 */
	public function stylesheetExclusionsForCommerce( array $exclusions, array $commerceIds ): array {
		$catalog = array(
			'fluentcart'  => array( '/plugins/fluent-cart/', '/plugins/fluent-cart-pro/' ),
			'edd'         => array( '/plugins/easy-digital-downloads/' ),
			'woocommerce' => array( '/plugins/woocommerce/' ),
		);

		foreach ( array_values( array_unique( $commerceIds ) ) as $commerceId ) {
			$exclusions = array_merge( $exclusions, $catalog[ $commerceId ] ?? array() );
		}

		return array_values( array_unique( array_map( 'strval', $exclusions ) ) );
	}

	/**
	 * @param list<string> $exclusions Script URL fragments.
	 * @return list<string>
	 */
	public function javascriptExclusions( array $exclusions ): array {
		if ( ! $this->protectionEnabled() ) {
			return $exclusions;
		}

		$exclusions = array_merge( $exclusions, $this->plugins->activeJavascriptExclusions() );

		if ( (bool) Settings::get( 'integrations.akismet', true ) && $this->plugins->active( 'akismet' ) ) {
			$exclusions[] = '/plugins/akismet/';
		}
		if ( (bool) Settings::get( 'integrations.jetpack', true ) && $this->plugins->active( 'jetpack' ) ) {
			$exclusions = array_merge(
				$exclusions,
				array(
					'/plugins/jetpack/modules/contact-form/',
					'/plugins/jetpack/modules/subscriptions/',
					'jetpack-search',
					'videopress',
				)
			);
		}

		return array_values( array_unique( $exclusions ) );
	}

	/**
	 * @param array<string, mixed> $cache Cache policy.
	 * @return array<string, mixed>
	 */
	public function cachePolicy( array $cache ): array {
		if (
			! $this->protectionEnabled()
			|| ! (bool) Settings::get( 'integrations.jetpack', true )
			|| ! $this->plugins->active( 'jetpack' )
		) {
			return $cache;
		}

		$cache['bypass_cookies'] = array_values(
			array_unique(
				array_merge(
					(array) ( $cache['bypass_cookies'] ?? array() ),
					array(
						'eucookielaw',
						'jetpack_blog_subscribe_',
						'jetpack_comments_subscribe_',
						'jetpack_sso_',
						'jp-visit-counter',
						'jpp_math_pass',
						'personalized-ads-consent',
					)
				)
			)
		);

		return $cache;
	}

	/**
	 * @param array<string, mixed> $compiled Compiled configuration.
	 * @return array<string, mixed>
	 */
	public function compiledConfig( array $compiled ): array {
		$compiled['cache'] = $this->cachePolicy( (array) ( $compiled['cache'] ?? array() ) );

		return $compiled;
	}

	public function perfmattersUnusedCss( bool $enabled ): bool {
		return $this->perfmattersFeature( $enabled, (bool) Settings::get( 'css.enabled', false ) );
	}

	public function perfmattersDeferJavaScript( bool $enabled ): bool {
		return $this->perfmattersFeature( $enabled, (bool) Settings::get( 'javascript.defer', false ) );
	}

	public function perfmattersDelayJavaScript( bool $enabled ): bool {
		return $this->perfmattersFeature( $enabled, (bool) Settings::get( 'javascript.delay', false ) );
	}

	public function perfmattersMinifyJavaScript( bool $enabled ): bool {
		return $this->perfmattersFeature( $enabled, (bool) Settings::get( 'javascript.minify', false ) );
	}

	public function refreshCompiledConfig(): void {
		Settings::compile();
	}

	private function perfmattersFeature( bool $enabled, bool $gtFeatureEnabled ): bool {
		if ( ! $enabled || ! $this->protectionEnabled() || ! $this->plugins->active( 'perfmatters' ) ) {
			return $enabled;
		}

		return $this->ownership->disablePerfmatters( $this->perfmattersMode(), $gtFeatureEnabled )
			? false
			: $enabled;
	}

	private function protectionEnabled(): bool {
		return (bool) Settings::get( 'integrations.auto_protection', true );
	}

	private function perfmattersMode(): string {
		return (string) Settings::get( 'integrations.perfmatters_owner', 'automatic' );
	}
}
