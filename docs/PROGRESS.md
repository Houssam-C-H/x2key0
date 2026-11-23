# Progress

**Current milestone:** M5 — Offers & storage (next). M1–M4 code complete; Docker/Postgres local run still pending (WSL2 admin install).

| # | Milestone | Status | Notes |
|---|---|---|---|
| M0 | Business feasibility (founder) | ⏳ founder | §2.3 decisions open; building on defaults (ADR-001) |
| M1 | Scaffold | ✅ code done · ⏳ Sail run | See checklist below |
| M2 | Identity, levels, settings, certification, roles | ✅ done | See checklist below |
| M3 | Catalog, listing, facets, Meilisearch, sitemaps | ✅ done | See checklist below |
| M4 | Sellers: activation, store, store management | ✅ done | See checklist below |
| M5 | Offers, storage vault, uploads | next | |
| M6–M19 | — | not started | |

## M4 checklist
- [x] Tables: seller_levels (6 seeded tiers: commission, hold days, reserve, offer limit, auto-approve, rank boost, recompute thresholds), seller_profiles, seller_status_changes (append-only history), seller_stats, seller_service_applications, seller_store_media, merchant_contacts
- [x] `SellerProfileStateMachine` (spec 6.3): inactive → active needs email + phone + identity verified; active ⇄ vacation; suspend/reinstate with reason; ban from anywhere; every transition writes a history row; `status` not mass assignable
- [x] Activation via Certification Center: onboarding checklist page, `ActivateSeller` creates the store (unique name/slug from username, entry level, stats, media), records the "Store" certification; idempotent; banned stores and suspended accounts refused
- [x] Seller audience: non-sellers see one "Become a seller" entry and no Storage group; every seller route redirects them to onboarding (`seller` middleware); suspended sellers keep read-only access
- [x] Store Management › My Store: status + level terms, vacation mode (with return date), store notice (en/fr/ar), chat auto-reply + working hours, languages spoken
- [x] Service Application Status (6.9): apply per game×category, duplicates blocked, refused → reapply, auto-approve levels pass at once; `SellerProfile::canSellIn()` for M5
- [x] Seller Information: shop name (unique, case-insensitive), avatar + banner uploads (public disk, old file deleted), description per locale, operating info (status, level, payouts, services)
- [x] Merchant Services Contacts: assigned manager, channels, merchant e-mail, ticket link
- [x] Contact-info guard (`NoContactInfo` rule): e-mails, links, messenger handles and phone numbers refused in seller text
- [x] Public store `/{locale}/store/{slug}` (SSR, SEO, sitemap): banner, avatar, level stars, member since, vacation/notice, about, services, stats, languages; only active/vacation stores are public
- [x] Admin (merchant managers + admins): Stores (level override + lock, manager, payouts flag for finance, suspend/reinstate/ban with reason, contacts, status history), Service applications queue (pass/refuse, never your own), Seller levels (admins edit; managers/finance read)
- [x] Demo: `seller_demo` / `password` with an open store and 4 approved services
- [x] 285 tests green (onboarding flow end to end = acceptance test); Larastan max 0 errors; Pint; TS strict; live SSR smoke test
- [ ] Featured offers and store video — with offers and file storage (M5)
- [ ] Seller level recompute job and stats writers — M8/M16 (orders, disputes, reviews feed them)
- [ ] Notifications to sellers on service decisions / suspension — with the notification matrix (M10)

## M3 checklist
- [x] Tables: category_types (15, seeded, enable/disable per D3), games, game_categories (label override, attribute schema, buyer-input schema, delivery methods, policy override, notice, cached counts), platforms, regions, products (ULID, MSRP, cached lowest price/offers, soft deletes)
- [x] `AttributeSchema` (select / multiselect / number / boolean / text; validated on save; product values normalised) and `BuyerInputSchema` (text / number / select with regex; produces checkout validation rules for M6)
- [x] Listing search behind `CatalogSearch`: exact database driver (default, tests) and Meilisearch driver (production) — disjunctive facet counts, number ranges, price range (USD cents), text search, 5 sorts, 24 per page; product changes sync to the index after commit; `catalog:search-sync`
- [x] Parity test: Meilisearch returns the same ids, totals, facet counts and bounds as the database driver (10 scenarios, run against a live Meilisearch 1.57 locally and in CI)
- [x] Public pages (SSR, en/fr/ar): Game Index (HOT + A–Z), category hub, game hub, game×category listing (filters with counts, sort, grid/list, pagination, notice, tabs), product page (attributes, MSRP; buy box waits for offers in M5), search (games by name/alias, products)
- [x] Mega menu: one column per enabled category type, top 10 games, cached per locale, flushed on catalog edits
- [x] SEO: canonical, hreflang (+ x-default), noindex for filtered/paged views except single platform/region, BreadcrumbList + Product JSON-LD, per-locale sitemaps without thin pages
- [x] `catalog:recount` (scheduled every 10 min, idempotent); counts show offers once they exist, products until then
- [x] Admin (moderators + admins): category types (edit only), games (+ categories), game categories with visual schema builders and validation, products (attributes checked against the schema), platforms, regions; all audited
- [x] Demo seed: 15 types, 12 platforms, 10 regions, 48 games, 129 game×category pages, 346 products (2.8 s)
- [x] 224 tests green; Larastan max 0 errors; Pint; TS strict; live SSR smoke test
- [ ] Image uploads for games/products (paths only for now) — with file storage in M5
- [ ] Offer cards in listings, buy box, "other sellers" — M5

