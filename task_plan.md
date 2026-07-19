# Task Plan: GT Performance Differentiation Suite

## Goal

Implement the seven-feature product roadmap in the plugin itself with secure admin controls, deterministic diagnostics, safe fallbacks, automated tests, and a release-ready package.

## Scope

1. Explain This Page cache diagnostics.
2. Verified origin and Cloudflare purge receipts.
3. Cloudflare Free rule compiler with rule-budget and drift reporting.
4. Commerce Safety Lab for FluentCart, EDD, and WooCommerce.
5. Unused CSS Training Mode with selector capture and staged artifacts.
6. Private Islands for explicitly registered private fragments.
7. A 25-site Fleet Console foundation using signed, non-secret configuration bundles.

## Phases

- [x] Phase 1: Re-triage the repository and establish constraints
- [x] Phase 2: Map existing cache, Cloudflare, commerce, CSS, admin, and persistence seams
- [x] Phase 3: Implement shared diagnostics, receipts, and safety data models
- [x] Phase 4: Implement Cloudflare compiler and Commerce Safety Lab
- [x] Phase 5: Implement CSS Training Mode and Private Islands
- [x] Phase 6: Implement the Fleet Console foundation and policy bundles
- [x] Phase 7: Integrate the standalone admin UI, admin bar, CLI, and documentation
- [x] Phase 8: Run static analysis, unit tests, build/package checks, and Studio smoke tests
- [x] Phase 9: Review the final diff and prepare a verified handoff

## Key Questions

1. Which existing services can be extended without adding request-path overhead?
2. How can edge verification distinguish Cloudflare from origin without exposing secrets?
3. Which commerce checks can be safely automated without creating real orders?
4. How can CSS selector training remain opt-in, bounded, private, and reversible?
5. How can private fragments fail closed when JavaScript, REST, or nonce validation fails?
6. What fleet functionality is safe without creating a remote arbitrary-code channel?

## Decisions Made

- Keep Core Web Vitals measurement out of scope.
- Prefer deterministic evidence and reason codes over opaque scores or AI recommendations.
- Default every commerce, fragment, CSS-training, and fleet feature to fail closed.
- Never cache authenticated fragment responses or persist their HTML.
- Keep Cloudflare Free compatibility as the baseline and compile into its rule budget.
- Treat fleet bundles as configuration-only, signed exports; do not add remote code execution.
- Preserve PHP 8.1 and WordPress 6.6 minimums already established by the project.

## Errors Encountered

- PHPUnit passed with 59 tests and 161 assertions after the initial implementation slice.
- The first full check exposed a missing PHP output boundary in the new Fleet tab and several WordPress coding-standard formatting/sanitization findings. The boundary and JSON-field handling were corrected before the complete gate passed.
- Release validation correctly rejected the stale Composer content hash after the alpha.11 metadata change. A dependency-preserving lock refresh resolved it.
- The first Studio admin-render probe over-escaped a PHP namespace in the WP-CLI evaluation string. WordPress itself remained healthy and the corrected probe passed.
- The first CLI smoke probe used the shorthand `gtp`; the existing registered namespace is `gt-performance`. The runtime command passed under the correct namespace.
- The combined Studio teardown command was rejected before execution because it included a generic file-removal operation. Studio's scoped stop/delete commands and the patch mechanism completed teardown safely.

## Status

**Complete** - All seven features, admin/CLI surfaces, release metadata, production package, automated gates, Studio runtime checks, responsive browser checks, and teardown are complete. Publishing remains intentionally separate.
