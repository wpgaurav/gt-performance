# Notes: GT Performance Product Research

## Scope

This file records the research and constraints used to create `PRODUCT-PLAN.md`. It is not an implementation specification.

## FlyingPress Capability Inventory

Primary sources:

- FlyingPress features: https://flyingpress.com/features/
- FlyingPress configuration collection: https://docs.flyingpress.com/en/collections/12930847-configuration-features
- FlyingPress CSS and JavaScript collection: https://docs.flyingpress.com/en/collections/12931481-css-javascript-optimizations
- FlyingPress Cloudflare integration: https://docs.flyingpress.com/en/articles/11977701-flyingpress-cloudflare-integration-full-page-caching-setup-guide
- FlyingPress image optimization: https://docs.flyingpress.com/en/articles/13435045-image-optimization
- FlyingPress e-commerce compatibility: https://docs.flyingpress.com/en/articles/11405614-woocommerce-e-commerce-compatibility
- FlyingPress changelog: https://flyingpress.com/changelog/

Observed feature groups:

- Page caching, preload queue, related-page purge/preload, auto-refresh, mobile variants, role-specific logged-in caching, exclusions, query-parameter policy, cookie bypasses, and Redis object caching.
- CSS/JavaScript minification, unused-CSS removal, JavaScript deferral/delay, interaction-based script loading, and third-party asset self-hosting.
- Image lazy loading, responsive sizing, missing dimensions, critical-resource priority, background-image handling, image compression, and WebP/AVIF conversion.
- Video and iframe lazy loading, lightweight YouTube previews, Gravatar self-hosting, and lazy rendering of below-fold elements.
- Font preloading, Google Font self-hosting, and system-font-first behavior.
- Database cleanup, WordPress bloat controls, and real-user Core Web Vitals tracking.
- Cloudflare full-page cache, automatic purge, shared bypass/query/device settings, and cache-state verification.
- WooCommerce page exclusions plus product/archive purge and preload.
- A lightweight internal background queue and Redis object cache were added in 2026.

## Cloudflare Constraints and Opportunities

Primary sources:

- Cache API: https://developers.cloudflare.com/workers/runtime-apis/cache/
- Cache keys: https://developers.cloudflare.com/cache/how-to/cache-keys/
- Cache-tag purge: https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/
- Revalidation: https://developers.cloudflare.com/cache/concepts/revalidation/
- Cache-Control: https://developers.cloudflare.com/cache/concepts/cache-control/

Findings:

- `cache.put()` content is local to the data center that stored it and does not participate in Tiered Cache.
- Cloudflare recommends the `fetch()` path for tiered caching.
- Cache Rules, origin cache headers, query normalization, purge-by-URL, and capability-dependent purge-by-tag are the preferred full-page cache foundation.
- `stale-while-revalidate` can serve a cached response while Cloudflare refreshes it asynchronously, but incompatible directives can disable that behavior.
- Cache tags support dependency-based invalidation but must be treated as a capability, with URL purging as the portable fallback.
- GT Performance must detect the Cloudflare plan and available APIs rather than assume every feature is available.
- Cloudflare configuration changes must be idempotent, backed up, narrowly scoped, and reversible. The plugin must never replace unrelated customer rules.
- API tokens should use minimum zone permissions. Global API keys should not be requested.

## Commerce Requirements

### FluentCart

Primary sources:

- Assigned storefront pages: https://docs.fluentcart.com/guide/settings-configuration/pages-setup
- Checkout API: https://dev.fluentcart.com/restapi/checkout
- Cart and checkout hooks: https://dev.fluentcart.com/hooks/filters/cart-and-checkout
- Cart model: https://dev.fluentcart.com/database/models/cart

Requirements:

- Discover the configured Shop, Cart, Checkout, Customer Profile, and Receipt page IDs rather than assuming slugs.
- Bypass all cart-, checkout-, receipt-, account-, and customer-specific requests at WordPress, host, and Cloudflare layers.
- Recognize the cart hash/session cookie family, `fluent-cart` instant-checkout requests, FluentCart REST checkout/cart routes, and the checkout AJAX route.
- Never cache rendered checkout summaries, payment configuration, order information, receipts, or private customer responses.
- Purge product, taxonomy, shop/archive, related block/query, and known landing-page dependencies after price, stock, visibility, or product changes.
- Validate with a fresh guest session and the exact checkout URL produced by the storefront.

