# Changelog

## 1.2.0 - 2026-09-28

Works out of the box: a guided setup, optimization for sites whose host already caches pages, and a plugin that cleans up after itself.

Setup and cache modes

- A Setup tab walks a fresh install to a verified cached page: AUTH_KEY, OpenSSL, the cache directory, and who owns advanced-cache.php; active page-cache plugins and host caches (from the environment, or from the home page's headers fetched once as a visitor: LiteSpeed, Kinsta, Hostinger, SiteGround, WP Engine, Varnish, Nginx, and others); the cache mode; Cloudflare; store and language plugins; and a verification that requests the home page as a visitor, up to four times two seconds apart, until GT Performance answers HIT. Cloudflare's answer is shown beside the result rather than deciding it: on gatilab.com the edge kept answering MISS for a while after a full purge while the origin was already serving HITs. Nothing runs until a button is pressed, and activation does not redirect. The Dashboard points to Setup until verification passes.
- Optimize-only mode runs the optimization pipeline on responses the eligibility rules would cache and hands them to the host's cache without storing them. The drop-in is not required, and the compiled configuration tells an installed one never to serve. Bypassed requests start no output buffer. Responses must pass the same checks a stored page must, and warming and stale refresh are skipped. Cache headers for eligible pages are left to the host. A response the eligibility rules would not cache is sent no-store, as in store mode: a shared cache that receives no Cache-Control applies its own default lifetime (two hours at Cloudflare), so staying silent would have shared pages this plugin refused to cache. The mode is chosen in Setup or on the Cache tab and shown on the Dashboard, in the health report, `cache status`, and the site-status ability.

Caching

- "Cache each value separately" lists query parameters whose values each get their own stored copy, instead of bypassing the cache as unknown parameters. Values over 100 characters are not cached, and a page holds at most 100 variants; beyond that, new values are served uncached. Each variant is recorded in a per-page index, so purging the page removes every variant at the origin and passes their URLs to the edge purge.
- The editor's GT Performance box adds "Don't cache this page" (never stored or optimized, sent no-store, reported by Explain as `page-option`) and "Use original CSS" (full stylesheets, no unused-CSS build queued).
- Query parameters sent as arrays (`name[]=`) bypass the cache as `query_array:<name>` and are sent no-store, like never-cache parameters. parse_str() made them arrays and the request context kept only scalars, so `?preview[]=1` or `?s[]=x` was judged and keyed as the page with no query, in WordPress and in the drop-in.
- A settings save advances the cache generation, which purges the origin and sends purge-everything to Cloudflare, only when a setting that reaches cached pages changed. Credentials, connection status, background work, and admin-only settings are listed as output-neutral; anything else, including any new setting, still purges.

Cloudflare

- Deactivation deletes this site's managed Cache Rule, leaving every other rule, and purges this site's hostnames, best effort and without blocking deactivation. After reactivation the Cloudflare tab says the rule is gone until the next sync. `wp gt-performance cloudflare disconnect [--forget]` and a Disconnect button do the same and turn the integration off; `--forget` also deletes the credentials and Zone ID.
- Sites that share a Cloudflare zone (example.com and shop.example.com on separate installs) no longer take over each other's cache rule. Every site used one fixed rule ref, and refs are unique per zone, so a second site's sync overwrote the first site's rule and its deactivation deleted it. New rules get a ref per hostname; a rule under the old shared ref still belongs to the site whose host its expression names and keeps that ref when updated, since Cloudflare refuses to change a ref. Conflict detection no longer mistakes a subdomain's rule for this host's.
- A full edge purge (a cache-relevant settings save, disconnect, deactivation, `cloudflare purge` without `--page-url`) purges this site's hostnames instead of sending purge_everything, which also emptied Cloudflare's cache for every other site and subdomain in the zone. Purge by hostname is available on every plan.
- Every sync, including the one Setup runs, purges this site's hostnames after writing the rule. On gtp-demo.gatilab.com a request that reached Cloudflare in the seconds before a just-deleted rule stopped applying stored the page WordPress sent without the plugin, and recreating the rule served that copy. Callers report a failed purge.
- The ruleset backup written on every sync was never read, and restoring it would undo the site owner's later rule changes, so it is no longer written. Uninstall still removes the old option.
- The connection check adds two stages that warn rather than fail: whether the site host's DNS records are proxied (falling back to the site's own CF-Ray header when the token cannot read DNS), and whether APO is on. The summary says when a stage needs attention.

Compatibility and diagnostics

- WPML, Polylang, TranslatePress, Weglot, WooCommerce Multilingual & Multicurrency, CURCY, FOX (WOOCS), Aelia Currency Switcher, and Price Based on Country are detected. Integrations flags each active one with what to set, and the health report warns while any is active. Nothing is changed automatically.
- The health report and cron checks are translatable, CronHealth leaves the server path out of exported reports through a flag instead of string replacement, and `languages/gt-performance.pot` ships with the plugin.

## 1.1.1 - 2026-09-27

Fixes found while verifying the documentation against the 1.1.0 code. Each one was reproduced before it was fixed.

Cache serving

- The page-cache drop-in never read `GTPERF_SAFE_MODE`, so already-cached pages kept being served with safe mode on. It now falls through to WordPress, which reports `X-GT-Cache: SAFE-MODE`.
- A cache hit sent only six fixed headers. CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, the cross-origin policies, X-Robots-Tag, Content-Language, and Link headers set in PHP disappeared from cached pages, including a `noindex` X-Robots-Tag. Capture stores an allowlist of them (at most 20 lines of 8 KB) and the drop-in replays them after re-validating each line. Pages cached before this release get their headers when they are rebuilt; purge once to refresh them all.
- Any client could send `X-GT-Preload` and turn every stale page into a full WordPress render. Preload requests now carry a ten-minute HMAC token derived from `AUTH_KEY`, which the drop-in verifies without a database.
- `X-GT-Cache-Reason` uses one sanitizer in the drop-in and in WordPress, so `path:/cart/` no longer arrives as `pathcart` or `path:cart`.

Purging

