# GT Performance validation

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
