=== GT Performance ===
Contributors: gauravtiwari
Tags: cache, performance, cloudflare, unused css, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page caching, unused CSS removal, and Cloudflare Free edge caching that never caches a cart or checkout. Everything runs on your own server.

== Description ==

### What is GT Performance?

GT Performance is a free WordPress performance plugin that speeds up every step between your server and your visitor's screen. It caches finished pages on your server, trims the CSS and JavaScript each page sends, and lets Cloudflare's free plan serve your HTML from its edge network. One plugin does all three, and every step knows about the others.

Most sites get fast by combining a page cache plugin, an unused CSS service, a script optimizer, and a Cloudflare add-on. Each tool guesses at what the others are doing, and when a post changes, not all of them notice. In GT Performance one set of rules decides what is cached at your server and at Cloudflare, what is left out of both, and what gets purged when content changes.

It works in three layers:

* **On your server**: An `advanced-cache.php` drop-in serves stored pages before WordPress loads. Redis object caching and background database cleanup reduce the work WordPress does when a page isn't cached.
* **In the page**: Unused CSS is removed per page, JavaScript is deferred or delayed without breaking dependencies, Google Fonts are hosted locally, hero images can be preloaded, and YouTube embeds load the player only after a click.
* **At the edge**: A single managed Cloudflare Cache Rule lets the Free plan cache your HTML, and edits are purged URL by URL. An optional origin-pull CDN serves the static file types you choose.

Speed doesn't matter if the site breaks, so safety comes first. WooCommerce, Easy Digital Downloads, and FluentCart carts, checkouts, accounts, and session cookies are never cached at your server or at Cloudflare. Cached pages keep their security headers. Riskier optimizations are off until you turn them on, and one `wp-config.php` constant switches everything off if something looks wrong.

It also shows its work. Explain this page tells you why any URL is or isn't cached. Each purge comes with a receipt showing whether it actually reached Cloudflare, the health report lists what needs attention, and settings history lets you roll back a change.

GT Performance suits blogs, content sites, online stores, and anyone who manages sites from WP-CLI or deploy scripts. Everything runs on your own WordPress server. There's no account to create, no telemetry, and the plugin never contacts servers of its own.

### Feature highlights

* **Page cache**: Pages are served from an `advanced-cache.php` drop-in before WordPress loads. Files are written atomically, so a visitor never gets a half-written page, and stale pages rebuild in the background.
* **Unused CSS removal**: Each page gets only the CSS it uses, built on your server. On one measured page, CSS dropped from 320 KB to 101 KB.
* **Cloudflare edge caching on the Free plan**: One narrowly scoped Cache Rule and exact-URL purges. No Workers, no APO subscription.
* **Safe for stores**: WooCommerce, Easy Digital Downloads, and FluentCart carts, checkouts, accounts, receipts, and session cookies are kept out of both the origin cache and Cloudflare.
* **Purges that follow your content**: Updating a post also clears the listings, query loops, widgets, reusable blocks, and shop pages that showed it. Unrelated pages stay cached.
* **Cache warming**: Reads your sitemaps in resumable background batches, warms recently changed pages first, and stops at your cache size budget.
* **JavaScript defer and delay**: Defer follows WordPress's own script dependencies, so inline jQuery keeps working. Analytics plugin scripts are protected automatically.
* **Explain this page**: Shows why any URL is or isn't cached, what your server holds, and whether Cloudflare agrees.
* **Safe mode**: One constant in `wp-config.php` switches off every optimization and cache read without touching a setting.

### Page caching

