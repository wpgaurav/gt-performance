# GT Performance validation

## 1.0.0-beta-3 local release validation

Validated on 2026-07-22:

- Composer validation, WordPress Coding Standards, PHPStan, and PHPUnit passed with 96 tests and 252 assertions.
- Release metadata agreed on `1.0.0-beta-3` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP passed integrity checks with one `gt-performance/` root, 292 entries, embedded version `1.0.0-beta-3`, and local SHA-256 `89a6ccf04ec4e4ebac99879c9e7b2fe9f018ceb58c3549404579337f8270cab0`.
- A disposable native WordPress Studio site on WordPress 7.0.2 and PHP 8.2 installed and activated the production ZIP, reported version `1.0.0-beta-3`, returned HTTP 200, and detected the Independent Analytics Pro and Site Kit compatibility exclusions.

## 1.0.0-beta-3 distribution validation

Validated on 2026-07-22:

- Commit `90af5b1` passed GitHub CI run `29914570605` on PHP 8.1, 8.3, and 8.5; release workflow `29914572076` published tag `v1.0.0-beta-3` as a prerelease.
- The canonical GitHub ZIP is 367,034 bytes with SHA-256 `4853acb853098004cb7270c47b27455201f305d92e6660979f848739e5463894`; its checksum, ZIP integrity, package root, and embedded plugin version agree.
- FluentCart product `1170147` points to download row `110`, version `1.0.0-beta-3`, containing the exact canonical GitHub ZIP. Beta-2 row `109` and its file remain available for rollback.
- An unauthenticated updater request returned beta-3 metadata without a package URL. A disposable non-customer license activated successfully, returned protected beta-3 metadata, downloaded the exact 367,034-byte canonical package, deactivated, and left no temporary license, activation, or site rows.
- Both `gauravtiwari.org` and `gatilab.com` run GT Performance `1.0.0-beta-3` as an active plugin. Their installed 238-file trees share aggregate SHA-256 `40a530942b743cd49614c2392dba397b8028fd6bd862f2d51f8db5bfbeae4722`, matching the extracted canonical ZIP.
- PHP, WordPress, cache-directory writability, the owned page-cache drop-in, `WP_CACHE`, the owned Redis drop-in, and Cloudflare passed `wp gt-performance doctor` on both sites. Plugin-owned cache purges succeeded, and public requests reached HTTP 200 plus Cloudflare cache hits on both sites.
- `gauravtiwari.org` now has an active xCloud site cron running every five minutes under a non-overlapping lock with the verified LiteSpeed PHP 8.3 binary. The day-old WordPress cron backlog was replayed, and all 11 Independent Analytics overview datasets, including Site Traffic, Site Metrics, and Devices, were rebuilt and readable through WordPress.
- Follow-up: the Redis drop-in's full-cache scan does not match prefixes containing Redis glob characters. The xCloud runner safely deletes the exact `alloptions` and `notoptions` keys before cron as an operational workaround; the scan escaping itself requires a later plugin release rather than rewriting the immutable beta-3 artifact.

## 1.0.0-beta-2 local release validation

Validated on 2026-07-22:

- `composer check` passed WordPress Coding Standards, PHPStan, and PHPUnit: 92 tests with 234 assertions.
- Release metadata validation confirmed `1.0.0-beta-2` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, README, and dated changelog.
- The production ZIP installed and remained active as `1.0.0-beta-2` on a native WordPress Studio site running WordPress 7.0.2 and PHP 8.3.
- Playwright checked the CDN and Cloudflare settings screens at 1440px and 390px. Both widths had no horizontal overflow or browser-console errors; the Cloudflare token, Global API Key, and Zone ID help links used the intended official documentation URLs.
- A real front-end response rewrote only selected `.woff2` files to the configured HTTPS CDN base while leaving unselected JavaScript URLs on the origin, including when the cache-bypass query prevented origin page caching.

## 1.0.0-beta-2 distribution validation

Validated on 2026-07-22:

