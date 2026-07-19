# Changelog

## 0.1.0-alpha.11 - 2026-07-19

- Added Explain This Page diagnostics backed by the production cache policy, deterministic cache keys, local artifact metadata, and compiled edge expectations.
- Added Verified Purge with bounded redacted receipts covering origin removal, post-purge response fingerprints, response privacy signals, and Cloudflare cache headers.
- Added a Cloudflare Free rule compiler with exact expression preview, managed-rule drift detection, competing-rule overlap warnings, expected create/update/noop operation, and ten-rule capacity reporting.
- Added Commerce Safety Lab in-memory policy simulation and safe read-only checks for FluentCart, Easy Digital Downloads, and WooCommerce dynamic routes.
- Added administrator-only Unused CSS Training Mode with bounded selector observation, one-hour sessions, candidate review, publication, rollback, and deterministic 0/10/25/50/100 percent rollout cohorts.
- Added opt-in signed Private Islands for explicitly registered cart-count, account-link, and developer fragments with private no-store responses and fail-closed public fallbacks.
- Added a 25-site Fleet Console foundation using five-minute, one-use, license-signed configuration bundles with recursive credential removal and no remote-code capability.
- Added standalone Safety Lab and Fleet screens, Cloudflare plan UI, administrator-bar shortcuts, WP-CLI commands, and focused unit coverage for the new policy and signing layers.

## 0.1.0-alpha.10 - 2026-07-19

- Added stable, port-safe license identities for Studio and other local WordPress sites so FluentCart protected-package signatures are not corrupted by `localhost:PORT` values.
- Kept ordinary production site URLs unchanged and added a `gt_performance_license_site_url` filter for deliberate identity overrides.

## 0.1.0-alpha.9 - 2026-07-19

- Moved the live manual database scan and selectable cleanup interface from Optimization to Tools while keeping scheduled database maintenance in Optimization.
- Returned completed manual cleanup actions to the Tools tab and removed the redundant one-click cleanup card.
- Added compatible `WP_REDIS_*` configuration for Till Krüss Redis Object Cache, including ACL credential arrays, TCP/TLS and Unix sockets, database, prefix, timeouts, legacy key salt, and the emergency disable constant.
- Kept `GTP_REDIS_*` constants as highest-precedence overrides and added isolated configuration coverage for compatibility and precedence.
- Published the $199 FluentCart product page with verified direct-checkout links, one-site lifetime-license details, and responsive purchase sections.

## 0.1.0-alpha.8 - 2026-07-19

- Added encrypted FluentCart license activation, weekly verification, deactivation, version checks, protected package delivery, and native WordPress update metadata.
- Added a dedicated License tab with masked credentials, plan and expiration details, on-demand update checks, wp-config.php license support, and administrator-friendly errors.
- Added response normalization and tests for FluentCart's live top-level updater response, malformed versions, unsafe package URLs, and the no-update path.
- Fixed activation-time registration of the plugin's custom queue and weekly cron schedules.
- Added the GT Performance FluentCart product identity plus reproducible WordPress directory, storefront, and social assets that embed real WordPress Studio screenshots.

## 0.1.0-alpha.7 - 2026-07-19

- Added partial and validated regular-expression matching to the unused-CSS selector safelist.
- Added automatic Perfmatters feature ownership plus compatibility reporting for Akismet, Jetpack, Jetpack Boost, FlyingPress, WP Rocket, LiteSpeed Cache, WP Super Cache, W3 Total Cache, Autoptimize, and Core Forms.
- Added Jetpack visitor-state cache bypasses and automatic Akismet/Jetpack CSS and JavaScript safeguards.
- Added encrypted Redis credentials, TLS, ACL username, logical database, persistent connection, prefix, timeout, health-test, guarded drop-in installation controls, and documented `wp-config.php` constants for every connection setting.
- Added administrator-bar actions for current-page purge, cache warming, CSS regeneration, full page/edge purge, object-cache flush, Redis testing, and report/settings navigation.
- Added a pinned GitHub Actions release flow with version-surface validation, changelog release notes, PHP quality gates, verified ZIP packaging, SHA-256 checksums, artifact provenance attestations, and automatic prerelease publishing.

