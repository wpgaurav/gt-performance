# Task Plan: GT Performance

## Goal

Build a testable GT Performance WordPress plugin that implements the planned page cache, Cloudflare Free orchestration, server-side CSS pipeline, optimization modules, diagnostics, and first-class FluentCart, Easy Digital Downloads, and WooCommerce safeguards.

## Current Scope

- Implement the plugin in `/Users/gauravtiwari/Development/gt-performance`.
- Deliver the complete modular architecture in one integrated build.
- Keep aggressive transformations disabled by default until their validation gates pass.
- Verify with static analysis, automated tests, packaging checks, and a disposable WordPress runtime.

## Phases

- [x] Phase 1: Establish scope and create the planning workspace
- [x] Phase 2: Inventory FlyingPress capabilities and current platform constraints
- [x] Phase 3: Design the WordPress, cache, Cloudflare, and commerce architecture
- [x] Phase 4: Define milestones, acceptance criteria, testing, security, and release strategy
- [x] Phase 5: Review the plan, initialize Git, and hand off the repository
- [x] Phase 6: Scaffold plugin architecture, lifecycle, configuration, tooling, and CI
- [x] Phase 7: Implement origin page cache, drop-in, invalidation, queue, CLI, and diagnostics
- [x] Phase 8: Implement Cloudflare Free integration and commerce policy adapters
- [x] Phase 9: Implement CSS, JavaScript, media, font, database, bloat, and Redis modules
- [x] Phase 10: Add admin experience, automated tests, Playground validation, packaging, and handoff

## Key Questions

1. Which FlyingPress capabilities are required for credible feature parity?
2. Which features belong in WordPress, at the origin, at Cloudflare, or in an optional service?
3. How should cache keys, invalidation, stale content, and session bypasses work across all layers?
4. How will FluentCart, Easy Digital Downloads, and WooCommerce integrations prevent cached private or transactional state?
5. What is the smallest safe delivery sequence that produces measurable value before full parity?
6. Which features require licensing, paid Cloudflare products, server support, or third-party infrastructure?

## Decisions Made

- Repository slug: `gt-performance`.
- Product name: `GT Performance`.
- Architecture: modular WordPress plugin with explicit Cloudflare and commerce adapters.
- Commerce integrations: FluentCart, Easy Digital Downloads, and WooCommerce are first-class modules with automated compatibility tests.
- Planning artifacts: `task_plan.md`, `notes.md`, and `PRODUCT-PLAN.md`.
- This phase will not copy proprietary FlyingPress code or branding; parity will be based on observable behavior and documented capabilities.
- Cloudflare HTML caching will prefer Cache Rules and the normal CDN fetch path; optional Worker logic will not use data-center-local Cache API storage as the primary page cache.
- Unused CSS will be processed server-side and support external-file, full-inline, and critical-inline-plus-file delivery.
- Dynamic commerce pages and session state must be bypassed consistently at page-cache, host-cache, and Cloudflare layers.
- Cloudflare Free is the default supported edge mode and must not require a Worker, APO, or paid cache capabilities.
- The baseline Cloudflare policy will compile to one narrowly scoped managed Cache Rule where account capabilities permit.
- GT Performance will target PHP 8.1+ and WordPress 6.6+ unless changed before implementation.
- Repository initialized on the `main` branch.
- WordPress project triage currently reports `unknown`, as expected for a planning-only repository with no plugin bootstrap yet; Milestone 0 will make it a detectable WordPress plugin.
- Aggressive modules will ship opt-in until compatibility validation promotes them to safe defaults.
- Cloudflare credentials will use a constant when available or authenticated encryption at rest; secrets will never enter diagnostics.

## Errors Encountered

- Initial CSS URL-rebasing regular expression used conflicting single-quote escaping and failed PHP parsing. Replaced it with an equivalent double-quoted expression and re-ran syntax validation.
- The first lightweight-embed draft used unsupported `array(...)` foreach destructuring and another conflicting quoted pattern. Switched to bracket destructuring and a double-quoted validation pattern.
- The first PHPCS run stopped because the planned `tests/` path did not exist yet. Added the test bootstrap and initial unit suite before rerunning standards checks.
- PHPUnit passed but emitted libxml HTML5-tag warnings from the CSS fixture. Scoped libxml error collection inside the fixture so the test suite is clean without suppressing runtime optimizer failures.
- The initial full WordPress Coding Standards profile produced thousands of PSR-4 filename, camelCase, and mandatory internal-docblock findings that conflict with the chosen namespaced architecture. Narrowed only those convention sniffs while retaining WordPress security, escaping, database, and formatting checks; test files remain covered by PHPUnit/PHPStan.
- The first PHPStan run exhausted the local 128 MB worker limit while loading WordPress stubs. Raised only the analysis command's limit to 512 MB and retained the same analysis level and paths.
- WordPress stubs plus PHPStan 2 still exhausted a 512 MB parallel worker. Disabled parallel analysis for this small codebase and raised the analysis-only ceiling to 1 GB.
- WordPress Playground initially failed under Node 26 because its native filesystem helper had no matching macOS ARM binary. Re-ran the Playground CLI under an isolated Node 22 package without changing the machine-wide runtime.
- The first live settings save updated the WordPress option but left the early-cache compiled file stale in Playground. Made the registered sanitize path compile the exact cleaned settings before the option write, while retaining the update hook for programmatic option changes.
- A purge followed immediately by a request exposed a cross-worker stat-cache race: the early runtime saw a metadata file as readable, then `require` fatally failed after another worker removed it. Cleared per-file stat state, changed metadata loading to non-fatal include, and read the cached HTML into memory before emitting it so concurrent purges safely become misses.
- Restarting the mounted Playground exposed another early-boot edge case: `advanced-cache.php` could load before the mounted plugin directory was available. Generated drop-ins now verify every runtime file before requiring any of them, making a temporarily missing or moved plugin a safe cache bypass instead of a fatal error.

## Status

**Complete** - Integrated alpha implemented, validated in WordPress Playground, and packaged.
