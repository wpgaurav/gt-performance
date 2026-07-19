# Notes: GT Performance Product Research

## 2026-07-19 Distribution and Updater Research

### Scope

- Build canonical marketing and directory assets for GT Performance.
- Match the updater contract used by ACF Blocks and served by FluentCart Pro.
- Keep FluentCart publication as a separate, verified release operation once the exact GT Performance product exists.

### Confirmed WordPress Directory Asset Contract

- Normal banner: `banner-772x250.png`.
- Retina banner: `banner-1544x500.png`; it supplements rather than replaces the normal banner.
- Normal icon: `icon-128x128.png`.
- Retina icon: `icon-256x256.png`.
- Optional vector icon: `icon.svg`, with PNG fallback still required.
- Screenshots use `screenshot-1.png`, `screenshot-2.png`, and so on, with matching captions in `readme.txt`.
- Directory assets belong beside `trunk` and `tags` in WordPress.org SVN, not inside the customer plugin ZIP.

Primary source: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/

### Safety Constraints

- Never print FluentCart license keys, activation hashes, signed package URLs, customer data, or SSH credentials.
- Parse only the required SSH command from `~/.env`; never source the file.
- Preserve the previous FluentCart download row and file during an eventual product release.
- Use an expected-old-state check and transaction for future product version/file mutations.

### Implemented Product and Release State

- FluentCart product ID: `1170147`.
- The product page is published at $199 with a one-site lifetime license.
- FluentCart updater responses are top-level JSON objects; the client also accepts the nested `data` shape for compatibility.
- Protected package URLs are exposed to WordPress only when FluentCart reports a valid activation.
- The license key and activation hash are encrypted separately at rest.
- `0.1.0-alpha.8` is the first release with the licensed updater and product identity.
- GitHub release `v0.1.0-alpha.8` is the package authority. Its ZIP is 314,835 bytes with SHA-256 `c6c279679d5a8a0fbe7cbdcabf15683adfe5037699e0fef0fe1755f865597816`.
- FluentCart download row `104` points to that exact ZIP; row `103` and the alpha.7 file remain available for rollback.
- FluentCart's local driver requires `file_path` to be relative to its storage directory. An absolute path passes metadata checks but the signed download resolves to HTML instead of the ZIP.
- A temporary Studio activation verified valid metadata, the protected package response, ZIP magic bytes, exact size, and the GitHub SHA-256. The temporary license, activation, site, and local option were removed afterward.

### Marketing Asset System

- Magnific GPT 2 produced the original 2048 × 2048 icon master and 3072 × 2048 background field.
- Exact typography and real WordPress Studio screenshots are composed through `distribution-assets/source/marketing-assets.html`.
- Directory icons, banners, five screenshots, a FluentCart cover, and a social card are stored outside the installable ZIP.
- The WordPress-ready product page is generated as a `marketers-delight/inline-page-block` so its scoped CSS follows the established shop-page contract.
- FluentCart direct checkout for variation `68` uses `?fluent-cart=instant_checkout&item_id=68&quantity=1`; a fresh guest request redirected to Checkout with GT Performance at exactly $199.
- The published page shows price and direct checkout in the hero, alpha section, and final offer, with cache-busted desktop and 390px phone verification.

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
- API tokens should use minimum zone permissions and remain the recommended default. Legacy Global API Keys require the account email and must be explicitly selected.

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
