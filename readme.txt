=== GT Performance ===
Contributors: gauravtiwari
Tags: cache, performance, cloudflare, woocommerce, database
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safe page caching, server-side CSS optimization, Cloudflare and custom CDN delivery, and commerce-aware performance controls.

== Description ==

GT Performance combines an atomic origin page cache with server-side CSS and frontend optimization. It can synchronize a narrowly scoped Cloudflare Cache Rule and purge exact URLs on Cloudflare Free. It also detects xCloud's separate Cloudflare Enterprise add-on, reports its edge traffic, and prevents duplicate edge ownership.

An optional origin-pull CDN can rewrite selected same-site static-file URLs to a separate HTTPS hostname while Cloudflare continues to cache eligible HTML independently.

FluentCart, Easy Digital Downloads, and WooCommerce adapters protect cart, checkout, account, receipt, session-cookie, and transactional query state from public caching.

Unused CSS can be delivered as an immutable file, fully inline, or as critical CSS inline with the remaining CSS in a file.

Perfmatters ownership coordination, Akismet and Jetpack safeguards, automatic analytics-plugin script protection, Redis credentials, and administrator-bar cache actions are built in.

Explain This Page, verified purge receipts, a Cloudflare Free rule compiler, Commerce Safety Lab, CSS Training Mode with staged rollout, signed Private Islands, and a Fleet policy console add deterministic diagnostics and safer deployment controls.

Origin caching uses the maximum-impact lifetime profile by default but does not become active until its owned drop-in is installed. Riskier frontend transformations remain opt-in and should be tested on staging before production use.