* Atomic origin cache served before WordPress loads.
* Stale pages rebuild in the background instead of in a visitor's page load.
* Security and indexing headers survive caching: Content-Security-Policy, Strict-Transport-Security, X-Frame-Options, Referrer-Policy, Permissions-Policy, X-Robots-Tag, and more.
* Optional separate caches for desktop, mobile, and tablet, purged together.
* Hourly cleanup of expired entries, with a configurable cap on cached entries.
* Core, plugin, and theme updates purge the cache, so cached pages never point at replaced asset files.
* Every purge produces a verified receipt, including partial failures.
* Optimize-only mode for hosts that already cache pages, such as LiteSpeed, Hostinger, xCloud, and Kinsta: pages are optimized on their way into your host's cache, and nothing is stored twice.
* Query parameters that change the page, such as `orderby` or `lang`, can get a cached copy per value instead of skipping the cache. Purging the page clears every copy.
* Saving a setting purges only when the change affects cached pages, so a new API token or cleanup schedule leaves the cache and Cloudflare alone.

### Unused CSS, built on your server

Stylesheet collection, selector analysis, and pruning all happen on your WordPress server. Nothing is sent to an outside service.

* Three delivery modes: a generated file, all used CSS inline, or critical CSS inline with the rest in a file.
* Built in the background and reused across pages that share a template.
* Page builder state styles are kept for Elementor, Bricks, Divi, Beaver Builder, Oxygen, Breakdance, WPBakery, Brizy, Kadence Blocks, Spectra, GenerateBlocks, and SiteOrigin: open menus, active tabs, sticky headers, popups, sliders, and animations.
* Fetch important CSS classes checks sample pages in your browser and keeps styles that JavaScript adds later, such as tables of contents, ads, and sliders.
* Keeps `:focus-visible` rules, Tailwind's escaped class names, inline SVG, and non-Latin text intact. Stylesheets it can't safely analyze pass through untouched.
* CSS Status shows savings, build timings, and failures in plain language, with per-URL and full regeneration.

### Cloudflare and CDN

* Compiles and syncs one managed Cache Rule on Cloudflare Free. Your other Cloudflare rules are left alone.
* Purges exact URLs, retries temporary failures up to three times, and honors Retry-After.
* Connects with a scoped API token or a Global API Key with account email.
* The connection check also confirms that visitors actually pass through Cloudflare (proxied DNS) and that APO isn't caching HTML alongside the managed rule.
* Deactivating the plugin, or `wp gt-performance cloudflare disconnect`, deletes this site's managed rule and purges this site's pages, so Cloudflare never keeps serving pages nothing will refresh.
* Detects xCloud's Cloudflare Enterprise add-on, reports its edge traffic, and avoids two systems owning the same edge cache.
* Optional origin-pull CDN for static files. You pick the exact file extensions it serves, and HTML, API responses, and third-party URLs stay unchanged.

### Built for stores

* Dynamic paths, session cookies, and transactional query parameters from WooCommerce, Easy Digital Downloads, and FluentCart are compiled into both the origin and Cloudflare bypass rules.
* Product pages and shop listings clear when price or stock changes through the store's own tools, not only when the product is saved.
* Speculative loading stays away from cart, checkout, and account pages.

### Frontend optimization

* JavaScript defer that respects script dependencies and inline code.
* JavaScript delay until first interaction or five seconds. Off by default.
* Opt-in JavaScript minification, with a fallback to the original script.
* Hero image rules with optional responsive preload, plus per-page options in the editor: script handling, hero image, "Don't cache this page", and "Use original CSS".
* Local hosting for Google Fonts your theme or plugins already load.
* Lightweight YouTube embeds that load the player from youtube-nocookie.com only after a click.
* Separate controls for the main feed and secondary feeds.

### Database and object cache

* Database cleanup runs in the background with live progress and a stop button, and keeps the number of revisions you choose.
* Redis object cache with encrypted credentials. Reads the same `WP_REDIS_*` constants as Redis Object Cache.

### See what your cache is doing

* **Explain this page** in Tools and the admin bar, with a link to view any page with every optimization off.
* **Health report** in Tools and Site Health, with a redacted export for support requests.
* **Background queue** you can pause, retry, and cancel from Tools or WP-CLI.
* **Settings history** keeps your last 20 saves for 90 days, with restore and JSON export and import. Credentials are never stored in history.
* **WP-CLI** commands for cache, Cloudflare, database, and health. `wp gt-performance doctor` exits with status 1 when a check fails, so it fits deploy scripts.

### Optional AI assistant access

