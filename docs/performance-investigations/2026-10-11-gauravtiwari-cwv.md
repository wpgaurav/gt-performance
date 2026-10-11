# GT Performance: GauravTiwari.org Investigation

Open issues only. Last measured: 11 October 2026. Remove an issue from this file after its fix is verified. The entries below distinguish measured problems from causes that still need investigation.

## Mobile LCP and Rendering

Mobile LCP remains above the 2.5-second good threshold on the three repeated pages:

| Page | Latest Mobile Score | Latest Mobile LCP |
|---|---:|---:|
| Article spinner tools | 94 | 2.55 s |
| Easy Affiliate Marketing | 86 | 3.38 s |
| Homepage | 94 | 2.71 s |

The LCP elements are text, and document TTFB is low. CSS and font delivery need profiling; neither is yet established as the sole cause. The spinner's earlier repeat was 73 / 4.67 s; the fresh release-preparation run is substantially faster, so measurement variability and stored page versions need to be controlled before attributing the difference to one change. Homepage desktop scored 100 with LCP 0.68 s.

- Theme CSS transfers roughly 21–24 KiB compressed, with additional Optins and Share CSS.
- GT Performance's unused-CSS engine is disabled. Evaluate a controlled preview/rollout with checks for typography, icons, mobile navigation, optins, product/cart UI and dynamic states.
- Field results use origin fallback. A lab improvement alone cannot establish field CWV recovery.
- Mobile origin field LCP remains 2.624 s at the 75th percentile; INP is 135 ms and CLS is 0. The LCP threshold remains unmet.

Next: profile the repeated pages, isolate rendering bottlenecks, validate a scoped optimization and repeat comparable mobile measurements.

## Image Delivery

The DNA illustration still uses a large PNG. The ebook cover and Xbox image candidates also show possible size waste. Their measured mobile LCP elements are text, so image savings should be verified separately from LCP improvement.

Next: inspect responsive candidates at common viewport widths and pixel densities, then validate compression and sharpness before replacing delivery variants.

## Evidence for Open Issues

- [Retest results](</Users/gauravtiwari/Library/Mobile Documents/com~apple~CloudDocs/writing-system/__work/internal-page-performance-fixes-2026-10-11/RESULTS.md>)
- Latest measurements: `writing-system/__work/gt-performance-release-1.3.1-2026-10-11/psi/`.
- Workbench: `writing-system/__work/internal-page-performance-fixes-2026-10-11/`, especially `baseline/`, `after/`, `repeat-mobile/` and the queue-state snapshots.
