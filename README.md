# GT Performance

GT Performance is an independent WordPress performance plugin for safe page caching, server-side frontend optimization, Cloudflare Free orchestration, and commerce-aware cache protection.

The current release is `1.0.5`, distributed free through the WordPress.org plugin directory. Origin caching uses a maximum-impact shared-cache profile while aggressive frontend transformations remain opt-in. Cache correctness and prevention of private commerce-page caching take priority over cache hit rate.

## What is implemented

- Atomic origin HTML cache with an early `advanced-cache.php` drop-in, deterministic keys, stale retention, response validation, exact URL purge, configurable automatic invalidation after publishing, a preload queue, and sitemap-driven cache warming after a full purge.
- Reversible `WP_CACHE` management and drop-in ownership checks, including exact restoration of existing single-line declarations.
- Cloudflare Free setup through one managed Cache Rule, origin-aware TTLs, URL/full purge, encrypted API-secret storage, rule backup, and automatic fallback when a Free zone rejects custom query-string cache keys.
- xCloud site discovery, host-cache status, and cache invalidation through its Public API, plus separate detection and 12-hour traffic reporting for xCloud's Cloudflare Enterprise add-on.
- Optional origin-pull CDN URL rewriting for an HTTPS hostname or hostname plus path, restricted to selected static-file extensions and same-site source URLs.
- A Cloudflare Free rule compiler that previews the exact expression, managed-rule drift, competing rules, operation, and remaining ten-rule budget before synchronization.
- First-class bypass policies and product invalidation for FluentCart, Easy Digital Downloads, and WooCommerce.
- Core Forms poll compatibility: the global voter cookie is suppressed only on pages without polls, while real poll pages remain uncached.
- Automatic optimization ownership for Perfmatters, plus active-plugin compatibility reporting for common cache and optimization plugins.
- Akismet and Jetpack safeguards for dynamic selectors, sensitive scripts, forms, comments, subscriptions, media, search, and visitor-state cookies.
- Automatic tracker protection for Independent Analytics, Burst Statistics, Koko Analytics, Matomo Analytics, WP Statistics, Site Kit by Google, MonsterInsights, ExactMetrics, and PixelYourSite when those plugins are active.
- Server-side unused CSS processing with three delivery modes:
  - immutable external file;
  - fully inline;
  - critical CSS inline with the remaining CSS in an immutable file.
- Unused CSS Training Mode that records bounded structural selectors during an administrator browsing session, then supports review, publication, rollback, and deterministic 0/10/25/50/100 percent rollout cohorts.
- Conservative JavaScript minification, defer, and interaction-delay controls.
- Image loading priorities, missing dimensions, WebP/AVIF variants, lightweight YouTube embeds, and optional local Google Fonts.
- Manual database scanning and selectable cleanup in Tools, scheduled database maintenance in Optimization, and Perfmatters-style WordPress request and bloat controls.
- Explain This Page diagnostics with cache-decision reasons, deterministic key, local artifact state, and the expected Cloudflare result.
- Verified Purge receipts that compare bounded response fingerprints and cache headers after origin and edge invalidation without storing page bodies.
- Commerce Safety Lab policy simulation and safe read-only route checks for active FluentCart, EDD, and WooCommerce adapters.
- Opt-in Private Islands for signed, explicitly registered cart-count, account-link, and developer fragments whose responses are always private and `no-store`.
- A Fleet Console foundation for short-lived, one-use configuration bundles signed with a shared secret that exclude credentials and cannot execute code.
- Standalone GT Performance admin with Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, Safety Lab, CSS Reports, Fleet, and Tools sections.
- Administrator-bar actions for explaining, purging, or purge-verifying the current page, warming it, regenerating its CSS, controlling CSS Training Mode, purging page and edge caches, flushing object cache, testing Redis, and opening safety reports.
- Comprehensive cache, CSS, JavaScript, media, font, database, bloat, Cloudflare, commerce, and exception controls.
- Live unused-CSS processing reports with ready, processing, stale, skipped, and failed states plus delivery and size details.
- Redacted logs, WP-CLI doctor/cache/queue/Cloudflare/database commands, durable jobs, retries, and dead-letter state.