Off by default. On WordPress 6.9 or later, an administrator can let an external AI assistant read cache and health information and, with separate permission, purge or preload URLs and propose settings for approval. It works through the WordPress REST API or the official WordPress MCP Adapter plugin.

On WordPress 7.0 or later, an optional adviser explains diagnostics using the AI provider you configured in WordPress. It shows exactly what will be sent before anything leaves your site.

### Works with

* WooCommerce, Easy Digital Downloads, and FluentCart
* Cloudflare Free and xCloud, including xCloud's Cloudflare Enterprise add-on
* Elementor, Bricks, Divi, Beaver Builder, Oxygen, Breakdance, WPBakery, Brizy, Kadence Blocks, Spectra, GenerateBlocks, SiteOrigin, GT Page Blocks Builder, and Thrive Architect
* Perfmatters, with coordination over which plugin owns each overlapping optimization
* Akismet and Jetpack
* Site Kit by Google and PixelYourSite, whose scripts are never deferred or delayed
* Redis Object Cache `wp-config.php` constants
* WordPress Studio and WordPress Playground (SQLite)

### Before you turn it on

* Page caching starts from the Setup tab, which checks your server, detects a host page cache, installs the drop-in (or switches to optimize-only mode), and confirms a real cached page.
* Language and currency plugins such as WPML, Polylang, TranslatePress, Weglot, and WooCommerce currency switchers can show visitors different pages at the same URL. The Integrations tab says what to set for each.
* Riskier optimizations such as unused CSS removal and JavaScript delay are off by default. Test them on staging first.
* GT Performance runs on single sites. It won't activate on multisite.

### Privacy

GT Performance collects no data and has no telemetry. It contacts a third-party service only after you turn on an integration that needs one. Each service, what it receives, and when is listed under External Services below.

### Links

