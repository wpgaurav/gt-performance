# GT Performance: GauravTiwari.org Investigation

Date: 11 October 2026. This is a developer issue log. Keep observed behaviour separate from hypotheses and completed fixes. Record new evidence here while this investigation continues.

## Confirmed Cache Bugs and Changes

1. **Alternate representations intercepted by HTML cache.** A warmed origin returned HTML for `Accept: text/markdown`; the edge had previously returned Markdown to ordinary browsers. Fixed in the prepared 1.3.1 patch: capture Accept in the early runtime, deny HTML-cache eligibility for explicit Markdown/format requests, and mirror the guard in Cloudflare/server rules. Rejected/lower-priority Markdown still reaches the renderer. GTORG's renderer now honours MIME quality preferences and sends no-store. Studio and live representation-order tests pass.
2. **Cloudflare edge TTL overrode origin instructions.** Live managed rule used `override_origin` for 86,400 seconds, including a publicly cached Markdown object whose downstream response said private/no-store. Site edge TTL is now 0 (respect origin). Existing commerce/account/query bypass rules were retained. This is a configuration hazard when negotiated/private responses aren't excluded, rather than a claim that every explicit edge-TTL setting is defective.
3. **Manual theme deployment missed complete invalidation.** The previous deployment refreshed four URLs. Six sampled pages subsequently contained the old sampler. WordPress upgrader hooks already purge, but manual file copies do not invoke them. Documented explicit `wp gt-performance cache purge` after manual deployments; deployed current runtime and verified markers across nine pages after full origin/edge refresh.
4. **Preload header can be intercepted at Cloudflare.** Preloader sends a signed `X-GT-Preload` header, but the managed expression previously allowed that request to hit the edge cache. A request which never reaches the drop-in cannot rebuild an expired origin entry. Controlled testing confirmed the old rule returned `edge_observed / cf_hit` and left origin metadata stale. The deployed 1.3.1 guard excludes preload headers from Cloudflare and LiteSpeed lookup while leaving the origin public-page policy eligible. After the fix, the same control returned `origin_ready / rebuilt`; origin metadata was rebuilt and fresh.

## Integration Findings

- The separate Site Optimizer Worker used synthetic query keys (`_cv`, bot tier), while the site's last Cloudflare rule bypasses all query-string requests. Its old compatibility date did not enable Cache API overrides. Fetch/subrequest cache policy and Cache API policy must be tested independently; unit cache mocks cannot establish production Cloudflare behaviour.
- GauravTiwari.org now uses GT Performance and its managed Cloudflare rule as the page-cache owners. The extra Worker's HTML pipeline is disabled for this hostname; its configuration was backed up. A 4.1 source patch isolates Markdown and private/Set-Cookie responses for future controlled use. Other sites were not deployed or reconfigured.
- Full cache refresh briefly exposed timeout responses during cold-cache population. The origin later answered a cached control page in about 6 ms; all nine warmed public URLs returned 200 with the current native runtime. Investigate cold rebuild dependencies and bounded preload scheduling before recommending repeated full purges.
- Background queue was draining (577 pending to 477 in subsequent snapshots). Three old preload jobs had transport failures recorded on October 8. They have not been deleted. `DISABLE_WP_CRON` is true; use the actual queue hook `gt_performance_run_queue`, not the nonexistent `gt_performance_queue_tick`, when checking scheduling.

## Frontend Findings Still Open

- JavaScript defer was already enabled. The broad `md-` URL exclusion matched the child theme's `md-new` directory, so conversion and study-notes scripts stayed blocking. Core enqueue defer strategies were added only to those two child scripts; other exclusions were retained. Browser readback confirms both defer attributes. Mocked affiliate-click and support-form events fired with no JavaScript errors. Check effective output and dependency ordering rather than treating a saved defer setting as proof that all scripts defer.
- Fresh mobile scores remain variable. Text is the LCP node; backend TTFB is low. Repeat the weak spinner/product/homepage cases before attributing the scores to one change. Desktop scores are 100 in the current nine-page run. Do not report field CWV recovery from a lab score.
- Theme CSS remains roughly 21–24 KiB compressed, plus Optins and Share CSS. GT Performance's unused-CSS engine is disabled. Any rollout needs staging/preview checks for typography, icons, mobile navigation, optins, product/cart UI and dynamic states. No broad unused-CSS setting was switched on during this fix.
- DNA illustration still uses a large PNG; product-cover and Xbox candidates also have possible size waste. Their mobile LCP nodes were text. Treat these as image-delivery work, not demonstrated LCP fixes.
- The current managed expression is 3,987 bytes, close to Cloudflare's expression-size limit. Track compilation length as bypass policies grow; preserve private guards when shortening expressions.

## Evidence and Code

- Workbench: `writing-system/__work/internal-page-performance-fixes-2026-10-11/` (baseline, after, repeat-mobile, representation/private tests, source hashes, deployment/backup receipts).
- Plugin patch: https://github.com/wpgaurav/gt-performance/pull/11 (draft; site package deployed, no public release tag created).
- Original audit: `writing-system/__work/internal-page-performance-audit-2026-10-10/PLAN.md`.
- Remote backup: `/home/gauravtiwari/backups/gtperf-cwv-20261011`.

Official cache references: [Cloudflare Vary](https://developers.cloudflare.com/cache/concepts/vary/), [Cache Rules settings](https://developers.cloudflare.com/cache/how-to/cache-rules/settings/), [Worker/Cache Rule compatibility](https://developers.cloudflare.com/cache/interaction-cloudflare-products/workers-cache-rules/).

## Latest Measurements

Nine-page post-change run: all desktop scores are 100. Mobile scores are 99 (loans), 73 (spinner), 93 (service), 85 (ebook), 93 (DNA), 93 (calculator), 93 (snippet), 92 (Xbox) and 90 (home). Repeats: spinner 73 / LCP 4.67 s, ebook 86 / 3.45 s, home 93 / 2.85 s. Lab performance is not fully resolved. The spinner was 99 / 2.10 s immediately after the user’s Cloudflare purge, so preserve both baselines and investigate the freshly rendered CSS/content/font differences rather than attributing that improvement to this patch. All nine mobile browser checks passed with current runtime, no JavaScript errors and no horizontal overflow. Field data still uses the origin fallback.

Validation after the preload fix: 693 PHP tests / 2,750 assertions, PHPCS and PHPStan pass. The scoped stale-cache control was restored automatically whenever no rebuild occurred; the successful rebuilt artifact was retained. Browser conversion tests used a mocked analytics receiver and prevented navigation, so no real affiliate visit or form submission occurred.

## Admin Design Finding

The Cloudflare purge-status card rendered headings and paragraphs directly inside `.gtp-panel`, whose base styles deliberately have no padding (normal cards pad their header/field wrappers). The queue-upgrade message used the same bare layout. A scoped `.gtp-panel--padded` modifier now gives those two cards the existing 20 px vertical / responsive inset spacing. Admin CSS uses its file revision in the asset version so users see these same-version site patches after a normal refresh. This is a design fix; the UTC timestamp and purge/verification semantics were not changed.
