=== GT Performance ===
Contributors: gauravtiwari
Tags: cache, performance, cloudflare, woocommerce, core web vitals
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0-alpha.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safe page caching, server-side CSS optimization, Cloudflare Free orchestration, and commerce-aware performance controls.

== Description ==

GT Performance combines an atomic origin page cache with server-side CSS and frontend optimization. It can synchronize a narrowly scoped Cloudflare Cache Rule and purge exact URLs on Cloudflare Free.

FluentCart, Easy Digital Downloads, and WooCommerce adapters protect cart, checkout, account, receipt, session-cookie, and transactional query state from public caching.

Unused CSS can be delivered as an immutable file, fully inline, or as critical CSS inline with the remaining CSS in a file.

This is an alpha. Aggressive modules are disabled by default and should be tested on staging before production use.

== Installation ==

1. Upload and activate GT Performance.
2. Open Settings > GT Performance.
3. Install the page-cache drop-in.
4. Enable only the modules you have tested for your theme and plugins.
5. Optionally connect a scoped Cloudflare API token and synchronize the managed cache rule.

== Frequently Asked Questions ==

= Does Cloudflare require a paid plan? =

No. The baseline uses Cache Rules and targeted purge available on Cloudflare Free. No Worker or APO subscription is required.

= Is unused CSS processed by an external service? =

No. Stylesheet collection, selector analysis, pruning, and artifact creation run on the WordPress server.

= Are checkout pages cached? =

GT Performance compiles dynamic paths, session cookies, and query parameters from active FluentCart, EDD, and WooCommerce adapters into both origin and Cloudflare bypass policies.

== Changelog ==

= 0.1.0-alpha.1 =

* Initial integrated alpha.
* Added atomic origin caching and durable preload/purge queue.
* Added Cloudflare Free rule management and cache purge.
* Added server-side unused CSS file, inline, and hybrid modes.
* Added FluentCart, EDD, and WooCommerce cache-safety adapters.
* Added JavaScript, media, font, database, bloat, Redis, RUM, admin, CLI, and diagnostics modules.