* [GT Performance Home](https://gauravtiwari.org/product/gt-performance/) - features, setup guides, and answers to common questions.
* [Changelog](https://products.gatilab.com/changelogs/gt-performance/) - every release with its fixes and upgrade notes.
* [Roadmap](https://products.gatilab.com/roadmaps/gt-performance/) - what is planned next.
* [GT Performance Community](https://gauravtiwari.org/portal/) - ask questions and get setup help from other users.
* [GitHub](https://github.com/wpgaurav/gt-performance) - bug reports and pull requests are welcome.
* [More WordPress Plugins](https://gauravtiwari.org/wordpress-plugins/) - other plugins by Gaurav Tiwari.

== Installation ==

1. Install and activate GT Performance from Plugins → Add New.
2. Open GT Performance in the main WordPress admin menu.
3. Open the Setup tab and follow its six steps. It detects whether your host already caches pages, installs the page-cache drop-in or switches to optimize-only mode, and verifies a cached page. Caching starts here.
4. Turn on optimization modules one at a time, and check your theme and plugins after each.
5. Optional: connect a scoped Cloudflare API token, or a Global API Key with account email, then sync the managed cache rule.
6. Optional: set up an origin-pull CDN and choose the exact file extensions it should serve.

Requires WordPress 6.6 or later and PHP 8.1 or later.

== Frequently Asked Questions ==

= Will it cache my cart or checkout? =

No. GT Performance compiles the dynamic paths, session cookies, and query parameters from active WooCommerce, Easy Digital Downloads, and FluentCart adapters into both the origin and Cloudflare bypass rules.

= Does Cloudflare require a paid plan? =

No. The baseline uses Cache Rules and targeted purge, both available on Cloudflare Free. No Worker or APO subscription is required.

= Something looks wrong. How do I switch everything off fast? =

Add `define( 'GTPERF_SAFE_MODE', true );` to `wp-config.php`. Every HTML transformation stops and no page is served from or written to the cache. Your settings stay as they are. Remove the line to turn everything back on.

= Does unused CSS work with page builders? =

Yes. When Elementor, Bricks, Divi, Beaver Builder, Oxygen, Breakdance, WPBakery, Brizy, Kadence Blocks, Spectra, GenerateBlocks, or SiteOrigin is active, the classes it adds after the page loads (open menus, active tabs, sticky headers, popups, sliders, animations) are kept. GT Page Blocks Builder and Thrive Architect styles are left untouched. Add your own selectors under Exceptions if a custom script needs more.

= Is unused CSS processed by an external service? =

No. Stylesheet collection, selector analysis, pruning, and file creation run on your WordPress server.

= Can used CSS be inlined? =

Yes. Choose Generated file, Inline all used CSS, or Critical inline + remaining file. Hybrid mode falls back to a generated file if the critical part exceeds its inline budget.

= Can I use another CDN alongside Cloudflare? =

Yes. Enter its HTTPS origin-pull URL on the CDN tab and select the static-file extensions it should serve. GT Performance rewrites only same-site assets with those extensions. Third-party URLs, HTML routes, API responses, and unselected file types stay unchanged.

= Does it work on multisite? =

No. A network shares one compiled configuration and cache directory, so one site's settings would decide another site's cache behavior. GT Performance won't activate on multisite.

= Can I undo a settings change? =

Yes. Tools → Settings history keeps the last 20 saves for up to 90 days and restores earlier values without touching saved credentials. Settings can also be exported to and imported from a JSON file.

= Can Redis credentials be configured in wp-config.php? =

Yes. GT Performance reads the `WP_REDIS_HOST`, port, socket path, scheme, database, ACL password array, prefix, timeout, read-timeout, and disable constants used by Till Krüss's Redis Object Cache. Existing `GTPERF_REDIS_*` constants remain supported and take highest precedence. The Object Cache screen provides a copy-ready example.

= Does GT Performance send my site data to an AI service? =

Only when you ask it to. The optional abilities answer requests from an assistant you connect yourself, using an Application Password you create. They're off by default, require an administrator account, and never include credentials. The optional adviser (also off by default) sends a redacted diagnostic report to the AI provider configured in WordPress only after you review exactly what will be sent and press Send. Nothing is sent automatically, from visitors, or from scheduled tasks.

== Third-party libraries ==

GT Performance bundles three MIT-licensed PHP libraries in `vendor/`. All three are GPL-compatible and are used server-side only.

* [matthiasmullie/minify](https://github.com/matthiasmullie/minify) - JavaScript minification in memory. MIT.
* [sabberworm/php-css-parser](https://github.com/MyIntervals/PHP-CSS-Parser) - CSS parsing for the unused-CSS engine. MIT.
* [symfony/css-selector](https://github.com/symfony/css-selector) - CSS selector to XPath translation. MIT.

The full GPL-2.0 text this plugin is licensed under ships as `LICENSE` in the plugin directory.

== External Services ==

GT Performance sends no data anywhere by default. Each service below is contacted only after you turn on the feature that needs it, and only with credentials you supply.

* **Cloudflare API** (api.cloudflare.com): Used when you connect your Cloudflare account. Sends your API token or key, zone, the managed cache rule, this site's hostname when the connection check looks up its DNS records, and the URLs or hostnames being purged. [Terms](https://www.cloudflare.com/terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/).
* **xCloud API** (app.xcloud.host): Used when you connect an xCloud-hosted site. Sends your xCloud token and site domain to refresh the integration and purge host caches. [Privacy Policy](https://xcloud.host/privacy-policy/).
* **Google Fonts** (fonts.googleapis.com, fonts.gstatic.com): Used when local font hosting is on. Your server downloads the fonts once, with no visitor data, and serves them from your domain. [Privacy Policy](https://policies.google.com/privacy).
* **YouTube** (i.ytimg.com, www.youtube-nocookie.com): Used when lightweight embeds are on. The visitor's browser loads the thumbnail, and loads the player only after the visitor clicks play. [Terms](https://www.youtube.com/t/terms), [Privacy Policy](https://policies.google.com/privacy).

Cache warming, CSS generation, and purge verification request your own site's URLs only. Script hostnames such as `googletagmanager.com` are stored only as patterns for matching the scripts your site already loads. They are never contacted.

== Upgrade Notice ==

= 1.2.0 =
New Setup tab, optimize-only mode for hosts that already cache pages, per-page cache and CSS options, and cleaner Cloudflare deactivation. Existing settings keep working; run Setup once to verify your cache.

= 1.1.1 =
Safe mode now stops the drop-in too, cached pages keep their security headers, and signed-out purges reach Cloudflare and xCloud. Purge the cache once after updating so every cached page picks up its headers.

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

= 1.2.0 =
* New: Setup tab. Six steps check this server, detect other page caches and your host's cache, choose the cache mode, connect Cloudflare, list store and language plugins, and verify a real cached page, with Cloudflare's answer shown beside it. The Dashboard points to it until verification passes.
* New: optimize-only mode for hosts that already cache pages. Eligible pages are optimized as WordPress sends them and your host stores the result; nothing is stored twice. Cache headers are left to your host, except that carts, checkouts, and other excluded pages are still sent as no-store.
* New: "Don't cache this page" and "Use original CSS" in the editor's GT Performance box. Explain this page reports the first as `page-option`.
* New: "Cache each value separately" query parameters. Pages like `?orderby=price` or `?lang=de` get their own cached copy (values up to 100 characters, up to 100 copies per page) instead of bypassing the cache, and purging the page clears every copy at the origin and at Cloudflare.
* Saving settings purges the page cache and Cloudflare only when the change affects cached pages. Credentials, connection status, cleanup schedules, and preload limits no longer purge.
* Deactivation deletes this site's managed Cloudflare rule, and only that rule, then purges this site's pages from Cloudflare. `wp gt-performance cloudflare disconnect [--forget]` and a Disconnect button do the same and turn the integration off.
* Sites sharing a Cloudflare zone, such as example.com and shop.example.com on separate installs, each keep their own cache rule, and a full purge clears only the site's own hostnames instead of the whole zone.
* The Cloudflare connection check warns about DNS-only (grey cloud) records and about APO caching HTML alongside the managed rule.
* WPML, Polylang, TranslatePress, Weglot, and common WooCommerce currency switchers are detected, flagged on Integrations with what to set, and reported in the health report.
* Query parameters sent as arrays, such as `?s[]=x`, now bypass the cache. They were read as the page with no query and could be stored under its key.
* The health report and cron checks are translatable, and a translation template ships in `languages/`.

= 1.1.1 =
* Safe mode (`GTPERF_SAFE_MODE`) now also stops the page-cache drop-in from serving stored pages, so nothing is served from the cache while it is on.
* Cached pages keep the security and indexing headers WordPress and other plugins send: Content-Security-Policy, Strict-Transport-Security, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, cross-origin policies, X-Robots-Tag, Content-Language, and Link.
* Preload requests carry a short-lived signed token, so visitors can no longer force stale pages to rebuild.
* Cloudflare and xCloud now purge when the purge starts from a signed-out request, such as a visitor's approved comment or a checkout that changes stock.
* WordPress core, plugin, and theme updates purge the page cache, so cached pages no longer point at replaced asset versions.
* Lightweight YouTube previews no longer leave an empty band above the video in the YouTube embed block, show YouTube's red play button, and play from a click anywhere on the thumbnail.
* New: Tools → Explain this page shows why a URL is or is not cached, what the origin holds, and whether Cloudflare agrees. The admin bar links straight to it, with a link to view the page with every optimization off.
* `wp gt-performance database run` keeps your "Scheduled revisions to retain". Add `--all-revisions` to delete every revision, as the Run cleanup button does.
* `wp gt-performance doctor` and `health` exit with status 1 when a check fails. Warnings still exit 0.
* `--page-url` must be on this site for cache purge, explain, and verify, and for cloudflare purge.
* The autosave interval setting now takes effect.
* Settings export, read-only abilities, and proposals include "Disable password strength meter".
* `X-GT-Cache-Reason` keeps paths such as `path:/cart/`.
* Corrected the Diagnostic logging and Purge GT cache descriptions, and removed unused code.

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