Development happens in the open on [GitHub](https://github.com/wpgaurav/gt-performance), where bug reports and pull requests are welcome.

== Installation ==

1. Upload and activate GT Performance.
2. Open GT Performance in the main WordPress admin menu.
3. Install the page-cache drop-in.
4. Enable only the modules you have tested for your theme and plugins.
5. Optionally connect a scoped Cloudflare API token or a legacy Global API Key with account email, then synchronize the managed cache rule.
6. Optionally configure an origin-pull asset CDN and choose the exact file extensions it should serve.

== Frequently Asked Questions ==

= Does Cloudflare require a paid plan? =

No. The baseline uses Cache Rules and targeted purge available on Cloudflare Free. No Worker or APO subscription is required.

= Is unused CSS processed by an external service? =

No. Stylesheet collection, selector analysis, pruning, and artifact creation run on the WordPress server.

= Can I use another CDN alongside Cloudflare? =

Yes. Configure its HTTPS origin-pull URL on the CDN tab and select the static-file extensions it should serve. GT Performance rewrites only same-site assets with those extensions; third-party URLs, HTML routes, API responses, and unselected file types remain unchanged.

= Can used CSS be inlined? =

Yes. Choose Generated file, Inline all used CSS, or Critical inline + remaining file. Hybrid mode falls back to a generated file if the critical segment exceeds its inline budget.

= What does CSS Training Mode collect? =

Only bounded element IDs and classes observed while an administrator browses the site. It does not collect text, field values, cookies, or customer data. Candidates must be reviewed and published before they affect generated CSS.

= Are checkout pages cached? =

GT Performance compiles dynamic paths, session cookies, and query parameters from active FluentCart, EDD, and WooCommerce adapters into both origin and Cloudflare bypass policies.

= Does Fleet Console provide remote access? =

No. It accepts only short-lived, one-use GT Performance setting bundles signed with a secret you save on each of your sites. Secrets are stripped, settings are sanitized again on import, and no file upload, plugin installation, PHP evaluation, or remote command path exists.

= Can Redis credentials be configured in wp-config.php? =

Yes. GT Performance reads the `WP_REDIS_HOST`, port, socket path, scheme, database, ACL password array, prefix, timeout, read-timeout, and disable constants used by Till Krüss Redis Object Cache. Existing `GTPERF_REDIS_*` constants remain supported and take highest precedence. The Integrations screen provides a copy-ready example.

== External Services ==

GT Performance works entirely on your server by default and sends no data anywhere. Each integration below contacts a third-party service only after you enable it and, where credentials are involved, only with credentials you supply. There is no telemetry, no account requirement, and the plugin never contacts servers of its own.

= Cloudflare API (api.cloudflare.com) =

Contacted only when you connect your own Cloudflare account to manage its cache rule and purge its cache. Requests carry the API token or Global API Key and account email you saved, your zone identifier or domain, the compiled cache-rule expression, and the exact URLs being purged. They are sent when you connect, synchronize, run diagnostics, or purge, and automatically when a content change requires an edge purge. Provider: Cloudflare, Inc. — [Terms of Service](https://www.cloudflare.com/terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

= xCloud hosting API (app.xcloud.host) =

Contacted only when you connect a site hosted on xCloud using your own xCloud API token. Requests carry that token and your site's domain or xCloud identifier, and are sent when you connect or refresh the integration and when host-level caches are purged. Provider: xCloud by WPDeveloper — [Privacy Policy](https://xcloud.host/privacy-policy/).

= Google Fonts (fonts.googleapis.com, fonts.gstatic.com) =

Contacted only when you enable local Google Fonts hosting on a site whose theme or plugins already load Google Fonts. Your server downloads the stylesheet and font files once and serves them from your own domain afterward. The download is a server-side request that carries no visitor data, and the feature removes visitors' browser requests to Google entirely. Provider: Google LLC — [Privacy Policy](https://policies.google.com/privacy), [Google Fonts privacy notes](https://developers.google.com/fonts/faq/privacy).

= YouTube (i.ytimg.com, www.youtube-nocookie.com) =

Involved only on pages where you have already embedded a YouTube video and the lightweight embed option is enabled. The visitor's browser loads the video thumbnail from i.ytimg.com, and the player loads from the privacy-enhanced youtube-nocookie.com domain only after the visitor clicks play. Your server sends nothing to YouTube; without this option the standard YouTube embed would contact YouTube earlier and more broadly. Provider: Google LLC — [Terms of Service](https://www.youtube.com/t/terms), [Privacy Policy](https://policies.google.com/privacy).

GT Performance also sends requests to your own site's URLs for cache warming, purge verification, and Safety Lab checks. Those requests never leave your domain.

= Hostnames that are matched, not contacted =

GT Performance stores a list of script hostname patterns such as `connect.facebook.net`, `googletagmanager.com`, `google-analytics.com`, `clarity.ms`, and `hotjar.com`. These are exclusion rules, not connections. They are compared against the script URLs your own site already loads so that those scripts are never minified, deferred, or delayed. GT Performance never contacts these hosts, sends them no data, and adds no script to your site that would.

== Changelog ==

= 1.0.1 =
* Configuration and page-cache metadata are now inert JSON data files. Nothing generates or executes PHP at runtime.
* advanced-cache.php now ships as a bundled file that is copied into place instead of being generated, and resolves its own paths, so a renamed or relocated plugin directory keeps working.
* The early cache drop-in and WordPress now sanitize every request value through one shared implementation, so cache keys and bypass decisions can no longer diverge between them.
* Fixed keyboard focus styles being pruned from generated CSS. `:focus-visible` and `:focus-within` rules were dropped as unused.
* Every output buffer this plugin opens is now closed explicitly on shutdown.
* Renamed the `GTP_` and `gtp_` prefixes to `GTPERF_` and `gtperf_`. Constants set in `wp-config.php`, the Private Islands shortcode, and stored transients all use the new prefix and the old names are no longer read.
* Updated the bundled CSS parser to 9.4.0. The new version pulls in a required library that makes the plugin about 2.4 MB larger; pages served from the cache are unaffected.

= 1.0.0 =
* First stable release, and the first release distributed free through the WordPress.org plugin directory.
* Atomic origin page caching with an owned advanced-cache.php drop-in, background stale rebuilds, sitemap-driven warming, and verified purge receipts.
* Server-side unused-CSS optimization with file, inline, and hybrid delivery, CSS Training Mode, staged rollout, and per-URL regeneration.
* Cloudflare Free cache-rule compiler, exact-URL purging, connection diagnostics, and scoped-token provisioning.
* Optional origin-pull static-asset CDN rewriting with exact extension controls.
* FluentCart, Easy Digital Downloads, and WooCommerce cache-safety adapters plus Commerce Safety Lab checks.
* JavaScript, media, font, embed, database, bloat, and Redis object-cache modules with encrypted credentials.
* xCloud host integration with explicit edge ownership, Perfmatters ownership coordination, and automatic analytics-plugin protection.
* Fleet Console for moving signed, credential-free setting bundles between your sites using a shared signing secret.
* Explain This Page diagnostics, admin-bar actions, and WP-CLI commands.
