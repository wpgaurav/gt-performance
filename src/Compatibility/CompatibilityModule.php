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
		add_filter( 'gt_performance_generate_image_variants', array( $this, 'allowGtImageVariants' ) );
		add_filter( 'gt_performance_rewrite_image_variants', array( $this, 'allowGtImageVariants' ) );
		add_filter( 'gt_performance_media_lazy_load', array( $this, 'allowGtMediaLazyLoad' ) );
		add_filter( 'gt_performance_media_add_dimensions', array( $this, 'allowGtMediaDimensions' ) );

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
	 * Let EWWW own local next-generation files and delivery when enabled.
	 *
	 * EWWW's ordinary upload compression remains complementary and does not turn
	 * off GT Performance variants by itself.
	 */
	public function allowGtImageVariants( bool $enabled ): bool {
		if ( ! $enabled || ! $this->ewwwProtectionEnabled() ) {
			return $enabled;
		}

		return $this->ewwwModernFormatsEnabled() ? false : $enabled;
	}

	/**
	 * Avoid adding a second lazy-loading implementation to the same images.
	 */
	public function allowGtMediaLazyLoad( bool $enabled ): bool {
		if ( ! $enabled || ! $this->ewwwProtectionEnabled() ) {
			return $enabled;
		}

		return $this->ewwwLazyLoadEnabled() ? false : $enabled;
	}

	/**
	 * EWWW adds dimensions through its lazy-load pipeline. Only yield this
	 * feature when that paired option, or Easy IO, actually owns it.
	 */
	public function allowGtMediaDimensions( bool $enabled ): bool {
		if ( ! $enabled || ! $this->ewwwProtectionEnabled() ) {
			return $enabled;
		}

		$ewwwOwnsDimensions = $this->ewwwExactDnEnabled()
			|| ( $this->ewwwLazyLoadEnabled() && (bool) $this->ewwwOption( 'ewww_image_optimizer_add_missing_dims' ) );

		return $ewwwOwnsDimensions ? false : $enabled;
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

	private function ewwwProtectionEnabled(): bool {
		return $this->protectionEnabled() && $this->plugins->active( 'ewww-image-optimizer' );
	}

	private function ewwwModernFormatsEnabled(): bool {
		return (bool) $this->ewwwOption( 'ewww_image_optimizer_webp' ) || $this->ewwwExactDnEnabled();
	}

	private function ewwwLazyLoadEnabled(): bool {
		return (bool) $this->ewwwOption( 'ewww_image_optimizer_lazy_load' )
			|| (bool) get_option( 'easyio_lazy_load', false )
			|| $this->ewwwExactDnEnabled();
	}

	private function ewwwExactDnEnabled(): bool {
		return (bool) $this->ewwwOption( 'ewww_image_optimizer_exactdn' )
			|| (bool) get_option( 'easyio_exactdn', false );
	}

	private function ewwwOption( string $name ): mixed {
		if ( function_exists( 'ewww_image_optimizer_get_option' ) ) {
			return ewww_image_optimizer_get_option( $name );
		}

		return get_option( $name, false );
	}

	private function perfmattersMode(): string {
		return (string) Settings::get( 'integrations.perfmatters_owner', 'automatic' );
	}
}
