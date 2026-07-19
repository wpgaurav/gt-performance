# GT Performance validation

## Automated gates

Validated on 2026-07-19:

- WordPress Coding Standards: 59 production PHP files passed.
- PHPStan: level 6 passed with WordPress and WP-CLI stubs.
- PHPUnit: 21 tests and 39 assertions passed.
- Composer security audit: no known vulnerable packages.
- Release package: production dependencies installed, ZIP integrity passed, and no development dependencies included.

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
