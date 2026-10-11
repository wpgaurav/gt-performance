# GT Performance: GauravTiwari.org Investigation

Open issues only. Last measured: 11 October 2026. Remove an issue from this file after its fix is verified. The entries below distinguish measured problems from causes that still need investigation.

## Mobile LCP and Rendering

Mobile LCP remains above the 2.5-second good threshold on the three repeated pages:

| Page | Latest Mobile Score | Latest Mobile LCP |
|---|---:|---:|
| Article spinner tools | 73 | 4.67 s |
| Easy Affiliate Marketing | 86 | 3.45 s |
| Homepage | 93 | 2.85 s |

The LCP elements are text, and document TTFB is low. CSS and font delivery need profiling; neither is yet established as the sole cause. The spinner scored 99 / 2.10 s immediately after the earlier Cloudflare purge, so compare both stored page versions and their CSS/font requests before attributing the slower fresh rendering to one change.

- Theme CSS transfers roughly 21–24 KiB compressed, with additional Optins and Share CSS.
- GT Performance's unused-CSS engine is disabled. Evaluate a controlled preview/rollout with checks for typography, icons, mobile navigation, optins, product/cart UI and dynamic states.
- Field results use origin fallback. A lab improvement alone cannot establish field CWV recovery.

Next: profile the repeated pages, isolate rendering bottlenecks, validate a scoped optimization and repeat comparable mobile measurements.

## Image Delivery

The DNA illustration still uses a large PNG. The ebook cover and Xbox image candidates also show possible size waste. Their measured mobile LCP elements are text, so image savings should be verified separately from LCP improvement.

Next: inspect responsive candidates at common viewport widths and pixel densities, then validate compression and sharpness before replacing delivery variants.

## Historical Failed Preloads

Three preload jobs retain transport failures recorded on 8 October. The queue was draining during the latest checks, so these historical failures do not establish that the current queue is stopped.

Next: inspect the three target URLs and failure details, retry appropriate jobs and record their outcomes. Check the actual queue hook, `gt_performance_run_queue`, and the existing server cron schedule (`DISABLE_WP_CRON` is enabled).

## Cold-Cache Timeouts

A full cache refresh briefly exposed timeouts while pages were being rebuilt. Warmed public pages subsequently answered normally; the cause of the cold-cache delays has not been isolated.

Next: profile cold rebuild dependencies and verify bounded preload scheduling before repeating full-site purges.

## Cloudflare Expression Headroom

The current managed expression is 3,987 ASCII characters, leaving 109 characters under Cloudflare's [4,096-character expression limit](https://developers.cloudflare.com/ruleset-engine/rules-language/expressions/).

Next: budget and simplify compilation as bypass policies grow, preserving private-page, representation and preload guards. Validate the resulting expression against the API.

## Evidence for Open Issues

- [Retest results](</Users/gauravtiwari/Library/Mobile Documents/com~apple~CloudDocs/writing-system/__work/internal-page-performance-fixes-2026-10-11/RESULTS.md>)
- Workbench: `writing-system/__work/internal-page-performance-fixes-2026-10-11/`, especially `baseline/`, `after/`, `repeat-mobile/` and the queue-state snapshots.