- Commit `06efe64` passed GitHub release workflow `29884675415`; tag `v1.0.0-beta-2` was published as a prerelease.
- The canonical GitHub ZIP is 365,961 bytes with SHA-256 `8ad11d1d565305a1b3334f885d4ca43562756068c23da4419ea6013abd491fa0`; its checksum, ZIP integrity, plugin header, runtime constant, and stable tag agree.
- FluentCart product `1170147` points to download row `109`, version `1.0.0-beta-2`, containing the exact canonical GitHub ZIP. Beta-1 row `108` and its file remain available for rollback.
- An unauthenticated update request returned beta-2 metadata without a package URL. A disposable non-customer license activated successfully, returned protected beta-2 metadata, downloaded the exact 365,961-byte canonical package, deactivated, and left zero temporary license, activation, and site rows.
- Both `gauravtiwari.org` and `gatilab.com` run GT Performance `1.0.0-beta-2` as an active plugin. Their installed 238-file trees share aggregate SHA-256 `03c25b76e417d294b1db693d1e6b663201b724f5388822cbb7dbc1fe96209e7e`, matching the extracted canonical ZIP.
- PHP, WordPress, cache-directory writability, the owned page-cache drop-in, `WP_CACHE`, the owned Redis drop-in, and Cloudflare passed `wp gt-performance doctor` on both sites; plugin-owned cache purges succeeded.
- Direct-origin homepage requests on both sites progressed from `X-GT-Cache: MISS` to `HIT`. This confirms the empty `HTTP_AUTHORIZATION` server variable on `gauravtiwari.org` no longer forces an authorization bypass. Public requests also reached Cloudflare cache hits on both sites.

## 1.0.0-beta-1 local release validation

Validated on 2026-07-22:

- Composer metadata validation passed, and the release metadata tool confirmed `1.0.0-beta-1` across Composer, the plugin header, runtime constant, WordPress stable tag, package builder, PHPStan bootstrap, and README.
- WordPress Coding Standards passed for 95 PHP files; PHPStan level 6 passed; PHPUnit passed 82 tests with 209 assertions.
- The canonical GitHub ZIP is 360,046 bytes with SHA-256 `7ccbc16f0f4bc2bdfd80466b32c46385a6f104e6395845f23efbc50edbf68034`. ZIP integrity, package root, plugin header, runtime constant, stable tag, bundled production dependencies, and development-file exclusions passed.
- A fresh native WordPress Studio site on WordPress 7.0.2 and PHP 8.2.32 installed and activated the exact ZIP as `1.0.0-beta-1`; the page-cache drop-in was owned and `WP_CACHE` was enabled.
- An exact URL purge produced `MISS` then `HIT`. Inserting an approved comment invalidated that cached post and again produced `MISS` then `HIT`; changing the comment to spam repeated the same invalidation sequence.
- WP-CLI `cache explain --page-url=...` found the port-aware local artifact, while targeted purge no longer emitted unrelated status output after success.
- The packaged Cache and Integrations admin screens rendered through WordPress with the revised labels, six and five tooltip triggers respectively, and the explicit `Protect Akismet assets` label.
- The disposable Studio site and its files were moved to Trash after validation.

## 1.0.0-beta-1 distribution validation

Validated on 2026-07-22:

- Commit `860bc9b` passed GitHub CI on PHP 8.1, 8.3, and 8.5, including the release-package job. Tag `v1.0.0-beta-1` was published as a GitHub prerelease.
- FluentCart product `1170147` points to download row `108`, version `1.0.0-beta-1`, containing the exact canonical GitHub ZIP. Alpha.12 row `107` and its file remain available for rollback.
- An unauthenticated update request returned beta-1 metadata without a package URL. A temporary non-customer license then activated successfully, returned valid protected metadata, downloaded 360,046 bytes with the canonical GitHub checksum, and left zero temporary license, activation, or site rows after cleanup.
- Both `gauravtiwari.org` and `gatilab.com` run GT Performance `1.0.0-beta-1` as an active plugin. Their installed 236-file trees share aggregate SHA-256 `79a1c18c4f4e3c03fa64e449e2b9a43a9060336447e9bec07e967c23a12ef65c`, matching the extracted canonical ZIP.
- On both sites, PHP, WordPress, cache-directory writability, the owned page-cache drop-in, `WP_CACHE`, the owned Redis drop-in, and Cloudflare pass `wp gt-performance doctor`; plugin-owned cache purges also succeed.
- Gatilab's origin returned `X-GT-Cache: MISS` followed by `HIT`, and Cloudflare returned HTTP 200 followed by an edge hit. On `gauravtiwari.org`, Cloudflare returned HTTP 200 and an edge hit, but the origin exposes an empty `HTTP_AUTHORIZATION` server variable that beta-1 currently treats as an authenticated request; origin requests therefore report `BYPASS authorization`. This is a follow-up compatibility bug rather than a package or deployment mismatch.

