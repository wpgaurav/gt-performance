# GT Performance feature status

This inventory describes the 1.0.12 submission candidate. The original beta implementation report is available in Git history.

- Origin page caching and commerce adapters protect eligible anonymous pages while bypassing private routes and session state.
- Explain This Page uses the production eligibility policy, deterministic key, local artifact metadata, and compiled Cloudflare expectation.
- Verified Purge records bounded, redacted receipts with artifact removal, response stability, cache headers, and safety signals. Receipts are available in Tools.
- The Cloudflare rule compiler previews rule capacity, drift, expected operation, overlaps, and the exact expression before synchronization.
- Unused CSS supports file, inline, and hybrid delivery, background generation, manual safelists, deterministic URL rollout, and status/regeneration controls in Optimization.
- JavaScript minification uses in-memory processing, transients, and signed external delivery. Defer and interaction delay remain available.
- Media, fonts, database cleanup, Redis, xCloud, and custom CDN controls remain opt-in where appropriate.

Private Islands, Fleet Console, Commerce Safety Lab, and CSS Training Mode were removed in 1.0.8 and are not available in this release. Their former controls and endpoints are not part of the current feature set.

See VALIDATION.md for version-specific test evidence.
