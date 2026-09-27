# 1.0.15 development: lazy library loading — 2026-09-27

Not a release. gatilab.com runs `1.0.15-dev.14` (sha256 `772b25ca…4b934af0e`); anantamias.com stays on dev.12.

- **Before:** every request included Composer's autoloader and, through it, 201 files of GT Performance's vendor directory on gatilab.com (the Safe library's function files and their PHP-version-specific copies), defining 1,176 functions. A warm-OPcache benchmark of the plugin's bootstrap took 10.7 ms with 175 library files; the new one takes 0.25 ms with none.
- **After:** a request on gatilab.com includes 0 library files. Uncached `/wp-json/` over 25 origin requests: median 151.6 ms (163.8 ms before), p25 139.4 ms (148.7 ms before), p75 185.6 ms (176.0 ms before) on a shared host, so read the spread as noise around a saving of about 10 ms.
- **The libraries still load when needed:** a CSS build of /fluentcart-pickup-scheduling/ completed in 0.59 s and visitors were served its four generated blocks; the JavaScript minifier loaded and minified through the same path. A unit test boots the real plugin file in a fresh PHP process and checks that nothing loads at boot or when the pruner is built, that pruning and minifying still work, and that Composer's loader does not stay in the autoload queue. Another checks the namespace list against composer.lock.
- 512 unit tests / 2,067 assertions pass; PHPCS and PHPStan are clean.

---

# 1.0.15 development: gatilab.com and anantamias.com on dev.12 — 2026-09-27

Not a release. Both sites run `1.0.15-dev.12` (sha256 `c29614b4…f46a0185`), deployed with file parity, raw-settings, and drop-in checks. Unused CSS is on at both.

- **gatilab.com, /fluentcart-pickup-scheduling/.** On dev.10 its build was ready (38 KB to 20 KB) but no visitor was ever served it, and a new build was queued every few minutes. Two uncached renders differed only in Akismet's `ak_js` value, which is random per render. Its earlier builds also failed three times: Cloudflare and Hostinger answered the constant build URL from cache (`cf-cache-status: HIT`, `x-cache-status: HIT`), so GT never ran; 406 of gatilab's 407 failed CSS jobs carry that error.
- **Cascade order.** With dev.11, visitors were served the build, and the page changed visibly: smaller body text and orange author links (6.8% of pixels on desktop, 23.5% on mobile), because `global-styles-inline-css` moved from before the theme's `md-globals-css` to the end of `<head>`. dev.12 places each run where it stood: four generated blocks around the theme's stylesheets, and the page is pixel-identical to the original at 1280 and 390 px.
- **anantamias.com.** /siachen-glacier/ (with its script-built table of contents, kept by the browser check's results) and /admissions/ are pixel-identical at both widths on dev.12, and visitors are served the build.
- **Fetch important CSS classes, WordPress Studio lab.** The check reports "Checking pages: N of M done", then "Saving what was found…", then what it found and a link to CSS Status (or to turn unused CSS on). Only post types that visitors can view and that are not hidden from search are checked.
- 510 unit tests / 1,857 assertions pass; PHPCS and PHPStan are clean.

---

# 1.0.15 development: Bricks on anantamias.com, script-built classes — 2026-09-26

Not a release. anantamias.com (Bricks 2.4.1, Bricks child theme, WordPress 7.1.2, PHP 8.5.10) runs `1.0.15-dev.10` (sha256 `1a258f7c…6b46f7016`), deployed with the same file-parity and raw-settings checks as gatilab.com. Unused CSS stays off there; every build below ran in a separate WP-CLI process with CSS forced on for that process only, and its reports, reuse entries, and build locks were deleted afterwards. Rollback stage: `/var/tmp/gtperf-ai-20260926-e6f66498`.

- **Bricks stylesheets are not optimized as the site is configured.** Every Bricks and theme stylesheet is served from `r2.anantamias.com`, which is not the site or GT Performance CDN host, so only inline styles were pruned (16 KB to 7 KB per page). Rendered in Chromium against the live assets, 8 of 9 pages were pixel-identical at 1280 and 390 px.
- **With the R2 host allowed, `1.0.15-dev.8` broke Bricks pages.** The `@layer` statement opening `frontend-light-layer.min.css` nested Bricks' base layer inside `.aligncenter`: screen-reader text became visible, navigation links underlined, spacing collapsed (8.8% of pixels changed on desktop, 17.7% on mobile). `dev.9` fixed it: all 9 pages pixel-identical, and 34 KB more CSS removed per page (for example 242 KB to 148 KB on /admissions/), because Ionicons and the Bricks framework file were finally pruned.
- **Script-built components.** /siachen-glacier/ lost its table of contents styling and ad rail layout (the desktop page grew 4,178 px): both are built by scripts after load. The browser check learned 15 classes, 1 ID, and 19 selectors for Posts (`.sp-toc li`, `.sp-toc a:hover`, `body.aca-has-rail .aca-rail`, …) and dropped 41 per-post heading IDs. With those, a build of the page through the real request path was pixel-identical at both widths, and its CSS went from 356 KB to 185 KB.
- **Admin flow, WordPress Studio lab.** A lab-only mu-plugin builds a table of contents on load, a widget when scrolled into view, and a bar only on narrow screens. Saving a safelist change started the check by itself; it learned exactly those classes and selectors for Posts and nothing for Pages, cleared its request, and rebuilt CSS. A visitor then received the rules: computed styles of all three components matched. Before the token fix, posts on the lab were never served their build at all.
- 507 unit tests / 1,840 assertions pass; PHPCS and PHPStan are clean.

---

# 1.0.15 development: page builder unused-CSS protection — 2026-09-26

Covers 14 builders: GT Page Blocks, Elementor, Bricks, Divi/Extra, Beaver Builder, Oxygen, Breakdance, WPBakery, Thrive Architect, Brizy, Kadence Blocks, Spectra, GenerateBlocks, and SiteOrigin.

- Runtime state classes were extracted from shipped front-end JavaScript (`classList.add/toggle`, `addClass/toggleClass`) for:
  - Elementor 4.3.2, Beaver Builder Lite 2.11.0.6, Kadence Blocks 3.7.11.1, Spectra 2.20.3, GenerateBlocks 2.4.1, and SiteOrigin 2.36.1, downloaded from WordPress.org;
  - Bricks, from the `bricks.min.js` served by anantamias.com.
- Divi, Oxygen, Breakdance, WPBakery, Brizy, and GenerateBlocks Pro use documented state prefixes. GT Page Blocks, whose live output on gatilab.com shows an inline `gt-page-block-css` with runtime `.visible` and `[data-theme]` states, and Thrive Architect are excluded whole.
- 473 unit tests / 1,748 assertions pass. Safelist entries are all valid. Through the real pruner, builder state rules survive while unused rules (and `.interactive-*` lookalikes) are removed. The collector skips page-block inline CSS while still optimizing other inline styles.
- gatilab.com `1.0.15-dev.3`: GT Page Blocks and GenerateBlocks were detected, and their exclusions and safelist flow through the real filters. Unused CSS is currently off on that site, so this is not yet exercised on live pages.

---

# 1.0.15 development: M5–M7, gatilab.com stress testing — 2026-09-26

Not a release. Deployed to gatilab.com as `1.0.15-dev.2` (sha256 `cb106b3c…31e71`); receipt and rollback in `__work/deploy-m2-20260926/receipt.md`.

Local verification (PHP 8.5.10, WordPress 7.1, MariaDB 13.0.2):

- Coding standards and PHPStan are clean; 469 unit tests / 1,717 assertions pass. Release metadata is consistent at 1.0.15.
- The real-database suite passes 59 tests / 515 assertions: `OperationsTest` (M5), `FrontendOptimizationTest` (M6, real core speculation rules and core-printed scripts), and `AdvisorTest` (M7, a scripted provider, and a second process holding the in-flight lock).
- The checks found and fixed:
  - `true` as a return type, which is invalid on the supported PHP 8.1;
  - the `%` unit escaping measurement checks;
  - the `jquery` alias's inline code being ignored by the first script planner. Browser-caught; a regression test now fails if the alias is skipped.

Studio (WordPress 7.1.2, SQLite):

- MCP operate journey: 14 abilities discovered; the purge result reported origin, edge, and public responses; replay returned the same operation; the proposal was applied through the CLI; a disallowed field was rejected by schema.
- Browser runs (desktop MISS/HIT, mobile touch) on a fixture with jQuery plus inline "after" code, a dependent script, a delay-selected script, and a consent `text/plain` script: 0 console errors; the jQuery initializer ran; the delayed script loaded only after the first key press or touch; the consent script was untouched; the hero preload was present; speculation rules excluded `/checkout/*`.
- All admin tabs measure exactly 390 px at a 390-pixel viewport, after constraining the adviser's controls. The editor box appears on viewable post types only.
- AI provider: the Anthropic provider plugin was detected as registered but not configured, and the adviser reported that clearly. **No live provider request was made** (no key available).

gatilab.com:

- Schema 6 → 7 migrated. Frontend with defer on: 5 real pages had identical deferred-script counts and 0 JavaScript errors before and after.
- Settings history attributed the two wp-admin changes made there on 2026-09-26: agent access off → read, and unused CSS on → off, both by administrator #3.
- Stress runs against the local origin (at most 8 concurrent, watchdog on load 4 and 2% errors; the host is shared with other production sites):
  - Hit path: 595/600 at 13.5 ms p50, 65.6 ms p99.
  - Cold-miss bursts: every response 200, no fatal errors, every page stored, 0 duplicate dependency rows.
  - Queue: exactly one active runner, no job ran twice.
  - Health builds in 14 ms; dependents lookup takes 1 ms across 576 indexed pages.
- Intermittent 30 s stalls (0.5–1% of PHP requests, higher in bursts) also occurred for a temporary one-line PHP file with no WordPress (3/600), so they come from the host's OpenLiteSpeed/lsphp layer. Static files never stalled. Recommendation: review gatilab's LSAPI child and connection limits in xCloud.

Not covered: a live AI provider request; named MCP clients against a production HTTPS site; PHP 8.1 and WordPress 6.6/6.9.0 runs; Plugin Check and distribution packages for this build.

---

# 1.0.15 version preparation — 2026-09-26

M1–M4 ship as 1.0.15, the first release for the WordPress.org directory. The slug `gt-performance` is approved; its SVN repository exists but has no trunk or tags yet, and the plugin information API still reports "Plugin not found". The version is 1.0.15 on every surface `bin/release-metadata.php` checks, and it passes. CHANGELOG.md has a dated 1.0.15 section (preparation date; refresh at release). readme.txt has the 1.0.15 changelog, upgrade notice, description, and FAQ. Nothing was committed to WordPress.org SVN, tagged, or published. Evidence for the milestones follows.

---

# 1.0.15 development, M3: configuration history and dependency-aware purge — 2026-09-26

Development candidates for 1.0.15, not a release. These builds were tested before the version bump, so their headers read 1.0.14.

Local verification on PHP 8.5.10, WordPress 7.1, and MariaDB 13.0.2:

- WordPress coding standards and PHPStan are clean; 451 unit tests / 1,536 assertions pass. New units cover the diff, portability, import rejection (foreign format, schema version, unknown, protected, and mistyped keys), history bounds (count, age, bytes), `saveChanges()` keeping a concurrent edit, the query classifier, and affected signatures.
- The real-database suite passes 41 tests / 351 assertions. `ConfigurationTest` covers exact restore with current credentials kept, stale-hash refusal, failed publication leaving settings and history unchanged, import, and cross-process lock contention. It exposed a real lost update: a writer that waited for the lock then read a stale per-request option cache and overwrote the first writer. Fixed by invalidating the option cache when the lock is taken. `DependenciesTest` covers membership, pagination, unrelated-page retention, reassignment, renames, reusable blocks, `get_posts()`, commerce stock, generation binding, foreign hosts, and the preview. Mutating term matching failed 3 of its tests.

WordPress Studio (WordPress 7.1.2, PHP 8.4, SQLite), real HTTP renders:

- A landing page with a category Query Loop, a reusable block, and Latest Posts recorded `post:18` (the block), `term:2`, the shown posts, the `get_posts()` results with `pt:post`, and the navigation fallback's `pt:page`/`pt:wp_navigation`.
- Editing a post the landing page never showed left it cached. Editing the reusable block purged it and it then served the new content; nothing was purged for this before. Moving a post out of the category purged the landing page and the old category archive. Unrelated pages stayed cached throughout.
- CLI history, dry-run, stale-hash refusal (exit 1), and restore all worked. An admin form save recorded an `admin` revision and left no lock row. Tools rendered at 390 px without overflow.

gatilab.com (candidates `1.0.14-m3-candidate` then `.2`, same parity, settings, and HTTP checks as before; receipt in `__work/deploy-m2-20260926/receipt.md`):

- Schema 5 → 6 migrated. Origin MISSes through the loopback recorded dependencies, and a purge preview for the newest post named home and `/blog/` as "shows post 1060682".
- The preview exposed a foreign author URL (`https://gauravtiwari.org/about/`) in the related purge. That URL was also being forwarded to this zone's edge purge. Fixed in candidate `.2` and verified there.

Not covered: FSE template parts and slug-referenced navigation, commerce listings rendered outside `WP_Query`, page-builder custom queries, and live Cloudflare purge of recorded dependents. No distribution package or Plugin Check run for these candidates.

---

# 1.0.15 development, M4: read-only MCP and gatilab.com test deployment — 2026-09-26

Development candidates for 1.0.15, not a release. These builds were tested before the version bump, so their headers read 1.0.14.

Local verification on PHP 8.5.10, WordPress 7.1, and MariaDB 13.0.2:

- WordPress coding standards and PHPStan are clean; 441 unit tests / 1,477 assertions pass.
- The real-database suite passes 27 tests / 279 assertions, including queue priority aging and `AbilitiesTest` on the real Abilities API. That test covers registration and category; output-schema validation of all seven abilities; administrator-only access with live off/read switching (`check_permissions()` codes, while `execute()` returns core's generic `ability_invalid_permissions`); closed, bounded, same-site inputs; secret-free settings with a hash that changes on save; cursor paging without payloads; and the core `/wp-abilities/v1` run route (200 for administrators, 401/403 otherwise).

MCP over real HTTP (Studio lab: WordPress 7.1.2, PHP 8.4, SQLite, official MCP Adapter 0.6.1 with its production dependencies):

- Anonymous `initialize` returned 401. An Application Password session without cookies initialized and listed the adapter's discover/get-info/execute tools.
- With agent access off, no GT ability was discovered, and get-info and execute were refused (`mcp.public!=true`).
- With access set to read-only through the normal settings save, all seven were discovered with their annotations. Health, status, explain-url, list-jobs, and get-settings (with hash) returned the envelope. A foreign URL and an undeclared `purge` argument were rejected.
- A subscriber's Application Password passed the adapter's login gate. Every GT execute was refused with the administrator-only message; the adapter still lists ability names to logged-in users.
- `/wp-json/…` and `?rest_route=…` forms of the MCP and abilities routes are both page-cache bypasses.
- Background cleanup on gatilab.com (1.0.15-dev.7, 2026-09-26): during a sitewide warm run (192 preloads pending), a manual expired-transients run started in 7 ms at priority 30 and completed on the next queue tick, about 30 s later. At the earlier priority 60, the same run sat queued for over 5 minutes behind the preloads.
- Plugin Check (2026-09-26, `wp plugin check` on the built WordPress.org package in the MariaDB fixture): no errors or warnings. The same check on the previous build (1.0.15-dev.6) reproduces the three reported findings (stable tag, `DependencyIndex.php:92` placeholder count, `Integration.php:50` unprefixed hook), so it would catch them. With a must-use plugin changing the adapter's default route, the AI & MCP tab showed `/wp-json/mcp/custom-route-probe`, and that route answered 401 (it exists and needs authentication).
- Background database cleanup (2026-09-26, Studio lab): with 462 revisions, 150 spam comments, and 60 trashed posts, **Run selected optimization** returned in 0.21 s instead of holding the request. The open Database tab showed per-task progress and reloaded with fresh counts about 2 s later; every count reached 0. `DatabaseCleanupTest` (6 tests) passes on MariaDB, and its purge test fails with the old purge behavior restored.
- Unused CSS status (2026-09-26, Studio lab): Hybrid with a 2 KB limit built five pages as "Ready, one file", recording 13.9–15.9 KB of critical CSS; CSS Status suggested 16 KB. At 16 KB, all five rebuilt with 14–15 KB inlined plus a remaining file, confirmed in the served HTML. CSS Status fits at 390 px after the hidden-heading fix.
- Tab restructure (2026-09-26, Studio lab): all 13 tabs (Dashboard, Page Cache, Optimization, CSS Status, Exceptions, Cloudflare, CDN, Object Cache, Database, Integrations, AI & MCP, Tools, License) render without PHP or console errors and measure exactly 390 px at a 390-pixel viewport. Saving on AI and Object Cache returns to the same tab and records no settings change, Test Redis returns to Object Cache, and Refresh status works on CSS Status. The tab row wraps to two lines at 1280 px with no tab hidden, fits on one line at 1600 px, and scrolls on phones. Tables inside panels draw only the panel's border; summary cards sit 20 px inside the panel edge. xCloud cache status is hidden while the xCloud integration is off.
- The admin panel rendered. All eight admin tabs now measure exactly 390 px at a 390-pixel viewport, after fixing hidden tooltips that widened the Cache and Integrations tabs. Tooltips still open on keyboard focus.

gatilab.com (WordPress 7.1.2, PHP 8.3.33, MariaDB/InnoDB, Hostinger + Cloudflare), WordPress.org channel. Every install passed preflight, exact file parity, an owned drop-in, an unchanged raw settings option, and HTTP 200 on sample pages. Backups, the table export, and rollback steps: `__work/deploy-m2-20260926/receipt.md`.

- Schema 3 → 5 migrated in one CLI pass on real data: 188 pending jobs keyed and 17 duplicates consolidated.
- Health surfaced a warm job starved for 447 hours by strict priority. This was fixed with aging plus a warm priority of 50 and verified: the job ran and oldest-due age fell to minutes.
- Real warm run: 1,183 URL targets from core, robots-declared, and redirected sitemaps. The first outcomes included `rebuilt`, `existing`, and 41 `edge_observed` (`cf_hit`), the first real-edge evidence for that state. One failure was a sitemap URL that 301s.
- M4 is installed with access off: abilities are registered and hidden, and anonymous access is denied. No MCP Adapter is installed there, so no remote MCP session was run against production.

Not qualified: MCP over HTTPS on a production host, WordPress 6.9.0 exactly, the adapter's STDIO transport, and specific MCP clients. No distribution package, Plugin Check run, or PHP 8.1 / WordPress 6.6 pass for these candidates.

---

# 1.0.15 development, M2: warming and health — 2026-09-26

The second roadmap slice on `main`, on top of the unreleased queue foundation below. It is not a release or deployment, and there is no MCP endpoint or AI-provider call.

Fresh verification on PHP 8.5.10, WordPress 7.1, and MariaDB 13.0.2 (InnoDB):

- Composer strict validation and release metadata passed; version metadata remains 1.0.14.
- WordPress coding standards, PHPStan, and 436 unit tests / 1,353 assertions passed. New units cover sitemap `lastmod`, robots.txt (including CRLF lines, which the first draft dropped), numeric `<loc>` values, preload outcome classification, health thresholds, stalled runs, export redaction, Site Health mapping, and sitemap-source sanitizing.
- The real-database suite passed 19 tests / 181 assertions, up from 13 / 107. `WarmingDatabaseTest` runs the actual queue runner with in-process HTTP fixtures. It covers redirected, nested, cyclic, broken, and foreign sitemaps; a redirect cycle fetched once; no fetch beyond five levels; private and search URLs excluded; recovery from a worker dying mid-discovery without refetching or duplicate targets; mobile variants against a five-entry budget (`capacity_limited`); an origin-stored page reported as `origin_ready`/`rebuilt` while an edge MISS with nothing stored stays `requested`; and 60,000 URLs capped at exactly 50,000 targets, at most two sitemap fetches per leased job, within bounded memory.

WordPress Studio qualification (WordPress 7.1.2, PHP 8.4, SQLite integration), on a fresh "GT Performance M2 Lab" site:

- Found and fixed: on SQLite, `GET_LOCK` returns `'1=1'` and locks nothing, so the M1 schema lock never succeeded and no plugin tables were created. A driver probe showed the rest of the SQL used here is emulated (dbDelta with an engine clause, table status, unique indexes, `INSERT IGNORE`, `UPDATE … LIMIT`, `FOR UPDATE`, `UTC_TIMESTAMP`, `DATE_ADD`). `NamedLock` keeps `GET_LOCK` on MySQL and uses expiring option rows via `INSERT IGNORE` on SQLite. A first draft used `add_option()`, which upserts and is not exclusive; that was replaced before running. On Studio, the lock was exclusive per name, independent across names, taken over only after expiry, and left no row after release. A web-spawned cron runner holding the runner lock correctly kept a concurrent CLI runner idle.
- A real HTTP warm run against the core sitemap (robots.txt pointed to the same sitemap and was deduplicated) read 5 sitemaps and recorded 17/17 URLs as `origin_ready`. The home page went first, then posts with recent `lastmod`, then archives. The run finished `complete`, and `curl` showed `x-gt-cache: HIT` afterwards. A full purge emptied the store, queued `warm_site` automatically, and restored `complete` with 17 `origin_ready` and HITs.
- Tools → Health and Cache warming rendered at 1280 px, and at 390 px without horizontal overflow or console errors. The Site Health test appeared. The support export downloaded 2.4 KB of JSON with no absolute paths. Health correctly flagged 12 preload jobs that 404ed; these were post URLs made stale by switching permalinks during setup, not a plugin fault.
- Studio ships the placeholder `AUTH_KEY`, which the plugin deliberately refuses for runtime-config encryption, so Health reported configuration publication failed until the salts were shuffled. This is existing, intended behavior.

Limits: no Cloudflare/xCloud edge was involved, so `edge_observed` is covered only by fixtures. Font-localization jobs can still exceed the runner's 20-second budget. A dead SQLite runner holds its slot for up to ten minutes. 4xx preloads still retry three times. No distribution package, Plugin Check run, PHP 8.1, or WordPress 6.6 pass was repeated for this slice.

---

# 1.0.15 development, M1: queue foundation — 2026-09-26

This is the first implementation slice of the feature/MCP/AI roadmap on `main`, based on 1.0.14. It is not a release or deployment. MCP and AI are not implemented. The saved roadmap tracks the remaining M1 budget split and M2–M7 work.

Fresh verification on PHP 8.5.10, WordPress 7.1, and MariaDB 13.0.2 with an InnoDB jobs table:

- Composer strict validation and release metadata consistency passed; version metadata remains 1.0.14.
- WordPress coding standards, PHPStan, and 419 unit tests / 1,300 assertions passed. The final preload redirect condition also passed focused integration and static checks.
- The separate real-database suite passed 13 tests / 107 assertions. Separate PHP processes use a deterministic selection barrier to reproduce enqueue and claim contention. Coverage includes worker expiry and attempt exhaustion, backoff/retry, cancellation, purge priority during pause, bounded migration beyond 500 rows, duplicate live leases, migration locking, no frontend migration, late file/CSS-report publication rejection, runner limits, and refusal to follow redirects with lease credentials.
- Real WordPress admin browser checks passed for pause, resume, retry, and pending cancellation. Stored options/rows agreed with the UI. Errors were redacted. The page width remained 390 pixels at a 390-pixel viewport; the queue table scrolled inside its container.
- An unauthenticated admin-post request returned HTTP 400 without resuming the queue. An authenticated administrator POST with an invalid nonce returned HTTP 403 without pausing it.
- Both distribution candidates were built as `dist/gt-performance-1.0.14-m1-candidate*.zip`, without overwriting the release archives. Internal audit/plan files and tests are excluded. The WordPress.org package excludes licensing; the FluentCart package retains it.

Plugin Check passed without findings for the WordPress.org candidate. The FluentCart candidate passed with only the directory-specific `plugin_updater` check excluded; that channel deliberately retains its existing licensed updater and Update URI. All 104 WordPress.org and 112 FluentCart runtime source/assets/drop-in files matched their packaged copies. Candidate hashes and the exact file counts are in `__work/queue-integration/package-receipt.json`.

The database fixture lives under ignored `__work/queue-integration/` and uses only its dedicated `gtperf_integration_20260926` database. Reproducible database test instructions are in `tests/Integration/README.md`.

Limits: existing sitemap/font tasks renew leases at checked unit boundaries but can still exceed the runner's 20-second between-job budget; durable resumable discovery and smaller jobs are pending. There is no new MCP endpoint or AI-provider call. No production data, external cache rules, or release tags were changed. PHP 8.1, WordPress 6.6, Studio/SQLite, commerce checkout journeys, live edge purge effects, and MCP/AI compatibility are not qualified by this run.

---

# 1.0.14 store release and two-site deployment — 2026-09-21

The FluentCart package is published for product **1170147**, download **302**; prior download **298 / 1.0.12** is retained. Licensed updater checks pass **13/13**, and a real local WordPress bulk upgrade downloaded the exact artifact while maintenance mode was active. The WordPress.org package is active on **gauravtiwari.org** and **gatilab.com**, with exact 569-file parity and saved settings preserved.

Managed Cloudflare rules were synchronized without changing unrelated rules. Full purges pass on both sites. **Per-page purges still do not clear the sites' separate Site Optimizer Worker caches**, which use custom `_cv` / bot-tier Cache API keys. This additional deployment-specific limitation was confirmed through the Cloudflare API and public before/after checks; the Workers are unchanged and an integration preference is pending. Do not describe per-page eviction on these sites as fixed by this deployment.

Complete release, backups, cleanup and remaining-work receipt: `__work/release-1.0.14-20260921/release-receipt.md`.

---

# 1.0.14 Cloudflare purge fixes — 2026-09-21

Local release preparation only. Composer validation, coding standards, PHPStan, and **415 PHPUnit tests / 1,285 assertions** pass. The packaged plugin passed **18 native WordPress integration checks** on WordPress 6.6.2 / PHP 8.3.33 with an offline Cloudflare provider, Plugin Check with no errors, HTTP 200 smoke requests, and exact installed-file parity.

Both WordPress.org and FluentCart packages are in `dist/`, with SHA-256 files. After installation, use **Connect/sync Cloudflare** once to apply the updated managed cache rule. No remote rules were changed or production deployment performed. Full evidence and hashes: `__work/cloudflare-audit-20260921/RELEASE-1.0.14.md`.

---

# Final 1.0.13 audit fixes — 2026-09-20

All six filesystem audit findings are addressed. The final unsubmitted WordPress.org ZIP is `dist/gt-performance-1.0.13.zip` (784307 bytes; SHA-256 `c5eb2ff69b60b5f732b1ba70298ff7acdc7aa51c1491366129149af99b9ca892`).

Coding standards, PHPStan, and 394 PHPUnit tests / 1215 assertions passed. The installed package passed Plugin Check and WordPress 6.6.2 / PHP 8.3.33 cache and privacy checks. All 569 installed files match the ZIP. Full changes, failure-case regressions, test-site setup notes, and evidence: `__work/config-review-20260920/fixes/report.md`.

No submission, external publication, or production deployment was performed. Earlier audit findings below refer to superseded candidates.

---

# 1.0.13 configuration review correction — 2026-09-20

Local correction only; not submitted, deployed, or published.

- Runtime and temporary configuration files now contain authenticated AES-256-GCM encrypted JSON with no PHP guard or PHP filename. Configuration remains sourced from WordPress options.
- Both standalone early readers authenticate/decrypt the envelope using the existing WordPress AUTH_KEY. Secrets are never written as plaintext. Missing/placeholder keys fail closed; rotated keys require regenerating copies by saving settings.
- Complete writes, owner-only permissions, same-directory atomic publication, and cleanup are retained. Successful compilation removes known legacy PHP configurations.
- Coding standards and PHPStan passed. PHPUnit passed 364 tests / 1096 assertions; subsequently added wrong-key and truncated-envelope checks passed in the focused suite (8 tests / 39 assertions).
- Installation now reports a configuration failure instead of publishing a drop-in without a usable configuration. Redis-only installations compile configuration when updating the object-cache drop-in.
- Existing unrelated worktree changes were preserved. Pre-change diff: __work/config-review-20260920/before.patch.
- No new WordPress Studio, HTTP integration, or Plugin Check run was performed for this correction. Historical results below apply only to their named builds.

---

# GT Performance validation

## 1.0.12 FluentCart store publication

Published and verified on 2026-09-18 after the user requested uploading the prepared package to the store.

- Product **1170147**, GT Performance, now has `license_settings.version=1.0.12` and `global_update_file=298`. New download row **298** references `gt-performance-1.0.12-fluentcart.zip` at the root of the existing FluentCart R2 bucket.
- Uploaded through the installed FluentCart Pro R2 driver. A streamed signed GET matched the local ZIP's **793,674 bytes** and SHA-256 `1a1920c7be1502b07cb2aaedd8924eb73ecfce1feeac53657b2795d7711696f3` before changing the database.
- Created the download row and updated version, updater pointer, and changelog in one transaction, with fresh row locks and expected-old-state checks. Prior download rows **273 / 1.0.11** and **271 / 1.0.10** and their R2 archives remain intact.
- Readback confirmed that product content, publication status, slug, pricing, variations, stock, unrelated product metadata, and other license settings were preserved.
- Real licensed updater verification: **13 passed, 0 failed, 0 skipped**. A valid activation receives 1.0.12 and a direct off-site R2 package; invalid activation receives no package. The actual download matches the expected bytes, checksum, ZIP root, and embedded version.
- The isolated Studio site's real WordPress bulk-upgrader downloaded the licensed package with `.maintenance` active, upgraded active 1.0.11 to active 1.0.12, and cleared maintenance mode. All **576 installed files** match the published package. Packaged PHP lint passed for **545 files**.
- R2 reconciliation listed 175 objects and confirmed references for 1.0.10, 1.0.11, and 1.0.12. Five historical unreferenced objects were reported and retained; no R2 objects were deleted.
- Removed the temporary server-side upload ZIP and signed URL, the local signed URL, and the completed temporary mutation scripts. Private rollback metadata and receipts remain in `__work/deploy-1.0.12-20260918/` and the private server staging directory. The isolated Studio server was stopped afterward.
- This was a store release only. No GitHub or WordPress.org publication and no production plugin deployment were performed. Production still reports installed GT Performance 1.0.11.

## 1.0.12 FluentCart package

Initially built locally on 2026-09-18 at the user's request. The subsequent authorized store publication is recorded above.

- Package: `dist/gt-performance-1.0.12-fluentcart.zip`, **793,674 bytes / 576 files**, SHA-256 `1a1920c7be1502b07cb2aaedd8924eb73ecfce1feeac53657b2795d7711696f3`.
- Includes all eight licensing/updater files and retains `Update URI: false` and the FluentCart product URL. These channel files match the previous 1.0.11 FluentCart archive byte for byte.
- All **101 shared runtime files** match the tested 1.0.12 WordPress.org ZIP. The only differing common files are the plugin's channel-specific header and Composer's two generated class maps; the only additional files are the eight licensing files.
- ZIP integrity, version metadata, development-file exclusion, and packaged updater-class autoloading pass. Distribution-channel tests pass: **5 tests / 12 assertions**. Shared-code runtime validation is recorded below; the later licensed-download and bulk-upgrade results are recorded above.
- Manifest: `__work/wporg-1.0.12-review/fluentcart-manifest.json`. A SHA-256 sidecar is saved beside the ZIP.

## 1.0.12 WordPress.org follow-up candidate

Validated locally on 2026-09-18. Prepared for resubmission; no WordPress.org upload, reviewer message, GitHub publication, store update, or production deployment was performed.

- Removed stale Fleet Console, Private Islands, Commerce Safety Lab, and CSS Training Mode claims from current descriptions. Corrected admin-tab/action documentation and removed the empty Private Islands panel and obsolete feature notices. The user also requested removing unneeded features from the readme: references were removed from its historical entries, along with contradictory interim CSS-removal text and a separate-distribution license-screen entry. The first-release entry no longer claims a completed WordPress.org listing. Updated the feature inventory and labeled the original product plan as historical.
- Configuration writes now create an exclusive `.json.php` temporary file, apply `0640` permissions before data is written, require a complete write and successful close, and publish with an atomic rename. Failure cases preserve the old configuration and clean up the temporary file. Tests inspect the real temporary file before writing and publishing; its PHP guard discloses no data.
- Generated inline styles escape `<` with the CSS escape `\3C ` at the WordPress inline-style API boundary. A browser fixture preserved `<`, `>`, `&`, Unicode, icon escapes, and an SVG data URL. Mixed-case closing tags and an HTML payload produced one style element, zero scripts, zero injected images, and no console errors.
- Complete transformed and cached responses now return through pure PHP output-buffer callbacks. Nested WordPress asset printing, exception/non-string fallback, and intact scripts/forms/SVG remain covered. The remaining JavaScript asset response is served with its existing signature/hash verification, JavaScript MIME type, and nosniff header; it is not inserted into HTML.
- `composer check`: PHPCS and PHPStan pass; PHPUnit passes **364 tests / 1,096 assertions** on PHP 8.5.10. Composer strict validation, release-version agreement, and whitespace checks pass. No dependency versions changed.
- Installed the exact candidate ZIP over active 1.0.11 on the isolated Studio review site, running **WordPress 6.6.2 / PHP 8.3.33**. Plugin Check **2.1.0** reports `Success: Checks complete. No errors found.` with no findings.
- HTTP checks pass for generated-file, inline, hybrid, and budget-fallback modes: private generation, pruning, generated assets, preserved scripts and Unicode, identical public MISS/HIT responses, empty HEAD output, and ETag 304 responses. A direct request to a guarded `.json.php` test file returned HTTP 200 with zero body bytes; the fake secret was not exposed and the probe was removed afterward.
- WordPress.org ZIP: `dist/gt-performance-1.0.12.zip`, **781,296 bytes / 568 files**, SHA-256 `1c5fe3b7c5c72c8e7c49f3e10ac39385a8cead23e31a08005678ea7b98b89867`. Every installed file matches the ZIP. Licensing, tests, internal plans, and scratch files are excluded. The packaged current descriptions contain no retired-feature claims.
- Preserved the pre-existing dirty working tree in a source snapshot and diff before editing. The isolated Studio plugin/database snapshot, focused patch, reviewer reply, package manifest, and test evidence are under `__work/wporg-1.0.12-review/`. The previous 1.0.11 artifacts were retained.

## 1.0.11 FluentCart distribution

Published on 2026-09-11 after the user narrowed the request to building and uploading the FluentCart package. Site deployments were not performed by this task; gauravtiwari.org was deployed separately by the user.

- Built `dist/gt-performance-1.0.11-fluentcart.zip`: 794,796 bytes, 576 files, SHA-256 `98f0b739a04986a6a586a693e517d4866d66154bea9be0a600f6a3a6d0ac2a07`. The licensing updater is included, `Update URI: false` is retained, and 101 shared source/asset/drop-in files match the tested WordPress.org package byte for byte.
- The FluentCart package passed a local upgrade from 1.0.10, retained minification settings, and passed the signed JavaScript runtime checks.
- Uploaded through the installed FluentCart Pro R2 driver to the existing `gauravtiwari-org-fluentcart` bucket under the versioned filename. A signed GET matched the local package size and checksum before store mutation.
- Product 1170147 (`GT Performance`) now has download row 273. `license_settings.version=1.0.11` and `global_update_file=273` were changed transactionally with expected-old-state checks. The updater changelog was prepended; other license settings and product content/status/slug were preserved.
- Previous download row 271 and `gt-performance-1.0.10-fluentcart.zip` remain available for rollback.
- Real licensed updater verification passed 13 checks, with zero failures or skips. A valid activation received 1.0.11 and an off-site R2 URL; the downloaded ZIP matched the expected size, SHA-256, root, and embedded version. Invalid activation returned no package.
- The actual WordPress bulk-upgrader installed the licensed package over active 1.0.10 on the isolated Studio site. Maintenance mode was confirmed during download, cleared afterward, and 1.0.11 remained active.
- R2 reconciliation listed 150 objects and confirmed references for 1.0.10 and 1.0.11. Five older unreferenced GT Performance objects were reported and retained; nothing was deleted from R2.
- Temporary upload files and signed URLs were removed. Private pre-release metadata backups and publication receipts remain in `__work/deploy-1.0.11-20260911/` and the private server staging directory. Abandoned site-deployment staging was removed after confirming no directory swap occurred.
- No GitHub tag/push/release or WordPress.org submission was made; the user requested the store upload only.

Evidence: `store-final.json`, `fluentcart-manifest.json`, `licensed-updater.log`, `bulk-upgrade-licensed.log`, `r2-reconciliation.log`, and `store-before.json` under `__work/deploy-1.0.11-20260911/`.

## 1.0.11 WordPress.org review remediation

Validated on 2026-09-11. This is the final candidate with JavaScript minification preserved; the earlier local candidate that removed it was superseded before any submission or publication.

- Opt-in JavaScript minification now processes source text in memory, caches smaller results through WordPress transients, and serves them through a signed external JavaScript endpoint. No runtime JavaScript files are written, and source text is never executed on the server. Saved minify/defer/delay settings remain supported.
- The endpoint validates signatures and same-origin static source URLs, preserves encoded version values, serves GET/HEAD/ETag responses, and uses non-cacheable original-script fallback when a cached result is evicted or the feature is disabled. Source-content revisions change asset URLs. Requests bypass HTML caching regardless of ignored-query settings. Modules, integrity and URL-dependent scripts, existing exclusions, and unsafe/oversized source files are preserved.
- A cold asset request boots WordPress; repeat requests can use browser caching. This avoids executable file writes without changing scripts into inline code or claiming that all cold requests become faster.
- Generated styles and bundled YouTube/delay loaders use WordPress registration/enqueue/printing APIs. HTML transforms run after owned capture buffers close so core asset printers can safely open buffers.
- The expanded audit hardened both standalone drop-in configuration readers, added a regular-file check for local CSS, and replaced remotely derived font output extensions with recognized binary-font types and atomic publication. Other network and filesystem paths were traced and documented in `__work/wporg-1.0.11-review/pattern-audit.md`.
- `composer check`: WordPress Coding Standards and PHPStan pass; PHPUnit passes 360 tests / 1,067 assertions on PHP 8.5.9. JavaScript syntax, Composer metadata, release-version agreement, and whitespace checks pass.
- Final Plugin Check 2.1.0 reports `Success: Checks complete. No errors found.` with no findings. The font rename operation retains the same documented atomic-filesystem exception as the existing CSS artifact publisher; it is not replaced with a potentially non-atomic copy fallback.
- Dedicated Studio site: WordPress 6.6.2 / PHP 8.3.33. The 1.0.10-to-1.0.11 upgrade preserves enabled minify/defer/delay settings. The plugin and owned page-cache drop-in report 1.0.11.
- Real HTTP checks pass for all CSS modes, private generation, and matching public MISS/HIT output. Actual minified scripts return smaller bodies, correct JavaScript MIME/nosniff/cache headers, HEAD and ETag 304 responses. Invalid signatures return 403; transient eviction returns a non-cacheable redirect to the original. A source version containing percent-encoded reserved characters passes end to end. No `.js` files were generated in the cache directory.
- Browser tests used a static copy of independently verified cached HTML to avoid guest optimization being bypassed by the browser's existing WordPress session. Deferred and delayed minified scripts executed correctly, retaining their IDs and the signed URLs; no console errors were reported. Earlier YouTube click-to-play behavior remains covered.
- Final WordPress.org ZIP: 782,415 bytes, 568 files, SHA-256 `84b48e63f34a27820afd81a05b3538b8e781139aa77e4e2452c260539d38d771`. Every installed file matches the archive. Licensing, tests, and scratch artifacts are excluded; the minifier library and its license are bundled. ZIP integrity passes.
- Working branch: `codex/wporg-1.0.11-review`. At implementation handoff, no publication or deployment had been performed. The later FluentCart publication is recorded above. The earlier candidate's checksum and removal notes are obsolete.

Evidence: `__work/wporg-1.0.11-review/`, particularly `check-restored-final.log`, `plugin-check-restored-final.json`, `plugin-check-restored-final.stderr`, `minifier-runtime-results.json`, `runtime-results.json`, `upgrade-preserved-settings.json`, and `package-restored-manifest.json`.

## 1.0.0-rc.2 local release validation

Validated on 2026-08-17:

- Composer metadata validated strictly after regenerating the lock content hash for the new version. WordPress Coding Standards passed across 106 PHP files, PHPStan completed without errors, and PHPUnit passed 174 tests with 423 assertions. Whitespace checks passed.
- Release metadata agreed on `1.0.0-rc.2` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP passed integrity checks with one `gt-performance/` root, 303 entries, 247 files, 398,509 bytes, and local SHA-256 `d03cc9957065c9edea5fc527332d37ce2ab73d03b08ca6b7cdb5a1b23ba214aa`. All 219 packaged PHP files passed syntax validation, and development-only root files were absent.
- The RC1 update fatal was reproduced before fixing it. On a dedicated WordPress Studio site running `1.0.0-rc.1`, a stand-in listener on `deleted_site_transient` that refreshes the plugin update cache drove `Updater::clearCache()` into unbounded recursion; the run had to be killed after two minutes. The same scenario under PHPUnit reached 569,006 stack frames before exhausting memory, which matches the reported production allocation failure of one 262,144-byte PHP VM stack page.
- Two defects combined to produce it. `Updater::clearCache()` deleted its own transient from inside WordPress's update-transient deletion hook, and the Redis object-cache drop-in reported a successful delete for a key it never held, so `delete_site_transient()` re-dispatched the generic `deleted_site_transient` hook on every repeat pass instead of settling after one.
- With RC2 installed, the full WordPress upgrader completed in three seconds under a 512 MB memory limit while the re-entrant listener was armed and the unfixed RC1 drop-in was deliberately restored, confirming the updater guard alone is sufficient for sites upgrading with an older drop-in still on disk.
- The dedicated Studio site upgraded from `1.0.0-rc.1` to `1.0.0-rc.2` through the WordPress upgrader and reported the plugin active on WordPress 7.0.4. The Redis drop-in regenerated itself to the fixed build on the version change, and the PHP, WordPress, cache-directory, page-drop-in, `WP_CACHE`, Redis drop-in, xCloud, and WP-Cron Doctor checks passed. Cloudflare produced the expected warning because that optional service is not configured in the isolated site.

## 1.0.0-rc.1 local release validation

Validated on 2026-08-16:

- Composer metadata and the locked production dependency installation passed at the declared PHP 8.1 platform. Composer audit reported no known vulnerabilities after updating PHP_CodeSniffer from 3.13.5 to the patched 3.13.6 release.
- WordPress Coding Standards passed across 106 PHP files, PHPStan completed without errors, and PHPUnit passed 170 tests with 418 assertions. JavaScript syntax, project JSON, PHP syntax, and whitespace checks also passed.
- Release metadata agreed on `1.0.0-rc.1` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP passed integrity checks with one `gt-performance/` root, 303 entries, 247 files, 397,521 bytes, and local SHA-256 `6bf2fb88b68410e1b9128aaa55934975583e4c08e21c653be7b912602606148f`. Every packaged PHP file passed syntax validation, and development-only root files were absent.
- WordPress Studio CLI 1.18.0 installed and activated the exact production ZIP over beta-9. The dedicated site reported GT Performance `1.0.0-rc.1` active on WordPress 7.0.4 and PHP 8.3.33, returned HTTP 200, scheduled the plugin queue, and passed the PHP, WordPress, cache-directory, page-drop-in, `WP_CACHE`, xCloud, and WP-Cron Doctor checks. Redis and direct Cloudflare produced the expected warnings because those optional services are not configured in the isolated site.
- A live upload of the original image returned promptly after queuing 13 per-file image jobs; all 13 jobs completed in the background. EWWW Image Optimizer remained inactive, so the test covered GT Performance's independent queue ownership without reintroducing synchronous image processing.

## 1.0.0-rc.1 distribution validation

Validated on 2026-08-16:

- Commit `40219ea` passed GitHub CI on PHP 8.1, 8.3, and 8.5, including the release-package job. The tag workflow published `v1.0.0-rc.1` as a prerelease.
- The canonical GitHub ZIP is 397,521 bytes with SHA-256 `d6476408d172e359294c1cc3cc0fb8c4bb25fed130d51fceddfd4b67cfa22674`. Its published checksum, ZIP integrity, single package root, 247-file extracted tree, plugin header, runtime constant, and stable tag agree with the locally tested package.
- FluentCart now exposes `1.0.0-rc.1` through a new R2-backed download row containing the exact GitHub ZIP. The beta-7 row and exact R2 object remain available for rollback, and product variations were unchanged.
- No reusable active activation existed, so a disposable local non-customer license, site, and activation exercised the public updater and were deleted afterward. The valid response returned RC1 metadata and a direct off-site R2 package; the invalid response remained package-free. The protected package matched the GitHub size, checksum, ZIP root, and embedded version.
- A dedicated WordPress Studio site bulk-updated from beta-7 to RC1 through the licensed R2 package while WordPress maintenance mode was active. Maintenance mode cleared afterward, GT Performance remained active as `1.0.0-rc.1`, and the front end returned HTTP 200.
- Temporary local and server-side release files were removed after verification. The live production plugin installation was not upgraded as part of this distribution release.

## 1.0.0-beta-9 recommended integration defaults

Validated on 2026-08-04 against `gauravtiwari.org`:

- JavaScript syntax, WordPress Coding Standards across 106 PHP files, PHPStan, and PHPUnit passed; PHPUnit completed 159 tests with 399 assertions.
- The production ZIP passed integrity checks with one `gt-performance/` root, 303 entries, 247 files, 395,038 bytes, and local SHA-256 `676da92a9972c7b24c1a57cd05252bab39e967bbe3bf375910bfa72b17222812`.
- The live WordPress admin rendered beta-9 without console warnings or errors. Enabling xCloud filled an intentionally blank site domain and displayed the recommended-default notice; enabling Private Islands restored both recommended fragment switches. Reloading without saving discarded the test values and preserved production state.
- Production uses xCloud as the sole edge owner and GT Performance Redis as the object-cache owner. Direct Cloudflare and custom CDN rewriting remain off; xCloud host page cache, free Edge Full Page Cache, and Enterprise Edge Page Caching remain off; Enterprise static caching and security features remain available.
- Automatic Perfmatters ownership, Akismet and Jetpack safeguards, and all three commerce adapters are armed. Only active plugins contribute rules. Private Islands is disabled because no stored content uses its shortcode; the public homepage no longer loads `private-islands.js`.
- Redis connection testing passed. Doctor passed PHP, WordPress, cache-directory, page drop-in, `WP_CACHE`, Redis drop-in, xCloud, and WP-Cron checks. The expected direct-Cloudflare-disabled warning remains.
- Commerce Safety Lab passed ten FluentCart policy checks and four live checks with no failures or warnings. Two consecutive exact requests each to cart, checkout, account, and receipt returned HTTP 200, `CF-Cache-Status: DYNAMIC`, no edge `Age`, `X-GT-Commerce-Cache: BYPASS`, and `X-GT-Cache: BYPASS`. Enterprise static asset delivery remained a cache `HIT`.
- The installed 247-file plugin tree has aggregate SHA-256 `1e111dd884eb32d8e1c17c7ee353d11ae3f5a5ea975e243a52676ff8bef99b0d`, matching the extracted ZIP. The beta-8 plugin tree and pre-change settings are retained outside the public web root for rollback.

## 1.0.0-beta-8 xCloud Enterprise validation

Validated on 2026-08-04 against the live `gauravtiwari.org` installation:

- WordPress Coding Standards scanned 105 PHP files, PHPStan completed without errors, and PHPUnit passed 156 tests with 381 assertions.
- The production ZIP passed its integrity test with one `gt-performance/` root, 301 entries, 218 PHP files, 392,435 bytes, and local SHA-256 `eb692b65da666fbafd3a1605b733479820c5f3db2d70d195a1d6032031e7aac4`.
- The xCloud Public API confirmed the OpenLiteSpeed stack, disabled host page cache, disabled free Edge Full Page Cache, and independently active Cloudflare Enterprise add-on without exposing private traffic data in the release record.
- The Enterprise analytics request is token-authenticated, but the dashboard Enterprise purge mutation redirects token-only requests to interactive login. GT Performance therefore fails closed with a non-zero WP-CLI exit and never substitutes the unrelated broad host `purge-all` endpoint.
- Initial live requests proved xCloud Enterprise Edge Page Caching cached `/cart/`, `/checkout/`, `/account/`, and `/receipt/` despite browser, generic CDN, and Cloudflare-specific `no-store` origin directives. That Enterprise page-cache option was disabled and its dashboard cache was purged; static caching, WAF, and the other add-on features remained enabled.
- Two consecutive exact requests to each commerce route returned HTTP 200, `X-GT-Commerce-Cache: BYPASS`, `X-GT-Cache: BYPASS`, `CF-Cache-Status: DYNAMIC`, and no edge `Age`. A static CSS asset independently changed from `CF-Cache-Status: MISS` to `HIT`.
- GT Performance `1.0.0-beta-8` is active. PHP 8.3.30, WordPress 7.0.2, the writable cache directory, owned page and Redis drop-ins, `WP_CACHE`, xCloud, and WP-Cron passed Doctor; direct Cloudflare remains intentionally disabled. The installed 246-file tree has aggregate SHA-256 `27bc0eddaf50f496e56bce9c36beeafcc16c0f0fb8a41f216fcd944e098cb777`, matching the extracted ZIP.
- The pre-final live plugin tree is retained outside the public web root for rollback.

## 1.0.0-beta-7 local release validation

Validated on 2026-08-04:

- Composer metadata, WordPress Coding Standards, PHPStan, and PHPUnit passed with 146 tests and 356 assertions. Release metadata agreed on `1.0.0-beta-7` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP passed integrity checks with one `gt-performance/` root, 294 entries, embedded version `1.0.0-beta-7`, 380,116 bytes, and local SHA-256 `71588ab47a821354baccab4101c435889954bab00942e774b9df0a202bffac78`. All 212 packaged PHP files passed syntax validation.
- The established WordPress Studio site on WordPress 7.0.2 and PHP 8.3 installed and activated the exact ZIP as `1.0.0-beta-7`.
- File, fully inline, critical-inline-plus-file, and hybrid budget-fallback modes removed the known unused selector while preserving used selectors and hexadecimal icon-font escapes. Inline mode kept `content:"\\e800"` through HTML serialization and emitted no `&#59392;` entity.
- CSS Reports rendered the URL-specific and site-wide regeneration controls at desktop and 390px mobile widths. URL regeneration invalidated, purged, and warmed the homepage; site-wide regeneration advanced the settings generation and marked 19 reports stale; a cross-site URL was rejected.
- A live `gauravtiwari.org` hotfix retained 100% unused-CSS rollout in the site's existing hybrid mode, which selected a generated file for this page. The temporary MD stylesheet exception was removed after the generic hexadecimal-escape repair was deployed.

## 1.0.0-beta-7 distribution validation

Validated on 2026-08-04:

- Commit `c79ce95` passed GitHub CI run `30876228720`; release workflow `30876229951` published tag `v1.0.0-beta-7` as a prerelease.
- The canonical GitHub ZIP is 380,116 bytes with SHA-256 `e717f338110fb42395a7b760916cf25eb740d87695012cd576a31746c04fa021`. Its checksum, package root, entry count, embedded plugin header, runtime constant, and stable tag agree.
- FluentCart product `1170147` points to new download row `153`, version `1.0.0-beta-7`, containing the exact canonical GitHub ZIP. Beta-6 row `146` and its R2 object remain available for rollback.
- An unlicensed updater request returned beta-7 metadata without a package URL. A disposable non-customer license activated successfully, returned protected beta-7 metadata, downloaded the exact 380,116-byte canonical package, deactivated, and left zero temporary license, activation, and site rows.
- `gauravtiwari.org` runs GT Performance `1.0.0-beta-7` as an active plugin. Its installed 240-file tree has aggregate SHA-256 `76f47ceb5e8a69055d2829fada2c8bd53ab65effb705f745ef95ee7b2cb71e59`, matching the extracted canonical ZIP. The pre-upgrade beta-6 tree is retained at `/home/gauravtiwari/backups/gt-performance/gt-performance-beta6-pre-beta7-20260804040832.tar.gz`.
- Production settings remained unchanged: unused CSS enabled, 100% rollout, hybrid delivery, `md-icon` safelisted, and only the existing GT Extensions and Razorpay stylesheet exclusions. PHP, WordPress, cache-directory writability, page and Redis drop-in ownership, `WP_CACHE`, and WP-Cron passed Doctor; Cloudflare integration remains intentionally disabled in the plugin.
- Origin and exact Cloudflare homepage purges succeeded. Desktop and mobile public requests returned HTTP 200. The generated used-CSS file returned HTTP 200, its content hash matched its immutable filename, it retained `\\e800`, `\\f0e1`, and `md-icon`, and it contained no HTML numeric entity.

## 1.0.0-beta-6 local release validation

Validated on 2026-07-31:

- Composer metadata and the production dependency installation passed at the declared PHP 8.1 platform. Composer audit reported no known vulnerabilities after updating WordPress Coding Standards to 3.4.1.
- WordPress Coding Standards, PHPStan, and PHPUnit passed with 133 tests and 319 assertions. Direct syntax validation also passed for 139 source PHP files, four JavaScript files, and the project JSON file.
- Release metadata agreed on `1.0.0-beta-6` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP passed integrity checks with one `gt-performance/` root, 294 entries, embedded version `1.0.0-beta-6`, 376,535 bytes, and local SHA-256 `e5a05f691d6ff5c5add49bac8490b39611836a4fd52caa8b6772f32c9e4caa58`. All 212 packaged PHP files passed syntax validation.
- The established WordPress Studio site on WordPress 7.0.2 and PHP 8.3 installed and activated the exact ZIP as `1.0.0-beta-6`. Its plugin-owned page-cache drop-in, `WP_CACHE`, cache directory, PHP, WordPress, and WP-Cron Doctor checks passed, and a cache-bypassed front-end request returned HTTP 200.
- Redis request-local coherence, forced refresh, conditional `NX`/`XX` writes with expiry, a deterministic two-process `add()` race, option recreation, cron advancement, owned drop-in refresh, foreign drop-in preservation, and exact aggregate-option invalidation are covered by focused regression tests. The Studio site does not provide PhpRedis, so live Redis/drop-in replacement remains a production deployment verification gate.

## 1.0.0-beta-6 distribution validation

Validated on 2026-07-31:

- Commit `8c9e01c` passed GitHub CI run `30600076560` on PHP 8.1, 8.3, and 8.5, including the release-package job. Release workflow `30600132444` published tag `v1.0.0-beta-6` as a prerelease.
- The canonical GitHub ZIP is 376,535 bytes with SHA-256 `a95d6c4b6cdc67e8bbeaa11b9310866bd848c4e80cb913f729c60e0cdd037e43`. It has one `gt-performance/` root, 294 entries, 212 syntax-valid PHP files, and matching plugin-header, runtime-constant, and stable-tag versions.
- FluentCart product `1170147` points to download row `146`, version `1.0.0-beta-6`, containing the exact canonical GitHub ZIP. Beta-5 row `120` and its package remain available for rollback.
- An unlicensed updater request returned beta-6 metadata without a package URL. A disposable non-customer license activated successfully, returned protected beta-6 metadata, downloaded the exact 376,535-byte canonical package, deactivated, and left zero temporary license, activation, and site rows.
- Both `gauravtiwari.org` and `gatilab.com` run GT Performance `1.0.0-beta-6` as an active plugin. Each host retains a beta-5 rollback archive, and their installed 240-file trees share aggregate SHA-256 `ec19ee60d1da8f6000a19b6310dff3b83568b9ef0fc7e5b720f3453c004c49c5`, matching the extracted canonical ZIP.
- On the first post-upgrade request, each owned Redis drop-in atomically refreshed to the stamped beta-6 copy. The exact `options:notoptions`, `options:alloptions`, and `options:cron` cache entries were then invalidated without a blanket object-cache flush; an already-absent `cron` entry was left absent.
- PHP, WordPress, cache-directory writability, the owned page-cache drop-in, `WP_CACHE`, the owned Redis drop-in, and WP-Cron passed `wp gt-performance doctor` on both sites. Gatilab's configured Cloudflare integration also passed; the integration remains disabled on `gauravtiwari.org`, where Doctor reports the expected warning.
- Both sites have request-driven WP-Cron enabled, so no redundant permanent external runner was installed. The due queues were nevertheless executed once under site-specific `flock` locks; Independent Analytics' module refresh advanced to 2026-07-31 04:00 UTC and its click-processing event resumed its normal recurring schedule.
- Plugin-owned cache purges succeeded. Direct-origin requests returned HTTP 200 with `X-GT-Cache: HIT` on both sites, while public requests returned HTTP 200 and Cloudflare edge hits.

## 1.0.0-beta-4 local release validation

Validated on 2026-07-23:

- Composer validation, WordPress Coding Standards, PHPStan, and PHPUnit passed with 101 tests and 262 assertions.
- Release metadata agreed on `1.0.0-beta-4` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP passed integrity checks with one `gt-performance/` root, 293 entries, embedded version `1.0.0-beta-4`, 368,736 bytes, and local SHA-256 `cf59e9aa5956c75a40cd4ca41038d5577fb3121caa4b42ab5eb25a56d5eef003`.
- A disposable native WordPress Studio site on WordPress 7.0.2 and PHP 8.2 installed and activated the production ZIP, owned the page-cache drop-in, and passed its PHP, WordPress, cache-directory, drop-in, and `WP_CACHE` doctor checks.
- The packaged Cache screen rendered the automatic-clearing setting, all four policies, and its explanatory tooltip. Its recommended `related` default was stored, and updating a published post changed both a fresh post artifact and a fresh homepage artifact to missing.

## 1.0.0-beta-4 distribution validation

Validated on 2026-07-23:

- Commit `1255b46` passed GitHub CI run `29971859144` on PHP 8.1, 8.3, and 8.5, including the release-package job; release workflow `29971914677` published tag `v1.0.0-beta-4` as a prerelease.
- The canonical GitHub ZIP is 368,736 bytes with SHA-256 `568a5a77edd536054a88d853afce43cc29cc09bf7a8205077c2938ae91ba29f6`; its checksum, ZIP integrity, package root, plugin header, runtime constant, and stable tag agree.
- FluentCart product `1170147` points to download row `118`, version `1.0.0-beta-4`, containing the exact canonical GitHub ZIP. Beta-3 row `110` and its file remain available for rollback.
- An unauthenticated updater request returned beta-4 metadata without a package URL. A disposable non-customer license activated successfully, returned protected beta-4 metadata, downloaded the exact canonical package, deactivated, and left zero temporary license and site rows.
- Both `gauravtiwari.org` and `gatilab.com` run GT Performance `1.0.0-beta-4` as an active plugin. Their installed 239-file trees share aggregate SHA-256 `5d2731234709226c3dea55af867c8f84d4c5ed0673e1f745ba3ea0e3f20a9447`, matching the extracted canonical ZIP.
- PHP, WordPress, cache-directory writability, the owned page-cache drop-in, `WP_CACHE`, the owned Redis drop-in, and Cloudflare passed `wp gt-performance doctor` on both sites. The automatic post-publish policy resolved to the recommended `related` mode and plugin-owned full purges succeeded.
- Direct-origin requests returned `X-GT-Cache: HIT` on both sites. Public requests returned HTTP 200 and reached Cloudflare edge hits after the release purge.

## 1.0.0-beta-3 local release validation

Validated on 2026-07-22:

- Composer validation, WordPress Coding Standards, PHPStan, and PHPUnit passed with 96 tests and 252 assertions.
- Release metadata agreed on `1.0.0-beta-3` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP passed integrity checks with one `gt-performance/` root, 292 entries, embedded version `1.0.0-beta-3`, and local SHA-256 `89a6ccf04ec4e4ebac99879c9e7b2fe9f018ceb58c3549404579337f8270cab0`.
- A disposable native WordPress Studio site on WordPress 7.0.2 and PHP 8.2 installed and activated the production ZIP, reported version `1.0.0-beta-3`, returned HTTP 200, and detected the Independent Analytics Pro and Site Kit compatibility exclusions.

## 1.0.0-beta-3 distribution validation

Validated on 2026-07-22:

- Commit `90af5b1` passed GitHub CI run `29914570605` on PHP 8.1, 8.3, and 8.5; release workflow `29914572076` published tag `v1.0.0-beta-3` as a prerelease.
- The canonical GitHub ZIP is 367,034 bytes with SHA-256 `4853acb853098004cb7270c47b27455201f305d92e6660979f848739e5463894`; its checksum, ZIP integrity, package root, and embedded plugin version agree.
- FluentCart product `1170147` points to download row `110`, version `1.0.0-beta-3`, containing the exact canonical GitHub ZIP. Beta-2 row `109` and its file remain available for rollback.
- An unauthenticated updater request returned beta-3 metadata without a package URL. A disposable non-customer license activated successfully, returned protected beta-3 metadata, downloaded the exact 367,034-byte canonical package, deactivated, and left no temporary license, activation, or site rows.
- Both `gauravtiwari.org` and `gatilab.com` run GT Performance `1.0.0-beta-3` as an active plugin. Their installed 238-file trees share aggregate SHA-256 `40a530942b743cd49614c2392dba397b8028fd6bd862f2d51f8db5bfbeae4722`, matching the extracted canonical ZIP.
- PHP, WordPress, cache-directory writability, the owned page-cache drop-in, `WP_CACHE`, the owned Redis drop-in, and Cloudflare passed `wp gt-performance doctor` on both sites. Plugin-owned cache purges succeeded, and public requests reached HTTP 200 plus Cloudflare cache hits on both sites.
- `gauravtiwari.org` now has an active xCloud site cron running every five minutes under a non-overlapping lock with the verified LiteSpeed PHP 8.3 binary. The day-old WordPress cron backlog was replayed, and all 11 Independent Analytics overview datasets, including Site Traffic, Site Metrics, and Devices, were rebuilt and readable through WordPress.
- Follow-up: the Redis drop-in's full-cache scan does not match prefixes containing Redis glob characters. The xCloud runner safely deletes the exact `alloptions` and `notoptions` keys before cron as an operational workaround; the scan escaping itself requires a later plugin release rather than rewriting the immutable beta-3 artifact.

## 1.0.0-beta-2 local release validation

Validated on 2026-07-22:

- `composer check` passed WordPress Coding Standards, PHPStan, and PHPUnit: 92 tests with 234 assertions.
- Release metadata validation confirmed `1.0.0-beta-2` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP installed and remained active as `1.0.0-beta-2` on a native WordPress Studio site running WordPress 7.0.2 and PHP 8.3.
- Playwright checked the CDN and Cloudflare settings screens at 1440px and 390px. Both widths had no horizontal overflow or browser-console errors; the Cloudflare token, Global API Key, and Zone ID help links used the intended official documentation URLs.
- A real front-end response rewrote only selected `.woff2` files to the configured HTTPS CDN base while leaving unselected JavaScript URLs on the origin, including when the cache-bypass query prevented origin page caching.

## 1.0.0-beta-2 distribution validation

Validated on 2026-07-22:

- Commit `06efe64` passed GitHub release workflow `29884675415`; tag `v1.0.0-beta-2` was published as a prerelease.
- The canonical GitHub ZIP is 365,961 bytes with SHA-256 `8ad11d1d565305a1b3334f885d4ca43562756068c23da4419ea6013abd491fa0`; its checksum, ZIP integrity, plugin header, runtime constant, and stable tag agree.
- FluentCart product `1170147` points to download row `109`, version `1.0.0-beta-2`, containing the exact canonical GitHub ZIP. Beta-1 row `108` and its file remain available for rollback.
- An unauthenticated update request returned beta-2 metadata without a package URL. A disposable non-customer license activated successfully, returned protected beta-2 metadata, downloaded the exact 365,961-byte canonical package, deactivated, and left zero temporary license, activation, and site rows.
- Both `gauravtiwari.org` and `gatilab.com` run GT Performance `1.0.0-beta-2` as an active plugin. Their installed 238-file trees share aggregate SHA-256 `03c25b76e417d294b1db693d1e6b663201b724f5388822cbb7dbc1fe96209e7e`, matching the extracted canonical ZIP.
- PHP, WordPress, cache-directory writability, the owned page-cache drop-in, `WP_CACHE`, the owned Redis drop-in, and Cloudflare passed `wp gt-performance doctor` on both sites; plugin-owned cache purges succeeded.
- Direct-origin homepage requests on both sites progressed from `X-GT-Cache: MISS` to `HIT`. This confirms the empty `HTTP_AUTHORIZATION` server variable on `gauravtiwari.org` no longer forces an authorization bypass. Public requests also reached Cloudflare cache hits on both sites.

## 1.0.0-beta-1 local release validation

Validated on 2026-07-22:

- Composer metadata validation passed, and the release metadata tool confirmed `1.0.0-beta-1` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, and README.
- WordPress Coding Standards passed for 95 PHP files; PHPStan level 6 passed; PHPUnit passed 82 tests with 209 assertions.
- The canonical GitHub ZIP is 360,046 bytes with SHA-256 `7ccbc16f0f4bc2bdfd80466b32c46385a6f104e6395845f23efbc50edbf68034`. ZIP integrity, package root, plugin header, runtime constant, stable tag, bundled production dependencies, and development-file exclusions passed.
- A fresh native WordPress Studio site on WordPress 7.0.2 and PHP 8.2.32 installed and activated the exact ZIP as `1.0.0-beta-1`; the page-cache drop-in was owned and `WP_CACHE` was enabled.
- An exact URL purge produced `MISS` then `HIT`. Inserting an approved comment invalidated that cached post and again produced `MISS` then `HIT`; changing the comment to spam repeated the same invalidation sequence.
- WP-CLI `cache explain --page-url=...` found the port-aware local artifact, while targeted purge no longer emitted unrelated status output after success.
- The packaged Cache and Integrations admin screens rendered through WordPress with the revised labels, six and five tooltip triggers respectively, and the explicit `Protect Akismet assets` label.
- The disposable Studio site and its files were moved to Trash after validation.

## 1.0.0-beta-1 distribution validation

Validated on 2026-07-22:

- Commit `860bc9b` passed GitHub CI on PHP 8.1, 8.3, and 8.5, including the release-package job. Tag `v1.0.0-beta-1` was published as a GitHub prerelease.
- FluentCart product `1170147` points to download row `108`, version `1.0.0-beta-1`, containing the exact canonical GitHub ZIP. Alpha.12 row `107` and its file remain available for rollback.
- An unauthenticated update request returned beta-1 metadata without a package URL. A temporary non-customer license then activated successfully, returned valid protected metadata, downloaded 360,046 bytes with the canonical GitHub checksum, and left zero temporary license, activation, or site rows after cleanup.
- Both `gauravtiwari.org` and `gatilab.com` run GT Performance `1.0.0-beta-1` as an active plugin. Their installed 236-file trees share aggregate SHA-256 `79a1c18c4f4e3c03fa64e449e2b9a43a9060336447e9bec07e967c23a12ef65c`, matching the extracted canonical ZIP.
- On both sites, PHP, WordPress, cache-directory writability, the owned page-cache drop-in, `WP_CACHE`, the owned Redis drop-in, and Cloudflare pass `wp gt-performance doctor`; plugin-owned cache purges also succeed.
- Gatilab's origin returned `X-GT-Cache: MISS` followed by `HIT`, and Cloudflare returned HTTP 200 followed by an edge hit. On `gauravtiwari.org`, Cloudflare returned HTTP 200 and an edge hit, but the origin exposes an empty `HTTP_AUTHORIZATION` server variable that beta-1 currently treats as an authenticated request; origin requests therefore report `BYPASS authorization`. This is a follow-up compatibility bug rather than a package or deployment mismatch.

## Alpha.12 distribution validation

Validated on 2026-07-19:

- GitHub CI passed on PHP 8.1, 8.3, and 8.5, including the production package job.
- GitHub release `v0.1.0-alpha.12` is published as a prerelease from commit `7f03035`.
- The GitHub ZIP is 349,385 bytes with SHA-256 `5fd42dd4b236a280ce03a28c8cc95d0003f7e135e85c1c1212c22b1bbb94e646`; its checksum file, ZIP integrity, plugin header, runtime constant, and stable tag agree.
- The release workflow skips provenance attestation only when GitHub reports a private repository, where the service is unavailable; checksums and retained workflow artifacts remain mandatory.
- FluentCart product `1170147` points to alpha.12 download row `107`, containing the exact GitHub ZIP. Alpha.10 row `106` and its file remain the verified rollback target.
- A temporary non-customer FluentCart license activated successfully, returned valid alpha.12 metadata, downloaded the protected ZIP with HTTP 200 and the exact GitHub checksum, and left no temporary license, activation, or site rows.
- Gatilab reports GT Performance alpha.12 active. Its page-cache and Redis drop-ins are owned, `WP_CACHE` and Cloudflare are enabled, GT Performance's own purge succeeds, and the public homepage returns HTTP 200 through Cloudflare.
- The established Studio site installed the exact GitHub ZIP, reports alpha.12 active, owns the page-cache drop-in, and returns an anonymous `X-GT-Cache: MISS` followed by `HIT`.
- Studio cache headers match the maximum-impact default: one-hour fresh cache, 24-hour stale retention, and five-minute browser max-age.

## Automated gates

Validated on 2026-07-19:

- WordPress Coding Standards: 92 scanned PHP files passed.
- PHPStan: level 6 passed with WordPress and WP-CLI stubs.
- PHPUnit: 61 tests and 170 assertions passed.
- Composer security audit: no known vulnerable packages.
- Release package: production dependencies installed, ZIP integrity passed, and no development dependencies included.

## WordPress Studio CLI alpha.11 differentiation run

Runtime:

- WordPress Studio CLI 1.15.0
- WordPress 7.0.2
- PHP 8.2.32
- Fresh disposable native Studio site
- Packaged GT Performance `0.1.0-alpha.11`

Verified:

- The production ZIP installed and activated without warnings or fatals; its package, plugin header, stable tag, and runtime constant all report alpha.11.
- Dashboard navigation exposes Safety Lab, CSS Reports, Fleet, and the existing screens without raw internal notice keys.
- Optimization, Safety Lab, and Fleet rendered in the browser with no console warnings or errors and no page-level horizontal overflow.
- At a 390px viewport, main and panel gutters resolve to 20px, panels retain 20px bottom separation, and only the tab bar scrolls horizontally.
- The owned page-cache drop-in installed successfully, enabled `WP_CACHE`, and changed an anonymous homepage request from `X-GT-Cache: MISS` to `HIT`.
- Explain This Page returned the production eligibility reason, exact deterministic cache key, fresh artifact metadata, and expected Cloudflare expression.
- Purge and Verify removed the fresh origin artifact, observed stable response fingerprints, recorded a safe MISS followed by HIT, and returned a verified receipt.
- The Cloudflare rule compiler produced a within-budget create plan without mutating Cloudflare; live API sync remains credential-dependent.
- The CSS training repository accepted one valid structural selector, rejected an attribute-value selector, and exposed the Training Mode screen.
- Unused CSS file, inline, hybrid, and hybrid budget-fallback modes removed the known unused selector while preserving used state; setting rollout to zero restored the original stylesheet immediately.
- The signed Private Islands endpoint returned the registered cart count with `Cache-Control: no-store, private, max-age=0` and `X-GT-Private-Fragments: BYPASS`.
- Commerce Safety Lab completed cleanly with no active commerce plugins; adapter policy behavior remains covered by focused unit tests and full checkout E2E remains an external integration gate.
- Fleet export correctly failed closed while disabled, and its signed REST receiver route was registered without granting an arbitrary-code surface.

## WordPress Studio CLI admin and unused CSS run

Runtime:

- WordPress Studio CLI 1.11.0
- WordPress 7.0.2
- PHP 8.3.32
- Dedicated local Studio site
- Packaged GT Performance `0.1.0-alpha.4`

Verified:

- GT Performance appears as a top-level WordPress admin menu and opens at `admin.php?page=gt-performance`.
- The legacy `options-general.php?page=gt-performance` route redirects to the matching standalone tab.
- Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, CSS Reports, and Tools render without browser console errors.
- Optimization exposes 30 controls; Exceptions exposes cache, CSS, JavaScript, and media exception lists; Cloudflare exposes token and Global API Key authentication fields.
- Desktop and 390px mobile layouts have no page-level horizontal overflow. Tabs and the CSS report table use intentional local horizontal scrolling.
- Mobile gutters, panel padding, and control heights resolve to 20px, 20px, and 44px respectively.
- Rounded status cards use only 1px borders; state is communicated with text color and soft background contrast.
- Known and unknown operation failures render friendly notices without exposing internal codes such as `gtperf_cloudflare_token`.
- A settings save persisted the CSS safelist without resetting settings on other tabs and correctly marked older CSS reports stale.
- File, inline, and hybrid unused-CSS delivery all returned HTTP 200 with `X-GT-Cache: MISS`.
- A known unused selector was removed in every mode; used, hover-state, below-fold, and safelisted selectors were preserved.
- Hybrid mode wrote critical CSS inline and the below-fold rule to a separate immutable file.
- CSS Reports showed all three regenerated modes as ready, refreshed every three seconds, and reported no browser console errors.

## WordPress Playground smoke run

Runtime:

- WordPress 7.0.2
- PHP 8.1.34
- Fresh disposable site
- Source mounted as `gt-performance`

Verified:

- Plugin detected, activated, and rendered its settings screen without PHP warnings or fatals.
- The Cloudflare settings screen rendered both scoped-token and legacy Global API Key modes, including account email, domain, and optional Zone ID fields.
- Selective response-cookie tests verify that Core Forms voter cookies can be removed without dropping unrelated commerce/session cookies.
- Activation created the schema, schedules, compiled config, and writable cache directories.
- Page-cache drop-in installation added an owned `advanced-cache.php` and enabled `WP_CACHE`.
- An anonymous first request returned `X-GT-Cache: MISS`.
- The next identical request returned `X-GT-Cache: HIT`, an `Age` header, an ETag, and byte-identical HTML.
- `If-None-Match` returned HTTP 304 with an empty body.
- An ignored `utm_source` query reused the canonical cache entry.
- An unknown query parameter bypassed storage and returned `Cache-Control: no-store, private`.
- Full purge removed the cached files and the next request safely returned MISS.
- A purge/request cross-worker race returned a safe MISS after the runtime fix.
- Restarting WordPress with the mounted plugin temporarily unavailable did not fatal after the drop-in guard fix.
- Deactivation removed the owned page-cache drop-in, restored the plugin-owned `WP_CACHE` change, unscheduled jobs, and left the public site responding with HTTP 200.

## External gates still requiring credentials or installed integrations

- FluentCart, Easy Digital Downloads, and WooCommerce checkout E2E tests require those plugins and test payment configurations.
- Redis installation requires PhpRedis and a disposable Redis namespace.
- Multisite and host-specific caching combinations remain pre-stable compatibility work.

## Gatilab live validation

Validated on the authenticated Gatilab WordPress installation:

- Installed and activated GT Performance `0.1.0-alpha.3`; WordPress reports the same version.
- Page-cache drop-in is owned, `WP_CACHE` is enabled, Redis is owned, and the settings and Plugins screens have no browser console errors.
- Cloudflare Global API Key mode was configured through encrypted settings using the account email and domain; zone discovery and the managed Free-plan Cache Rule synchronized successfully.
- The Gatilab homepage contains a Core Forms poll and correctly remains uncached with its voter cookie.
- A normal article changed from origin `MISS` to `HIT`. Five origin-cache HIT samples had a median TTFB of 0.233 seconds versus 0.569 seconds for cache-bypassed requests.
- After Cloudflare synchronization, five consecutive edge HIT samples had a median TTFB of 0.100 seconds and all reported `CF-Cache-Status: HIT`.
