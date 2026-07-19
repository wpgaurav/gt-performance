# Changelog

## 0.1.0-alpha.4 - 2026-07-19

- Replaced the Settings submenu with a standalone top-level GT Performance admin at `admin.php?page=gt-performance`, while redirecting the legacy URL.
- Added focused Dashboard, Cache, Optimization, Exceptions, Cloudflare, Integrations, CSS Reports, and Tools sections with responsive WordPress-native controls.
- Exposed cache query/path/cookie exceptions, CSS safelists and stylesheet exclusions, JavaScript exclusions and delay patterns, and media selector exceptions.
- Exposed the remaining cache, CSS, JavaScript, media, font, database, WordPress cleanup, RUM, Cloudflare, and commerce settings.
- Added persistent unused CSS generation reports with live processing, ready, stale, skipped, and failed indicators plus delivery, size, savings, duration, and errors.
- Replaced internal action codes with friendly success, warning, and error notices, including safe fallback copy for unknown codes.
- Refined desktop and mobile spacing and replaced thick status-card borders with thin boundaries and soft status contrast.

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
