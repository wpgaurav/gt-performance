# GT Performance

[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-FFDD00?style=flat&logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/gauravtiwari)

GT Performance is an independent WordPress performance plugin for safe page caching, server-side frontend optimization, Cloudflare Free orchestration, and commerce-aware cache protection.

[Try it in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/wpgaurav/gt-performance/main/distribution-assets/wordpress-org/blueprints/blueprint.json) · [GT Performance Community](https://gauravtiwari.org/portal/) · [More WordPress Plugins](https://gauravtiwari.org/wordpress-plugins/)

The current release is `1.2.1`. It is free GPL software in the WordPress.org plugin directory as [`gt-performance`](https://wordpress.org/plugins/gt-performance/), where 1.0.14 was its first release. Origin caching uses a maximum-impact shared-cache profile while aggressive frontend transformations remain opt-in. Cache correctness and prevention of private commerce-page caching take priority over cache hit rate.

## What is implemented

- Atomic origin HTML cache with an early `advanced-cache.php` drop-in, deterministic keys, stale retention, response validation, exact URL purge, configurable automatic invalidation after publishing, a preload queue, and sitemap-driven cache warming after a full purge.
- Reversible `WP_CACHE` management and drop-in ownership checks, including exact restoration of existing single-line declarations.
- Cloudflare Free setup through one managed Cache Rule, origin-aware TTLs, URL/full purge, encrypted API-secret storage, rule removal on deactivation or `cloudflare disconnect`, and automatic fallback when a Free zone rejects custom query-string cache keys.
- xCloud site discovery, host-cache status, and cache invalidation through its Public API, plus separate detection and 12-hour traffic reporting for xCloud's Cloudflare Enterprise add-on.
- Optional origin-pull CDN URL rewriting for an HTTPS hostname or hostname plus path, restricted to selected static-file extensions and same-site source URLs.
- A Cloudflare Free rule compiler that previews the exact expression, managed-rule drift, competing rules, operation, and remaining ten-rule budget before synchronization.
- First-class bypass policies and product invalidation for FluentCart, Easy Digital Downloads, and WooCommerce.
- Core Forms poll compatibility: the global voter cookie is suppressed only on pages without polls, while real poll pages remain uncached.
- Automatic optimization ownership for Perfmatters, plus active-plugin compatibility reporting for common cache and optimization plugins.
- Akismet and Jetpack safeguards for dynamic selectors, sensitive scripts, forms, comments, subscriptions, media, search, and visitor-state cookies.
- Automatic tracker protection for Independent Analytics, Burst Statistics, Koko Analytics, Matomo Analytics, WP Statistics, Site Kit by Google, MonsterInsights, ExactMetrics, and PixelYourSite when those plugins are active.
- Server-side unused CSS processing with three delivery modes:
  - immutable external file;
  - fully inline;
  - critical CSS inline with the remaining CSS in an immutable file.
- Conservative JavaScript minification, defer, and interaction-delay controls.
- Image loading priorities, missing dimensions, WebP/AVIF variants, lightweight YouTube embeds, and optional local Google Fonts.
- Manual database scanning and selectable cleanup that runs in the background with live progress, plus scheduled database maintenance, on the Database tab, and Perfmatters-style WordPress request and bloat controls.
- Explain This Page diagnostics with cache-decision reasons, deterministic key, local artifact state, and the expected Cloudflare result.
- Verified Purge receipts that compare bounded response fingerprints and cache headers after origin and edge invalidation without storing page bodies.
- Standalone GT Performance admin with Dashboard, Page Cache, Optimization, CSS Status, Exceptions, Cloudflare, CDN, Object Cache, Database, Integrations, AI & MCP, and Tools sections.
- Administrator-bar actions for purging or purge-verifying the current page, warming it, purging page and edge caches, flushing object cache, and testing Redis.
- Comprehensive cache, CSS, JavaScript, media, font, database, bloat, Cloudflare, commerce, and exception controls.
- Live unused-CSS processing reports with ready, processing, stale, skipped, and failed states plus delivery and size details.
- Redacted logs, WP-CLI doctor/cache/queue/Cloudflare/database commands, durable jobs, retries, and dead-letter state.

This list describes the current plugin. The original product plan is historical design context, not a list of available features.

## How unused CSS works

The **CSS Status** tab counts results for the current delivery mode (ready, out of date, queued, building, failed, skipped) and explains each page's result in plain language: what visitors get, and what to do when something needs attention. **Refresh status** updates the report without losing unsaved settings. Savings describe analyzed CSS bytes before compression, not measured visitor bandwidth.

Hybrid delivery inlines the CSS for the top of the page (the first 160 elements, plus site-wide rules such as design tokens and fonts) and loads the rest as a file. When a page needs more than the **Hybrid inline CSS limit**, it inlines nothing and sends one file, exactly like Generated file mode. CSS Status marks those pages "Ready, one file", records how much CSS they needed, and suggests a limit that fits three in four pages.

Use **Force regenerate URL** or a row’s **Regenerate** button to invalidate that URL’s reusable CSS and queue a fresh build. **Force regenerate all CSS** invalidates all results, purges page caches, and rebuilds known eligible URLs in batches; other URLs rebuild on their next eligible visit. Builds respect saved rollout, exclusions, safe mode, and optimization ownership. WordPress cron must run to drain the queue. Existing generated files are retained for cached pages.

GT Performance processes the final anonymous HTML response on the WordPress server. It collects eligible same-origin stylesheets and inline style blocks, parses them into a CSS syntax tree, matches selectors against the rendered document, and keeps configured safelist and dynamic-state selectors conservatively. Safelist lines use partial matching by default and accept validated delimited regular expressions such as `/^\.modal(?:--|\b)/i`. Excluded or cross-origin stylesheets remain untouched.

Generated styles and bundled frontend loaders use WordPress registration, enqueue, and printing APIs. Inline CSS uses CSS escapes for less-than characters so stylesheet text cannot close its HTML style element. The completed response is transformed after its capture buffer closes, so core asset filters can run safely. Opt-in JavaScript minification processes eligible local classic scripts in memory and caches smaller results through WordPress transients, which use the database or an installed object cache. A signed same-origin endpoint serves the result with a versioned URL, browser caching, and ETag revalidation; it never writes JavaScript files or executes JavaScript on the server. Scripts remain external so defer and delay keep their normal execution timing. If the transient is evicted or minification is disabled, the signed URL falls back to the original script.

The first uncached request for a minified script boots WordPress, so this delivery method trades a PHP request for smaller transferred code and avoids generated executable files. Browser caches can reuse the response. Already minified scripts, modules, scripts with integrity attributes, scripts that depend on their own URL, cross-origin or query-driven scripts, unsafe paths, and files over 2 MB are left unchanged. Existing exclusions and commerce-script protection remain in force.

After a non-empty used-CSS result is verified, the original collected style nodes are replaced according to the selected delivery mode:

- **Generated file:** all used CSS is written to an immutable, content-hashed file.
- **Inline all used CSS:** all used CSS is added to the document head in a style element.
- **Critical inline + remaining file:** conservatively detected early-page CSS is inlined and the remaining used CSS is written to a hashed file. If the critical segment exceeds the configured inline budget, the optimizer falls back to a generated file instead of inflating the HTML.

If collection, parsing, pruning, artifact writing, or HTML serialization fails, GT Performance returns the original HTML and stylesheets.

The staged rollout control assigns each URL to a stable cohort. Setting it to zero restores original stylesheets immediately. Safelists and dynamic-state preservation protect selectors that are absent from the initial HTML.

## Diagnostics and safety

Run `wp gt-performance cache explain --page-url=https://example.com/page/` to inspect a URL's cache decision using the production eligibility policy. Use **Purge and verify this URL** in the administrator bar, then review the recorded receipts in **Tools**. Verified Purge stores bounded timestamps, hashes, status, `Age`, Cloudflare cache state, and public/private response signals; it does not retain HTML bodies.

Active FluentCart, EDD, and WooCommerce adapters supply bypass rules for dynamic paths, session cookies, and transactional query parameters.

## Requirements

- WordPress 6.6 or newer
- PHP 8.1 or newer with DOM and JSON
- Composer dependencies bundled in the release ZIP
- A writable `wp-content` directory for origin caching
- Optional: Cloudflare proxied DNS and a scoped API token or legacy Global API Key
- Optional: an xCloud API token with `read:sites` and `write:sites` scopes
- Optional: an origin-pull CDN hostname configured to fetch static files from the WordPress site
- Optional: PhpRedis for the object-cache drop-in

## Redis object cache

Open **GT Performance → Object Cache** to configure a Redis host or Unix socket, port, database, ACL username, password, TLS, persistent connections, key prefix, and bounded connection/read timeouts. Passwords are encrypted in the WordPress option. The early object-cache drop-in receives an authenticated encrypted JSON runtime configuration and fails back to request-local caching if Redis is unavailable.

GT Performance reads the standard constants used by [Till Krüss Redis Object Cache](https://github.com/rhubarbgroup/redis-cache), so an existing configuration does not need to be duplicated. The Object Cache screen includes this copy-ready `wp-config.php` example:

```php
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_REDIS_DATABASE', 0 );
define( 'WP_REDIS_PASSWORD', array( 'username', 'replace-with-a-secret' ) );
define( 'WP_REDIS_PREFIX', 'gtperf:site:' );
define( 'WP_REDIS_TIMEOUT', 0.5 );
define( 'WP_REDIS_READ_TIMEOUT', 0.5 );
```

`WP_REDIS_PATH` with `WP_REDIS_SCHEME` set to `unix` is supported for sockets; `tls` and `rediss` schemes enable TLS. `WP_REDIS_DISABLED` is honored as the emergency switch. Existing `GTPERF_REDIS_*` constants remain supported and take highest precedence over compatible constants and saved settings.

## Safe defaults

The origin cache setting defaults on with one hour of freshness, 24 hours of shared retention and stale-if-error protection, and five minutes of browser caching. It remains inactive until the owned page-cache drop-in and `WP_CACHE` are installed. Logged-in caching stays off, and commerce adapters continue to bypass personalized state.

Cloudflare changes, unused CSS, JavaScript transformations, database automation, Redis, image rewriting, and font hosting remain disabled until enabled by an administrator. Image dimensions and non-critical lazy loading are the only low-risk frontend transformation defaults.

When unused CSS parsing, stylesheet fetching, artifact writing, or HTML serialization fails, the original HTML and stylesheets are returned.

## Cloudflare Free setup

The recommended setup is a scoped token for the site’s zone with:

- Zone read access if GT Performance should discover the zone ID;
- Cache Rules edit access;
- Cache purge access.

Open **GT Performance → Cloudflare**, enter the token and domain, then select **Connect/sync Cloudflare**. The Zone ID is optional and can be discovered from the domain.

Select **Preview rule plan** before synchronization to inspect the exact managed expression, whether GT Performance will create, update, or leave the rule unchanged, competing rule overlaps, and remaining Cloudflare Free rule capacity. GT Performance will not create its rule when the ten-rule budget is already full.

Legacy Global API Key authentication is also supported. Select **Global API Key**, then enter the account email, Global API Key, and domain. The key is encrypted at rest with the same site-keyed cipher used for scoped tokens. A scoped token remains safer because its permissions can be limited to one zone.

Credentials may instead be supplied in `wp-config.php` through `GTPERF_CLOUDFLARE_API_TOKEN`, or through `GTPERF_CLOUDFLARE_GLOBAL_API_KEY` with `GTPERF_CLOUDFLARE_EMAIL`. `GTPERF_CLOUDFLARE_DOMAIN` can provide the zone name. Constants take precedence over saved values.

GT Performance uses the normal Cloudflare CDN fetch path and Cache Rules; it does not require APO, Workers, Cache Reserve, Argo, or an Enterprise plan.

If Cloudflare Free does not expose custom cache-key controls on the zone, GT Performance retries with a portable rule. Marketing query parameters will still be normalized by the origin cache, while Cloudflare may keep separate edge entries for those URLs.

### Cloudflare WP-CLI operations

Use the Cloudflare command family to inspect or change only the edge layer:

```bash
wp gt-performance cloudflare status
wp gt-performance cloudflare plan
wp gt-performance cloudflare sync
wp gt-performance cloudflare purge
wp gt-performance cloudflare purge --page-url=https://example.com/page/
```

The purge command exits non-zero when credentials, zone discovery, URL validation, or the Cloudflare API fails. Use `wp gt-performance cache purge` when both GT Performance's origin page cache and the connected Cloudflare cache should be cleared together.

## xCloud and Cloudflare Enterprise

Open **GT Performance → Integrations** to connect an xCloud API token. GT Performance discovers the exact hosted domain and keeps three cache products separate:

- xCloud host page cache, purged through the narrow Public API page-cache endpoint;
- xCloud's free Edge Full Page Cache, purged through its documented host all-cache endpoint only when that free edge layer is enabled;
- the paid Cloudflare Enterprise add-on, detected independently through its add-on analytics capability.

When Cloudflare Enterprise is active, GT Performance treats xCloud as the edge owner and blocks direct Cloudflare rule synchronization and duplicate direct-Cloudflare purges. The Integrations screen reports the last 12 hours of total and Cloudflare-served requests.

Private and commerce responses send browser `Cache-Control`, standard `CDN-Cache-Control`, and Cloudflare's higher-priority `Cloudflare-CDN-Cache-Control` no-store directives as defense in depth. Live xCloud testing found that the add-on's current **Edge Page Caching** rule overrides even these origin directives and caches cart, checkout, account, and receipt HTML. Commerce sites must keep **Edge Page Caching** off unless xCloud provides equivalent request-level bypass rules. Enterprise static caching, WAF, DDoS protection, HTTP/3, Brotli, and the add-on's other features can remain enabled.

xCloud's current Public API does not publish a token-authenticated purge operation for the Enterprise add-on. The dashboard's Enterprise purge action requires an interactive xCloud session, so GT Performance deliberately fails closed instead of calling xCloud's unrelated broad host `purge-all` endpoint. Use the Purge control on the site's Cloudflare Enterprise page until xCloud adds that operation to the Public API.

The token is encrypted in the WordPress option. It may instead be supplied with `GTPERF_XCLOUD_API_TOKEN` in `wp-config.php`; the constant takes precedence over the saved value.

```bash
wp gt-performance xcloud status
wp gt-performance xcloud refresh
wp gt-performance xcloud purge
```

`xcloud purge` exits non-zero for an active Enterprise add-on rather than claiming a purge that xCloud's token API did not perform.

## Recommended integration defaults

When an integration is switched on in the WordPress admin, GT Performance fills only missing values with its recommended baseline and arms safe dependent safeguards. It preserves saved credentials, provider endpoints, and non-empty custom values.

- Cloudflare defaults to scoped-token authentication, the current site domain, and a 24-hour edge lifetime.
- xCloud defaults to the current site domain while site identifiers remain API-discovered.
- CDN rewriting defaults to static styles, scripts, images, and font formats only.
- Redis defaults to local PhpRedis with short half-second timeouts; existing remote host, database, and credential values are preserved.
- Compatibility protection defaults to automatic Perfmatters ownership plus dormant Akismet and Jetpack safeguards that activate only when those plugins are active.

## Custom asset CDN

Open **GT Performance → CDN** to rewrite selected same-site static asset URLs to a separate HTTPS CDN hostname. The provider must support origin pull and retain the original WordPress path. Cloudflare remains independent: it can continue caching eligible HTML while browsers request selected CSS, JavaScript, image, font, media, or download files from the custom CDN.

Only explicitly selected extensions are rewritten. Third-party URLs, extensionless routes, HTML, API responses, data URLs, and other unselected file types stay on their original URLs. Changing CDN settings purges GT Performance's origin page cache and the connected Cloudflare cache; purge the separate CDN through its provider when replacing an asset at the same URL.

## Updates

GT Performance is free software with no license key or activation. The WordPress.org slug `gt-performance` belongs to this plugin. Installs from the plugin directory receive updates from WordPress.org. The source in this repository and the FluentCart package keep `Update URI: false` and are updated from their own channel.

## Background queue controls

1.1.0 adds **Tools → Background queue** with recent jobs, attempts, redacted errors, and pause/resume/retry/cancel controls. Pausing prevents new optional jobs from starting; URL invalidations and the separate Cloudflare retry cron remain active. Cancellation is cooperative: an in-flight network request cannot be recalled, and running jobs stop at their next checked boundary.

```sh
wp gt-performance queue status
wp gt-performance queue list --status=failed --limit=20
wp gt-performance queue pause
wp gt-performance queue resume
wp gt-performance queue retry --id=123
wp gt-performance queue cancel --id=123
wp gt-performance queue run --limit=20
```

Omitting the action still runs jobs, preserving existing cron commands. Runs start at most 100 jobs and stop taking new work after 20 seconds. Sitemap discovery now fetches at most two sitemaps per job; a single font-localization task can still exceed that interval. Leases renew between checked units. Image encoding processes one source file per job. Workers use at-least-once delivery; a repeated external HTTP effect remains possible after a crash.

Schema version 4 adds unique active keys, leases, cooperative cancellation, and bounded duration metadata. Duplicate enqueue requests return the active job ID. Terminal jobs release their key; crashed workers stop after three claims and can be retried manually. Publication checks stop superseded workers from replacing local files or completed CSS reports. One connection-owned runner slot is used per site.

The migration runs on admin/CLI requests, backfills up to 500 rows per request, and waits for duplicate live leases to expire. New enqueue/claim/retry work is disabled until schema readiness is confirmed. Open Tools or repeat a CLI command to advance an unfinished migration. Queue claims require MySQL/MariaDB with an InnoDB jobs table, or the SQLite integration used by WordPress Studio and Playground. Any other engine is reported as incomplete instead of silently enabling unsafe workers. MySQL uses connection-owned advisory locks for the migration and the single runner. SQLite has none, so those locks are expiring option rows; a runner that dies there holds its slot for up to one ten-minute lease period. Anonymous frontend requests do not run this backfill.

## Resumable warming and health

A warm run is now a sequence of bounded jobs instead of one request loop. It starts after a full purge (when warming is on), from **Tools → Cache warming → Start warm run**, or with `wp gt-performance cache warm`.

- **Sources:** **Page cache → Cache warming → Sitemap sources** accepts up to 10 sitemap URLs from this site. Empty means core's `/wp-sitemap.xml` plus same-origin `Sitemap:` lines in robots.txt.
- **Discovery:** each job fetches at most two sitemaps, with a 10-second timeout and a 2 MB response limit. Nested indexes and redirect hops are followed five levels deep, and redirects are not followed automatically. Other hosts, visited sitemaps, and ineligible or private URLs are skipped. A run holds at most 50,000 targets and keeps its state in `gtperf_warm_targets` for seven days, so a worker that dies resumes where it stopped.
- **Order and limits:** the home page goes first, then sitemap entries modified in the last seven days, then everything else. **URLs per warming batch** (`preload_max_urls`, default 200, maximum 2,000) sets how many preloads are queued at once. The next batch waits until the previous one reports. With a positive `cache.entry_budget`, a run stops at that many entries (mobile copies count separately) and reports `capacity_limited` instead of evicting pages just to finish.
- **Outcomes:** every preload records `origin_ready` (a fresh page is stored, and whether this request rebuilt it), `edge_observed` (Cloudflare answered from cache, so the origin was not rebuilt), `requested` (HTTP 200 with no stored page), `skipped`, or `failed`. HTTP 200 alone is not treated as success. With a separate mobile cache, mobile copies are requested with a phone user agent.
- **Run states:** `discovering`, `warming`, `complete`, `partial` (a sitemap failed, was truncated, hit a limit, or preloads were disabled), `capacity_limited`, or `superseded`.

**Tools → Health** combines queue backlog and age, the last scheduled queue run, WP-Cron, cache storage, drop-ins, edge ownership, runtime configuration publication, purge verification receipts, recent unused-CSS failures, and the latest warm run. Each row names its source (live probe or saved state) and observation time. It requests no pages and never estimates a site-wide hit rate: early cache and edge hits skip WordPress, so the plugin cannot count them. The same report appears as one WordPress Site Health test. **Download support report** exports redacted JSON, without absolute paths, credentials, or query strings.

```sh
wp gt-performance cache warm
wp gt-performance cache warm-status
wp gt-performance health
wp gt-performance health --format=json
```

`doctor` and `health` exit with status 1 when any check fails, so monitoring and CI can act on them; warnings exit 0. **Tools → Explain this page** (also linked from the admin bar) shows why one URL is or is not cached, what the origin holds for it, and whether Cloudflare agrees, with a link to open the page with every optimization off.

Schema version 5 adds the warm-targets table through the same locked admin/CLI upgrade.

Waiting jobs age toward priority 20: after 30 minutes they run as 50, after two hours as 30, after six hours as 20. Purge invalidation (10) always leads. Warm-run jobs themselves run at 50, level with preloads and ahead of unused-CSS generation (70). Before this, a steady CSS backlog could postpone warming indefinitely; one production site had a warm run waiting 18 days.

## Dependency-aware purging

When the origin renders and stores a page, GT Performance records what the page was built from:

- the posts it showed, including those fetched with `get_posts()` (for example the Latest Posts block);
- the listings it asked for: a post type, or a category or tag for term-limited queries such as Query Loop blocks and category archives;
- reusable blocks and navigation menus it embedded.

When content changes, the automatic purge still clears the same related pages as before (the post, home, archives, author, and terms). It then adds every cached page whose recorded dependencies the change affects:

- A content edit reaches pages that showed that post.
- Publishing, unpublishing, deleting, changing the date, or moving a post between terms reaches every listing of that post type or term, including a page two it was never shown on and the category it was moved out of.
- Editing a reusable block or navigation menu purges the pages that embed it; previously nothing was purged.
- Commerce price and stock changes purge shop pages and product grids as well as the product page.
- Renaming a term purges its old and new archive URLs.

Pages that recorded nothing affected stay cached. Records belong to the current settings generation and are pruned in bounded batches; a page not rendered since the last cache-relevant settings change simply has none, and the related-page purge still covers it. Related URLs on another host (an author link to a personal site, for example) are now left out of both local and edge purges. Only the "post and related pages" purge policy adds recorded dependents.

```sh
wp gt-performance cache preview --post=123              # a content edit
wp gt-performance cache preview --post=123 --membership # a publication, withdrawal, or term move
```

The preview lists every URL with its reasons and how many pages are indexed. It purges nothing.

## Settings history, export, and restore

Every settings save, whether from the admin screens, WP-CLI, connection flows, or a restore, records the non-secret values it replaced. History keeps the last 20 revisions, none older than 90 days, 512 KB at most. **Tools → Settings history** lists them with the differences from now and a restore button. From there you can also export settings to JSON, then preview and apply an import.

Credentials, Cloudflare and xCloud identity, Redis, agent access, and the cache generation are never stored in history, exported, imported, or restored. Restoring keeps the current credentials. Imports reject a foreign file, an unsupported schema version, and any unknown or protected key. Restore and import are bound to the settings hash you previewed, so they refuse to overwrite a change made in the meantime. All writers share one settings lock, and connection flows that call a remote API before saving now apply only the keys they changed. A restore changes local settings only: when Cloudflare is connected and cache settings changed, it asks you to run a Cloudflare sync rather than claiming the edge was restored.

```sh
wp gt-performance config history
wp gt-performance config export --file=settings.json
wp gt-performance config diff settings.json
wp gt-performance config import settings.json --dry-run
wp gt-performance config import settings.json --expected-hash=<hash>
wp gt-performance config restore <revision-id-or-prefix> --expected-hash=<hash>
```

Schema version 6 adds a generation column to the dependency table.

## Frontend loading safety

**JavaScript.** Defer and delay decisions come from WordPress's script registry:

- A script is deferred only when no inline code runs right after it and every script that depends on it can be deferred too. This is the same rule WordPress core applies to its own loading strategies.
- Aliases count: inline code attached to `jquery` keeps jQuery blocking. Previously, enabling defer also deferred jQuery and broke inline `jQuery(...)` calls.
- A script matching a delay pattern is delayed together with every script that depends on it, or, when any of them is excluded or has inline code after it, none of them are.
- Scripts WordPress did not register (hardcoded tags) have unknown ordering. They are never deferred, but can still be delayed by naming them in the delay patterns.
- With diagnostic logging on, every page ends with an HTML comment explaining each decision.
- The editor's **GT Performance** box can turn off delay, or all script changes, for one page.

**Hero images.** **Optimization → Media → Hero image rules** replaces "the first image in the document" with a declared hero, one rule per line:

```text
post_type:product => .wp-post-image preload
front_page => url:https://example.com/wp-content/uploads/hero.jpg
template:landing => attachment:123 preload
* => .hero-image
```

The first matching rule, or the page's own setting in the editor, picks the image that gets `fetchpriority="high"` and eager loading. `preload` adds one responsive `<link rel="preload">`, never duplicating an existing preload. `url:` declares a CSS background hero to preload; backgrounds are never guessed from stylesheets. Attributes your theme or WordPress set still win.

**Speculative loading.** On WordPress 6.8+, core prefetches a page when a visitor starts to click its link. GT Performance adds every cache bypass path to core's exclusions, including each active commerce adapter's cart, checkout, and account paths. With plain permalinks it also excludes action, cart, download, and signature parameters. Modes: WordPress default plus exclusions (recommended), prefetch on press only, or off. If the Speculative Loading plugin is active, it keeps control of the mode and GT adds exclusions only.

## Optional AI adviser

On WordPress 7.0+ with an AI provider configured in WordPress (for example the AI Provider for Anthropic, OpenAI, or Google plugins), **AI & MCP → AI adviser** enables the adviser on the same tab. It can explain a page's caching, diagnose the queue and warming, review optimization settings, or explain a purge.

Each request is two explicit steps. **Prepare** builds a redacted report and shows exactly what will be sent and to which provider. The report is at most 24 KB and holds relative paths only: no HTML, cookies, headers, credentials, customers, orders, or server paths. **Send** makes one request through the WordPress AI Client, with at most 2,000 output tokens, a 30-second timeout, and no retry. GT Performance stores no AI keys and names no model.

Answers are validated before they are shown:

- findings must cite report items;
- numbers not present in the report are marked;
- model text is shown as plain text;
- suggestions must be allowlisted settings with valid values.

Suggestions have no authority. **Create a proposal** turns them into a settings proposal that an administrator applies separately. Limits: one request at a time and 20 per site per UTC day. Failed requests still count. The last 20 answers are kept for seven days, token usage is shown, and nothing estimates cost.

## AI assistants through MCP and REST

On WordPress 6.9 and later, GT Performance registers seven abilities with the WordPress Abilities API. An external AI assistant (Claude, ChatGPT/Codex, or any MCP client) can then read this site's cache evidence through the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin:

| Ability | Returns |
|---|---|
| `gt-performance/get-status` | Versions, schema and queue readiness, enabled modules, MCP/AI availability, last runner and warm-run times |
| `gt-performance/explain-url` | Cacheability and reason, cache key, stored origin page state, Cloudflare rule agreement (public or mobile variant) |
| `gt-performance/get-health` | The redacted health report |
| `gt-performance/list-jobs` | Queue jobs, newest first, filterable by status and type, paged by cursor; no payloads |
| `gt-performance/get-css-report` | The stored unused-CSS report for one URL; no server paths |
| `gt-performance/get-settings` | Non-secret settings and their hash; credentials, account emails, Redis host/auth, and hosting identifiers are never included |
| `gt-performance/list-purge-receipts` | Saved purge-verification receipts |

Two more read abilities accompany operations: `preview-purge` (what an update to a post would purge, and why) and `get-operation` (the state and result of an operation or proposal).

**Read and operate** access adds five abilities. Each needs a client-generated `request_id` UUID: repeating it returns the first result, and reusing it with different arguments is a conflict.

| Ability | Does |
|---|---|
| `gt-performance/purge-urls` | Purges up to 20 of this site's URLs at the origin and configured edge, then requests each publicly; per-URL results report origin, edge, and public response |
| `gt-performance/preload-urls` | Queues preloads for up to 20 URLs |
| `gt-performance/regenerate-css` | Queues an unused-CSS rebuild for one URL |
| `gt-performance/retry-job` | Retries one failed preload, warming, CSS, or image job |
| `gt-performance/propose-settings` | Records a proposal for warming, JavaScript defer/delay, critical images, CSS rollout, or safelist changes; an administrator applies it on the **AI & MCP** tab or with `wp gt-performance operations apply <id>` within 15 minutes |

Operations run in the background queue and return an operation ID immediately. Before starting, a queued operation re-checks that access is still "operate" and that its requester is still an administrator; otherwise it is cancelled. Limits: 60 submissions a minute per user and 100 outstanding operations per site. There is no full-site or zone purge, settings apply, credential change, or raw database access.

Access is off by default. Set it under **AI & MCP → MCP and REST access → Agent access**, which also shows the REST and MCP endpoints and the last call. While access is off, abilities are hidden from MCP and REST and every call is denied, even from a client that listed them earlier; read-only hides the five operations the same way. Calls always require an administrator account.

**REST needs no extra plugin.** Scripts, automation tools, and assistants that call HTTP APIs can run abilities at `/wp-json/wp-abilities/v1/abilities/gt-performance/<name>/run` with an Application Password. The MCP Adapter is only needed for MCP clients such as Claude, Codex, or Cursor.

To connect an MCP client: install and activate the MCP Adapter (qualified against 0.6.1). Create a WordPress Application Password for a dedicated administrator account and use HTTPS. The endpoint is `/wp-json/mcp/mcp-adapter-default-server`. The adapter's default server exposes discovery, info, and execute tools; clients discover GT abilities through them rather than as separate top-level tools. An Application Password carries the account's full WordPress privileges, so revoke it from that user's profile to disconnect. Other plugins' abilities on the shared server are theirs to control. `wp gt-performance abilities status` shows the same readiness from the command line.


## Development

Development happens in the open in this repository. Bug reports and pull requests are welcome at [github.com/wpgaurav/gt-performance](https://github.com/wpgaurav/gt-performance).

```bash
composer install
composer check
./bin/build-package.sh
```

`composer check` runs WordPress coding standards, PHPStan level 6 with WordPress/WP-CLI stubs, and PHPUnit.

Real queue, warming, Abilities, settings, and dependency tests run separately against a disposable WordPress installation with a database named `gtperf_integration_*`. They truncate the fixture jobs and warm-target tables and exercise schema upgrades. Never point them at a customer site. See [the integration fixture instructions](tests/Integration/README.md).

```sh
GTPERF_TEST_WP_ROOT=/absolute/path/to/disposable-wordpress vendor/bin/phpunit -c tests/Integration/phpunit.xml
```

## Status

Cloudflare and xCloud mutations require real credentials and are not exercised by the offline test suite. FluentCart, EDD, WooCommerce, multisite, image-optimizer, and host-cache combinations continue to grow their compatibility matrix.

GT Performance is an independent implementation. It does not include or copy FlyingPress code, branding, or private protocols.

Runtime configuration copies require PHP OpenSSL and an existing non-placeholder WordPress `AUTH_KEY`. If unavailable, configuration installation fails without writing plaintext. After rotating `AUTH_KEY`, save the plugin settings to regenerate the runtime copies.

Debug diagnostics are stored as a bounded, non-autoloaded WordPress option, with secret fields redacted. Older plaintext diagnostic files are removed automatically. Cache configuration and drop-ins are published only after complete writes; failed settings publication is reported and the previous settings are retained.

### Updating the Cloudflare integration

After installing 1.0.14, open **GT Performance → Cloudflare → Connect/sync Cloudflare** once to update the managed rule with internal PURGE support. The sync preserves unrelated rules and applies the current checkout, session, and query protections. Installing the ZIP alone does not rewrite remote rules.

Manual page-cache purges and verification wait for the Cloudflare response. The Cloudflare panel records the latest accepted or failed request independently of debug logging. Temporary connection errors, HTTP 429 and server errors receive up to three WordPress cron retries; each retry contains only unfinished cache-key batches. Full-zone purges clear queued retries after Cloudflare confirms success. Desktop, mobile and tablet entries are included when separate device caching is enabled. API acceptance and public-response verification are reported separately.

## Support This Project

GT Performance is free GPL software with no license key, and it handles page caching, unused CSS, Cloudflare Free setup and cache bypass for FluentCart, EDD and WooCommerce. I develop it in the open here, and keeping private commerce pages out of the cache takes priority over hit rate.

If it kept your checkout out of the page cache or got Cloudflare Free caching your HTML without APO, you can buy me a coffee.

<a href="https://buymeacoffee.com/gauravtiwari"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy me a coffee" height="50"></a>

A star on the repo helps too, and so does a bug report with your WordPress and PHP versions, the cache or commerce plugins you run and the steps that broke a page.
