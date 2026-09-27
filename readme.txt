=== GT Performance ===
Contributors: gauravtiwari
Tags: cache, performance, cloudflare, woocommerce, database
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safe page caching, server-side CSS optimization, Cloudflare and custom CDN delivery, and commerce-aware performance controls.

== Description ==

GT Performance combines an atomic origin page cache with server-side CSS and frontend optimization. It can synchronize a narrowly scoped Cloudflare Cache Rule and purge exact URLs on Cloudflare Free. It also detects xCloud's separate Cloudflare Enterprise add-on, reports its edge traffic, and prevents duplicate edge ownership.

An optional origin-pull CDN can rewrite selected same-site static-file URLs to a separate HTTPS hostname while Cloudflare continues to cache eligible HTML independently.

FluentCart, Easy Digital Downloads, and WooCommerce adapters protect cart, checkout, account, receipt, session-cookie, and transactional query state from public caching.

Unused CSS can be delivered as an immutable file, fully inline, or as critical CSS inline with the remaining CSS in a file.

Perfmatters ownership coordination, Akismet and Jetpack safeguards, automatic analytics-plugin script protection, Redis credentials, and administrator-bar cache actions are built in.

Explain This Page, verified purge receipts, a Cloudflare Free rule compiler, and staged unused-CSS rollout help administrators inspect cache behavior and control optimization changes.

Cache warming reads your sitemaps in resumable background batches, starts with the pages that changed recently, stops at your cache size budget, and reports for each page whether it was actually stored. Purges follow what each cached page was built from, so query loops, reusable blocks, and shop listings refresh when their content changes while unrelated pages stay cached.

A health report (also shown in Site Health), a background queue you can pause, retry, and cancel, and settings history with restore, export, and import cover day-to-day operations.

JavaScript defer follows WordPress's own script dependencies, so inline jQuery code keeps working. Hero image rules choose the image that loads first, and speculative loading stays away from cart, checkout, and account pages.

On WordPress 6.9 or later, administrators can optionally let an external AI assistant read cache and health information, and with separate permission purge or preload URLs and propose settings for approval. This works through the WordPress REST API or the official WordPress MCP Adapter plugin. On WordPress 7.0 or later, an optional adviser can explain diagnostics using the AI provider you configured in WordPress. Everything AI-related is off by default.

Origin caching uses the maximum-impact lifetime profile by default but does not become active until its owned drop-in is installed. Riskier frontend transformations remain opt-in and should be tested on staging before production use.

