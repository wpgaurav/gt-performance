# GT Performance

GT Performance is an independent WordPress performance plugin for safe page caching, server-side frontend optimization, Cloudflare Free orchestration, and commerce-aware cache protection.

The current release is `1.0.14`. It is free GPL software; it is not yet listed in the WordPress.org plugin directory, and submission is pending. Origin caching uses a maximum-impact shared-cache profile while aggressive frontend transformations remain opt-in. Cache correctness and prevention of private commerce-page caching take priority over cache hit rate.

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
- Conservative JavaScript minification, defer, and interaction-delay controls.
- Image loading priorities, missing dimensions, WebP/AVIF variants, lightweight YouTube embeds, and optional local Google Fonts.
- Manual database scanning and selectable cleanup in Tools, scheduled database maintenance in Optimization, and Perfmatters-style WordPress request and bloat controls.
- Explain This Page diagnostics with cache-decision reasons, deterministic key, local artifact state, and the expected Cloudflare result.
- Verified Purge receipts that compare bounded response fingerprints and cache headers after origin and edge invalidation without storing page bodies.
- Standalone GT Performance admin with Dashboard, Cache, Optimization, Exceptions, Cloudflare, CDN, Integrations, and Tools sections.
- Administrator-bar actions for purging or purge-verifying the current page, warming it, purging page and edge caches, flushing object cache, and testing Redis.
- Comprehensive cache, CSS, JavaScript, media, font, database, bloat, Cloudflare, commerce, and exception controls.
- Live unused-CSS processing reports with ready, processing, stale, skipped, and failed states plus delivery and size details.
- Redacted logs, WP-CLI doctor/cache/queue/Cloudflare/database commands, durable jobs, retries, and dead-letter state.

This list describes the current plugin. The original product plan is historical design context, not a list of available features.

## How unused CSS works

The **Optimization → Unused CSS status** panel shows queued, processing, ready, stale, failed, and skipped results; original and generated sizes; build duration; and failure details. Totals cover all stored URL/mode reports, while the table shows the latest 50. Use **Refresh status** to update the report without losing unsaved settings. Savings describe analyzed CSS bytes, not measured visitor bandwidth.

Use **Force regenerate URL** or a row’s **Regenerate** button to invalidate that URL’s reusable CSS and queue a fresh build. **Force regenerate all CSS** invalidates all results, purges page caches, and rebuilds known eligible URLs in batches; other URLs rebuild on their next eligible visit. Builds respect saved rollout, exclusions, safe mode, and optimization ownership. WordPress cron must run to drain the queue. Existing generated files are retained for cached pages.

GT Performance processes the final anonymous HTML response on the WordPress server. It collects eligible same-origin stylesheets and inline style blocks, parses them into a CSS syntax tree, matches selectors against the rendered document, and keeps configured safelist and dynamic-state selectors conservatively. Safelist lines use partial matching by default and accept validated delimited regular expressions such as `/^\.modal(?:--|\b)/i`. Excluded or cross-origin stylesheets remain untouched.

Generated styles and bundled frontend loaders use WordPress registration, enqueue, and printing APIs. Inline CSS uses CSS escapes for less-than characters so stylesheet text cannot close its HTML style element. The completed response is transformed after its capture buffer closes, so core asset filters can run safely. Opt-in JavaScript minification processes eligible local classic scripts in memory and caches smaller results through WordPress transients, which use the database or an installed object cache. A signed same-origin endpoint serves the result with a versioned URL, browser caching, and ETag revalidation; it never writes JavaScript files or executes JavaScript on the server. Scripts remain external so defer and delay keep their normal execution timing. If the transient is evicted or minification is disabled, the signed URL falls back to the original script.

The first uncached request for a minified script boots WordPress, so this delivery method trades a PHP request for smaller transferred code and avoids generated executable files. Browser caches can reuse the response. Already minified scripts, modules, scripts with integrity attributes, scripts that depend on their own URL, cross-origin or query-driven scripts, unsafe paths, and files over 2 MB are left unchanged. Existing exclusions and commerce-script protection remain in force.

