# Queue integration tests

These tests use real WordPress, InnoDB SQL, and two independent PHP worker processes. They require `proc_open`, a local MySQL/MariaDB server, and a disposable WordPress installation. A database name beginning with `gtperf_integration_` is mandatory. The suite truncates its jobs table and alters its queue schema during migration tests.

Create a fresh fixture with WP-CLI using the locally available WordPress core or a supported version. Disable WP-Cron in the fixture. Keep the plugin inactive in the database; the test bootstrap loads its classes directly. Do not use production credentials or a database containing other work.

```sh
GTPERF_TEST_WP_ROOT=/absolute/path/to/disposable-wordpress \
  vendor/bin/phpunit -c tests/Integration/phpunit.xml --no-coverage
```

The concurrency tests block both child processes after selecting the same job (or checking the same active key), release them together, and assert the stored result. Other tests cover lease expiry, crash attempt limits, retries/backoff, cancellation, purge priority while paused, publication fencing, migration locks, old-schema upgrades exceeding one batch, and duplicate running leases. The fixture refuses to run without the dedicated database prefix.

`WarmingDatabaseTest` drives the real queue runner through warm runs, serving HTTP in-process with `pre_http_request`. Fixtures cover core, robots, redirected, nested (beyond five levels), cyclic, broken, and foreign sitemaps, private-URL exclusion, a worker dying mid-discovery, mobile variants against the entry budget, origin-stored versus edge-only outcomes, a 60,000-URL site against the 50,000-target cap within bounded memory, and seven-day retention.

`AbilitiesTest` exercises the real WordPress Abilities API (6.9+): registration and category, output validation of every ability, administrator-only permission with live mode switching, closed and bounded inputs, secret-free settings and hash changes, cursor paging, and the core `/wp-abilities/v1` run route for administrators, subscribers, and anonymous requests. The bootstrap registers the module hooks before the registry first loads.

`ConfigurationTest` covers exact restore with current credentials kept, stale-hash refusal, failed runtime publication leaving settings and history unchanged, import validation, and a second PHP process holding the settings lock while this one waits and then keeps its change. `DependenciesTest` records real queries, `get_posts()`, and reusable blocks, then checks new and removed membership, pagination, unrelated-page retention, term reassignment and renames, reusable block edits, commerce stock changes, generation binding, foreign-host exclusion, and the purge preview.

`DatabaseCleanupTest` starts a cleanup run and checks nothing is deleted in that request, then drains it through the real queue runner: revisions, trash, spam, and transients to completion; revision retention across many posts, including one-row slices that must look past their window; one run at a time; stop leaving remaining tasks untouched; abandoned runs no longer blocking; and deleting trashed posts or spam comments purging nothing.

`OperationsTest`, `FrontendOptimizationTest`, and `AdvisorTest` cover:

- operations: replay, conflict, rate and outstanding limits, cancellation after revocation or demotion, per-URL purge reports, preload, the retry allowlist, and the proposal lifecycle (stale, expired, applied once);
- frontend: real core speculation rules with bypass exclusions and modes, and script plans for scripts printed by core, including inline code on the `jquery` alias;
- the adviser, with a scripted provider: two-step sending of exactly the previewed report, secrets absent, failures counted and never retried, the daily limit, a second process holding the in-flight lock, injected instructions staying data, and proposals from suggestions.

This is separate from the stub-based unit suite. Passing it does not qualify external Cloudflare/xCloud effects, Studio's SQLite emulation, every supported PHP/WordPress version, MCP clients, or AI providers.
