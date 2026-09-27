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
	 * Classes that carousel, lightbox, and animation libraries bundled by page
	 * builders add after the page loads. Added once when any builder is active.
	 *
	 * @var list<string>
	 */
	public const RUNTIME_LIBRARY_SAFELIST = array( 'swiper-', 'splide', 'slick-', 'tns-', 'flickity-', 'glide__', 'mfp-', 'pswp', 'glightbox', 'gslide', 'fancybox', 'lg-', 'aos-', 'animate__', 'tippy-', 'select2-', 'aria-expanded', 'aria-selected', 'aria-hidden', 'aria-current' );

	/**
	 * @return array<string, array{name:string,files:list<string>,group:string,protection:string,themes?:list<string>,javascript_exclusions?:list<string>,css_safelist?:list<string>,css_stylesheet_exclusions?:list<string>}>
	 */
	public function catalog(): array {
		return self::builders() + array(
			'perfmatters'     => array(
				'name'       => 'Perfmatters',
				'files'      => array( 'perfmatters/perfmatters.php' ),
				'group'      => 'optimization',
				'protection' => __( 'Coordinates unused CSS, JavaScript, and front-end optimization ownership.', 'gt-performance' ),
			),
			'ewww-image-optimizer' => array(
				'name'       => 'EWWW Image Optimizer',
				'files'      => array( 'ewww-image-optimizer/ewww-image-optimizer.php' ),
				'group'      => 'optimization',
				'protection' => __( 'Coordinates modern image formats, lazy loading, and missing image dimensions while preserving EWWW upload compression.', 'gt-performance' ),
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
		) + self::visitorVariation();
	}

	/**
	 * Plugins that can show two visitors different pages at the same URL.
	 *
	 * The page cache stores one copy per URL and query, so a language picked from a
	 * cookie or the browser, or a currency remembered per visitor, can be served to
	 * someone who chose another. Nothing here is changed automatically: which
	 * cookie or parameter carries the choice depends on how each site is set up.
	 *
	 * @return array<string, array{name:string,files:list<string>,group:string,protection:string}>
	 */
	public static function visitorVariation(): array {
		$language = __( 'Each language is cached separately when it has its own URL (a directory, subdomain, or domain). If the language comes from a ?lang= parameter, add lang under Cache each value separately. If it is detected from a cookie or the browser, turn that redirect off or add its cookie under Never cache cookies, or visitors can get a page in another language.', 'gt-performance' );
		$currency = __( 'Remembers each visitor\'s currency, usually in a cookie, while the page cache keeps one copy per URL, so a cached page can show another visitor\'s prices. Add the switcher\'s currency cookie under Never cache cookies so visitors who switch get fresh pages, or check that it converts prices in the browser.', 'gt-performance' );

		$entries = array(
			'wpml'              => array( 'WPML', array( 'sitepress-multilingual-cms/sitepress.php' ), $language ),
			'polylang'          => array( 'Polylang', array( 'polylang/polylang.php', 'polylang-pro/polylang.php' ), $language ),
			'translatepress'    => array( 'TranslatePress', array( 'translatepress-multilingual/index.php' ), $language ),
			'weglot'            => array( 'Weglot', array( 'weglot/weglot.php' ), $language ),
			'wcml'              => array( 'WooCommerce Multilingual & Multicurrency', array( 'woocommerce-multilingual/wpml-woocommerce.php' ), $currency ),
			'curcy'             => array( 'CURCY Multi Currency for WooCommerce', array( 'woo-multi-currency/woo-multi-currency.php', 'woocommerce-multi-currency/woocommerce-multi-currency.php' ), $currency ),
			'woocs'             => array( 'FOX Currency Switcher (WOOCS)', array( 'woocommerce-currency-switcher/index.php' ), $currency ),
			'aelia-currency'    => array( 'Aelia Currency Switcher', array( 'woocommerce-aelia-currencyswitcher/woocommerce-aelia-currencyswitcher.php' ), $currency ),
			'price-by-country'  => array( 'Price Based on Country', array( 'woocommerce-product-price-based-on-countries/woocommerce-product-price-based-on-countries.php' ), $currency ),
		);

		$catalog = array();
		foreach ( $entries as $id => $entry ) {
			$catalog[ $id ] = array(
				'name'       => $entry[0],
				'files'      => $entry[1],
				'group'      => 'variation',
				'protection' => $entry[2],
			);
		}

		return $catalog;
	}

	/**
	 * Names of active plugins that can vary a page by visitor at the same URL.
	 *
	 * @return list<string>
	 */
	public function activeVisitorVariation(): array {
		$names = array();
		foreach ( self::visitorVariation() as $id => $plugin ) {
			if ( $this->active( $id ) ) {
				$names[] = $plugin['name'];
			}
		}

		return $names;
	}

	/**
	 * Page builders whose front-end scripts add classes after the page loads.
	 *
	 * Unused CSS removes rules whose selectors match nothing in the rendered HTML,
	 * so open menus, active tabs, sticky headers, popups, and entrance animations
	 * lose their styles once a visitor interacts. Each entry keeps those state
	 * selectors. Entries marked "from source" were extracted from the builder's
	 * shipped front-end JavaScript (classList/addClass calls); the others, for
	 * builders not distributed publicly, use documented state-class prefixes.
	 * Over-keeping only leaves some CSS unpruned; it never breaks a page.
	 *
	 * @return array<string, array{name:string,files:list<string>,group:string,protection:string,themes?:list<string>,css_safelist:list<string>,css_stylesheet_exclusions?:list<string>}>
	 */
	public static function builders(): array {
		$protection = __( 'Keeps menu, tab, accordion, sticky, popup, slider, and animation state styles out of unused CSS removal.', 'gt-performance' );

		return array(
			// From source: GT Page Blocks renders each block's own HTML, CSS, and script,
			// whose states (for example `.visible` and `[data-theme]`) are arbitrary.
			'gt-page-blocks'  => array(
				'name'                      => 'GT Page Blocks Builder',
				'files'                     => array( 'page-blocks-builder/page-blocks-builder.php' ),
				'group'                     => 'builder',
				'protection'                => __( 'Leaves page block styles untouched: each block ships its own CSS and scripts.', 'gt-performance' ),
				'css_safelist'              => array(),
				'css_stylesheet_exclusions' => array( 'gt-page-block', '/plugins/page-blocks-builder/' ),
			),
			// From source (Elementor 4.3 free); Pro sticky, popup, and motion prefixes documented.
			'elementor'       => array(
				'name'         => 'Elementor',
				'files'        => array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'elementor-active', 'e-active', 'e-activated', 'elementor-invisible', 'animated', 'is-sticky', 'e-scroll-active', 'e-hidden', 'e-n-tab', 'e-con--floating', 'dialog-', 'elementor-sticky--', 'elementor-motion-effects', 'elementor-popup-modal', 'elementor-lightbox', 'elementor-menu-toggle', 'elementor-nav-menu--dropdown' ),
			),
			// From source (Bricks front-end script served by a production site).
			'bricks'          => array(
				'name'         => 'Bricks',
				'files'        => array(),
				'themes'       => array( 'bricks' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'brx-open', 'brx-animated', 'brx-animate-', 'brx-closing', 'brx-has-multilevel', 'brx-multilevel-', 'brx-submenu-', 'brx-sub-submenu-', 'brx-gallery-item-reveal', 'brx-load-more-hidden', 'brx-loading-animation', 'brx-popup', 'brx-offcanvas', 'show-mobile-menu', 'no-scroll', 'bricks-lightbox', '/\.(active|open|show|visible|hide|loaded|closing|scrolling|sliding|dragging|is-active|is-loading)(?![\w-])/' ),
			),
			// Documented Divi and Extra state classes.
			'divi'            => array(
				'name'         => 'Divi',
				'files'        => array( 'divi-builder/divi-builder.php' ),
				'themes'       => array( 'divi', 'extra' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'et_pb_animation', 'et-animated', 'et-waypoint', 'et-pb-active-slide', 'et_pb_tab_active', 'et_pb_toggle_open', 'et_pb_toggle_close', 'et_mobile_menu', 'mobile_nav', 'et-fixed-header', 'et_fixed_nav', 'et-search-form', 'et_pb_sticky', 'et-pb-controllers', 'et_pb_active_control', 'et-show-dropdown', 'et-hover', '/\.(opened|closed)(?![\w-])/' ),
			),
			// From source (Beaver Builder Lite 2.11); theme header states documented.
			'beaver-builder'  => array(
				'name'         => 'Beaver Builder',
				'files'        => array( 'bb-plugin/fl-builder.php', 'beaver-builder-lite-version/fl-builder.php' ),
				'themes'       => array( 'bb-theme' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'fl-active', 'fl-animation', 'fl-animated', 'fl-tab-active', 'fl-accordion-item-active', 'fl-menu-mobile', 'fl-theme-builder-header-sticky', 'fl-theme-builder-header-scrolled', 'fl-slideshow', 'bx-' ),
			),
			// Documented Oxygen state classes.
			'oxygen'          => array(
				'name'         => 'Oxygen',
				'files'        => array( 'oxygen/functions.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'oxy-nav-menu-open', 'oxy-nav-menu-prevent-overflow', 'oxy-modal', 'oxy-tab-active', 'oxy-sticky-header', 'oxy-lightbox', 'oxy-pro-accordion', '/\.oxy-[\w-]*(active|open|live|visible)/' ),
			),
			// Documented Breakdance BEM state modifiers.
			'breakdance'      => array(
				'name'         => 'Breakdance',
				'files'        => array( 'breakdance/plugin.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'breakdance-popup', 'is-sticky', 'is-open', 'is-active', '/\.(bde|breakdance)-[\w-]*(active|open|opened|visible|sticky|scrolled|current)/' ),
			),
			// Documented WPBakery state classes.
			'wpbakery'        => array(
				'name'         => 'WPBakery Page Builder',
				'files'        => array( 'js_composer/js_composer.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'vc_active', 'vc_tta-', 'wpb_animate_when_almost_visible', 'wpb_start_animation', 'animated', 'vc_toggle_active', 'flex-active', 'prettyphoto', 'pp_' ),
			),
			// Thrive's runtime classes are not published; leave its styles whole.
			'thrive-architect' => array(
				'name'                      => 'Thrive Architect',
				'files'                     => array( 'thrive-visual-editor/thrive-visual-editor.php' ),
				'group'                     => 'builder',
				'protection'                => __( 'Leaves Thrive Architect styles untouched because its interactive states are not documented.', 'gt-performance' ),
				'css_safelist'              => array(),
				'css_stylesheet_exclusions' => array( '/plugins/thrive-visual-editor/' ),
			),
			// Documented Brizy BEM state modifiers (the front-end script is not bundled).
			'brizy'           => array(
				'name'         => 'Brizy',
				'files'        => array( 'brizy/brizy.php', 'brizy-pro/brizy-pro.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'brz-animated', 'brz-popup', '/\.brz-[\w-]*--(active|opened|open|visible|shown)/' ),
			),
			// From source (Kadence Blocks 3.7), including Kadence header states.
			'kadence-blocks'  => array(
				'name'         => 'Kadence Blocks',
				'files'        => array( 'kadence-blocks/kadence-blocks.php', 'kadence-blocks-pro/kadence-blocks-pro.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'kt-active-tab', 'kt-panel-is-', 'kb-modal-open', 'kb-smc-open', 'kb-header-sticky', 'item-is-fixed', 'item-is-stuck', 'header-is-fixed', 'child-is-fixed', 'menu-item--toggled-on', 'show-off-canvas', 'toggle-show', 'kt-masonry-trigger-animation', 'typed-cursor', '/\.kb-[\w-]*(open|active|visible)/' ),
			),
			// From source (Spectra 2.20).
			'spectra'         => array(
				'name'         => 'Spectra',
				'files'        => array( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'uagb-tabs__active', 'uagb-tabs-body__active', 'uagb-faq-item-active', 'uagb-position__sticky--', 'uagb-timeline__', 'uagb-toc__', 'uagb-activated-script', 'uagb-forms-success-message', 'uagb-forms-failed-message', 'spectra-image-gallery__control-dot--active', 'show_popup', 'in-view', 'out-view', 'list-open', 'list-collapsed', 'scroll-button-is-visible' ),
			),
			// From source (GenerateBlocks 2.4); Pro accordion, tab, and overlay states follow its gb- naming.
			'generateblocks'  => array(
				'name'         => 'GenerateBlocks',
				'files'        => array( 'generateblocks/plugin.php', 'generateblocks-pro/plugin.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'gblocks-action-message--show', 'gb-responsive-tabs', '/\.gb-[\w-]*(open|active|current|show|visible|toggled)/' ),
			),
			// From source (SiteOrigin Page Builder 2.36): only parallax is added at runtime.
			'siteorigin'      => array(
				'name'         => 'SiteOrigin Page Builder',
				'files'        => array( 'siteorigin-panels/siteorigin-panels.php' ),
				'group'        => 'builder',
				'protection'   => $protection,
				'css_safelist' => array( 'simpleParallax' ),
			),
		);
	}

	/**
	 * Selector safelist entries for the active builders, plus runtime library
	 * classes once when any builder is active.
	 *
	 * @param list<string> $active  Active plugin basenames.
	 * @param list<string> $network Network-active plugin basenames.
	 * @return list<string>
	 */
	public function builderCssSafelist( array $active, array $network, string $template ): array {
		$safelist = array();
		$any      = false;
		foreach ( self::builders() as $builder ) {
			if ( ! $this->builderActive( $builder, array_merge( $active, $network ), $template ) ) {
				continue;
			}
			$any      = true;
			$safelist = array_merge( $safelist, $builder['css_safelist'] );
		}
		if ( $any ) {
			$safelist = array_merge( $safelist, self::RUNTIME_LIBRARY_SAFELIST );
		}

		return array_values( array_unique( $safelist ) );
	}

	/**
	 * Stylesheet URL or inline style ID fragments to leave unpruned for active builders.
	 *
	 * @param list<string> $active  Active plugin basenames.
	 * @param list<string> $network Network-active plugin basenames.
	 * @return list<string>
	 */
	public function builderStylesheetExclusions( array $active, array $network, string $template ): array {
		$exclusions = array();
		foreach ( self::builders() as $builder ) {
			if ( $this->builderActive( $builder, array_merge( $active, $network ), $template ) ) {
				$exclusions = array_merge( $exclusions, $builder['css_stylesheet_exclusions'] ?? array() );
			}
		}

		return array_values( array_unique( $exclusions ) );
	}

	/**
	 * @return list<string>
	 */
	public function activeBuilderCssSafelist(): array {
		return $this->builderCssSafelist( ...$this->installation() );
	}

	/**
	 * @return list<string>
	 */
	public function activeBuilderStylesheetExclusions(): array {
		return $this->builderStylesheetExclusions( ...$this->installation() );
	}

	/**
	 * @param array{files:list<string>,themes?:list<string>} $builder Catalog entry.
	 * @param list<string>                                    $plugins Active plugins.
	 */
	private function builderActive( array $builder, array $plugins, string $template ): bool {
		return (bool) array_intersect( $builder['files'], $plugins )
			|| ( '' !== $template && in_array( strtolower( $template ), $builder['themes'] ?? array(), true ) );
	}

	/**
	 * @return array{0:list<string>,1:list<string>,2:string}
	 */
	private function installation(): array {
		$active  = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		$network = is_multisite() ? array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) : array();

		return array( $active, $network, function_exists( 'get_template' ) ? (string) get_template() : '' );
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
		if ( isset( $catalog[ $id ]['themes'] ) ) {
			return $this->builderActive( $catalog[ $id ], $installed, function_exists( 'get_template' ) ? (string) get_template() : '' );
		}

		return (bool) array_intersect( $catalog[ $id ]['files'], $installed );
	}
}
