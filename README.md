# GT Performance

GT Performance is an independent WordPress performance plugin for safe page caching, server-side frontend optimization, Cloudflare Free orchestration, and commerce-aware cache protection.

The current release is `0.1.0-alpha.3`. Aggressive transformations are opt-in. Cache correctness and prevention of private commerce-page caching take priority over cache hit rate.

## What is implemented

- Atomic origin HTML cache with an early `advanced-cache.php` drop-in, deterministic keys, stale retention, response validation, exact URL purge, related-page invalidation, and preload queue.
- Reversible `WP_CACHE` management and drop-in ownership checks, including exact restoration of existing single-line declarations.
- Cloudflare Free setup through one managed Cache Rule, origin-aware TTLs, URL/full purge, encrypted API-secret storage, rule backup, and automatic fallback when a Free zone rejects custom query-string cache keys.
- First-class bypass policies and product invalidation for FluentCart, Easy Digital Downloads, and WooCommerce.
- Core Forms poll compatibility: the global voter cookie is suppressed only on pages without polls, while real poll pages remain uncached.
- Server-side unused CSS processing with three delivery modes:
  - immutable external file;
  - fully inline;
  - critical CSS inline with the remaining CSS in an immutable file.
- Conservative JavaScript minification, defer, and interaction-delay controls.
- Image loading priorities, missing dimensions, WebP/AVIF variants, lightweight YouTube embeds, and optional local Google Fonts.
- Database dry-run/cleanup, common WordPress bloat controls, optional Redis object-cache drop-in, and sampled Core Web Vitals collection.
- Native Settings screen, redacted logs, WP-CLI doctor/cache/queue/Cloudflare/database commands, durable jobs, retries, and dead-letter state.

The full product architecture and 1.0 roadmap are in [PRODUCT-PLAN.md](PRODUCT-PLAN.md).

## Requirements

- WordPress 6.6 or newer
- PHP 8.1 or newer with DOM and JSON
- Composer dependencies bundled in the release ZIP
- A writable `wp-content` directory for origin caching
- Optional: Cloudflare proxied DNS and a scoped API token or legacy Global API Key
- Optional: PhpRedis for the object-cache drop-in

## Safe defaults

The page cache, Cloudflare changes, unused CSS, JavaScript transformations, database automation, Redis, image rewriting, font hosting, and RUM are disabled until enabled by an administrator. Image dimensions and non-critical lazy loading are the only low-risk frontend defaults.

When unused CSS parsing, stylesheet fetching, artifact writing, or HTML serialization fails, the original HTML and stylesheets are returned.

## Cloudflare Free setup

The recommended setup is a scoped token for the site’s zone with:

- Zone read access if GT Performance should discover the zone ID;
- Cache Rules edit access;
- Cache purge access.

Enter the token under **Settings → GT Performance**, add the domain, then select **Connect/sync Cloudflare**. The Zone ID is optional and can be discovered from the domain.

Legacy Global API Key authentication is also supported. Select **Global API Key**, then enter the account email, Global API Key, and domain. The key is encrypted at rest with the same site-keyed cipher used for scoped tokens. A scoped token remains safer because its permissions can be limited to one zone.

Credentials may instead be supplied in `wp-config.php` through `GTP_CLOUDFLARE_API_TOKEN`, or through `GTP_CLOUDFLARE_GLOBAL_API_KEY` with `GTP_CLOUDFLARE_EMAIL`. `GTP_CLOUDFLARE_DOMAIN` can provide the zone name. Constants take precedence over saved values.

GT Performance uses the normal Cloudflare CDN fetch path and Cache Rules; it does not require APO, Workers, Cache Reserve, Argo, or an Enterprise plan.

If Cloudflare Free does not expose custom cache-key controls on the zone, GT Performance retries with a portable rule. Marketing query parameters will still be normalized by the origin cache, while Cloudflare may keep separate edge entries for those URLs.

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