- The edge modules load only for admin, cron, AJAX, REST, CLI, and signed-in requests, so a purge from an anonymous request (a visitor's auto-approved comment, a classic WooCommerce checkout that sells a product out) cleared the origin while Cloudflare and xCloud kept serving the old page. The plugin now loads them the moment a purge fires.
- WordPress core, plugin, and theme updates purge the cache. Translation updates and plugin installs do not.
- `--page-url` for `cache purge|explain|verify` and `cloudflare purge` must be on one of the site's canonical hosts. `verify` fetched any URL, and purges handed foreign URLs to the edge.

YouTube previews

- In a theme with responsive embeds, a YouTube embed block showed an empty band the height of the video above the preview. Core reserves the 16:9 box with a padding `::before` on the block wrapper and pins the iframe over it; the preview kept its own inline 16:9 box, so it stacked under the reserved one. Its styles now live in one stylesheet in the head, which pins the preview over the wrapper with core's own selector, and nothing is inline except the thumbnail, so a theme's embed wrapper can position it too.
- The preview shows YouTube's red play button instead of a browser-styled "Play" button, and a click anywhere on the thumbnail starts the video. Pages cached before this release keep the old preview until they are rebuilt.

Administration and WP-CLI

- Tools → Explain this page is new: wp-admin had no view for it, and the admin-bar link opened Tools with a URL nothing read. The panel shows the decision and reason, the origin copy's state and times, the cache key, and whether Cloudflare agrees, with a link to the page in per-request safe mode. The admin-bar link now encodes the URL and jumps to the panel.
- `wp gt-performance database run` ignored "Scheduled revisions to retain" and deleted every revision, which is how a server cron calling it behaved. It now keeps them; `--all-revisions` matches the Run cleanup button.
- `wp gt-performance doctor` and `health` exit 1 when a check fails. Warnings, such as an integration that is not in use, exit 0.
- The autosave interval setting never took effect: WordPress defines `AUTOSAVE_INTERVAL` right after `plugins_loaded`, and the plugin defined it on `init`.
- The credential-name guard dropped `bloat.disable_password_strength_meter` from settings exports, read-only abilities, and proposals. A reviewed exception keeps it; any new credential-like key is still hidden.
- Corrected the Diagnostic logging description (entries are kept in the database, not a log directory) and the Purge GT cache description (it removes stored pages, not generated assets).

Removed

- `Commerce\PolicyAudit`, `Optimization\Css\SelectorObservation`, and uncalled methods left from removed features.

## 1.1.0 - 2026-09-27

The first feature release in the WordPress.org plugin directory. It contains the work prepared as 1.0.15, which was never published, and the unused CSS fixes and start-up speed-up below.

Background queue

- Queue claims are conditional, so two workers can never take the same job, and a worker that lost its lease cannot record completion or publish generated files and CSS reports. Attempts count at claim time; a job whose worker keeps crashing fails after three claims instead of retrying forever.
- Duplicate requests for the same target return the existing job. Failed jobs can be retried and pending or running jobs cancelled from Tools and `wp gt-performance queue status|list|pause|resume|retry|cancel`; `queue` alone still runs jobs.
- Optional work can be paused without stopping cache invalidation. Waiting jobs age toward higher priority, so a steady backlog can no longer starve warming (a production site had a warm run waiting 18 days); purges always run first. Scheduled runs process up to 25 jobs a minute (was 5), still within a 20-second budget.
- The queue schema upgrades in bounded admin/CLI batches under a lock, never on visitor requests. One queue runner runs per site. WordPress Studio and Playground (SQLite) are supported.

Cache warming

- Warm runs resume across bounded background jobs instead of stopping after 20 child sitemaps. They read up to 10 sitemap sources you choose, or the WordPress sitemap plus sitemaps listed in robots.txt; follow nested indexes and same-site redirects five levels deep; skip foreign, private, and already-visited URLs; and cap a run at 50,000 targets.
- The home page and recently modified pages warm first, in batches. A run stops at the cache entry budget instead of evicting pages to finish, and warms mobile copies with a phone user agent when a separate mobile cache is on.
- Each preload records whether the origin stored a fresh page, only Cloudflare answered, or nothing was stored. HTTP 200 alone no longer counts as warm. Progress is in Tools and `wp gt-performance cache warm-status`.

Smarter purging

- Cached pages record the posts, listings, reusable blocks, and navigation menus they were built from. Updates purge those pages as well as the usual related pages: listings a post joins or leaves (including its old category and later pages), query loops, grids, and widgets that show it, pages embedding an edited reusable block or menu, and shop listings after price or stock changes. Renamed terms purge their old archive URL. Unrelated pages stay cached.
- `wp gt-performance cache preview --post=<id>` lists what an update would purge and why.
- Related-page purges no longer include URLs on another host (for example an author link to a personal site), which were also sent to the Cloudflare purge.

Settings history

- Every save keeps the non-secret values it replaced: the last 20 saves, up to 90 days. Tools → Settings history shows differences and restores them; settings export to and import from JSON with a preview. The same is available in `wp gt-performance config`.
- Credentials, Cloudflare and xCloud identity, Redis, and AI agent access are never stored, exported, imported, or restored. A shared settings lock and preview checks stop one administrator, CLI command, or connection flow from overwriting another's change.

Health and diagnostics

- Tools → Health combines queue backlog and age, runner heartbeat, WP-Cron, storage, drop-ins, edge ownership, configuration publication, purge verification, unused-CSS failures, and warming, each with its source and time. It also appears as a Site Health test, in `wp gt-performance health [--format=json]`, and as a redacted support-report download.

AI assistants (optional)

- On WordPress 6.9 and later, abilities let an external AI assistant read status, URL cache explanations, health, jobs, CSS reports, non-secret settings, purge previews, and operation results, through the WordPress REST API or the official WordPress MCP Adapter. Off by default and for administrators only; see the AI & MCP tab or `wp gt-performance abilities status`.
- "Read and operate" access also lets an assistant purge or preload up to 20 URLs, regenerate unused CSS for a URL, and retry failed preload, warming, CSS, or image jobs. Each request carries an ID, so retrying after a timeout never repeats the work. Limits: 60 requests a minute per user and 100 outstanding operations per site. Purge results report the origin, the edge provider's response, and what a public request returned afterwards. Queued work is cancelled if access is turned off or the user stops being an administrator.
- Assistants can propose (never apply) changes to cache warming, JavaScript defer and delay, critical images, unused-CSS rollout, and the CSS safelist. Proposals wait 15 minutes on the AI & MCP tab or in `wp gt-performance operations` for an administrator to apply or reject, and are refused if settings changed in the meantime.
- Optional in-admin AI adviser (WordPress 7.0+, off by default). It explains a page, diagnoses the queue and warming, reviews settings, or explains a purge, using the AI provider configured in WordPress. Before anything is sent it shows the exact redacted report and the recipient. Answers must cite that report; numbers not in it are flagged, and suggestions can only become a proposal. Limited to one request at a time and 20 a day per site. GT Performance stores no AI credentials.

Frontend

- Unused CSS keeps the state styles page builders add after the page loads: open menus, active tabs and accordions, sticky headers, popups, sliders, and entrance animations. Covered: Elementor, Bricks, Divi and Extra, Beaver Builder, Oxygen, Breakdance, WPBakery, Brizy, Kadence Blocks, Spectra, GenerateBlocks, and SiteOrigin, plus the carousel, lightbox, and animation libraries they bundle. Where the builder is freely available, the state classes were taken from its shipped front-end scripts. GT Page Blocks Builder and Thrive Architect styles are left whole. Protection applies only while the builder is active and "Automatic conflict protection" is on.
- JavaScript defer now follows WordPress's script registry, the same rule core uses. A script stays blocking when inline code runs right after it, or when a script depending on it must. Previously, turning on defer also deferred jQuery and broke inline `jQuery(...)` code. Delay applies to a selected script together with the scripts that depend on it, or to none of them. Scripts WordPress did not register are no longer deferred, because their order is unknown; they can still be delayed by naming them. Debug mode explains every decision in an HTML comment.
- Per-page options in the editor: turn off script delay or all script changes, and name the page's hero image.
- Hero image rules by post type, template, or front page choose the image that gets high priority, instead of document order. Optional responsive preload is never duplicated. Background heroes can be declared and preloaded. Attributes set by the theme or WordPress still win.
- Speculative loading (WordPress 6.8+) keeps cart, checkout, account, and every other cache-bypass path out of prefetching. Modes: WordPress default with exclusions, prefetch on press only, or off. When the Speculative Loading plugin is active, it keeps control of the mode.

Database cleanup

- Manual cleanup runs in the background. The button returns at once, the Database tab shows each task's count as it works, and a run can be stopped after its current batch. Deleting 1,000 items no longer holds the admin request open until the end.
- A manual run goes ahead of cache warming and preloads in the queue, but never ahead of cache purges. At the old priority it waited behind a whole warm run on a production site.
- Scheduled cleanup uses the same background run. `wp gt-performance database run` now finishes every task instead of stopping after 1,000 items per task.
- Keeping recent revisions now works across all posts. A post with too many revisions was missed when earlier posts in the scan were within the limit.
- Emptying the trash or deleting spam and trashed comments no longer purges pages. That content was never public, and each trashed post used to purge the homepage and its listings again.

Fetch important CSS classes

- Unused CSS reads the HTML the server sends, so parts that JavaScript adds after the page loads (a table of contents, an ad, a slider) looked unused and lost their styling. After you change unused CSS settings, CSS Status now opens two recent pages from each public post type in your browser, on desktop and mobile, and records the classes, IDs, and CSS rules those scripts need. Builds for that post type keep them. The check shows its progress, says what it found, and links to the next step; it can also be run from CSS Status at any time. IDs and rules seen on only one page, such as numbered heading anchors, are ignored, and post types hidden from search are skipped.

Unused CSS status

- Each result says in plain language what visitors get for that page and what to do, instead of codes such as `critical_budget_exceeded`. A Hybrid build that was over its inline limit is marked "Ready, one file": the page is styled correctly and loads like Generated file mode.
- Hybrid builds record how much CSS the top of the page needed. CSS Status uses that to suggest an inline limit that fits three in four pages, or recommends Generated file when the top of a typical page needs more than 50 KB.
- Totals count only the current delivery mode. Results from before a mode change are noted separately instead of inflating the stale count. Out-of-date results explain why they are out of date and what visitors get meanwhile.

Fixes

- Bricks 2 pages lost their base styles when Bricks' own stylesheets were optimized. Bricks opens its framework CSS with a layer-order statement (`@layer bricks.reset, …;`), which the CSS parser read into the next rule, nesting the rest of the file inside it. Leading layer statements are now kept apart and restored; a layer statement after any rule leaves that stylesheet untouched. On anantamias.com this also let the framework file shrink from 33 KB to 8 KB per page.
- Icon-font rules written with a single colon (`.ion-ios-add:before`, as Ionicons, Font Awesome, and most minified CSS do) were all kept whether or not the page used the icon: 696 Ionicons rules on a Bricks page that shows one. They are now tested like `::before`.
- Posts and other pages that print their own address (the comment form's cancel-reply link, login redirects, pagination) were never served their unused CSS. The build request's token appeared in that address, so the page a visitor got never matched the build. The token is now taken off the request before WordPress renders the page.
- Pages with an Akismet-protected comment form were never served their unused CSS either, and every uncached visit queued another build: Akismet puts a random number in each form. Builds are now matched on the markup that decides which rules apply, ignoring hidden form values, inline script contents, HTML comments, and CSP nonces.
- Unused CSS no longer changes which stylesheet wins. All generated CSS used to go at the end of `<head>`, so when a theme's stylesheets were excluded, WordPress's global styles moved behind them and overrode the theme's fonts and link colours. Each run of consolidated stylesheets is now replaced where it stood.
- GT Performance no longer loads its bundled libraries on every request. Composer's autoloader, with the 1,176 functions of the Safe library that the CSS parser depends on, was included for every uncached page view, admin screen, and REST call, even though the libraries are only used while CSS is pruned or JavaScript minified. They now load the first time one of their classes is needed: 201 fewer files per request on gatilab.com, and about 10 ms less start-up in a warm-cache benchmark.
- The plugin package no longer ships its libraries' README and changelog files or the Safe library's Rector migration configs (13 files, 90 KB), and the GitHub README stays out of it.
- CSS builds no longer fail on hosts that make the origin's no-store response cacheable (Hostinger's Site Optimizer did, and Cloudflare then answered later builds of the same page with a stored copy). Every build request now has a URL no cache has seen.
- Hidden tooltips no longer widen admin pages on phones. Report tables no longer widen the page on phones either: a visually hidden column heading escaped the table's scroll area.
- The settings screen has four new tabs. AI & MCP holds agent access, the adviser, and assistant proposals. Object Cache holds the Redis settings, the connection test, the drop-in installer, and the wp-config.php overrides. CSS Status holds the unused CSS build results and the regenerate controls. Database holds manual cleanup with live counts and the scheduled cleanup settings, which used to be split between Tools and Optimization. The Cache tab is now Page Cache, and Integrations keeps plugin, host, and commerce coordination. The xCloud cache status panel appears only when the xCloud integration is enabled.
- Admin tables no longer draw a second border inside their panel, and the summary cards above them are inset like the rest of the panel.
- Posts created or updated through the REST API with an Application Password (no login cookie), for example by automation tools, now purge the Cloudflare and xCloud edge as well as the origin.
- Plugin Check reports no warnings. The dependency lookup prepares each (type, ID) pair on its own, so every query has a fixed number of placeholders. The AI & MCP tab reads the MCP endpoint from the server the adapter actually registered instead of calling the adapter's filter itself, so a customized route is shown correctly too.
- An unwritable cache directory no longer floods the error log with PHP warnings while saving settings; the failure is still reported.

## 1.0.14 - 2026-09-21

- Fixed individual Cloudflare purges by allowing internal PURGE requests in the managed cache rule while preserving checkout, session, and query exclusions.
- Wait for Cloudflare before verifying or reporting manual cache purges; show partial failures and retain the latest purge result even with debug logging disabled.
- Purge desktop, mobile, and tablet cache variants when separate device caching is enabled.
- Retry temporary Cloudflare transport, rate-limit, and server failures up to three times, preserving unprocessed batches and honoring Retry-After.
- A successful full purge supersedes queued URL purges and retries. Failed verification requests no longer appear successful, and receipt details display correctly.
- After upgrading, use Connect/sync Cloudflare once to update the existing managed rule. Unrelated Cloudflare rules are preserved.

## 1.0.13 - 2026-09-20

- Preserved existing configuration and drop-ins when writes, permissions, or publication fail; temporary wp-config copies retain PHP handling.
- Moved bounded, redacted diagnostics into the database and removed legacy plaintext logs.
- Kept Redis failure markers inside the plugin cache and prevented uninstall from following cache-root aliases into other directories.
- Rejected settings changes when runtime publication fails, invalidated available stale copies, and surfaced failures in admin and CLI.

- Replaced PHP-containing runtime configuration with authenticated encrypted JSON, including temporary files.
- Updated both early cache readers and removed legacy PHP configuration after successful compilation.
- Fail configuration installation safely when encryption or file publication is unavailable.

## 1.0.12 - 2026-09-18

- Protected temporary configuration files with the same PHP guard and access-rule suffix as published configurations, restricted permissions before writing, and rejected incomplete writes.
- Escaped less-than characters as CSS escapes before adding generated inline styles, preventing HTML closing-tag injection while preserving CSS string values.
- Returned completed page responses through an output-buffer callback, preserving scripts, forms, and SVG while keeping escaping at each transformation boundary.
- Removed outdated feature claims and the empty Private Islands panel. Current descriptions no longer advertise Fleet Console, Private Islands, Commerce Safety Lab, or CSS Training Mode.

## 1.0.11 - 2026-09-11

- Changed generated CSS and frontend loaders to use WordPress asset registration, enqueue, and printing functions.
- Bundled the interaction-delay and YouTube loaders as static plugin assets.
- Fixed nested output-buffer handling so WordPress asset printers can run during final HTML optimization.
- Restricted early cache reads to validated local files and rejected paths outside the page-cache directory.
- Kept opt-in JavaScript minification using in-memory processing, WordPress transient storage, and signed external delivery instead of generated JavaScript files. Defer, delay, saved settings, and exclusions remain supported.
- Added safe original-script fallback when cached minified results expire, versioned URLs for source changes, browser caching, and ETag revalidation.
- Audited similar patterns across the plugin: hardened both early drop-in configuration readers and restricted downloaded font output to recognized binary font types with atomic publication.

## 1.0.10 - 2026-09-05

### Added

- Unused CSS status on the Optimization tab: queued, processing, ready, stale, failed, and skipped results; original and generated sizes; reduction percentage; build duration; and failure details.
- Manual status refresh, per-URL and per-result force regeneration, and full regeneration in bounded background batches. Known eligible URLs and the homepage are queued; other pages rebuild when visited.
- Counts across all stored reports, with the latest 50 results shown in the table. Current savings exclude stale results and missing generated files.

### Fixed

- Signed CSS generator requests were blocked by page-cache and commerce query rules. The authenticated build parameter is now excluded from the policy context while other request protections remain intact. Build responses remain private and are never cached.
- Generator tokens no longer become part of report URLs or CSS reuse keys.
- HTTP errors and responses without completed CSS reports now fail the background job so its retry policy applies. Successful generation purges the public page cache so visitors receive the new CSS.
- CSS reuse now accounts for the URL, full markup, and CSS revisions, preventing mismatches involving IDs, attribute values, and DOM relationships. Forced builds bypass existing results.
- Regeneration controls check saved rollout, exclusions, safe mode, and optimization ownership, and avoid duplicating active jobs. Disabled or newly excluded jobs record a skipped result.
- Consistent padding and spacing across status cards, statistics, reports, and regeneration controls.
- Clearer help text across optimization and cache settings.
- WordPress.org builds omit the self-hosted Update URI header. FluentCart URL handling uses WordPress parsing helpers, and the early Redis drop-in documents its filesystem fallback.

## 1.0.8 - 2026-09-05

Correctness release. Everything here is a defect a site could hit without opting
into anything, or a claim the shipped documents made that was not true.

### Fixed

- The managed Cloudflare Cache Rule instructed the edge to cache responses the
  origin marks `no-store, private`. `cloudflare.edge_ttl` now defaults to `0`, so
  the rule compiles as `respect_origin`. A positive lifetime still compiles as
  `override_origin`, but the expression is then narrowed to requests with no query
  string, because overriding the origin cannot be made safe for the unbounded set
  of query parameters the origin refuses.
- Trashing, unpublishing, or renaming a post never cleared its cached page. The
  `save_post` handler returns early for posts that are not publicly viewable, and
  a status change reaches it with the new status already applied, so a withdrawn
  page kept returning 200 for the rest of its stale window.
- Saving settings orphaned the whole cache. `generation` is part of the cache key
  and is bumped on every save; nothing deleted the now-unreachable entries.
- The capture pipeline ran when no page-cache drop-in was installed, which is the
  state directly after activation.
- A full purge deleted the `.htaccess` and `index.html` that keep the cache
  directory unreadable from the web.
- The private-fragments AJAX endpoint was registered even when the feature was off.

### Removed

- The WordPress revision limit control. It filtered `wp_revisions_to_keep`
  unconditionally at 5 on every activation, whether or not its own module was
  enabled, discarding revision history irreversibly on the next save.
- The `X-GT-Performance-Bypass` request header. Its reason code claimed a
  signature that nothing ever computed or verified, so any client could force a
  full uncached render on every request.
- The "Remove unused CSS" setting. The engine flattens native CSS nesting, drops
  `@import` stylesheets, prunes escaped utility class names, and runs during the
  visitor request; the damage was silent and cached. Define `GTPERF_UNUSED_CSS` in
  `wp-config.php` to run it anyway. It returns as a supported feature once
  generation moves out of the request and the differential safety net lands.
- Multisite activation. One compiled config and one cache root are shared across a
  network, so the last subsite to save decided every other subsite's cache
  behavior.

### Added

- `LICENSE`, and a `Third-party libraries` section disclosing the three bundled
  MIT libraries.
- `Update URI: false`, so nothing claiming the unclaimed `gt-performance`
  directory slug can push a package to existing installs.
- A golden-file HTML regression fixture, and a Plugin Check job in CI that runs
  against the built ZIP rather than the working tree.
- `Upgrade Notice` entries, including the one 1.0.4 shipped without.

### Changed

- Documentation no longer describes WordPress.org as the update authority. The
  plugin is not listed in the directory yet.

## 1.0.7 - 2026-09-01

### Fixed

- The `gtperf_private_island` shortcode now escapes the fragment fallback where it is returned. The fallback was already filtered through `wp_kses_post()` inside the fragment registry, so the rendered output is unchanged, but the escaping was applied in a different class and was not visible at the point of output.

### Changed

- The release package no longer contains the extensionless command line wrappers that Composer packages ship in their own `bin/` directories, such as `matthiasmullie/minify/bin/minifyjs` and `bin/minifycss`. WordPress.org does not permit them, and the minifier library itself is unaffected.
- `bin/build-package.sh` now fails the build when the staged package contains a file type the plugin directory does not permit, instead of producing an archive that is rejected on review.

## 1.0.6 - 2026-08-31

### Added

- Separate controls for the main feed and the secondary feeds. "Disable secondary feeds only" keeps `/feed/` serving and indexable while returning a 404 for comment feeds (site-wide and per post), category, tag, custom taxonomy, author, date, search, and post type archive feeds. "Remove secondary RSS feed links" keeps the main feed's discovery link in the document head and removes the rest. The existing all-or-nothing controls are unchanged and still win when enabled: "Disable every RSS feed" blocks the main feed too, and "Remove every RSS feed link" removes every discovery link.

### Changed

- The gauravtiwari.org WordPress preset now applies the two secondary-feed controls instead of removing every feed discovery link, so the main feed stays discoverable and indexable.

## 1.0.5 - 2026-08-27

### Fixed

- Uninstalling with data removal enabled deleted this plugin's options and tables but never touched the filesystem, so `wp-content/cache/gt-performance/` survived in full: cached HTML, generated CSS and JavaScript, logs, and both configuration files. `redis-config.json.php` holds a host, username, and password. The guard kept those unreadable over HTTP, but someone who asked for their data to be removed should not be left with credentials in `wp-content`. Uninstall now removes the directory, resolving the path the way `Core\Paths` does and confirming with `realpath()` that it still sits inside `wp-content` before deleting anything. `wp-content/cache` itself is left for other plugins.

## 1.0.4 - 2026-08-27

### Removed

- All upgrade compatibility carried since 1.0.1. `DropinRuntime::serve()` no longer loads `ConfigFile` on behalf of a drop-in published before 1.0.1, `Settings::compile()` no longer deletes the configuration files those releases wrote, `Database::install()` no longer drops their tables, and `uninstall.php` no longer lists their names.

### Upgrade note

- A site running 1.0.0 or earlier still has that release's generated `advanced-cache.php` on disk. It loads a fixed list of runtime files that predates `ConfigFile`, so on the first request after this update it raises a fatal from `wp-settings.php`, before WordPress can catch it, taking the front end and wp-admin down together. Replace the drop-in before or during the update. The build distributed from gauravtiwari.org carries a migrator that does this automatically; for any other route, run the standalone migration snippet first: https://gist.github.com/wpgaurav/03d61d313df00b4127db92393ed74681

## 1.0.3 - 2026-08-27

### Fixed

- The License screen's Activate, Deactivate, and Check buttons returned a blank page in the store build. Identical cause to the controls fixed in 1.0.2 - the handlers were still registered as `admin_post_gtp_license_*` while the buttons submitted `gtperf_license_*` - in a file the 1.0.2 sweep did not reach. The WordPress.org build has no licensing code and was never affected.
- `AdminActionWiringTest` now discovers every PHP file under `src/` instead of checking a hardcoded list of four. The hardcoded list was the same mistake the test exists to catch: it could not see the licensing module, which only ships in the store build, so 1.0.2 shipped believing the wiring was fully verified.

## 1.0.2 - 2026-08-27

### Fixed

- Every admin control in 1.0.1 returned a blank page. The 1.0.1 rename moved the action names the controls submit from `gtp_` to `gtperf_`, but left all 21 `add_action( 'admin_post_gtp_...' )` and `add_action( 'wp_ajax_gtp_...' )` registrations untouched, so nothing was hooked to the names being submitted. WordPress does not error in that case: it fires an action with no listeners and exits, which the browser renders as an empty response and which leaves no trace in the error log. Purge, Cloudflare connect/sync/preview/diagnose/token, Redis test and install, page-cache drop-in install, xCloud refresh, purge verification, Commerce Safety Lab, CSS training and regeneration, Fleet export and import, database cleanup, the admin-bar quick actions, the CSS report poll, and the Private Islands fragment endpoint were all dead.
- The rename missed these because it matched `\bgtp_`, and in `admin_post_gtp_purge` the `gtp_` is preceded by an underscore, which is a word character, so the boundary never applied. Hook strings are the one place that flaw could hide, and nothing compared the two sides.

### Added

- `AdminActionWiringTest` asserts that every action an admin control submits, every admin-bar action, and every AJAX action posted by the bundled JavaScript has a matching handler registered, and that no hook is registered under the retired prefix. A silent-blank-page regression of this shape now fails the test suite.

## 1.0.1 - 2026-08-26

### Security

- The compiled cache configuration and the Redis runtime configuration are no longer executable PHP. Both are stored as JSON behind a fixed `<?php exit; ?>` guard line and are read with `file_get_contents()` and `json_decode()`, never included. The guard keeps a direct web request from disclosing the Redis credentials on servers that do not honour `.htaccess`.
- The early cache drop-in and `RequestContext::fromGlobals()` now sanitize the request through one shared implementation. Control characters are stripped and every name and value is bounded before any of it reaches the `gt_performance_html` filter.

### Fixed

- Updating from 1.0.0 took the whole site down. The drop-in published by that release loads a fixed list of runtime files that predates `ConfigFile`, so the moment the new plugin files landed it fatally errored inside `wp-settings.php` — before WordPress exists to catch it — taking the front end and wp-admin down together with no way back except filesystem access. `DropinRuntime::serve()` now loads its own dependency when an older drop-in did not.
- Schema 3 renames this plugin's tables from the `gtp_` prefix to `gtperf_`. Without a schema bump the upgrade left the old tables in place and every queue, dependency, and CSS artifact query failed against a table that did not exist. The upgrade now creates the renamed tables and drops the superseded ones.
- `WpCacheConstant::enable()` rewrote an already-correct `WP_CACHE` line to an identical value, read the unchanged file as a failed update, and returned an error — which made `DropinInstaller::install()` delete the drop-in it had just published. Installing twice in a row disabled page caching.
- `DropinInstaller::syncVersion()` gated only on the version, so a migrated or restored site running the same release from a new path kept a compiled configuration naming the old directory. The drop-in found nothing to load and the site served uncached indefinitely without reporting anything. The gate now tracks the location alongside the version.
- Keyboard focus styles were pruned out of generated CSS. `:focus-visible` and `:focus-within` matched the shorter `focus` alternative in the dynamic-state pattern, leaving `-visible` and `-within` fused to the class name, so the rules matched nothing and were removed as unused.
- `RequestContext::fromGlobals()` did not unslash the superglobals, so any URL, query value, or cookie containing a quote hashed differently in WordPress than in the drop-in and could never produce a cache hit.
- `DropinInstaller::installedVersion()` captured the trailing period after the drop-in signature, which made every version comparison unequal and reinstalled the drop-in on each request.

### Changed

- Page-cache entry metadata is now `<hash>.meta.json` instead of a generated `<hash>.meta.php`. Because metadata no longer passes through opcache, the opcode-invalidation workaround is gone along with the stale-metadata window it covered on hosts running `opcache.validate_timestamps=0`.
- `advanced-cache.php` is a bundled file copied verbatim from `dropins/`, with only its version stamped in. It resolves the cache root from `WP_CONTENT_DIR` and the plugin directory from the compiled configuration, so no path is baked into the published drop-in.
- Every output buffer the plugin opens is closed explicitly through `Core\OutputBuffer`, on `shutdown` at priority 0, ahead of core's own `wp_ob_end_flush_all()`.
- Renamed the `GTP_` and `gtp_` prefixes to `GTPERF_` and `gtperf_` across constants, transients, AJAX actions, the cron schedule, the Private Islands shortcode, and the Redis key prefix. There is no compatibility shim: `wp-config.php` constants and any stored shortcode must use the new names.
- Updated `sabberworm/php-css-parser` from 8.9.0 to 9.4.0. Version 9 requires `thecodingmachine/safe` at runtime, which adds about 2.4 MB to the package and eagerly loads 79 function-definition files when the plugin bootstraps. That cost lands only on full WordPress requests, measured at roughly 5 ms; requests served from the page cache never load the plugin autoloader and are unaffected.
- The compiled configuration files are now `config.json.php` and `redis-config.json.php`. The names deliberately differ from the `config.php` and `redis-config.php` used up to 1.0.0: a drop-in left over from that release reads those paths with `require`, so pointing the new guarded files at the old names could have blanked every front-end response if the drop-in swap did not complete. Compiling also deletes the old files.
- `dropins/` is now covered by the coding-standards run.

## 1.0.0 - 2026-08-24

### Changed

- First stable release, distributed free through the WordPress.org plugin directory.
- Removed FluentCart licensing and the custom updater. Plugin updates now arrive through the normal WordPress.org update flow with no license key, activation, or weekly verification cron. The License tab, its admin-post actions, and the `gt_performance_verify_license` schedule are gone; deactivation and uninstall clean up state left by earlier licensed builds.
- Fleet Console no longer requires a license. Policy bundles are signed with a key derived from a shared fleet signing secret saved on each site (encrypted at rest) or defined as `GTPERF_FLEET_SIGNING_SECRET` in `wp-config.php`. The secret itself is stripped from exported bundles.
- Uninstall now also removes the fleet site identity and event log options.

## 1.0.0-rc.6 - 2026-08-20

### Fixed

- Fixed "Remove WordPress version" pinning every visitor to pre-update core assets. Dropping `ver` from a core script or stylesheet URL leaves an address that never changes across a WordPress release, so browsers and CDNs holding it under a long `max-age` keep serving the old bytes indefinitely. The version is now replaced with a stable site-specific hash instead of removed, which hides the release just as well and still busts the cache on every update. Symptom on a 7.1 upgrade: the new admin bar site icon rendered at full size because the cached stylesheet predated the `.site-icon` rules.

## 1.0.0-rc.5 - 2026-08-19

### Fixed

- Fixed the Operations cards sitting flush against the panel edge while the panel heading above them was inset, and fixed their rows sitting 40px apart against 20px columns. The grid carried no inset of its own, and each card is a panel in its own right whose 20px bottom margin stacked on the grid gap and hung a phantom band under the last row.
- Fixed the API token permission list and the "Install drop-ins, purge, and sync Cloudflare on the dashboard" link hanging outside the panel inset. The link now uses the existing `.gtp-inline-link` treatment, matching "View release history".
- Fixed `.gtp-inline-link` never picking up the narrow inset at the mobile breakpoint. Its override sat in a media block declared earlier in the file than the rule it was meant to override, so source order silently discarded it.

### Changed

- The panel inset is now a single `--gtp-inset` token, 24px normally and 20px under 782px, replacing 26 hard-coded values and five per-class media overrides. Because the token is redefined on `.gtp-admin` rather than on each block, a rule declared later in the file can no longer defeat the responsive override, which is the defect behind the mis-inset link and permission list. Adding a new block to a panel now means using the token instead of remembering to register the class in two places.

## 1.0.0-rc.4 - 2026-08-19

### Fixed

- Fixed the "Other cache rules that also match this site" block rendering at three different left offsets. The heading had no rule at all, so it fell back to the browser default and hung outside the panel inset; the note carried the standard 24px inset; and the conflict list carried none. The heading now uses `.gtp-subhead`, the list is inset to match its siblings, and both pick up the 20px inset at the mobile breakpoint. The default `1em` heading margin stacking on top of the note's own 20px padding also left an oversized gap, which is now collapsed.
- Fixed the "Or create it automatically" heading inside `.gtp-operation-panel` inheriting browser default type and margins. It now shares the 14px heading rule already used by the preset and database-result headings.

### Changed

- Admin notices are now a compact status pill instead of a full-width WordPress notice bar. When a failure carries an upstream reason, the pill gains a "Why?" disclosure that opens the detail in an anchored popover rather than pushing the page down. The popover is anchored to its own pill rather than promoted to the top layer, so it lands in the right place without depending on CSS anchor positioning, and it light-dismisses on outside click or Escape. Dismissing removes the `gtperf_notice` query argument instead of hiding the node, so a reload cannot resurrect a notice that has already been read.

## 1.0.0-rc.3 - 2026-08-18

### Fixed

- Fixed Cloudflare cache rule synchronization failing outright on any site with more than one bypassed query parameter. `RuleExpression::compile()` emitted a separate `concat("&", http.request.uri.query)` per parameter, and Cloudflare rejects an expression that calls `concat` more than once (error 20127), so every sync returned HTTP 400 and the managed rule silently stopped updating. Each parameter now compiles to an equivalent `starts_with()` plus `contains` pair that calls no rationed functions.
- Fixed the managed rule permanently reporting drift on plans that do not support custom cache keys. A custom cache key is an Enterprise capability, so the write only lands after `RuleManager` strips it, but `RuleCompiler::rule()` kept compiling the ideal rule for comparison. Drift was measured against a shape Cloudflare can never store and no amount of syncing cleared it. Comparison now uses the shape the plan accepts, while a sync still attempts the ideal rule so an upgraded plan heals itself.
- Fixed cache rule conflict detection ignoring rules that never name a hostname. A catch-all expression such as `true` applies to every hostname in the zone and was reported as zero conflicts.
- Fixed a fatal error in the connection check on zones with no cache ruleset yet, where a `WP_Error` was indexed as an array.

### Added

- Cloudflare API failures now report the reason Cloudflare gave, including its numeric error code and any nested error chain, instead of collapsing every failure into one generic sentence. Requests that never reached Cloudflare are reported separately from requests Cloudflare rejected.
- Added a Cloudflare connection check that walks integration state, edge ownership, credentials, authentication, zone lookup, and cache rule read and write in order, and names the stage that failed with the reason. The write stage rewrites the managed rule with its own current contents, so it proves the write path without changing anything.
- Added an API token panel listing the exact permissions the integration needs, a Cloudflare token-creation template link, and optional automatic creation of a zone-scoped token when a Global API Key is on file. A newly minted token is exercised before it replaces working credentials, because Cloudflare reveals a token secret only once.
- The rule plan panel now lists overlapping rules with their expressions and reports whether a custom cache key was applied.

### Changed

- A failed synchronization now still records the live rule plan, so the screen reflects current zone state instead of appearing never to have run.

## 1.0.0-rc.2 - 2026-08-17

### Fixed

- Fixed a fatal "Allowed memory size exhausted" error when updating the plugin. `Updater::clearCache()` runs on `delete_site_transient_update_plugins` and then deletes an update transient of its own, which fires the generic `deleted_site_transient` and `deleted_option` hooks; any listener that refreshes the plugin update cache in response re-entered the deletion hook, and the two recursed until PHP ran out of VM stack. `clearCache()`, `injectUpdate()`, and the remote fetch in `metadata()` now each hold a re-entry guard.
- Stopped `Updater::metadata()` from repeating the license-server request when the update transient filter re-enters before the first response is cached.
- Fixed the Redis object-cache drop-in reporting a successful delete for a key it never held. Core's `WP_Object_Cache::delete()` returns false in that case, and `delete_site_transient()` fires the generic `deleted_site_transient` hook only on a true result, so the unconditional answer re-dispatched that hook on every repeat deletion. This is what kept the update-cache recursion above from settling on sites where the drop-in is installed without a reachable Redis server.

## 1.0.0-rc.1 - 2026-08-16

### Added

- Added xCloud Public API site discovery, host-cache status and purge routing, independent Cloudflare Enterprise detection, explicit edge ownership, and requested 12-hour traffic reporting.
- Added safe enable-time profiles for Cloudflare, xCloud, static CDN rewriting, Redis, compatibility safeguards, Private Islands, and Fleet while preserving credentials, custom endpoints, and other non-empty provider values.
- Added feature-level EWWW Image Optimizer ownership for next-generation formats, Easy IO, lazy loading, and missing dimensions without disabling complementary upload compression.

### Changed

- Moved WebP and AVIF generation out of media-upload requests and split each source and registered image size into its own durable background job. Image work now runs ahead of cache preloads while keeping cache purges first.
- Added generic CDN and Cloudflare-specific no-store directives to private, commerce, feed, authenticated preview, and Private Islands responses.

### Fixed

- Prevented large multi-image uploads from spending the full PHP execution window generating every modern-format variant synchronously. Existing targets are skipped, duplicate physical sub-sizes are deduplicated, and queued jobs recheck ownership before writing.
- Prevented GT Performance and EWWW from generating, rewriting, lazy-loading, or dimensioning the same images when EWWW or Easy IO owns the corresponding feature.
- Blocked direct Cloudflare synchronization and duplicate purge routing while an enabled xCloud edge layer owns the cache, and failed closed when xCloud Enterprise exposes analytics but no token-authenticated purge mutation.

## 1.0.0-beta-9 - 2026-08-04

### Added

- Added recommended enable-time profiles for Cloudflare, xCloud, static CDN rewriting, Redis, compatibility safeguards, Private Islands, and Fleet.
- Profiles fill missing values, select safe dependent options, and keep existing credentials, custom endpoints, and provider-specific non-empty values intact.

## 1.0.0-beta-8 - 2026-08-04

### Added

- Added xCloud Public API site discovery, encrypted credentials, host-cache status and invalidation, requested status refresh, WP-CLI controls, and automatic routing from GT Performance origin purges.
- Added separate detection and 12-hour traffic reporting for xCloud's paid Cloudflare Enterprise add-on. GT Performance now treats an active xCloud edge as the sole edge owner and blocks direct Cloudflare rule synchronization and duplicate purge calls.

### Security

- Kept xCloud's free Edge Full Page Cache and Cloudflare Enterprise add-on on distinct code paths. Because xCloud's current Public API token does not authenticate the dashboard-only Enterprise purge mutation, the integration fails closed and never substitutes the unrelated broad host `purge-all` endpoint.
- Added `CDN-Cache-Control: no-store` and the higher-priority Cloudflare-specific no-store directive to every GT Performance private response as defense in depth. Live testing found xCloud's current Enterprise Edge Page Caching rule overrides those origin directives; the tested commerce-safe configuration therefore leaves that page-cache option off while retaining static caching and the add-on's other features.

## 1.0.0-beta-7 - 2026-08-04

### Added

- Added URL-specific and site-wide regeneration controls to CSS Reports. URL regeneration invalidates every delivery-mode report for that page, purges its origin and connected edge cache entries, and warms it immediately. Site-wide regeneration advances the settings generation, marks existing reports stale, purges the full page cache, and uses the normal preload queue.
- Extended stylesheet exclusions to match WordPress inline style IDs as well as external URLs, and added automatic server-side pruning exclusions for active FluentCart, Easy Digital Downloads, and WooCommerce application styles.

### Fixed

- Preserved hexadecimal CSS escapes such as `\\e800` and `\\f0e1` through parsing and HTML serialization. Inline used CSS no longer turns icon-font glyphs or other escaped `content` values into literal numeric entities.
- Preserved the original cascade order when collecting external and inline styles, ignored `noscript` fallbacks, treated asynchronous `media="print"` loaders that promote themselves to `all` correctly, and parsed each stylesheet independently so one parser-hostile source cannot alter following stylesheets.
- Kept custom-property definition blocks as dependencies, expanded supported dynamic pseudo-classes and state attributes, and expanded trained compound selectors into reusable ID and class fragments so runtime states remain protected when selector order differs.
- Made authenticated used-CSS previews bypass page and edge storage while still executing the production optimization pipeline.

## 1.0.0-beta-6 - 2026-07-31

### Fixed

- Made Redis object-cache writes request-local immediately, changed `add()` and `replace()` to atomic Redis `NX`/`XX` operations, and honored forced backend refreshes. Owned outdated object-cache drop-ins now update atomically on plugin boot without touching foreign drop-ins, then clear the exact `alloptions`, `notoptions`, and `cron` option-cache entries.
- Added a Doctor warning for materially overdue scheduled events when request-driven WP-Cron is disabled. The warning provides a host-specific five-minute external `flock` runner and does not change `DISABLE_WP_CRON`.
- Fixed `wp gt-performance cloudflare purge`, which previously fell through to Cloudflare rule synchronization without purging anything. It now supports a full-zone purge or one exact `--page-url`, reports Cloudflare API failures, and exits non-zero on invalid input.
- Rejected unknown cache, queue, Cloudflare, database, and fleet actions before constructing services or performing work. Empty or malformed explicit URLs can no longer degrade into unintended full purges, nonnumeric queue limits now fail instead of processing an arbitrary batch, and action-specific options are no longer silently ignored.
- Corrected the WP-CLI option documentation for action-based command families so WP-CLI can validate and display their positional actions consistently.

## 1.0.0-beta-5 - 2026-07-26

### Changed

- Moved the everyday operations — purge GT cache, Cloudflare sync, and the two drop-in installers — from the Tools tab onto the dashboard. Purging after a content change no longer takes a detour, and the installers are visible during setup, which is exactly when they are needed. Tools keeps runtime drop-in status and database maintenance. Each operation now remembers which screen it was run from and returns there instead of always landing on Tools.

### Fixed

- Stale pages are now rebuilt instead of being served indefinitely. Nothing regenerated an entry between `fresh_ttl` and `stale_ttl`: the drop-in served the stale body and exited, and a preload request received that same stale body, so the only escape from the stale window was `stale_until` expiring. A live site measured 1,011 of 1,023 cached pages stale, median age 14.3 hours. The queue now sweeps for stale entries on each scheduled run and enqueues preloads, and the drop-in treats a stale entry as a miss when the request carries `X-GT-Preload`, so those preloads actually rebuild the page. Batches are capped at 5 per run — matching what the queue drains per tick, since `enqueue()` does not deduplicate — and skipped entirely while a preload backlog is still pending, so the job table cannot grow faster than it clears. The cap is filterable via `gt_performance_revalidate_batch`.
- Fixed the Redis object cache silently flushing nothing. `flush()` and `flush_group()` build a `SCAN MATCH` pattern from the key prefix, which defaults to `WP_CACHE_KEY_SALT` — a random string that regularly contains `[`, `?`, or `*`, all glob metacharacters. An unclosed `[` makes the pattern match zero keys, so both calls deleted nothing and still returned `true`; a live site's `wp cache flush` reported success while the entries stayed in Redis. Literal prefixes are now escaped before use in a pattern.
- Re-arm the queue cron when the scheduled event is missing. `Activator` schedules it once at activation and nothing restored it if it was later lost, which stops the queue permanently: purges never preload, warms never run, stale pages are never rebuilt. A production site was found with the event absent and jobs pending for seven days.
- Invalidate a rebuilt page's metadata in the opcode cache. The drop-in reads metadata with `include`, so a refreshed entry could be read back with its previous timestamps until opcache revalidated — and never, on a host running `opcache.validate_timestamps=0`.
- Wrapped the Redis object cache's `SCAN` loop in the same error handling every other Redis call already had. It was the one unguarded call in the drop-in, so a mid-scan disconnect raised an uncaught `RedisException` through group flushes and took the request down with a fatal instead of degrading to a cache miss. The loop is now bounded as well, so a driver that returns without advancing the cursor cannot spin.

## 1.0.0-beta-4 - 2026-07-23

### Added

- Added configurable automatic cache clearing when public posts, pages, products, and custom post types are published or updated, with related-page, post-only, full page-and-edge cache, and disabled modes.
- Expanded the recommended related-page purge to cover author and public taxonomy archives in addition to the post, homepage, and post-type archive.

### Fixed

- Stopped WordPress revision cleanup from purging the homepage through the real-content deletion hook, so post-only and disabled publishing policies retain their intended scope.

## 1.0.0-beta-3 - 2026-07-22

### Added

- Added automatic compatibility detection and JavaScript exclusions for Independent Analytics, Burst Statistics, Koko Analytics, Matomo Analytics, WP Statistics, Site Kit by Google, MonsterInsights, ExactMetrics, and PixelYourSite.

## 1.0.0-beta-2 - 2026-07-22

### Fixed

- Treated an empty or whitespace-only `Authorization` server variable as absent so compatible hosts can still cache anonymous requests, while preserving the cache bypass for real credentials.
- Normalized panel, field, action, report, and responsive spacing across the settings interface and removed typographic shifts from active navigation states.

### Added

- Added a separate origin-pull CDN module that rewrites same-site static URLs to an HTTPS CDN base only for explicitly selected file extensions; third-party URLs, HTML/API routes, data URLs, and unselected types remain untouched.
- Added cache invalidation when CDN settings change, plus controls for images, styles, scripts, fonts, media, and downloadable files.
- Added direct links to Cloudflare's official scoped-token, Global API Key, and Zone ID documentation next to the relevant fields.

## 1.0.0-beta-1 - 2026-07-22

### Fixed

- Deferred the public `Cache-Control` header until after response validation, so a `Set-Cookie`, a non-200 status, or `DONOTCACHEPAGE` introduced during rendering can no longer instruct a shared or edge cache to store a private page.
- Stopped deselected scheduled database-cleanup tasks (and other list settings such as bypass paths) from being silently restored on save; list settings are now replaced wholesale instead of merged index by index.
- Fixed commerce bypass-path matching so the canonical `/checkout` on no-trailing-slash permalink sites is protected exactly like `/checkout/`, at both the origin and in the compiled Cloudflare edge rule.
- Preserved inline `<script>` and JSON-LD content and removed the stray `<?xml>` node that the DOM-based CSS, font, and embed optimizers could ship — and cache — on every optimized page.
- Corrected root-relative `url(/…)` rebasing in collected stylesheets so background and font references resolve against the site origin.
- Accepted `CSS.escape()`d utility-class selectors (for example Tailwind `md:flex`, `w-1/2`) in CSS Training Mode so utility-class themes no longer publish empty safelists.
- Canonicalized the Cloudflare managed-rule fingerprint so key-order differences in Cloudflare's response are no longer misread as drift and no longer trigger a redundant sync on every run.
- Anchored the Cloudflare bypass query-parameter rule to a parameter boundary so a short parameter such as `s` no longer excludes unrelated parameters like `utms`.
- Sent `Vary: User-Agent` when a separate mobile cache variant is active, and honored the "stale if error" duration in the emitted `Cache-Control`.
- Bounded queue-table growth by pruning terminal jobs on the queue cron, and web-hardened the cache and log directories.
- Stamped the advanced-cache drop-in with the plugin version and regenerated it automatically after an update.
- Removed the non-functional "Cache logged-in users" control.
- Invalidated affected post, homepage, and archive caches when comments are inserted through the front end, REST API, WP-CLI, or lower-level WordPress APIs, and when they are edited, moderated, or deleted.
- Batched related URL purges into one edge notification and removed both desktop and mobile origin variants.
- Prevented the deferred cache-safety header from rejecting the plugin's own otherwise cacheable response.
- Renamed the WP-CLI target option to `--page-url` so it no longer collides with WP-CLI's reserved `--url` site selector.
- Preserved explicit URL ports in diagnostic and purge cache keys so local Studio sites target the same artifact as live requests.
- Applied the configured Cloudflare edge lifetime to the managed Cache Rule instead of always respecting the origin value.
- Restricted sitemap warming to same-origin, cache-eligible URLs and bounded sitemap response sizes.
- Discarded obsolete and foreign settings keys during merges, and removed non-functional Gravatar self-hosting and font-preload controls.

### Added

- Sitemap-driven cache warming: after a full purge, eligible URLs discovered from the WordPress sitemap are queued for background preloading (controlled by the new `cache.preload` toggle and bounded by `cache.preload_max_urls`), with a matching `wp gt-performance cache warm` command.
- Accessible brief tooltips and clearer labels for cache lifetimes, exceptions, Cloudflare, optimization, Akismet, and Redis controls.

## 0.1.0-alpha.12 - 2026-07-19

- Added Explain This Page and verified purge receipts for deterministic cache diagnostics.
- Added the Cloudflare Free rule compiler and Commerce Safety Lab.
- Added unused-CSS training, staged rollout, review, publishing, and rollback controls.
- Added signed Private Islands for dynamic commerce fragments.
- Added the secure 25-site Fleet policy-console foundation.
- Made release publication compatible with private GitHub repositories by retaining checksums and workflow artifacts while conditionally skipping unavailable provenance attestations.

## 0.1.0-alpha.11 - 2026-07-19

- Added Explain This Page diagnostics backed by the production cache policy, deterministic cache keys, local artifact metadata, and compiled edge expectations.
- Added Verified Purge with bounded redacted receipts covering origin removal, post-purge response fingerprints, response privacy signals, and Cloudflare cache headers.
- Added a Cloudflare Free rule compiler with exact expression preview, managed-rule drift detection, competing-rule overlap warnings, expected create/update/noop operation, and ten-rule capacity reporting.
- Added Commerce Safety Lab in-memory policy simulation and safe read-only checks for FluentCart, Easy Digital Downloads, and WooCommerce dynamic routes.
- Added administrator-only Unused CSS Training Mode with bounded selector observation, one-hour sessions, candidate review, publication, rollback, and deterministic 0/10/25/50/100 percent rollout cohorts.
- Added opt-in signed Private Islands for explicitly registered cart-count, account-link, and developer fragments with private no-store responses and fail-closed public fallbacks.
- Added a 25-site Fleet Console foundation using five-minute, one-use, license-signed configuration bundles with recursive credential removal and no remote-code capability.
- Added standalone Safety Lab and Fleet screens, Cloudflare plan UI, administrator-bar shortcuts, WP-CLI commands, and focused unit coverage for the new policy and signing layers.

## 0.1.0-alpha.10 - 2026-07-19

- Added stable, port-safe license identities for Studio and other local WordPress sites so FluentCart protected-package signatures are not corrupted by `localhost:PORT` values.
- Kept ordinary production site URLs unchanged and added a `gt_performance_license_site_url` filter for deliberate identity overrides.

## 0.1.0-alpha.9 - 2026-07-19

- Moved the live manual database scan and selectable cleanup interface from Optimization to Tools while keeping scheduled database maintenance in Optimization.
- Returned completed manual cleanup actions to the Tools tab and removed the redundant one-click cleanup card.
- Added compatible `WP_REDIS_*` configuration for Till Krüss Redis Object Cache, including ACL credential arrays, TCP/TLS and Unix sockets, database, prefix, timeouts, legacy key salt, and the emergency disable constant.
- Kept `GTPERF_REDIS_*` constants as highest-precedence overrides and added isolated configuration coverage for compatibility and precedence.
- Published the $199 FluentCart product page with verified direct-checkout links, one-site lifetime-license details, and responsive purchase sections.

## 0.1.0-alpha.8 - 2026-07-19

- Added encrypted FluentCart license activation, weekly verification, deactivation, version checks, protected package delivery, and native WordPress update metadata.
- Added a dedicated License tab with masked credentials, plan and expiration details, on-demand update checks, wp-config.php license support, and administrator-friendly errors.
- Added response normalization and tests for FluentCart's live top-level updater response, malformed versions, unsafe package URLs, and the no-update path.
- Fixed activation-time registration of the plugin's custom queue and weekly cron schedules.
- Added the GT Performance FluentCart product identity plus reproducible WordPress directory, storefront, and social assets that embed real WordPress Studio screenshots.

## 0.1.0-alpha.7 - 2026-07-19

- Added partial and validated regular-expression matching to the unused-CSS selector safelist.
- Added automatic Perfmatters feature ownership plus compatibility reporting for Akismet, Jetpack, Jetpack Boost, FlyingPress, WP Rocket, LiteSpeed Cache, WP Super Cache, W3 Total Cache, Autoptimize, and Core Forms.
- Added Jetpack visitor-state cache bypasses and automatic Akismet/Jetpack CSS and JavaScript safeguards.
- Added encrypted Redis credentials, TLS, ACL username, logical database, persistent connection, prefix, timeout, health-test, guarded drop-in installation controls, and documented `wp-config.php` constants for every connection setting.
- Added administrator-bar actions for current-page purge, cache warming, CSS regeneration, full page/edge purge, object-cache flush, Redis testing, and report/settings navigation.
- Added a pinned GitHub Actions release flow with version-surface validation, changelog release notes, PHP quality gates, verified ZIP packaging, SHA-256 checksums, artifact provenance attestations, and automatic prerelease publishing.

## 0.1.0-alpha.6 - 2026-07-19

- Replaced the CSS delivery dropdown with explicit Generated file, Inline all used CSS, and Critical inline + remaining file choices.
- Made the dynamic-state preservation setting control hover, focus, open, checked, and related selector retention.
- Changed Hybrid mode to fall back to a generated file when critical CSS exceeds the inline budget.

## 0.1.0-alpha.5 - 2026-07-19

- Added one-click Maximum Impact, Balanced, and Frequently Updated cache lifetime presets that populate the existing fields without saving unexpectedly.
- Made the default cache profile one hour fresh, 24 hours retained for visitors and bots, 24 hours stale-if-error, and five minutes in visitor browsers.
- Added the active gauravtiwari.org Perfmatters request-removal configuration as an optional one-click WordPress baseline.
- Added WordPress controls for Dashicons, XML-RPC, jQuery Migrate, RSD, shortlinks, feed links/feeds, self-pingbacks, REST discovery/access, Google Maps, password strength, comments, author URLs, global styles, separate block styles, autosaves, Heartbeat, and revisions.
- Added manual database scans and selectable optimization for revisions, auto-drafts, spam and trashed comments, trashed posts, expired/all transients, and reclaimable table space.
- Added saved scheduled database tasks with daily, weekly, and monthly recurrence plus bounded revision retention.
- Removed Core Web Vitals collection, its frontend measurement script, REST endpoint, settings, and storage table.

## 0.1.0-alpha.4 - 2026-07-19

- Replaced the Settings submenu with a standalone top-level GT Performance admin at `admin.php?page=gt-performance`, while redirecting the legacy URL.
- Added focused Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, CSS Reports, and Tools sections with responsive WordPress-native controls.
- Exposed cache query/path/cookie exceptions, CSS safelists and stylesheet exclusions, JavaScript exclusions and delay patterns, and media selector exceptions.
- Exposed the remaining cache, CSS, JavaScript, media, font, database, WordPress cleanup, Cloudflare, and commerce settings.
- Added persistent unused CSS generation reports with live processing, ready, stale, skipped, and failed indicators plus delivery, size, savings, duration, and errors.
- Replaced internal action codes with friendly success, warning, and error notices, including safe fallback copy for unknown codes.
- Refined desktop and mobile spacing and replaced thick status-card borders with thin boundaries and soft status contrast.

## 0.1.0-alpha.3 - 2026-07-18

- Added Core Forms compatibility that selectively removes its globally emitted voter cookie only on pages without polls.
- Preserved the voter cookie and cache rejection on actual poll pages so voting identity and duplicate-vote protection remain correct.

## 0.1.0-alpha.2 - 2026-07-18

- Added Cloudflare authentication modes for scoped API tokens and legacy Global API Keys with account email.
- Added a domain setting for zone discovery, retained optional direct Zone ID configuration, and added matching `wp-config.php` constants.
- Encrypted Global API Keys at rest and covered both Cloudflare authentication header schemes with unit tests.
- Fixed false `WP_CACHE` custom-declaration errors caused by comments, supports existing single-line declarations, and preserves the exact declaration for restoration.

## 0.1.0-alpha.1 - 2026-07-18

- Added an atomic origin page cache, early drop-in, eligibility policy, response validation, targeted invalidation, stale retention, and durable preload/purge queue.
- Added Cloudflare Free Cache Rule management, scoped token encryption, zone discovery, URL/full purge, drift state, backup, and portable cache-key fallback.
- Added FluentCart, Easy Digital Downloads, and WooCommerce cache bypass and product invalidation adapters.
- Added server-side unused CSS analysis with file, inline, and critical-inline-plus-file delivery.
- Added JavaScript, media, image variant, YouTube, Google Fonts, database, WordPress bloat, and Redis modules.
- Added a native settings screen, WP-CLI commands, redacted diagnostics, PHPUnit coverage, PHPStan, WPCS, CI, Playground smoke validation, and release packaging.