## Alpha.12 distribution validation

Validated on 2026-07-19:

- GitHub CI passed on PHP 8.1, 8.3, and 8.5, including the production package job.
- GitHub release `v0.1.0-alpha.12` is published as a prerelease from commit `7f03035`.
- The GitHub ZIP is 349,385 bytes with SHA-256 `5fd42dd4b236a280ce03a28c8cc95d0003f7e135e85c1c1212c22b1bbb94e646`; its checksum file, ZIP integrity, plugin header, runtime constant, and stable tag agree.
- The release workflow skips provenance attestation only when GitHub reports a private repository, where the service is unavailable; checksums and retained workflow artifacts remain mandatory.
- FluentCart product `1170147` points to alpha.12 download row `107`, containing the exact GitHub ZIP. Alpha.10 row `106` and its file remain the verified rollback target.
- A temporary non-customer FluentCart license activated successfully, returned valid alpha.12 metadata, downloaded the protected ZIP with HTTP 200 and the exact GitHub checksum, and left no temporary license, activation, or site rows.
- Gatilab reports GT Performance alpha.12 active. Its page-cache and Redis drop-ins are owned, `WP_CACHE` and Cloudflare are enabled, GT Performance's own purge succeeds, and the public homepage returns HTTP 200 through Cloudflare.
- The established Studio site installed the exact GitHub ZIP, reports alpha.12 active, owns the page-cache drop-in, and returns an anonymous `X-GT-Cache: MISS` followed by `HIT`.
- Studio cache headers match the maximum-impact default: one-hour fresh cache, 24-hour stale retention, and five-minute browser max-age.

## Automated gates

Validated on 2026-07-19:

- WordPress Coding Standards: 92 scanned PHP files passed.
- PHPStan: level 6 passed with WordPress and WP-CLI stubs.
- PHPUnit: 61 tests and 170 assertions passed.
- Composer security audit: no known vulnerable packages.
- Release package: production dependencies installed, ZIP integrity passed, and no development dependencies included.

## WordPress Studio CLI alpha.11 differentiation run

Runtime:

- WordPress Studio CLI 1.15.0
- WordPress 7.0.2
- PHP 8.2.32
- Fresh disposable native Studio site
- Packaged GT Performance `0.1.0-alpha.11`

Verified:

- The production ZIP installed and activated without warnings or fatals; its package, plugin header, stable tag, and runtime constant all report alpha.11.
- Dashboard navigation exposes Safety Lab, CSS Reports, Fleet, and the existing screens without raw internal notice keys.
- Optimization, Safety Lab, and Fleet rendered in the browser with no console warnings or errors and no page-level horizontal overflow.
- At a 390px viewport, main and panel gutters resolve to 20px, panels retain 20px bottom separation, and only the tab bar scrolls horizontally.
- The owned page-cache drop-in installed successfully, enabled `WP_CACHE`, and changed an anonymous homepage request from `X-GT-Cache: MISS` to `HIT`.
- Explain This Page returned the production eligibility reason, exact deterministic cache key, fresh artifact metadata, and expected Cloudflare expression.
- Purge and Verify removed the fresh origin artifact, observed stable response fingerprints, recorded a safe MISS followed by HIT, and returned a verified receipt.
- The Cloudflare rule compiler produced a within-budget create plan without mutating Cloudflare; live API sync remains credential-dependent.
- The CSS training repository accepted one valid structural selector, rejected an attribute-value selector, and exposed the Training Mode screen.
- Unused CSS file, inline, hybrid, and hybrid budget-fallback modes removed the known unused selector while preserving used state; setting rollout to zero restored the original stylesheet immediately.
- The signed Private Islands endpoint returned the registered cart count with `Cache-Control: no-store, private, max-age=0` and `X-GT-Private-Fragments: BYPASS`.
- Commerce Safety Lab completed cleanly with no active commerce plugins; adapter policy behavior remains covered by focused unit tests and full checkout E2E remains an external integration gate.
- Fleet export correctly failed closed while disabled, and its signed REST receiver route was registered without granting an arbitrary-code surface.