Development happens in the open on [GitHub](https://github.com/wpgaurav/gt-performance), where bug reports and pull requests are welcome.

= Links =

* [GT Performance Home](https://gauravtiwari.org/product/gt-performance/) - features, setup guides, and answers to common questions.
* [Changelog](https://products.gatilab.com/changelogs/gt-performance/) - every release with its fixes and upgrade notes.
* [Roadmap](https://products.gatilab.com/roadmaps/gt-performance/) - what is planned next.
* [GT Performance Community](https://gauravtiwari.org/portal/) - ask questions and get setup help from other users.
* [More WordPress Plugins](https://gauravtiwari.org/wordpress-plugins/) - other plugins by Gaurav Tiwari.

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

= Does unused CSS work with page builders? =

Yes. When Elementor, Bricks, Divi, Beaver Builder, Oxygen, Breakdance, WPBakery, Brizy, Kadence Blocks, Spectra, GenerateBlocks, or SiteOrigin is active, the classes it adds after the page loads (open menus, active tabs, sticky headers, popups, sliders, animations) are kept. GT Page Blocks Builder and Thrive Architect styles are left untouched. Add your own selectors under Exceptions if a custom script needs more.

= Is unused CSS processed by an external service? =

No. Stylesheet collection, selector analysis, pruning, and artifact creation run on the WordPress server.

= Can I use another CDN alongside Cloudflare? =

Yes. Configure its HTTPS origin-pull URL on the CDN tab and select the static-file extensions it should serve. GT Performance rewrites only same-site assets with those extensions; third-party URLs, HTML routes, API responses, and unselected file types remain unchanged.

= Can used CSS be inlined? =

Yes. Choose Generated file, Inline all used CSS, or Critical inline + remaining file. Hybrid mode falls back to a generated file if the critical segment exceeds its inline budget.

= Are checkout pages cached? =

GT Performance compiles dynamic paths, session cookies, and query parameters from active FluentCart, EDD, and WooCommerce adapters into both origin and Cloudflare bypass policies.

= Does GT Performance send my site data to an AI service? =

Only when you ask it to. The optional abilities answer requests from an assistant you connect yourself, using an Application Password you create; they are off by default, require an administrator account, and never include credentials. The optional adviser (off by default) sends a redacted diagnostic report to the AI provider configured in WordPress only after you review exactly what will be sent and press Send. Nothing is sent automatically, from visitors, or from scheduled tasks.

= Can I undo a settings change? =

Yes. Tools → Settings history keeps the last 20 saves for up to 90 days and restores earlier values without touching saved credentials. Settings can also be exported to and imported from a JSON file.

= Can Redis credentials be configured in wp-config.php? =

Yes. GT Performance reads the `WP_REDIS_HOST`, port, socket path, scheme, database, ACL password array, prefix, timeout, read-timeout, and disable constants used by Till Krüss Redis Object Cache. Existing `GTPERF_REDIS_*` constants remain supported and take highest precedence. The Object Cache screen provides a copy-ready example.

== Third-party libraries ==

GT Performance bundles three MIT-licensed PHP libraries in `vendor/`. All three are GPL-compatible and are used server-side only.

* [matthiasmullie/minify](https://github.com/matthiasmullie/minify) - JavaScript minification in memory. MIT.
* [sabberworm/php-css-parser](https://github.com/MyIntervals/PHP-CSS-Parser) - CSS parsing for the unused-CSS engine. MIT.
* [symfony/css-selector](https://github.com/symfony/css-selector) - CSS selector to XPath translation. MIT.

The full GPL-2.0 text this plugin is licensed under ships as `LICENSE` in the plugin directory.

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

GT Performance also sends requests to your own site's URLs for cache warming, CSS generation, and purge verification. Those requests never leave your domain.

= Hostnames that are matched, not contacted =

GT Performance stores a list of script hostname patterns such as `connect.facebook.net`, `googletagmanager.com`, `google-analytics.com`, `clarity.ms`, and `hotjar.com`. These are exclusion rules, not connections. They are compared against the script URLs your own site already loads so that those scripts are never minified, deferred, or delayed. GT Performance never contacts these hosts, sends them no data, and adds no script to your site that would.

== Upgrade Notice ==

= 1.1.0 =
Resumable warming, dependency-aware purging, settings history, a health report, queue controls, and safer, faster unused CSS. The first wp-admin visit (or WP-CLI command) after updating upgrades the plugin's tables in small batches.

= 1.0.12 =
Protects temporary configuration files during writes, prevents generated inline CSS from closing its style element, and corrects descriptions of removed features.

= 1.0.11 =
Addresses WordPress.org review feedback. Opt-in JavaScript minification is preserved without generated JavaScript files; defer and delay remain available. Generated CSS and frontend loaders now use WordPress asset APIs.

= 1.0.10 =
Adds unused CSS status, statistics, and regeneration controls, and repairs background generation. Existing CSS settings are preserved. Use Optimization to review results and regenerate CSS after updating.

= 1.0.8 =
Removes the automatic revision limit and multisite activation. Cloudflare edge caching now respects origin headers by default. Unused CSS remains opt-in. Review Optimization and Cloudflare settings after updating.

= 1.0.4 =
Upgrading from 1.0.0 or earlier requires replacing the cache drop-in first. Run the standalone repair script linked in the 1.0.4 changelog entry before updating.

== Changelog ==

The complete release history is on the [GT Performance changelog](https://products.gatilab.com/changelogs/gt-performance/), and planned work is on the [roadmap](https://products.gatilab.com/roadmaps/gt-performance/).

= 1.1.0 =
* Cache warming resumes across background batches instead of stopping after 20 child sitemaps, reads chosen sitemaps or the WordPress sitemap plus robots.txt, warms recent pages first, respects the cache size budget, and records whether each page was actually stored.
* Purges now also clear pages that showed an updated post: listings it joins or leaves (including its old category and later pages), query loops, widgets, pages with an edited reusable block or menu, and shop listings after price or stock changes. Renamed terms clear their old archive URL.
* New purge preview: `wp gt-performance cache preview --post=<id>`.
* Related-page purges no longer include URLs on another site.
* Settings history with restore, plus JSON export and import. Credentials are never stored or restored, and simultaneous saves can no longer overwrite each other.
* New health report in Tools, Site Health, and `wp gt-performance health`, with a redacted support export.
* Background queue: jobs are claimed exclusively, crashed jobs stop after three attempts, and jobs can be paused, retried, and cancelled from Tools and WP-CLI. Waiting work can no longer be starved indefinitely.
* WordPress Studio and Playground (SQLite) are supported.
* New: Fetch important CSS classes. After you change unused CSS settings, sample pages of each public post type are checked in your browser, and builds keep the styles that JavaScript-added parts (tables of contents, ads, sliders) need.
* Fixed: Bricks 2 pages lost their base styles when Bricks' own stylesheets were optimized, icon-font rules written as `:before` were never pruned, pages with a comment form (including Akismet's) were never served their unused CSS, generated CSS could override a theme's excluded stylesheets, and CSS builds failed on hosts whose optimizer caches the build request.
* Optional AI assistant access (WordPress 6.9+, off by default): read cache and health evidence, and with separate permission purge or preload up to 20 URLs, regenerate CSS, retry jobs, and propose settings for an administrator to approve. Works through the REST API or the WordPress MCP Adapter.
* Optional AI adviser (WordPress 7.0+, off by default) that explains diagnostics using the AI provider configured in WordPress, showing exactly what will be sent first.
* Manual database cleanup runs in the background with live progress and a stop button, so the admin screen no longer waits for it. Emptying the trash or deleting spam no longer purges pages.
* CSS Status explains each result in plain language and suggests a Hybrid inline limit from the sizes it measured.
* New AI & MCP, Object Cache, Database, and CSS Status tabs in settings. The Cache tab is renamed Page Cache. Admin tables and summary cards now sit cleanly inside their panels.
* JavaScript defer now respects WordPress script dependencies and inline code, fixing inline jQuery code breaking when defer was on. Unregistered scripts are no longer deferred. Per-page script and hero options in the editor.
* Unused CSS now keeps page builder state styles (menus, tabs, sticky headers, popups, sliders, animations) for Elementor, Bricks, Divi, Beaver Builder, Oxygen, Breakdance, WPBakery, Brizy, Kadence Blocks, Spectra, GenerateBlocks, and SiteOrigin, and leaves GT Page Blocks Builder and Thrive Architect styles untouched.
* Hero image rules with optional responsive preload, and speculative loading kept away from cart, checkout, and account pages.
* REST API post updates without a login cookie now purge the edge cache too.
* Fixed admin tooltips widening pages on phones and PHP warnings when the cache directory is not writable.

= 1.0.14 =
* Fixed individual Cloudflare purges by allowing internal PURGE requests in the managed cache rule while preserving checkout, session, and query exclusions.
* Wait for Cloudflare before verifying or reporting manual cache purges; show partial failures and retain the latest purge result even with debug logging disabled.
* Purge desktop, mobile, and tablet cache variants when separate device caching is enabled.
* Retry temporary Cloudflare transport, rate-limit, and server failures up to three times, preserving unprocessed batches and honoring Retry-After.
* A successful full purge supersedes queued URL purges and retries. Failed verification requests no longer appear successful, and receipt details display correctly.
* After upgrading, use Connect/sync Cloudflare once to update the existing managed rule. Unrelated Cloudflare rules are preserved.

= 1.0.13 =

* Protects configuration and drop-ins against incomplete writes and unsafe temporary files.
* Stores private diagnostics in the database and removes old log files.
* Keeps Redis markers in the plugin cache and prevents unsafe symlink traversal during uninstall.
* Reports failed runtime settings saves and retains previous settings instead of falsely reporting success.
* Replaced PHP-containing runtime configuration files with authenticated encrypted JSON and migrated both cache drop-ins.
* Removed legacy configuration files after successful migration.

= 1.0.12 =
* Protected temporary configuration files with the same PHP guard and access-rule suffix as published configurations, restricted permissions before writing, and rejected incomplete writes.
* Escaped less-than characters as CSS escapes before adding generated inline styles, preventing HTML closing-tag injection while preserving CSS string values.
* Returned completed page responses through an output-buffer callback, preserving scripts, forms, and SVG while keeping escaping at each transformation boundary.
* Removed obsolete feature descriptions and interface remnants so the readme matches the current plugin.

= 1.0.11 =
* Changed generated CSS and frontend loaders to use WordPress asset registration, enqueue, and printing functions.
* Bundled the interaction-delay and YouTube loaders as static plugin assets.
* Fixed nested output-buffer handling so WordPress asset printers can run during final HTML optimization.
* Restricted early cache reads to validated local files and rejected paths outside the page-cache directory.
* Kept opt-in JavaScript minification with WordPress transient storage and signed external delivery instead of JavaScript file writes. Defer, delay, saved settings, and exclusions remain supported.
* Added original-script fallback for expired minification results, versioned URLs, browser caching, and ETag revalidation.
* Hardened both early drop-in configuration readers and restricted saved font files to recognized binary font types with atomic publication.

= 1.0.10 =
* Added unused CSS status counts, size savings, build timings, failure details, and manual report refresh on the Optimization tab.
* Added per-URL, per-result, and full CSS regeneration using bounded background jobs. Active jobs are not duplicated by repeated manual actions.
* Fixed page-cache and commerce rules blocking authenticated CSS generator requests. Other request protections remain intact, and build responses are never cached.
* Fixed generator tokens leaking into report URLs and reuse keys. HTTP errors and missing build reports now trigger queue retries instead of false success.
* Fixed CSS reuse across differing IDs, attributes, and DOM relationships. Forced regeneration invalidates reusable results and purges affected page caches.
* Statistics now cover all stored reports and exclude stale results and missing files from current savings.
* Aligned panel padding, report spacing, and regeneration controls, and clarified settings help text.

= 1.0.8 =
* Adds Safe Mode. Define `GTPERF_SAFE_MODE` in wp-config.php and every HTML transformation stops and no page is served from or written to the cache, without changing a single setting or touching the drop-in. It is the answer to "something looks wrong and I cannot tell which option did it".
* Adds automatic cleanup. Cached pages past their lifetime, entries left unreachable by a settings change, generated CSS and JavaScript nothing has requested in two weeks, and the diagnostic log are now reclaimed hourly, and the cache is capped at a configurable number of entries. Nothing removed cached files before, so one settings save could leave hundreds of megabytes on disk permanently.
* Adds a "Remove all data when the plugin is deleted" option. The uninstall routine has always been gated on a setting that nothing wrote, so deleting the plugin left its options, database tables, drop-ins, and the Redis credentials file behind whatever you chose.
* Unused CSS removal works again and is a normal setting on the Optimization tab, off by default. It no longer corrupts inline SVG or non-Latin text, keeps escaped utility class names such as the ones Tailwind generates, passes stylesheets it cannot safely analyze through untouched instead of mangling them, and leaves `rel="alternate stylesheet"` alone. Generation now happens in the background rather than in a visitor's page load, and the result is reused across pages that share a template. Measured on a real page: 320 KB down to 101 KB.
* Removed experimental features and their unused endpoints.
* Product pages now clear when stock or price changes through the shop's own tools rather than only when the post is saved.
* Fixed the managed Cloudflare Cache Rule telling the edge to cache responses this plugin marks private. The edge cache lifetime now defaults to respecting your origin's Cache-Control header. If you set a positive lifetime, the rule is narrowed to requests with no query string, because overriding the origin cannot be made safe for query strings the origin refuses to cache.
* Removed the WordPress revision limit control. It filtered `wp_revisions_to_keep` on every site that activated the plugin, whether or not the database module was enabled, so posts lost revision history that could not be recovered.
* Trashing, unpublishing, or renaming a post now clears its cached page. Previously a withdrawn page kept being served from the cache for the rest of its stale window, and a renamed post kept serving its old URL ahead of the redirect WordPress would issue.
* Saving settings now clears the page cache. Every save invalidates every stored entry, and nothing removed the unreachable files, so the cache directory grew without limit.
* The cache capture pipeline no longer runs when the page-cache drop-in is not installed, which is the state directly after activation. It was doing the full render, optimization, and two file writes for a cache nothing could read.
* Removed the `X-GT-Performance-Bypass` request header. It was never signed despite its internal name, so any client could force a full uncached render on every request.
* A full cache purge no longer deletes the .htaccess and index.html files that keep the cache directory unreadable from the web.
* GT Performance no longer activates on WordPress multisite. Its compiled configuration and cache directory are shared across a network, so one site's settings decided another site's cache behavior.
* Added a LICENSE file, disclosed the three bundled MIT libraries, and corrected documentation that described WordPress.org as the update authority. The plugin is not listed in the directory yet.

= 1.0.7 =
* The release package no longer ships the extensionless command line wrappers bundled with the minifier library. WordPress.org does not permit them, and the minifier itself is unaffected.

= 1.0.6 =
* Adds separate controls for the main feed and the secondary feeds. The main feed at /feed/ can stay live and indexable while comment, category, tag, taxonomy, author, date, search, and post type feeds return a 404, and the main feed's discovery link can stay in the head while the rest are removed.
* The gauravtiwari.org WordPress preset now keeps the main feed discoverable instead of removing every feed link.

= 1.0.5 =
* Uninstalling with "remove all data" enabled now also removes the cache directory. Cached pages, generated CSS and JavaScript, logs, and both configuration files were left on disk, and the Redis configuration file holds a host, username, and password.

= 1.0.4 =
* Removed the upgrade compatibility code carried since 1.0.1. The plugin no longer deletes configuration files written by earlier releases, no longer drops their database tables, and no longer loads a class on behalf of a cache drop-in published before 1.0.1.
* Upgrading from 1.0.0 or earlier requires replacing the cache drop-in first. A standalone repair script is available: https://gist.github.com/wpgaurav/03d61d313df00b4127db92393ed74681

= 1.0.2 =
* Fixes every button and background request in the GT Performance admin screens returning a blank page on 1.0.1. The 1.0.1 prefix rename renamed what the controls submit but not the handlers registered to receive it, so nothing was listening. Purge, Cloudflare connect and sync, Redis test and install, drop-in install, xCloud refresh, database cleanup, CSS regeneration, and admin-bar actions were affected.

= 1.0.1 =
* Fixed a fatal error that took the front end and wp-admin down when updating from 1.0.0.
* Fixed the plugin's database tables not being recreated after the internal prefix rename, which broke cache preloading and CSS generation on upgraded sites.
* Fixed installing the page-cache drop-in twice removing it instead of leaving it in place.
* A site moved to a new path now republishes its drop-in automatically instead of silently serving uncached.
* Configuration and page-cache metadata are now inert JSON data files. Nothing generates or executes PHP at runtime.
* advanced-cache.php now ships as a bundled file that is copied into place instead of being generated, and resolves its own paths, so a renamed or relocated plugin directory keeps working.
* The early cache drop-in and WordPress now sanitize every request value through one shared implementation, so cache keys and bypass decisions can no longer diverge between them.
* Fixed keyboard focus styles being pruned from generated CSS. `:focus-visible` and `:focus-within` rules were dropped as unused.
* Every output buffer this plugin opens is now closed explicitly on shutdown.
* Renamed the `GTP_` and `gtp_` prefixes to `GTPERF_` and `gtperf_`. Constants set in `wp-config.php` and stored transients all use the new prefix and the old names are no longer read.
* Updated the bundled CSS parser to 9.4.0. The new version pulls in a required library that makes the plugin about 2.4 MB larger; pages served from the cache are unaffected.

= 1.0.0 =
* First stable release, distributed as free GPL software.
* Atomic origin page caching with an owned advanced-cache.php drop-in, background stale rebuilds, sitemap-driven warming, and verified purge receipts.
* Server-side unused-CSS optimization with file, inline, and hybrid delivery, staged rollout, and per-URL regeneration.
* Cloudflare Free cache-rule compiler, exact-URL purging, connection diagnostics, and scoped-token provisioning.
* Optional origin-pull static-asset CDN rewriting with exact extension controls.
* FluentCart, Easy Digital Downloads, and WooCommerce cache-safety adapters.
* JavaScript, media, font, embed, database, bloat, and Redis object-cache modules with encrypted credentials.
* xCloud host integration with explicit edge ownership, Perfmatters ownership coordination, and automatic analytics-plugin protection.
* Explain This Page diagnostics, admin-bar actions, and WP-CLI commands.
