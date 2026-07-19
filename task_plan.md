# Task Plan: GT Performance Marketing Assets and FluentCart Updater

## Goal

Create a production-ready visual asset kit for WordPress and FluentCart listings, then implement and verify a secure FluentCart Pro licensed updater that follows the proven ACF Blocks contract.

## Phases

- [x] Phase 1: Establish scope, safety constraints, and planning workspace
- [x] Phase 2: Reverse-engineer the ACF Blocks and FluentCart Pro updater contracts
- [x] Phase 3: Define the GT Performance product, updater, and asset architecture
- [x] Phase 4: Implement the licensed updater and admin license experience
- [x] Phase 5: Create directory, FluentCart, social, and screenshot assets
- [x] Phase 6: Update package metadata, documentation, and release checks
- [x] Phase 7: Run automated, Studio, updater-contract, and visual verification
- [ ] Phase 8: Review the final diff and prepare a verified handoff

## Key Questions

1. Which request fields, routes, response fields, and error semantics does FluentCart Pro require?
2. Which parts of the ACF Blocks updater are reusable and which should be hardened or redesigned?
3. What stable product identifier should GT Performance use before the FluentCart product is published?
4. How should license keys and activation hashes be stored, redacted, and removed?
5. Which assets are required for WordPress.org, FluentCart, GitHub, and general marketing?
6. How do we keep directory assets outside the customer ZIP while preserving a canonical source?

## Decisions Made

- Treat the local ACF Blocks implementation and the installed FluentCart Pro server code as the updater contract authorities.
- Do not publish or mutate a FluentCart product until its exact post, variation, license metadata, and updater download row are verified.
- Use the public GitHub release artifact as the future FluentCart customer artifact so both delivery surfaces share the same package tree.
- Keep WordPress-directory artwork in a top-level distribution-assets area excluded from the installable ZIP.
- Preserve the existing GT Performance visual language: blue accent, neutral surfaces, thin boundaries, restrained radii, and no thick rounded borders.
- Use Magnific GPT 2 for the original raster mark and background field, then compose all text and real Studio screenshots deterministically.
- Keep the FluentCart product in draft until price and license-tier decisions are explicit.
- Release as `0.1.0-alpha.8`; the GitHub release ZIP and FluentCart download must be byte-for-byte identical.

## Errors Encountered

- Studio MCP is not exposed in this Codex session. Use the standalone WordPress Studio CLI for package/runtime validation.
- Custom cron schedules were not registered during activation because `plugins_loaded` had already fired. Register the shared schedule callback around the activation-time scheduling calls.

## Status

**Currently in Phase 8** - The updater, draft product page, Magnific-led asset kit, alpha.8 package, and Studio validation are complete. Remaining work is the final diff, commit/push/tag, GitHub release readback, and exact FluentCart package synchronization.