## WordPress Studio CLI admin and unused CSS run

Runtime:

- WordPress Studio CLI 1.11.0
- WordPress 7.0.2
- PHP 8.3.32
- Dedicated local Studio site
- Packaged GT Performance `0.1.0-alpha.4`

Verified:

- GT Performance appears as a top-level WordPress admin menu and opens at `admin.php?page=gt-performance`.
- The legacy `options-general.php?page=gt-performance` route redirects to the matching standalone tab.
- Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, CSS Reports, and Tools render without browser console errors.
- Optimization exposes 30 controls; Exceptions exposes cache, CSS, JavaScript, and media exception lists; Cloudflare exposes token and Global API Key authentication fields.
- Desktop and 390px mobile layouts have no page-level horizontal overflow. Tabs and the CSS report table use intentional local horizontal scrolling.
- Mobile gutters, panel padding, and control heights resolve to 20px, 20px, and 44px respectively.
- Rounded status cards use only 1px borders; state is communicated with text color and soft background contrast.
- Known and unknown operation failures render friendly notices without exposing internal codes such as `gtp_cloudflare_token`.
- A settings save persisted the CSS safelist without resetting settings on other tabs and correctly marked older CSS reports stale.
- File, inline, and hybrid unused-CSS delivery all returned HTTP 200 with `X-GT-Cache: MISS`.
- A known unused selector was removed in every mode; used, hover-state, below-fold, and safelisted selectors were preserved.
- Hybrid mode wrote critical CSS inline and the below-fold rule to a separate immutable file.
- CSS Reports showed all three regenerated modes as ready, refreshed every three seconds, and reported no browser console errors.

## WordPress Playground smoke run

Runtime:

- WordPress 7.0.2
- PHP 8.1.34
- Fresh disposable site
- Source mounted as `gt-performance`

Verified:

- Plugin detected, activated, and rendered its settings screen without PHP warnings or fatals.
- The Cloudflare settings screen rendered both scoped-token and legacy Global API Key modes, including account email, domain, and optional Zone ID fields.
- Selective response-cookie tests verify that Core Forms voter cookies can be removed without dropping unrelated commerce/session cookies.
- Activation created the schema, schedules, compiled config, and writable cache directories.
- Page-cache drop-in installation added an owned `advanced-cache.php` and enabled `WP_CACHE`.
- An anonymous first request returned `X-GT-Cache: MISS`.
- The next identical request returned `X-GT-Cache: HIT`, an `Age` header, an ETag, and byte-identical HTML.
- `If-None-Match` returned HTTP 304 with an empty body.
- An ignored `utm_source` query reused the canonical cache entry.
- An unknown query parameter bypassed storage and returned `Cache-Control: no-store, private`.
- Full purge removed the cached files and the next request safely returned MISS.
- A purge/request cross-worker race returned a safe MISS after the runtime fix.
- Restarting WordPress with the mounted plugin temporarily unavailable did not fatal after the drop-in guard fix.
- Deactivation removed the owned page-cache drop-in, restored the plugin-owned `WP_CACHE` change, unscheduled jobs, and left the public site responding with HTTP 200.

## External gates still requiring credentials or installed integrations

- FluentCart, Easy Digital Downloads, and WooCommerce checkout E2E tests require those plugins and test payment configurations.
- Redis installation requires PhpRedis and a disposable Redis namespace.
- Multisite and host-specific caching combinations remain pre-stable compatibility work.

## Gatilab live validation

Validated on the authenticated Gatilab WordPress installation:

- Installed and activated GT Performance `0.1.0-alpha.3`; WordPress reports the same version.
- Page-cache drop-in is owned, `WP_CACHE` is enabled, Redis is owned, and the settings and Plugins screens have no browser console errors.
- Cloudflare Global API Key mode was configured through encrypted settings using the account email and domain; zone discovery and the managed Free-plan Cache Rule synchronized successfully.
- The Gatilab homepage contains a Core Forms poll and correctly remains uncached with its voter cookie.
- A normal article changed from origin `MISS` to `HIT`. Five origin-cache HIT samples had a median TTFB of 0.233 seconds versus 0.569 seconds for cache-bypassed requests.
- After Cloudflare synchronization, five consecutive edge HIT samples had a median TTFB of 0.100 seconds and all reported `CF-Cache-Status: HIT`.
