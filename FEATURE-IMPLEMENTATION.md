# GT Performance feature status

This inventory describes 1.0.14 plus the milestones below, released in 1.1.0. The original beta implementation report is available in Git history.

- Origin page caching and commerce adapters protect eligible anonymous pages while bypassing private routes and session state.
- Explain This Page uses the production eligibility policy, deterministic key, local artifact metadata, and compiled Cloudflare expectation.
- Verified Purge records bounded, redacted receipts with artifact removal, response stability, cache headers, and safety signals. Receipts are available in Tools.
- The Cloudflare rule compiler previews rule capacity, drift, expected operation, overlaps, and the exact expression before synchronization.
- Unused CSS supports file, inline, and hybrid delivery, background generation, manual safelists, deterministic URL rollout, and status/regeneration controls in Optimization.
- JavaScript minification uses in-memory processing, transients, and signed external delivery. Defer and interaction delay remain available.
- Media, fonts, database cleanup, Redis, xCloud, and custom CDN controls remain opt-in where appropriate.

Private Islands, Fleet Console, Commerce Safety Lab, and CSS Training Mode were removed in 1.0.8 and are not available in this release. Their former controls and endpoints are not part of the current feature set.

See VALIDATION.md for version-specific test evidence.

## 1.1.0: queue controls

The development tree implements exclusive claims, durable active-job deduplication, attempt limits, expired-worker recovery, lease renewal, publication fencing, and admin/CLI pause/resume/retry/cancel controls. Schema upgrades are additive, locked, bounded, and withheld from anonymous frontend requests. Normal queue commands retain their existing run default. The real-database suite uses separate PHP worker processes with a deterministic selection barrier.

## 1.1.0: warming and health

Cache warming is a resumable run: sources are explicit sitemaps or core's sitemap plus robots.txt declarations. Discovery is bounded to two sitemap fetches per job, five nesting or redirect levels, 2 MB per response, and 50,000 targets per run, stored in `gtperf_warm_targets` for seven days. Preloads are dispatched in batches, prioritized by home page and recent `lastmod`, capped by the cache entry budget, and recorded as `origin_ready`, `edge_observed`, `requested`, `skipped`, or `failed` from origin metadata and edge headers rather than HTTP status. A shared `HealthReport` backs Tools → Health, one Site Health test, `wp gt-performance health`, and a redacted JSON export.

Waiting queue work ages toward priority 20 so a steady backlog cannot starve warming; purges keep precedence. On SQLite (Studio, Playground) advisory locks use expiring option rows.

## 1.1.0: read-only MCP

On WordPress 6.9+, seven read-only abilities (`get-status`, `explain-url`, `get-health`, `list-jobs`, `get-css-report`, `get-settings`, `list-purge-receipts`) register in the `gt-performance` category. Exposure to the MCP Adapter (`meta.mcp.public`) and core REST (`show_in_rest`) follows the `agents.mode` setting, off by default; the permission callback requires `manage_options` and a non-off mode on every call. Results share one envelope (`schema_version`, `site_id`, `observed_at`, `data`, `warnings`, and `settings_hash` for settings). Settings leave through an allowlisted projection with a credential-name guard.

## 1.1.0: dependencies and settings history

`DependencyRecorder` collects `WP_Query` objects at `pre_get_posts` (so suppressed `get_posts()` calls count), `core/block` and `core/navigation` references, and the main queried object during a cacheable render. It stores `post:`, `term:`, `pt:`, `ptv:` (volatile order), or `broad:` signatures in `gtperf_dependencies`, bound to the settings generation (schema 6). `DependencyInvalidator` adds recorded dependents to the related-URL purge for saves, status transitions, deletions, sticky changes, term reassignment (old and new terms), term edits (old and new archive URL), reusable block and navigation saves, and commerce price/stock events. `PurgePreview` and `wp gt-performance cache preview` explain each URL.

`ConfigurationService` restores, imports, exports, and previews non-secret settings through `Settings::save()` under a reentrant `SettingsLock`, with expected-hash checks. `RevisionRepository` records replaced values from every save path via the option update hook. `Settings::saveChanges()` applies only a caller's changed keys under the lock. Protected paths: generation, agents, redis, xcloud, and Cloudflare enabled/auth mode/domain/zone.

## 1.1.0: operations, frontend safety, and adviser

- M5 operations. `gtperf_operations` (schema 7) records agent operations and proposals, unique per (actor, request ID). `OperationService` validates, rate-limits (60 per minute per actor, 100 outstanding), and queues one `agent_operation` job. That job re-checks mode and role before running and reports purges per URL: origin, edge (`EdgeReport` reads the provider receipt written after the operation started), and a public request. `ProposalService` stores allowlisted patches bound to the settings hash; administrators apply them through `ConfigurationService`. Management modules also load at `rest_api_init`, so cookieless REST writes purge the edge.
- M6 frontend. `ScriptPlan` decides defer and delay from the script registry, including src-less aliases, with core's deferral rule and delay chains. `HeroRules`, `PageOverrides`, and the editor box add declared heroes and responsive preload. `SpeculationPolicy` adds bypass-path exclusions to core speculation rules, with core, conservative, and off modes.
- M7 adviser. `EvidenceBuilder` (redacted, 24 KB), `RecommendationValidator` (evidence IDs, measurements, allowlisted suggestions, plain text), and `Advisor` (two-step prepare/send, WordPress AI Client, per-site lock, daily quota, history, proposal hand-off).

Font-localization jobs can still exceed the runner's between-job time budget. Remaining gaps are listed in the roadmap. including dependency tracking, configuration history, Abilities/MCP, frontend improvements, and the AI adviser, remain unimplemented. No release or production deployment is implied by these source changes.