The full product architecture and 1.0 roadmap are in [PRODUCT-PLAN.md](PRODUCT-PLAN.md).

## How unused CSS works

GT Performance processes the final anonymous HTML response on the WordPress server. It collects eligible same-origin stylesheets and inline style blocks, parses them into a CSS syntax tree, matches selectors against the rendered document, and keeps configured safelist and dynamic-state selectors conservatively. Safelist lines use partial matching by default and accept validated delimited regular expressions such as `/^\.modal(?:--|\b)/i`. Excluded or cross-origin stylesheets remain untouched.

After a non-empty used-CSS result is verified, the original collected style nodes are replaced according to the selected delivery mode:

- **Generated file:** all used CSS is written to an immutable, content-hashed file.
- **Inline all used CSS:** all used CSS is added to the document head in a style element.
- **Critical inline + remaining file:** conservatively detected early-page CSS is inlined and the remaining used CSS is written to a hashed file. If the critical segment exceeds the configured inline budget, the optimizer falls back to a generated file instead of inflating the HTML.

If collection, parsing, pruning, artifact writing, or HTML serialization fails, GT Performance returns the original HTML and stylesheets.

Training Mode is administrator-only and expires after one hour. It observes element IDs and classes while an administrator exercises menus, dialogs, validation states, carts, and other interactive UI. It never records text, form values, cookies, or customer data. Candidates remain separate until reviewed and published. The staged rollout control assigns each URL to a stable cohort, and setting it to zero restores original stylesheets immediately.

## Diagnostics and safety

Open **GT Performance → Safety Lab** to explain a public URL, run a purge with readback, or test active commerce adapters. Explain This Page reuses the production eligibility policy instead of approximating it. Verified Purge stores a bounded receipt containing timestamps, hashes, status, `Age`, Cloudflare cache state, and public/private response signals; it does not retain HTML bodies.

Commerce Safety Lab first simulates every registered dynamic path, cookie prefix, and query parameter in memory. It then sends safe `GET` requests to configured cart, checkout, account, and receipt routes. It never creates an order, changes a cart, follows a payment action, or writes customer data.

## Private Islands

Private Islands is disabled by default. When enabled in **Integrations**, the shortcode `[gtperf_private_island id="commerce_cart_count"]` renders a public fallback that is replaced through a signed private request. `commerce_account_link` is also registered by default. Developers can add explicit fragments through `gt_performance_private_fragments`; arbitrary callbacks or markup requested by a visitor are never executed.

Every fragment response sends `Cache-Control: no-store, private, max-age=0`. If JavaScript, signature validation, or the endpoint fails, the public fallback remains in place.

## Fleet Console

Fleet Console moves reviewed settings between your sites. Save the same fleet signing secret on every site (or define `GTPERF_FLEET_SIGNING_SECRET` in `wp-config.php`); bundles are signed with a key derived from it. Exports expire after five minutes and imports are accepted only once. Cloudflare credentials, Redis credentials, the signing secret itself, and other secret fields are stripped recursively even when their parent module is selected.

The receiver applies only sanitized GT Performance settings. It does not install plugins, upload files, evaluate PHP, or expose a remote command channel. Sites can disable importing and remain export-only.

## Requirements

- WordPress 6.6 or newer
- PHP 8.1 or newer with DOM and JSON
- Composer dependencies bundled in the release ZIP
- A writable `wp-content` directory for origin caching
- Optional: Cloudflare proxied DNS and a scoped API token or legacy Global API Key
- Optional: an xCloud API token with `read:sites` and `write:sites` scopes
- Optional: an origin-pull CDN hostname configured to fetch static files from the WordPress site
- Optional: PhpRedis for the object-cache drop-in

## Redis object cache

Open **GT Performance → Integrations** to configure a Redis host or Unix socket, port, database, ACL username, password, TLS, persistent connections, key prefix, and bounded connection/read timeouts. Passwords are encrypted in the WordPress option. The early object-cache drop-in receives a guarded runtime configuration and fails back to request-local caching if Redis is unavailable.