## M2 checklist
- [x] Users rewritten for spec 5.1: ULID `public_id`, username, phone, locale/currency/country/timezone, status, levels, `User` moved to `app/Domains/Identity`
- [x] Tables: user_levels, user_devices, login_events (append-only), user_sessions (driver-independent registry), user_preferences, user_security, user_messengers, social_accounts, contracts, certifications
- [x] Fortify (headless) for login, registration, password reset, email verification, password change, 2FA (TOTP, confirm step, recovery codes); X2KEY's own localized Inertia pages
- [x] Login by email or username; suspended/banned/deleting accounts refused; 5/min per account+IP and 30/min per IP rate limits; generic error (no account enumeration)
- [x] Device recognition (long-lived cookie, hashed) + new-device email; failed and successful logins recorded
- [x] Sessions list and revoke (one / all others with password); password change and reset sign out other sessions; revoked or suspended sessions logged out on next request
- [x] Settings › Account (profile, language, display currency, country, timezone, messengers, seller promo emails; social linking "coming soon")
- [x] Settings › Security (email change with re-verification, password, phone via SMS code with expiry/attempt/rate limits, 2FA, Privacy Password, sessions, login history)
- [x] Privacy Password: must differ from login password, change signs out others, `privacy` middleware gate (15 min) ready for Storage (M5)
- [x] Certification Center: 6 levels with prerequisites; email/phone/identity live; identity via `KycProvider` (Fake driver), personal data encrypted at rest; payment/store/enterprise show their milestone
- [x] Staff roles (support, merchant_manager, moderator, finance, admin) seeded; `Gate::before` for admins except self-review; policies for users and certifications
- [x] Admin panel: no separate login (normal login + 2FA challenge), staff-only, 2FA mandatory (`RequireStaffTwoFactor`), Users (status/roles, role changes audited), Certification queue (approve/reject with reason, pending badge)
- [x] Account dropdown with logout; flash messages; translations en/fr/ar (311 keys each)
- [x] 147 tests green; Larastan max 0 errors; Pint; TS strict; live HTTP smoke test (sign-up → settings → logout)
- [ ] Real SMS and KYC providers (founder decisions: D11 + SMS vendor) — `LogSmsSender` / `FakeKycProvider` until then
- [ ] Social sign-in (Socialite + provider credentials) — later
- [ ] Buyer level recompute job — M16 (levels exist; entry level assigned at sign-up)

## M1 checklist
- [x] Laravel 13 + PHP 8.4, repo outside OneDrive, LF line endings
- [x] Inertia 3 + React 19 + TypeScript (strict) with SSR (client + SSR bundles build; SSR verified over HTTP)
- [x] Tailwind 4 with X2KEY design tokens (spec 1.0 roles), logical properties for RTL
- [x] Filament 5 admin panel at `/admin` (resources come with their milestones)
- [x] Pest 5, Larastan level max (0 errors), Pint
- [x] Horizon, Reverb, Scout + Meilisearch, spatie/laravel-permission installed
- [x] Sail `compose.yaml`: PostgreSQL, Redis, Meilisearch, RustFS (S3, replaces MinIO in current Sail), Mailpit
- [x] 28 domain folders under `app/Domains`, `app/Shared/{Audit,Money,Security,Support,Navigation}`
- [x] Money: `Amount` helpers (bps half-up, minimum fee, exact split) + `MoneyCast` (bigint + currency, rejects floats)
- [x] Settings: Part 27 defaults in `config/x2key.php`, DB overrides, audited set/reset, unknown keys rejected
- [x] Audit: append-only `audit_logs`, `Auditable` trait (diffs, secret redaction), `AuditLogger` service; `User` audited
- [x] Locale routing `/{en|fr|ar}/…`, cookie/browser fallback, `dir="rtl"` for Arabic, locale/currency modal (33 display currencies)
- [x] PublicLayout: header (search + 15-type select, locale pill, auth actions), mega menu shell, footer, cookie banner
- [x] DashboardLayout: full sidebar from spec 1.7 (10 groups, nested branches, 45 placeholder pages with milestone), breadcrumb, drawer on mobile, floating rail
- [x] GitHub Actions CI: Pint, Larastan, Pest on PostgreSQL 17, TS typecheck, Vite build
- [ ] `sail up` + test suite on PostgreSQL locally (needs Docker Desktop + WSL2; WSL2 install needs admin)
- [ ] ClamAV container (deferred to M5, first file uploads)

## Log
- 2026-11-22 — Spec copied to docs/X2KEY_SPEC.md, CLAUDE.md created, git repo initialised.
- 2026-11-22 — Repo moved to C:\dev\x2key. Docker Desktop installed; WSL2 install needs admin rights (founder action).
- 2026-11-23 — M1 scaffold built with portable PHP 8.4 + Composer in C:\dev\tools (no admin needed). 59 tests green on SQLite; Larastan max clean; SSR verified in en/fr/ar.
- 2026-11-23 — M2 identity built: Fortify auth + 2FA, sessions/devices, settings pages, certification center, staff roles, admin panel. 147 tests green. Bug found by tests and fixed: Fortify's 2FA QR label read a non-existent `login` attribute.
- 2026-11-23 — Fix: password confirmation crashed for the same reason (Fortify reads `$user->login`); custom confirm callback + end-to-end 2FA test.
- 2026-11-23 — M4 sellers built: activation via certification, store state machine, store management screens, public store page, seller admin. Bug found by tests and fixed: saving Seller Information without the description field wiped the stored description.
- 2026-11-23 — M3 catalog built. Portable Meilisearch 1.57 in C:\dev\tools\meilisearch for local parity tests. SQLite dev DB switched to WAL + busy timeout (concurrent dev server no longer locks seeding).