After a non-empty used-CSS result is verified, the original collected style nodes are replaced according to the selected delivery mode:

- **Generated file:** all used CSS is written to an immutable, content-hashed file.
- **Inline all used CSS:** all used CSS is added to the document head in a style element.
- **Critical inline + remaining file:** conservatively detected early-page CSS is inlined and the remaining used CSS is written to a hashed file. If the critical segment exceeds the configured inline budget, the optimizer falls back to a generated file instead of inflating the HTML.

If collection, parsing, pruning, artifact writing, or HTML serialization fails, GT Performance returns the original HTML and stylesheets.

The staged rollout control assigns each URL to a stable cohort. Setting it to zero restores original stylesheets immediately. Safelists and dynamic-state preservation protect selectors that are absent from the initial HTML.

## Diagnostics and safety

Run `wp gt-performance cache explain --page-url=https://example.com/page/` to inspect a URL's cache decision using the production eligibility policy. Use **Purge and verify this URL** in the administrator bar, then review the recorded receipts in **Tools**. Verified Purge stores bounded timestamps, hashes, status, `Age`, Cloudflare cache state, and public/private response signals; it does not retain HTML bodies.

Active FluentCart, EDD, and WooCommerce adapters supply bypass rules for dynamic paths, session cookies, and transactional query parameters.

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

Open **GT Performance → Integrations** to configure a Redis host or Unix socket, port, database, ACL username, password, TLS, persistent connections, key prefix, and bounded connection/read timeouts. Passwords are encrypted in the WordPress option. The early object-cache drop-in receives an authenticated encrypted JSON runtime configuration and fails back to request-local caching if Redis is unavailable.

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

Cloudflare changes, unused CSS, JavaScript transformations, database automation, Redis, image rewriting, and font hosting remain disabled until enabled by an administrator. Image dimensions and non-critical lazy loading are the only low-risk frontend transformation defaults.

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

## Custom asset CDN

Open **GT Performance → CDN** to rewrite selected same-site static asset URLs to a separate HTTPS CDN hostname. The provider must support origin pull and retain the original WordPress path. Cloudflare remains independent: it can continue caching eligible HTML while browsers request selected CSS, JavaScript, image, font, media, or download files from the custom CDN.

Only explicitly selected extensions are rewritten. Third-party URLs, extensionless routes, HTML, API responses, data URLs, and other unselected file types stay on their original URLs. Changing CDN settings purges GT Performance's origin page cache and the connected Cloudflare cache; purge the separate CDN through its provider when replacing an asset at the same URL.

## Updates

GT Performance is free software with no license key or activation. It is not yet listed in the WordPress.org plugin directory, so WordPress will not offer updates for it automatically: the plugin ships `Update URI: false` so that an unrelated plugin claiming the `gt-performance` slug can never push a package to these installs. Update by replacing the plugin directory with a release archive from this repository until the directory listing exists.

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

Runtime configuration copies require PHP OpenSSL and an existing non-placeholder WordPress `AUTH_KEY`. If unavailable, configuration installation fails without writing plaintext. After rotating `AUTH_KEY`, save the plugin settings to regenerate the runtime copies.

Debug diagnostics are stored as a bounded, non-autoloaded WordPress option, with secret fields redacted. Older plaintext diagnostic files are removed automatically. Cache configuration and drop-ins are published only after complete writes; failed settings publication is reported and the previous settings are retained.

### Updating the Cloudflare integration

After installing 1.0.14, open **GT Performance → Cloudflare → Connect/sync Cloudflare** once to update the managed rule with internal PURGE support. The sync preserves unrelated rules and applies the current checkout, session, and query protections. Installing the ZIP alone does not rewrite remote rules.

Manual page-cache purges and verification wait for the Cloudflare response. The Cloudflare panel records the latest accepted or failed request independently of debug logging. Temporary connection errors, HTTP 429 and server errors receive up to three WordPress cron retries; each retry contains only unfinished cache-key batches. Full-zone purges clear queued retries after Cloudflare confirms success. Desktop, mobile and tablet entries are included when separate device caching is enabled. API acceptance and public-response verification are reported separately.