GT Performance reads the standard constants used by [Till Krüss Redis Object Cache](https://github.com/rhubarbgroup/redis-cache), so an existing configuration does not need to be duplicated. The Integrations screen includes this copy-ready `wp-config.php` example:

```php
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_REDIS_DATABASE', 0 );
define( 'WP_REDIS_PASSWORD', array( 'username', 'replace-with-a-secret' ) );
define( 'WP_REDIS_PREFIX', 'gtperf:site:' );
define( 'WP_REDIS_TIMEOUT', 0.5 );
define( 'WP_REDIS_READ_TIMEOUT', 0.5 );
```

`WP_REDIS_PATH` with `WP_REDIS_SCHEME` set to `unix` is supported for sockets; `tls` and `rediss` schemes enable TLS. `WP_REDIS_DISABLED` is honored as the emergency switch. Existing `GTPERF_REDIS_*` constants remain supported and take highest precedence over compatible constants and saved settings.

## Safe defaults

The origin cache setting defaults on with one hour of freshness, 24 hours of shared retention and stale-if-error protection, and five minutes of browser caching. It remains inactive until the owned page-cache drop-in and `WP_CACHE` are installed. Logged-in caching stays off, and commerce adapters continue to bypass personalized state.

Cloudflare changes, unused CSS, CSS Training Mode, Private Islands, Fleet Console, JavaScript transformations, database automation, Redis, image rewriting, and font hosting remain disabled until enabled by an administrator. Image dimensions and non-critical lazy loading are the only low-risk frontend transformation defaults.

When unused CSS parsing, stylesheet fetching, artifact writing, or HTML serialization fails, the original HTML and stylesheets are returned.

## Cloudflare Free setup

The recommended setup is a scoped token for the site’s zone with:

- Zone read access if GT Performance should discover the zone ID;
- Cache Rules edit access;
- Cache purge access.

Open **GT Performance → Cloudflare**, enter the token and domain, then select **Connect/sync Cloudflare**. The Zone ID is optional and can be discovered from the domain.

Select **Preview rule plan** before synchronization to inspect the exact managed expression, whether GT Performance will create, update, or leave the rule unchanged, competing rule overlaps, and remaining Cloudflare Free rule capacity. GT Performance will not create its rule when the ten-rule budget is already full.

Legacy Global API Key authentication is also supported. Select **Global API Key**, then enter the account email, Global API Key, and domain. The key is encrypted at rest with the same site-keyed cipher used for scoped tokens. A scoped token remains safer because its permissions can be limited to one zone.

Credentials may instead be supplied in `wp-config.php` through `GTPERF_CLOUDFLARE_API_TOKEN`, or through `GTPERF_CLOUDFLARE_GLOBAL_API_KEY` with `GTPERF_CLOUDFLARE_EMAIL`. `GTPERF_CLOUDFLARE_DOMAIN` can provide the zone name. Constants take precedence over saved values.

GT Performance uses the normal Cloudflare CDN fetch path and Cache Rules; it does not require APO, Workers, Cache Reserve, Argo, or an Enterprise plan.

If Cloudflare Free does not expose custom cache-key controls on the zone, GT Performance retries with a portable rule. Marketing query parameters will still be normalized by the origin cache, while Cloudflare may keep separate edge entries for those URLs.

### Cloudflare WP-CLI operations

Use the Cloudflare command family to inspect or change only the edge layer:

```bash
wp gt-performance cloudflare status
wp gt-performance cloudflare plan
wp gt-performance cloudflare sync
wp gt-performance cloudflare purge
wp gt-performance cloudflare purge --page-url=https://example.com/page/
```

The purge command exits non-zero when credentials, zone discovery, URL validation, or the Cloudflare API fails. Use `wp gt-performance cache purge` when both GT Performance's origin page cache and the connected Cloudflare cache should be cleared together.

## xCloud and Cloudflare Enterprise

Open **GT Performance → Integrations** to connect an xCloud API token. GT Performance discovers the exact hosted domain and keeps three cache products separate:

- xCloud host page cache, purged through the narrow Public API page-cache endpoint;
- xCloud's free Edge Full Page Cache, purged through its documented host all-cache endpoint only when that free edge layer is enabled;
- the paid Cloudflare Enterprise add-on, detected independently through its add-on analytics capability.

When Cloudflare Enterprise is active, GT Performance treats xCloud as the edge owner and blocks direct Cloudflare rule synchronization and duplicate direct-Cloudflare purges. The Integrations screen reports the last 12 hours of total and Cloudflare-served requests.

Private and commerce responses send browser `Cache-Control`, standard `CDN-Cache-Control`, and Cloudflare's higher-priority `Cloudflare-CDN-Cache-Control` no-store directives as defense in depth. Live xCloud testing found that the add-on's current **Edge Page Caching** rule overrides even these origin directives and caches cart, checkout, account, and receipt HTML. Commerce sites must keep **Edge Page Caching** off unless xCloud provides equivalent request-level bypass rules. Enterprise static caching, WAF, DDoS protection, HTTP/3, Brotli, and the add-on's other features can remain enabled.

xCloud's current Public API does not publish a token-authenticated purge operation for the Enterprise add-on. The dashboard's Enterprise purge action requires an interactive xCloud session, so GT Performance deliberately fails closed instead of calling xCloud's unrelated broad host `purge-all` endpoint. Use the Purge control on the site's Cloudflare Enterprise page until xCloud adds that operation to the Public API.

The token is encrypted in the WordPress option. It may instead be supplied with `GTPERF_XCLOUD_API_TOKEN` in `wp-config.php`; the constant takes precedence over the saved value.

```bash
wp gt-performance xcloud status
wp gt-performance xcloud refresh
wp gt-performance xcloud purge
```

`xcloud purge` exits non-zero for an active Enterprise add-on rather than claiming a purge that xCloud's token API did not perform.

## Recommended integration defaults

When an integration is switched on in the WordPress admin, GT Performance fills only missing values with its recommended baseline and arms safe dependent safeguards. It preserves saved credentials, provider endpoints, and non-empty custom values.

- Cloudflare defaults to scoped-token authentication, the current site domain, and a 24-hour edge lifetime.
- xCloud defaults to the current site domain while site identifiers remain API-discovered.
- CDN rewriting defaults to static styles, scripts, images, and font formats only.
- Redis defaults to local PhpRedis with short half-second timeouts; existing remote host, database, and credential values are preserved.
- Compatibility protection defaults to automatic Perfmatters ownership plus dormant Akismet and Jetpack safeguards that activate only when those plugins are active.
- Private Islands enables both registered commerce fragments, while Fleet enables signed imports and the full safe configuration-module set.

## Custom asset CDN

Open **GT Performance → CDN** to rewrite selected same-site static asset URLs to a separate HTTPS CDN hostname. The provider must support origin pull and retain the original WordPress path. Cloudflare remains independent: it can continue caching eligible HTML while browsers request selected CSS, JavaScript, image, font, media, or download files from the custom CDN.

Only explicitly selected extensions are rewritten. Third-party URLs, extensionless routes, HTML, API responses, data URLs, and other unselected file types stay on their original URLs. Changing CDN settings purges GT Performance's origin page cache and the connected Cloudflare cache; purge the separate CDN through its provider when replacing an asset at the same URL.

## Updates

GT Performance is free software distributed through the WordPress.org plugin directory. Updates arrive through the normal WordPress update flow with no license key or activation.

## Development

Development happens in the open in this repository. Bug reports and pull requests are welcome at [github.com/wpgaurav/gt-performance](https://github.com/wpgaurav/gt-performance).

```bash
composer install
composer check
./bin/build-package.sh
```

`composer check` runs WordPress coding standards, PHPStan level 6 with WordPress/WP-CLI stubs, and PHPUnit.

## Status

Cloudflare and xCloud mutations require real credentials and are not exercised by the offline test suite. FluentCart, EDD, WooCommerce, multisite, image-optimizer, and host-cache combinations continue to grow their compatibility matrix.

GT Performance is an independent implementation. It does not include or copy FlyingPress code, branding, or private protocols.
