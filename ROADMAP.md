# GT Performance Roadmap

Internal planning document. It never ships: `bin/build-package.sh` excludes it from every package, and `.gitattributes` keeps it out of `git archive` and GitHub's source ZIPs.

- **Baseline:** 1.1.0, live on WordPress.org since 2026-09-27.
- **Built from:** the 1.1.0 source, [PRODUCT-PLAN.md](PRODUCT-PLAN.md) (the original design), [FEATURE-IMPLEMENTATION.md](FEATURE-IMPLEMENTATION.md) (what shipped), and the 2026-09-27 verification of every wiki page against the code.
- **Last reviewed:** 2026-09-27.
- **Public view:** [products.gatilab.com/roadmaps/gt-performance](https://products.gatilab.com/roadmaps/gt-performance/) is empty today. Items move there when they reach **Next**.

## How to read this

| Status | Meaning (same terms as the public roadmap) |
|---|---|
| **In Progress** | Being built now |
| **Next** | Prioritized for the upcoming release |
| **Planned** | Accepted, not yet scheduled |
| **Not planned** | Considered and declined, with the reason |

Sizes: **S** is a day or two, **M** is about a week, **L** is several weeks. Evidence points at the code that proves the gap, so whoever picks an item up starts from the right file.

An item is done when it has code, tests that exercise behavior (not source text), an updated wiki page, a readme changelog line, and an entry on the products.gatilab.com changelog.

## Principles

These carry forward from PRODUCT-PLAN.md, adjusted for a free WordPress.org plugin.

- **Correctness before hit rate.** A cached cart, receipt, or account page is a release blocker. So is a cached page that drops a security header.
- **Nothing happens by surprise.** Risky transformations stay opt-in. A setting save should not purge a whole Cloudflare zone when nothing cache-relevant changed.
- **Local first.** No required external service, no telemetry, no account. Cloudflare, xCloud, AI, and MCP stay optional and off by default.
- **Recoverable.** Every drop-in, rule, and artifact the plugin creates can be inspected, rolled back, and removed, including when the plugin is deactivated.
- **No compatibility layers.** Breaking changes ship clean with an upgrade note. A migration that is genuinely needed ships as a separate one-off snippet, not as code carried in the plugin.
- **Shared hosting is the reference environment.** Features must behave on a small plan with WP-Cron, no root, and no worker.

## 1.1.1: Fix what the audit found (In Progress: built and verified, awaiting release)

These surfaced on 2026-09-27 while the wiki was verified against the code. Each is small, and several break promises the readme or wiki already make.

Status 2026-09-27: every item is fixed with a behavior test, and the built package was verified on a Studio site and passes Plugin Check with no errors or warnings. Differences from the plan: the Cloudflare rule backup and the test-only `installedVersion()` helpers were kept (1.2.0 uses the backup; the installer tests use the helpers); the anonymous-purge fix turned out broader than comments (a classic WooCommerce checkout that sells a product out had the same gap); wp-admin had no Explain view at all, so 1.1.1 adds one on Tools; and `SafeMode::url()` got a caller instead of being deleted. Added after that build: the YouTube preview left an empty video-height band above itself inside the embed block (seen on gauravtiwari.org), and now shows YouTube's play button; rebuild the ZIP and rerun Plugin Check before tagging. Remaining before release: push, tag `v1.1.1`, then update the wiki pages that warn about these bugs.

| Item | Why it matters | Size | Evidence |
|---|---|---|---|
| Drop-in honors `GTPERF_SAFE_MODE` | The emergency switch stops new stores, but the drop-in keeps serving already-cached optimized pages, so it doesn't fully work when it's needed most | S | `src/Cache/DropinRuntime.php` never reads it; `src/Core/SafeMode.php` |
| Replay PHP-set response headers on cache hits | A hit sends only six headers. CSP, HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy, and Link headers set in PHP vanish from cached pages. Store an allowlist of them in the entry metadata and replay it | S | `DropinRuntime.php:111-116`, `FileStore` metadata |
| Authenticate `X-GT-Preload` | Any request with that header forces a full render of a stale page. Require the preload lease token the queue already issues | S | `DropinRuntime.php:168` |
| `wp gt-performance database run` respects "Scheduled revisions to retain" | With revisions selected, the CLI deletes every revision | S | `src/CLI/Command.php` `database()`, `src/Database/Cleaner.php` |
| Purge after plugin and theme updates | `switch_theme` purges, but `upgrader_process_complete` does nothing, so cached pages keep old asset versions after an update | S | `src/Cache/PageCacheModule.php:48-49` |
| Edge purge for signed-out comment approvals | An auto-approved comment from a visitor purges the origin copy but not Cloudflare or xCloud, because those modules aren't loaded on that request | S | Purging wiki page; `src/Cloudflare/CloudflareModule.php` load context |
| Admin-bar "Explain this page" shows that page | The link passes `gtperf_url` to Tools and nothing reads it | S | admin-bar node in `src/Admin/AdminModule.php` |
| `X-GT-Cache-Reason` keeps its punctuation | `sanitize_key` turns `path:/cart/` into `pathcart`; use the drop-in's sanitizer | S | `PageCacheModule.php:105` vs `DropinRuntime.php:40` |
| `cache purge --page-url` rejects other hosts | The command accepts any URL | S | `src/CLI/Command.php` `cache()` |
| Settings export keeps `bloat.disable_password_strength_meter` | The credential guard matches on "password" anywhere in a key; match the actual secret keys | S | `src/Configuration/` export projection |
| Autosave interval setting works or goes away | The plugin defines `AUTOSAVE_INTERVAL` on `init`, after core already has | S | `src/Database/DatabaseModule.php` |
| `doctor` and `health` exit non-zero on failures | Both always exit 0, which breaks monitoring and CI use | S | `src/CLI/Command.php:79` |
| Fix stale admin copy | "Diagnostic logging" mentions a log directory (logs live in an option), and the Dashboard purge card claims it removes generated assets | S | `src/Admin/AdminModule.php:1055`, Dashboard purge card |
| Delete dead code | `Commerce/PolicyAudit`, `Optimization/Css/SelectorObservation`, the `fleet` sanitize lines, the Cloudflare backup that is written and never read (unless 1.2.0 uses it), and uncalled methods (`JavaScriptOptimizer::lastPlan`, `SafeMode::parameter`, `SettingsLock::held`, test-only `installedVersion`) | S | `src/Core/Settings.php:440-482`, `src/Cloudflare/RuleManager.php:100` |
| Correct FEATURE-IMPLEMENTATION.md | Its last paragraph says dependency tracking, history, MCP, and the adviser "remain unimplemented" right after describing them as shipped | S | `FEATURE-IMPLEMENTATION.md:43` |

**Exit gate:** each fix has a behavior test; Troubleshooting, Diagnostics, WP-CLI, Hooks Reference, and Tools wiki pages drop their warnings about these bugs.

## 1.2.0: Works out of the box (Planned)

Today activation does nothing visible: every HTML optimization runs only on responses GT's own page cache stores, and the drop-in is a manual button. Sites behind a host page cache get no front-end optimization at all. This release makes the first ten minutes succeed, reaches those sites, and cleans up what the plugin leaves behind.

| Item | Why it matters | Size | Evidence |
|---|---|---|---|
| Optimize-only mode | Run the optimization pipeline on anonymous, cacheable responses without storing them, for sites whose host already caches pages (LiteSpeed, Hostinger, xCloud, Kinsta). Reuse the eligibility rules so a response that would never be cached is never transformed. Unused CSS reads existing builds only and queues missing ones; nothing expensive runs inline. Mode is chosen in setup and shown on the Dashboard | M | `src/Cache/PageCacheModule.php:61,173` gates every transformation on capture |
| First-run setup | One guided screen: check `AUTH_KEY`/OpenSSL, detect other cache plugins and host caches, install the drop-in and `WP_CACHE`, detect commerce and multilingual plugins, then verify a real `HIT` | M | Dashboard quick operations only; PRODUCT-PLAN §11.1 |
| Cloudflare cleanup on deactivate and uninstall | The managed Cache Rule stays live with no purging after the plugin is gone. Restore the saved backup or delete the owned rule, and add `cloudflare disconnect` | S | `RuleManager.php:100` backup never read; `src/Core/Deactivator.php` |
| DNS-proxied and APO checks | Connection diagnostics don't confirm the record is proxied or that APO isn't already caching HTML | S | `src/Cloudflare/ConnectionDiagnostics.php:57-224` |
| Purge only when a cache-relevant setting changes | Every settings save bumps `generation`, purges the whole origin cache, and sends purge-everything to the Cloudflare zone | S | `AdminModule.php:370-373`, `Settings.php:295` |
| Per-page controls in the editor | Add "Don't cache this page" and "Use original CSS" next to the existing JS and hero options | S | `src/Optimization/PageOverrides.php:13-14` |
| "Cache separately" query parameters | Parameters are either ignored or bypass. `orderby`, filters, and `lang` always bypass, which kills hit rate on shop and filter pages | S | `src/Cache/Eligibility.php:66`, `CacheKey.php:20` |
| Warn about multilingual and currency plugins | WPML, Polylang, TranslatePress, and currency switchers can vary output by cookie with no cache variant. Detect them and say what to exclude until 1.4.0 adds variants | S | no matches in `src/Compatibility/PluginDetector.php` |
| Translation-ready | Ship a `.pot`, translate the health report labels, load the text domain where needed | S | `src/Diagnostics/HealthReport.php:109` |

**Exit gate:** a fresh install on a Cloudflare Free site reaches a verified origin and edge `HIT` from the setup screen alone; a site behind a host cache gets optimized HTML in optimize-only mode with no measurable overhead on bypassed requests; deactivation leaves no rule behind.

## 1.3.0: Faster cache hits (Planned)

Every hit boots PHP, decrypts the runtime config, loads six files, and hashes the body. Serving from the web server removes all of that for the common case, while PHP stays the fallback and the source of truth for eligibility.

| Item | Why it matters | Size | Evidence |
|---|---|---|---|
| Apache and LiteSpeed rewrite serving | Serve stored pages straight from `wp-content/cache/gt-performance/` when no bypass cookie, query string, or method rule applies. Rules are compiled from the same policy as the drop-in and Cloudflare rule, written only on an explicit admin action through `insert_with_markers()`, and removed on deactivate and uninstall | L | serving is PHP-only in `DropinRuntime::serve` |
| Nginx snippet | Nginx config can't be written by a plugin; generate a copy-ready `location` block and have the health report confirm it's active | M | none today |
| Precompressed gzip and Brotli files | Store `.gz` (and `.br` when the Brotli extension exists) next to each page so the server skips compression. Correct `Content-Encoding` and `Vary` on every path | M | no compression code in `src/` |
| Web-server hits carry the right headers | Replayed headers from 1.1.1 go into the rewrite rules or a per-page header map, so a server-served hit and a PHP hit send the same security headers | M | depends on the 1.1.1 header replay |
| Cold-miss stampede lock | Stale entries rebuild once, but a cold URL under a burst renders in every request. Use `NamedLock` per cache key | S | `NamedLock` unused in `src/Cache/` |
| Measure plugin overhead | Nothing checks the "under 2 ms dynamic, under 5 ms hit" targets from PRODUCT-PLAN §14.3. Add `Server-Timing` in debug mode and a benchmark script, and publish PHP-hit vs server-hit numbers | S | no timing code in `DropinRuntime.php` |

**Exit gate:** on Apache, LiteSpeed, and Nginx staging hosts, a cacheable page is served without PHP, a request with any bypass cookie or unknown query string still reaches PHP, headers match the PHP path byte for byte, and deactivation restores `.htaccess` exactly.

## 1.4.0: WooCommerce caching (Planned)

Today a WooCommerce store is cached only for visitors with an empty cart. Proper caching keeps catalog, product, and content pages cached for shoppers too, while cart, checkout, account, and every personalized fragment stay private.

| Item | Why it matters | Size | Evidence |
|---|---|---|---|
| Commerce end-to-end gate first | No test installs WooCommerce, EDD, or FluentCart. Nothing else in this release ships until Playwright covers browse, add to cart, coupon, checkout, receipt privacy, account isolation, and price/stock purge for all three, on classic and block checkout | L | PRODUCT-PLAN §10.5; no adapter referenced in `tests/` |
| Cache pages for shoppers with a cart | `woocommerce_items_in_cart` and `woocommerce_recently_viewed` bypass every page, so the visitors who matter most never see a cached page. Serve the cached page and let WooCommerce's own cart fragments or the Store API hydrate the mini-cart and counters. Off by default until the gate passes, then recommended | M | `src/Commerce/WooCommerceAdapter.php:39-46` |
| Personalization detection | Refuse to store a page that contains cart contents, a customer name, or a nonce-bearing form for a shopper session, and log the reason, so a theme that renders the cart server-side can't leak one shopper's cart to another | M | `ResponseValidator` checks headers only |
| Server-rendered cart switch | For themes that print cart totals in PHP, a switch that keeps today's bypass behavior, surfaced by the setup check | S | none today |
| Geolocation and tax-by-location awareness | WooCommerce's "geolocate with page caching" mode and location-based prices need a variant or a bypass; detect the setting and choose the safe one | M | none today |
| Language and currency cache variants | Turn the 1.2.0 warning into real variants keyed by the plugin's cookie or path | M | PRODUCT-PLAN §6.2 |

**Exit gate:** the commerce gate passes for all three adapters on classic and block checkout, a shopper with a cart gets cached catalog pages with a correct mini-cart, and a private page never shows an `Age` header at the edge.

## 1.5.0: Behave on shared hosting (Planned)

| Item | Why it matters | Size | Evidence |
|---|---|---|---|
| Warming backs off under pressure | Warming ignores load, disk space, repeated 5xx or timeouts, and maintenance mode. Pause and resume with a visible reason | M | no `sys_getloadavg`, `disk_free_space`, `wp_is_maintenance_mode` in `src/` |
| Circuit breakers | Pause unused CSS or JS optimization automatically after a spike in asset 404s or failed renders, and show it in the health report | M | only the Redis breaker and the 3-attempt job cap exist |
| Warm the mobile variant only when output differs | With separate mobile caching on, every URL is warmed twice | S | `src/Cache/CacheWarmer.php` |
| Warm from menus too | Seeds come only from sitemaps, robots.txt, and invalidations | S | `CacheWarmer.php` sources |

**Exit gate:** a warm run on a throttled shared-hosting staging site finishes without tripping the host's resource limits.

## 1.6.0: Image pipeline (Planned)

| Item | Why it matters | Size | Evidence |
|---|---|---|---|
| Convert the existing media library | WebP/AVIF generation covers new uploads only. Add a resumable queue job with progress | M | `AdminModule.php:944`, `src/Optimization/ImageVariantGenerator.php` |
| Clean up variants on delete | Generated files are orphaned when an attachment is deleted | S | no `delete_attachment` hook |
| Safer serving | Rewriting `src` in place breaks on browsers or proxies that don't accept the format. Serve through `<picture>` or `Accept` negotiation | M | `ImageVariantGenerator.php:124-133` |
| Lazy-load background images and iframes | Only `<img>` and YouTube are handled today | M | `src/Optimization/MediaOptimizer.php` |
| Dimensions for any local image | Missing dimensions are added only for `wp-image-N` classes | S | `MediaOptimizer.php:66-75` |
| Local YouTube thumbnails | The facade still hotlinks `i.ytimg.com` | S | `src/Optimization/EmbedOptimizer.php:84` |

## 1.7.0: Unused CSS, second generation (Planned)

| Item | Why it matters | Size | Evidence |
|---|---|---|---|
| Template fingerprints | Builds are reused only for the same URL with near-identical markup, so every URL needs its own loopback build and pages with changing markup may never match | L | `src/Optimization/Css/UnusedCssOptimizer.php:478-488` |
| Keep the last good build and roll back | A failed build falls back to original CSS, but a bad successful build has no rollback. Keep one prior generation and add `wp gt-performance css build\|verify\|rollback` | M | `UnusedCssOptimizer.php:282-287`; no `css` CLI family |
| Verify output after each build | Nothing re-checks a build beyond "not empty" | M | `UnusedCssOptimizer.php:196,251` |
| Standalone CSS minify | Compact CSS exists only inside unused CSS | S | `src/Optimization/Css/CssPruner.php:68` |
| Preload critical fonts | Local Google Fonts has no preload for fonts used above the fold | S | `src/Optimization/FontOptimizer.php` |

## Engineering track (every release)

Runs alongside the feature releases and gates them.

| Item | Why it matters | Size |
|---|---|---|
| Run the integration suite in CI | 67 integration tests exist and never run in CI; add a MySQL service job | S |
| WordPress version matrix | CI has no WordPress matrix, and Plugin Check runs on 6.8 with warnings ignored while the readme says "Tested up to: 7.1" and AI features need 6.9 and 7.0. Test minimum, latest, and trunk | S |
| Redis and SQLite jobs | The object-cache drop-in and the SQLite lock path have no live test | S |
| Replace source-text tests | About 10 unit files assert on source text instead of behavior | M |
| Downgrade tests | Only upgrade paths for the queue schema are tested | S |
| Playwright and visual regression | Prerequisite for 1.4.0 and 1.7.0 | L |

## Later (Planned, unscheduled)

| Item | Why it matters | Size |
|---|---|---|
| Self-host third-party CSS and JS | Only Google Fonts can be localized today; needs the same SSRF controls | M |
| Consent-aware delay | Delay waits for interaction or five seconds, with no link to the WP Consent API | S |
| Delay inline scripts | Only `src` scripts can be delayed | M |
| Redis insight | Hit rate and memory pressure are counted but never shown; no ignored-groups control | S |
| Database extras | Orphaned-meta cleanup, a size forecast, and a run history instead of one overwritten last-run record | S |
| Font subsetting and a weights/duplicates audit | From PRODUCT-PLAN §9.3 | M |
| Optional Cloudflare capabilities | Tiered Cache detection, cache tags where the plan allows, Cloudflare Images. Enhancements only, never required | M |

## Not planned

| Item | Reason |
|---|---|
| Multisite | One compiled config and one cache root would let one site's settings decide another's cache behavior. Reconsider only with per-site config files and cache roots |
| HTML minification | Small saving, real breakage |
| Private Islands, Fleet Console, Commerce Safety Lab, CSS Training Mode | Removed. Not coming back in their old form |
| Logged-in and role-based page caching | High risk of serving one user's page to another for little gain on most sites. Revisit only with a proven isolation model |
| Required external services or telemetry | Conflicts with the local-first principle and WordPress.org guidelines |

## Decisions

Recorded 2026-09-27.

| Question | Decision | Where it landed |
|---|---|---|
| Optimize sites whose host already caches pages? | Build optimize-only mode | 1.2.0 |
| Retire the FluentCart channel? | No action. Store installs are already handled | Removed from the roadmap |
| Cache WooCommerce pages for shoppers with a cart? | Build proper WooCommerce caching, behind the end-to-end gate | 1.4.0 |
| Serve hits from the web server? | Build it, pulled forward from Later | 1.3.0 |
| Publish Next items on products.gatilab.com? | Open | |

## WordPress.org guideline constraints

Every item above must stay inside the [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) and the lessons from earlier directory reviews. Check each release against this list before tagging.

- **Everything free and complete (guideline 5).** No feature is locked, time-limited, or gated behind a key. Optimize-only mode, server serving, and WooCommerce caching ship fully functional in the directory build.
- **No remote code (guideline 8).** Nothing is downloaded and executed: no rules, scripts, or configs fetched from a server we run. Rewrite rules and Nginx snippets are compiled locally from the site's own policy.
- **No tracking, no phone-home (guideline 7).** Benchmarks, health checks, and detection run locally and send nothing anywhere. Any new external call is opt-in and disclosed in the readme's External Services section before release.
- **Server config changes only with consent.** `.htaccess` is written only after an administrator clicks a button that explains the change, always through `insert_with_markers()` inside a `GT Performance` marker block, and is restored on deactivate and uninstall. Nginx gets a copy-ready snippet, never a written file.
- **No generated PHP or JavaScript files.** Earlier reviews made us remove runtime PHP config (1.0.13) and generated JS files (1.0.11). Cached pages, `.gz`/`.br` files, CSS builds, and JSON metadata are data, not executable code. Keep it that way, and keep `index.html` and deny rules in every cache directory.
- **Front-end output through WordPress APIs.** Loaders and inline scripts go through `wp_register_script()`, `wp_add_inline_script()`, and the asset printers, as 1.0.11 required. Never replace or re-register WordPress's bundled libraries (guideline 13); defer and delay may change when they load, not which copy loads.
- **No admin hijacking (guideline 11).** The setup screen runs once on request, notices are dismissible and limited to GT Performance screens, and nothing links out to an upsell.
- **No public-site credits (guideline 10).** No "Optimized by" comments or badges in page output unless diagnostic logging is on.
- **Use WooCommerce's public APIs.** Cart hydration uses WooCommerce's own fragments and Store API; nothing from WooCommerce is bundled or patched.
- **Human-readable, GPL-compatible code (guidelines 1 and 4).** No minified-only PHP, no bundled library without its license, and the build keeps failing on unexpected top-level files and unpermitted file types.
- **Plugin Check stays clean of errors** on the minimum and latest WordPress before every tag, and every new direct filesystem or database call carries a justified `phpcs:ignore`.
