# Changelog

## 0.1.0-alpha.3 - 2026-07-18

- Added Core Forms compatibility that selectively removes its globally emitted voter cookie only on pages without polls.
- Preserved the voter cookie and cache rejection on actual poll pages so voting identity and duplicate-vote protection remain correct.

## 0.1.0-alpha.2 - 2026-07-18

- Added Cloudflare authentication modes for scoped API tokens and legacy Global API Keys with account email.
- Added a domain setting for zone discovery, retained optional direct Zone ID configuration, and added matching `wp-config.php` constants.
- Encrypted Global API Keys at rest and covered both Cloudflare authentication header schemes with unit tests.
- Fixed false `WP_CACHE` custom-declaration errors caused by comments, supports existing single-line declarations, and preserves the exact declaration for restoration.

## 0.1.0-alpha.1 - 2026-07-18

- Added an atomic origin page cache, early drop-in, eligibility policy, response validation, targeted invalidation, stale retention, and durable preload/purge queue.
- Added Cloudflare Free Cache Rule management, scoped token encryption, zone discovery, URL/full purge, drift state, backup, and portable cache-key fallback.
- Added FluentCart, Easy Digital Downloads, and WooCommerce cache bypass and product invalidation adapters.
- Added server-side unused CSS analysis with file, inline, and critical-inline-plus-file delivery.
- Added JavaScript, media, image variant, YouTube, Google Fonts, database, WordPress bloat, Redis, and Core Web Vitals modules.
- Added a native settings screen, WP-CLI commands, redacted diagnostics, PHPUnit coverage, PHPStan, WPCS, CI, Playground smoke validation, and release packaging.
