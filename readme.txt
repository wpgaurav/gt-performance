=== GT Performance ===
Contributors: gauravtiwari
Tags: cache, performance, cloudflare, woocommerce, database
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0-alpha.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safe page caching, server-side CSS optimization, Cloudflare Free orchestration, and commerce-aware performance controls.

== Description ==

GT Performance combines an atomic origin page cache with server-side CSS and frontend optimization. It can synchronize a narrowly scoped Cloudflare Cache Rule and purge exact URLs on Cloudflare Free.

FluentCart, Easy Digital Downloads, and WooCommerce adapters protect cart, checkout, account, receipt, session-cookie, and transactional query state from public caching.

Unused CSS can be delivered as an immutable file, fully inline, or as critical CSS inline with the remaining CSS in a file.

Perfmatters ownership coordination, Akismet and Jetpack safeguards, Redis credentials, and administrator-bar cache actions are built in.

This is an alpha. Origin caching uses the maximum-impact lifetime profile by default but does not become active until its owned drop-in is installed. Riskier frontend transformations remain opt-in and should be tested on staging before production use.

== Installation ==

1. Upload and activate GT Performance.
2. Open GT Performance in the main WordPress admin menu.
3. Install the page-cache drop-in.
4. Enable only the modules you have tested for your theme and plugins.
5. Optionally connect a scoped Cloudflare API token or a legacy Global API Key with account email, then synchronize the managed cache rule.
6. Activate your FluentCart license on the License tab to receive protected updates in WordPress.

== Frequently Asked Questions ==

= Does Cloudflare require a paid plan? =

No. The baseline uses Cache Rules and targeted purge available on Cloudflare Free. No Worker or APO subscription is required.

= Is unused CSS processed by an external service? =

No. Stylesheet collection, selector analysis, pruning, and artifact creation run on the WordPress server.

= Can used CSS be inlined? =

Yes. Choose Generated file, Inline all used CSS, or Critical inline + remaining file. Hybrid mode falls back to a generated file if the critical segment exceeds its inline budget.

= Are checkout pages cached? =

GT Performance compiles dynamic paths, session cookies, and query parameters from active FluentCart, EDD, and WooCommerce adapters into both origin and Cloudflare bypass policies.

= Can Redis credentials be configured in wp-config.php? =

Yes. GT Performance reads the `WP_REDIS_HOST`, port, socket path, scheme, database, ACL password array, prefix, timeout, read-timeout, and disable constants used by Till Krüss Redis Object Cache. Existing `GTP_REDIS_*` constants remain supported and take highest precedence. The Integrations screen provides a copy-ready example.

= How are plugin updates delivered? =

Activate a FluentCart license on the License tab. GT Performance checks version metadata through the normal WordPress update flow and receives the protected package only when the site activation is valid.

== Changelog ==

= 0.1.0-alpha.10 =

* Added port-safe license identities for Studio and other local WordPress sites so protected FluentCart updates work on localhost URLs with ports.

= 0.1.0-alpha.9 =

* Moved manual database scanning and selectable cleanup to Tools while keeping scheduled maintenance in Optimization.
* Made manual cleanup return to Tools and removed the redundant cleanup shortcut.
* Added compatible Till Krüss Redis Object Cache `WP_REDIS_*` constants, including ACL arrays, TLS, Unix sockets, and emergency disable.
* Published the $199 product page with verified direct-checkout links and responsive purchase details.

= 0.1.0-alpha.8 =

* Added encrypted FluentCart license activation, verification, deactivation, and protected WordPress updates.
* Added a dedicated License tab with masked credentials, plan and expiration details, on-demand checks, and friendly notices.
* Fixed activation-time registration of the queue and weekly license-verification schedules.
* Added a FluentCart product identity and update contract for GT Performance.
* Added a Magnific-led marketing asset system with real WordPress Studio screenshots for directory and storefront listings.

= 0.1.0-alpha.7 =

* Added partial and regular-expression unused-CSS selector safelists.
* Added Perfmatters ownership coordination and common plugin compatibility reporting.
* Added Akismet and Jetpack CSS, JavaScript, and visitor-state cache safeguards.
* Added encrypted Redis credentials, TLS, ACL, database, prefix, timeouts, testing, and guarded installation.
* Added documented wp-config.php constants for every Redis connection setting.
* Added admin-bar cache, CSS, object-cache, Redis, report, and settings actions.
* Added a GitHub Actions release pipeline with version validation, checksums, provenance, and automatic prereleases.

= 0.1.0-alpha.6 =

* Replaced the CSS delivery dropdown with explicit Generated file, Inline all used CSS, and Critical inline + remaining file choices.
* Made the dynamic-state preservation setting control hover, focus, open, checked, and related selector retention.
* Changed Hybrid mode to fall back to a generated file when critical CSS exceeds the inline budget.

= 0.1.0-alpha.5 =

* Added one-click cache lifetime presets and a default one-hour fresh, 24-hour retained, five-minute browser profile.
* Added a one-click WordPress optimization baseline derived from the active gauravtiwari.org configuration.
* Added the complete manual database cleanup set: revisions, drafts, spam, trash, transients, and table optimization.
* Added saved daily, weekly, and monthly database schedules with selectable tasks.
* Added Perfmatters-style WordPress request, metadata, editor, comments, REST, Heartbeat, revision, and autosave controls.
* Removed Core Web Vitals collection, its frontend measurement script, REST endpoint, settings, and storage table.

= 0.1.0-alpha.4 =

* Added a standalone top-level settings app with focused Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, CSS Reports, and Tools tabs.
* Exposed the full cache, optimization, Cloudflare, commerce, cleanup, and exception configuration.
* Added live server-side unused CSS generation reports with processing, ready, stale, skipped, and failed states.
* Added generated output size, savings, delivery mode, error, and refresh indicators for unused CSS.
* Added friendly action notices so internal error codes are never shown to administrators.
* Refined desktop and mobile spacing with thin card boundaries.

= 0.1.0-alpha.3 =

* Added Core Forms poll-cookie compatibility so non-poll pages can be cached safely.
* Real poll pages retain voter cookies and remain uncached.

= 0.1.0-alpha.2 =

* Added legacy Cloudflare Global API Key, account email, domain, and automatic zone discovery support.
* Kept scoped API tokens as the recommended authentication mode.
* Fixed WP_CACHE detection so comments no longer trigger a false custom-declaration error.
* Restores the exact single-line WP_CACHE declaration changed during installation.

= 0.1.0-alpha.1 =

* Initial integrated alpha.
* Added atomic origin caching and durable preload/purge queue.
* Added Cloudflare Free rule management and cache purge.
* Added server-side unused CSS file, inline, and hybrid modes.
* Added FluentCart, EDD, and WooCommerce cache-safety adapters.
* Added JavaScript, media, font, database, bloat, Redis, admin, CLI, and diagnostics modules.
