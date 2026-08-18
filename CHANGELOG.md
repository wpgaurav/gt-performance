# Changelog

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
- Kept `GTP_REDIS_*` constants as highest-precedence overrides and added isolated configuration coverage for compatibility and precedence.
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
