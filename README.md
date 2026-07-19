# GT Performance

GT Performance is an independent WordPress performance plugin for safe page caching, server-side frontend optimization, Cloudflare Free orchestration, and commerce-aware cache protection.

The current release is `0.1.0-alpha.9`. Origin caching uses a maximum-impact shared-cache profile while aggressive frontend transformations remain opt-in. Cache correctness and prevention of private commerce-page caching take priority over cache hit rate.

## What is implemented

- Atomic origin HTML cache with an early `advanced-cache.php` drop-in, deterministic keys, stale retention, response validation, exact URL purge, related-page invalidation, and preload queue.
- Reversible `WP_CACHE` management and drop-in ownership checks, including exact restoration of existing single-line declarations.
- Cloudflare Free setup through one managed Cache Rule, origin-aware TTLs, URL/full purge, encrypted API-secret storage, rule backup, and automatic fallback when a Free zone rejects custom query-string cache keys.
- First-class bypass policies and product invalidation for FluentCart, Easy Digital Downloads, and WooCommerce.
- Core Forms poll compatibility: the global voter cookie is suppressed only on pages without polls, while real poll pages remain uncached.
- Automatic optimization ownership for Perfmatters, plus active-plugin compatibility reporting for common cache and optimization plugins.
- Akismet and Jetpack safeguards for dynamic selectors, sensitive scripts, forms, comments, subscriptions, media, search, and visitor-state cookies.
- Server-side unused CSS processing with three delivery modes:
  - immutable external file;
  - fully inline;
  - critical CSS inline with the remaining CSS in an immutable file.
- Conservative JavaScript minification, defer, and interaction-delay controls.
- Image loading priorities, missing dimensions, WebP/AVIF variants, lightweight YouTube embeds, and optional local Google Fonts.
- Manual database scanning and selectable cleanup in Tools, scheduled database maintenance in Optimization, and Perfmatters-style WordPress request and bloat controls.
- Standalone GT Performance admin with Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, CSS Reports, and Tools sections.
- Encrypted FluentCart licensing with a dedicated License tab, protected WordPress updates, weekly verification, masked credentials, and on-demand checks.
- Administrator-bar actions for purging or warming the current page, regenerating its CSS, purging page and edge caches, flushing object cache, testing Redis, and opening reports.
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

## Requirements

- WordPress 6.6 or newer
- PHP 8.1 or newer with DOM and JSON
- Composer dependencies bundled in the release ZIP
- A writable `wp-content` directory for origin caching
- Optional: Cloudflare proxied DNS and a scoped API token or legacy Global API Key
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

Cloudflare changes, unused CSS, JavaScript transformations, database automation, Redis, image rewriting, and font hosting remain disabled until enabled by an administrator. Image dimensions and non-critical lazy loading are the only low-risk frontend transformation defaults.

When unused CSS parsing, stylesheet fetching, artifact writing, or HTML serialization fails, the original HTML and stylesheets are returned.

## Cloudflare Free setup

The recommended setup is a scoped token for the site’s zone with:

- Zone read access if GT Performance should discover the zone ID;
- Cache Rules edit access;
- Cache purge access.

Open **GT Performance → Cloudflare**, enter the token and domain, then select **Connect/sync Cloudflare**. The Zone ID is optional and can be discovered from the domain.

Legacy Global API Key authentication is also supported. Select **Global API Key**, then enter the account email, Global API Key, and domain. The key is encrypted at rest with the same site-keyed cipher used for scoped tokens. A scoped token remains safer because its permissions can be limited to one zone.

Credentials may instead be supplied in `wp-config.php` through `GTP_CLOUDFLARE_API_TOKEN`, or through `GTP_CLOUDFLARE_GLOBAL_API_KEY` with `GTP_CLOUDFLARE_EMAIL`. `GTP_CLOUDFLARE_DOMAIN` can provide the zone name. Constants take precedence over saved values.

GT Performance uses the normal Cloudflare CDN fetch path and Cache Rules; it does not require APO, Workers, Cache Reserve, Argo, or an Enterprise plan.

If Cloudflare Free does not expose custom cache-key controls on the zone, GT Performance retries with a portable rule. Marketing query parameters will still be normalized by the origin cache, while Cloudflare may keep separate edge entries for those URLs.

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

This is an alpha for disposable-site and controlled staging validation. Cloudflare mutations require real credentials and are not exercised by the offline test suite. FluentCart, EDD, WooCommerce, multisite, and host-cache combinations still need a growing compatibility matrix before a stable release.

GT Performance is an independent implementation. It does not include or copy FlyingPress code, branding, or private protocols.
