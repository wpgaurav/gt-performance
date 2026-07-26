# GT Performance

GT Performance is an independent WordPress performance plugin for safe page caching, server-side frontend optimization, Cloudflare Free orchestration, and commerce-aware cache protection.

The current release is `1.0.0-beta-5`. Origin caching uses a maximum-impact shared-cache profile while aggressive frontend transformations remain opt-in. Cache correctness and prevention of private commerce-page caching take priority over cache hit rate.

## What is implemented

- Atomic origin HTML cache with an early `advanced-cache.php` drop-in, deterministic keys, stale retention, response validation, exact URL purge, configurable automatic invalidation after publishing, a preload queue, and sitemap-driven cache warming after a full purge.
- Reversible `WP_CACHE` management and drop-in ownership checks, including exact restoration of existing single-line declarations.
- Cloudflare Free setup through one managed Cache Rule, origin-aware TTLs, URL/full purge, encrypted API-secret storage, rule backup, and automatic fallback when a Free zone rejects custom query-string cache keys.
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
- A 25-site Fleet Console foundation for short-lived, one-use, license-signed configuration bundles that exclude credentials and cannot execute code.
- Standalone GT Performance admin with Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, Safety Lab, CSS Reports, Fleet, License, and Tools sections.
- Encrypted FluentCart licensing with a dedicated License tab, protected WordPress updates, weekly verification, masked credentials, and on-demand checks.
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

Private Islands is disabled by default. When enabled in **Integrations**, the shortcode `[gtp_private_island id="commerce_cart_count"]` renders a public fallback that is replaced through a signed private request. `commerce_account_link` is also registered by default. Developers can add explicit fragments through `gt_performance_private_fragments`; arbitrary callbacks or markup requested by a visitor are never executed.

Every fragment response sends `Cache-Control: no-store, private, max-age=0`. If JavaScript, signature validation, or the endpoint fails, the public fallback remains in place.

## Fleet Console

Fleet Console moves reviewed settings between activations that share the same valid GT Performance license. Exports expire after five minutes and imports are accepted only once. License keys, Cloudflare credentials, Redis credentials, updater state, and other secret fields are stripped recursively even when their parent module is selected.

The receiver applies only sanitized GT Performance settings. It does not install plugins, upload files, evaluate PHP, or expose a remote command channel. Sites can disable importing and remain export-only.

## Requirements

- WordPress 6.6 or newer
- PHP 8.1 or newer with DOM and JSON
- Composer dependencies bundled in the release ZIP
- A writable `wp-content` directory for origin caching
- Optional: Cloudflare proxied DNS and a scoped API token or legacy Global API Key
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
define( 'WP_REDIS_PREFIX', 'gtp:site:' );
define( 'WP_REDIS_TIMEOUT', 0.5 );
define( 'WP_REDIS_READ_TIMEOUT', 0.5 );
```

`WP_REDIS_PATH` with `WP_REDIS_SCHEME` set to `unix` is supported for sockets; `tls` and `rediss` schemes enable TLS. `WP_REDIS_DISABLED` is honored as the emergency switch. Existing `GTP_REDIS_*` constants remain supported and take highest precedence over compatible constants and saved settings.

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

Credentials may instead be supplied in `wp-config.php` through `GTP_CLOUDFLARE_API_TOKEN`, or through `GTP_CLOUDFLARE_GLOBAL_API_KEY` with `GTP_CLOUDFLARE_EMAIL`. `GTP_CLOUDFLARE_DOMAIN` can provide the zone name. Constants take precedence over saved values.

GT Performance uses the normal Cloudflare CDN fetch path and Cache Rules; it does not require APO, Workers, Cache Reserve, Argo, or an Enterprise plan.

If Cloudflare Free does not expose custom cache-key controls on the zone, GT Performance retries with a portable rule. Marketing query parameters will still be normalized by the origin cache, while Cloudflare may keep separate edge entries for those URLs.

## Custom asset CDN

Open **GT Performance → CDN** to rewrite selected same-site static asset URLs to a separate HTTPS CDN hostname. The provider must support origin pull and retain the original WordPress path. Cloudflare remains independent: it can continue caching eligible HTML while browsers request selected CSS, JavaScript, image, font, media, or download files from the custom CDN.

Only explicitly selected extensions are rewritten. Third-party URLs, extensionless routes, HTML, API responses, data URLs, and other unselected file types stay on their original URLs. Changing CDN settings purges GT Performance's origin page cache and the connected Cloudflare cache; purge the separate CDN through its provider when replacing an asset at the same URL.

## License and protected updates

Open **GT Performance → License** and activate the key from your FluentCart account. GT Performance encrypts the key and activation hash separately, checks version metadata through the normal WordPress update flow, and receives a temporary package URL only for a valid site activation.

A deployment-managed key may be defined before the WordPress stop-editing comment:

```php
define( 'GTP_LICENSE_KEY', 'replace-with-your-license-key' );
```

The saved option never replaces a `GTP_LICENSE_KEY` constant. Deactivate the site from the License tab before removing or moving a deployment-managed key.

## Development

```bash
composer install
composer check
./bin/build-package.sh
```

`composer check` runs WordPress coding standards, PHPStan level 6 with WordPress/WP-CLI stubs, and PHPUnit.

## Status

This is a beta for controlled staging and production validation. Cloudflare mutations require real credentials and are not exercised by the offline test suite. FluentCart, EDD, WooCommerce, multisite, and host-cache combinations still need a growing compatibility matrix before a stable release.

GT Performance is an independent implementation. It does not include or copy FlyingPress code, branding, or private protocols.
