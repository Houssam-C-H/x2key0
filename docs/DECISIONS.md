# Architecture Decision Records

## ADR-001 — Build on spec defaults while M0 is open (2026-11-22)
**Context:** Founder decisions D1–D12 (spec §2.3) are unanswered.
**Decision:** Engineering proceeds with every documented default (FakePaymentProvider, USD settlement, en/fr/ar, PSP-held design, 8% commission, 3% min $0.30 buyer fee, no credits top-up, points → coupons only).
**Consequence:** All of these live in `settings` / config so they can change without code rewrites.

## ADR-002 — Versions at scaffold time (2026-11-23)
Laravel 13, PHP 8.4 (spec says 8.3+), Inertia 3, React 19, TypeScript 7, Tailwind 4, Vite 8, Filament 5, Pest 5, Larastan 3.
Sail dropped MinIO; **RustFS** is the S3-compatible store in `compose.yaml`. Code only talks to the S3 API, so either works.
Ziggy is not used: the server passes resolved URLs (e.g. menu hrefs), which also keeps SSR free of route tables.

## ADR-003 — Local tests on SQLite until Docker runs; CI on PostgreSQL (2026-11-23)
**Context:** The dev PC has no Docker/WSL2 yet (needs admin install). The spec targets PostgreSQL 16+.
**Decision:** `phpunit.xml` defaults to SQLite in-memory so the suite runs anywhere; CI overrides `DB_CONNECTION=pgsql` (Postgres 17 service). Migrations use portable Schema types (`json` → `jsonb` on Postgres). Postgres-only features (deferred zero-sum trigger for the ledger, `SKIP LOCKED`) arrive in M6/M7 and their tests will be marked Postgres-only.
**Consequence:** From M6 on, Docker (Sail) is required locally.

## ADR-004 — Dashboard menu is data, routes are generated from it (2026-11-23)
`App\Shared\Navigation\DashboardMenu` is the single source for the sidebar tree (spec 1.7 structure, Part 20 URLs). Routes for every leaf are registered from it, the presenter marks the active leaf (including query-string tabs like `/tickets?status=closed`), and a test walks every leaf. When a milestone builds a real screen, it replaces the placeholder route for that path; the menu entry stays.

## ADR-005 — Dashboard URLs are not localized (2026-11-23)
Public pages use `/{locale}/…` (SEO, hreflang). Dashboard pages (`/buy/…`, `/seller/…`) are private and not indexed, so the locale comes from the `locale` cookie (set by the URL or the modal), then the browser. The guest redirect goes to `/{preferred-locale}/login`.

## ADR-006 — Brand tokens (2026-11-23)
Same roles as spec 1.0 with X2KEY's own values: primary violet `#5b3df5` (not red), dark surface `#0b0e1f`, fonts Inter (body) + Sora (display), own line-icon set. To be replaced by the final brand once D12 (trademark clearance) is done.

## ADR-007 — Fortify as a headless auth backend (2026-11-23)
**Decision:** Laravel Fortify handles the security-critical POST flows (login, 2FA challenge, registration, reset, verification, password change, 2FA enable/confirm/disable). `fortify.views = false`; X2KEY registers its own GET pages with Fortify's route names, localized for public ones (`/{locale}/login`). Fortify's stock actions are replaced by classes in `App\Domains\Identity\Fortify`. Passkeys and `updateProfileInformation` are disabled (not in spec; profile is Settings › Account).
**Why:** well-tested, rate-limited 2FA and reset flows instead of hand-rolled ones, while keeping our UI and URLs.

## ADR-008 — Driver-independent session registry (2026-11-23)
Production sessions live in Redis, which cannot be listed per user. `user_sessions` records each authenticated session (id, device, IP, last activity). Revoking sets `revoked_at`; `TrackUserSession` logs that session out on its next request and also enforces account status. Writes are throttled to once a minute per session. Revoking others also clears the remember-me token.

## ADR-009 — One login for buyers, sellers and staff (2026-11-23)
The Filament panel has no login page; guests are sent to `/{locale}/login`, so staff get the same rate limits, device alerts and 2FA challenge. Panel access requires an active staff role (`canAccessPanel`) and confirmed 2FA (`RequireStaffTwoFactor`, redirects to Settings › Security). Admins pass all policies via `Gate::before`, except `review` of their own certification.

## ADR-010 — Provider seams for SMS and KYC (2026-11-23)
`SmsSender` (dev: `LogSmsSender` writes to the log) and `KycProvider` (dev: `FakeKycProvider`, staff approve in the admin queue) are bound in `AppServiceProvider`. Real vendors are swapped in once D11 and the SMS vendor are chosen; no other code changes.

## ADR-011 — Listing search: two drivers, one contract, a parity test (2026-11-23)
**Context:** Spec 4.1 wants Meilisearch from M3 for faceted counts; dev machines and unit tests should not need a search server.
**Decision:** `CatalogSearch` has a database driver (loads one game×category's active products and filters/counts in PHP — exact, works on SQLite and PostgreSQL) and a Meilisearch driver (one multi-search per page: results + bounds + one query per active facet). Both derive facet values from the same `ProductDocument`, so counts match by construction, and `MeilisearchParityTest` proves it on 10 scenarios against a live server. Driver chosen by `CATALOG_SEARCH_DRIVER` (default `database`; `.env.example`/production `meilisearch`).
**Facet semantics:** disjunctive — values inside a group are ORed, groups are ANDed, and each group's counts ignore its own selection (so buyers can add more values). Unpriced products sort last on price sorts and drop out of price filters.
**Limit:** the database driver is meant for up to a few thousand products per game×category; its product-name search does not match non-ASCII text stored as JSON escapes. Production uses Meilisearch.

## ADR-012 — Catalog conventions (2026-11-23)
- Product attribute column is `attribute_values` (spec says `attributes`, which collides with Eloquent's internal property).
- Product slugs are unique per game×category (URLs are `/{locale}/{game}/{category}/{product}`), not globally.
- Displayed counts are offer counts once offers exist (M5), product counts until then.
- Game slugs cannot be reserved first-segment words (`Game::RESERVED_SLUGS`); routes also exclude them.
- Category types are seeded and editable (name, order, enabled) but never created/deleted from the admin.
- Admin catalog URLs live under `/admin/catalog/…`; moderators manage the catalog (`CatalogPolicy`).

## ADR-013 — Seller conventions (2026-11-23)
- Activation = Certification Center "Store" level: `ActivateSeller` needs email + phone + identity verified, opens the store and records the Store certification as verified (provider `system`).
- The Seller Center opens for active, vacation and suspended stores (suspended = read-only: writes refused). Inactive/banned users see "Become a seller" instead; all seller routes carry the `seller` middleware generated from the menu's `seller` audience.
- Only active and vacation stores are public (`/{locale}/store/{slug}`, sitemap). The slug is fixed at activation; the shop name can change (unique, case-insensitive).
- The banner lives on `seller_profiles` only (spec also lists it on `seller_store_media`); `featured_offer_ids` is JSON, not `bigint[]`, to stay portable.
- Seller status history is its own append-only table (`seller_status_changes`), like the other state machines.
- Levels with `auto_approve` also pass service applications immediately (no reviewer); others wait for a merchant manager. Nobody reviews their own store's application.
- Staff level overrides set `level_locked` so the recompute job (M16) leaves the store alone; all edits are audited.
- Seller-written text (description, notice, auto-reply, shop name) is checked by `NoContactInfo`; offers reuse it in M5.