## 0.1.0-alpha.6 - 2026-07-19

- Replaced the CSS delivery dropdown with explicit Generated file, Inline all used CSS, and Critical inline + remaining file choices.
- Made the dynamic-state preservation setting control hover, focus, open, checked, and related selector retention.
- Changed Hybrid mode to fall back to a generated file when critical CSS exceeds the inline budget.

## 0.1.0-alpha.5 - 2026-07-19

- Added one-click Maximum Impact, Balanced, and Frequently Updated cache lifetime presets that populate the existing fields without saving unexpectedly.
- Made the default cache profile one hour fresh, 24 hours retained for visitors and bots, 24 hours stale-if-error, and five minutes in visitor browsers.
- Added the active gauravtiwari.org Perfmatters request-removal configuration as an optional one-click WordPress baseline.
- Added WordPress controls for Dashicons, XML-RPC, jQuery Migrate, RSD, shortlinks, feed links/feeds, self-pingbacks, REST discovery/access, Google Maps, password strength, comments, author URLs, global styles, separate block styles, autosaves, Heartbeat, and revisions.
- Added manual database scans and selectable optimization for revisions, auto-drafts, spam and trashed comments, trashed posts, expired/all transients, and reclaimable table space.
- Added saved scheduled database tasks with daily, weekly, and monthly recurrence plus bounded revision retention.
- Removed Core Web Vitals collection, its frontend measurement script, REST endpoint, settings, and storage table.

## 0.1.0-alpha.4 - 2026-07-19

- Replaced the Settings submenu with a standalone top-level GT Performance admin at `admin.php?page=gt-performance`, while redirecting the legacy URL.
- Added focused Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, CSS Reports, and Tools sections with responsive WordPress-native controls.
- Exposed cache query/path/cookie exceptions, CSS safelists and stylesheet exclusions, JavaScript exclusions and delay patterns, and media selector exceptions.
- Exposed the remaining cache, CSS, JavaScript, media, font, database, WordPress cleanup, Cloudflare, and commerce settings.
- Added persistent unused CSS generation reports with live processing, ready, stale, skipped, and failed indicators plus delivery, size, savings, duration, and errors.
- Replaced internal action codes with friendly success, warning, and error notices, including safe fallback copy for unknown codes.
- Refined desktop and mobile spacing and replaced thick status-card borders with thin boundaries and soft status contrast.

## 0.1.0-alpha.3 - 2026-07-18

- Added Core Forms compatibility that selectively removes its globally emitted voter cookie only on pages without polls.
- Preserved the voter cookie and cache rejection on actual poll pages so voting identity and duplicate-vote protection remain correct.

## 0.1.0-alpha.2 - 2026-07-18

- Added Cloudflare authentication modes for scoped API tokens and legacy Global API Keys with account email.
- Added a domain setting for zone discovery, retained optional direct Zone ID configuration, and added matching `wp-config.php` constants.
- Encrypted Global API Keys at rest and covered both Cloudflare authentication header schemes with unit tests.
- Fixed false `WP_CACHE` custom-declaration errors caused by comments, supports existing single-line declarations, and preserves the exact declaration for restoration.

## 0.1.0-alpha.1 - 2026-07-18

- Added an atomic origin page cache, early drop-in, eligibility policy, response validation, targeted invalidation, stale retention, and durable preload/purge queue.
- Added Cloudflare Free Cache Rule management, scoped token encryption, zone discovery, URL/full purge, drift state, backup, and portable cache-key fallback.
- Added FluentCart, Easy Digital Downloads, and WooCommerce cache bypass and product invalidation adapters.
- Added server-side unused CSS analysis with file, inline, and critical-inline-plus-file delivery.
- Added JavaScript, media, image variant, YouTube, Google Fonts, database, WordPress bloat, and Redis modules.
- Added a native settings screen, WP-CLI commands, redacted diagnostics, PHPUnit coverage, PHPStan, WPCS, CI, Playground smoke validation, and release packaging.