### Easy Digital Downloads

Primary sources:

- Cache configuration: https://easydigitaldownloads.com/docs/configure-cache/
- Empty-cart troubleshooting: https://easydigitaldownloads.com/docs/shopping-cart-is-empty-at-checkout/

Requirements:

- Discover Checkout, Purchase Confirmation, Purchase History, and account-related pages from EDD settings.
- Recognize EDD cart/session/purchase/discount cookie families and stateful checkout query arguments.
- Never cache checkout, confirmation, order history, cart recovery, discount application, fees, or personalized purchase data.
- Keep public download/product and taxonomy pages cacheable until product, price, file, status, or inventory changes require targeted invalidation.
- Avoid unsafe JavaScript transformations on checkout assets unless the compatibility suite proves them safe.

### WooCommerce

Primary source:

- FlyingPress compatibility behavior: https://docs.flyingpress.com/en/articles/11405614-woocommerce-e-commerce-compatibility

Requirements:

- Discover Cart, Checkout, My Account, Terms, order-pay, and order-received endpoints from WooCommerce configuration.
- Recognize cart/session cookies, `wc-ajax` requests, Store API cart/checkout routes, fragments, nonces, and personalized endpoints.
- Default to cached public catalog/product pages with client-side cart hydration where the theme supports it.
- Offer an explicit session-cookie bypass mode for themes whose cart UI is rendered into HTML.
- Targetedly purge product, variation, category, tag, shop, related-product, inventory, price, and sale dependencies.
- Test guest, logged-in, coupon, tax, shipping, variation, stock, and order-completion flows.

## Lessons From Existing Sites

- Page cache correctness must be verified across every active layer. A correct WordPress bypass is insufficient when Cloudflare still serves cached HTML.
- Dynamic commerce responses should produce `no-store`/private semantics and no edge age.
- Broad cache rules must be followed by higher-priority or later matching stateful bypasses, depending on the rule engine’s precedence model.
- Verify a commerce fix with a new guest session. Reusing an old cart produces misleading results.
- Cached gzip files must be served with correct HTML content type and decompression behavior. Header checks and body signatures belong in automated compatibility tests.
- Purge adapters must identify the installed cache/host integration. A generic LiteSpeed purge command is unsafe when a different cache system owns the page cache.
- Cache writes must be atomic, and failed optimization must fall back to the original page and asset graph.

## Server-Side Unused CSS Direction

User-required delivery modes:

1. Used CSS as an external file.
2. Used CSS fully inline.
3. Critical/important CSS inline with the remaining used CSS in an external file.

Proposed analysis pipeline:

- Fetch the final anonymous HTML through a signed loopback request that bypasses page cache but preserves normal rendering.
- Collect linked and inline styles, resolve imports, and parse them into an abstract syntax tree.
- Build template and device fingerprints from the route, theme, block styles, asset hashes, language, and configured variation dimensions.
- Run a conservative PHP selector pass on every supported host.
- When local Chromium is available, use viewport coverage for desktop and mobile plus an interaction-state corpus to improve critical/used separation.
- Preserve animation keyframes, font faces, custom properties, pseudo states, print rules, accessibility states, and selectors referenced from scripts or configured safelists.
- Write content-addressed output atomically and validate CSS syntax before publishing it.
- For hybrid delivery, inline a configurable critical budget and load the remaining used CSS from a hashed file without breaking the cascade.
- Retain the original stylesheet graph as an instant per-page and global fallback.
- Rebuild only affected fingerprints after content, theme, plugin, block, or asset changes.

## Open Product Decisions

- Commercial licensing and update infrastructure.
- Minimum PHP/WordPress versions. The draft plan recommends PHP 8.1+ and WordPress 6.6+ to avoid building a new performance product around end-of-life PHP.
- Whether local Chromium ships as an optional sidecar, a separately installed binary, or a managed service. The core server-side PHP analyzer must remain usable without it.
- Whether Redis object caching belongs in the first public release or a later module.
- Which Cloudflare paid capabilities become optional enhancements rather than base requirements.
