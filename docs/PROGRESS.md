# Progress

**Current milestone:** M7 — Ledger & points (next). M1–M6 code complete (follow-ups listed per milestone). Tests run locally on SQLite and on a portable PostgreSQL 17 (C:\dev\tools\pgsql); Docker/Sail still not running.

| # | Milestone | Status | Notes |
|---|---|---|---|
| M0 | Business feasibility (founder) | ⏳ founder | §2.3 decisions open; building on defaults (ADR-001) |
| M1 | Scaffold | ✅ code done · ⏳ Sail run | See checklist below |
| M2 | Identity, levels, settings, certification, roles | ✅ done | See checklist below |
| M3 | Catalog, listing, facets, Meilisearch, sitemaps | ✅ done | See checklist below |
| M4 | Sellers: activation, store, store management | ✅ done | See checklist below |
| M5 | Offers, storage vault, uploads | ✅ code done · uploads open | See checklist below |
| M6 | Checkout & payments (fake PSP + credits seam) | ✅ code done | See checklist below; M6b (real PSPs) waits for D2 |
| M7 | Ledger + points | next | |
| M8–M19 | — | not started | |

## M6 checklist
- [x] Tables: orders (number `X2K{yyMMdd}{seq}` from a locked per-day counter, money lines, paid credits/PSP split, commission and seller net, sealed buyer inputs, risk score/decision, unique (buyer, idempotency key)), order_items (offer snapshot, reserved manual qty), order_status_history (append-only), order_timers (unique per type), payment_methods_catalog, payments, payment_events (unique provider + event id), risk_flags, blocklist; PostgreSQL CHECKs on stock ≥ 0, delivered + refunded ≤ qty, paid ≤ total
- [x] Quote (spec 1.6 / 7.2): quantity tiers, buyer service charge 3 % min $0.30 half-up on the whole order, category add-on fee, credits first, method surcharge on the PSP part only, commission by seller level; integer money only
- [x] Checkout `/checkout` → `POST /checkout`: live offer, not your own, quantity rules (min/max/multiple/stock), buyer inputs validated against the game×category schema and sealed with SecretVault, first-order cap (`max_order_amount_new_buyer`), payment methods filtered by country/currency/amount, idempotency key per page (double submit → same order)
- [x] Reservations: manual stock decremented under the offer row lock; storage items reserved with `FOR UPDATE SKIP LOCKED` on PostgreSQL until the payment deadline, held for delivery once paid
- [x] `OrderStateMachine` (spec 6.1 up to `paid`): opened → pending_payment → paid | payment_review | cancelled; payment_review → paid | cancelled; side effects in the same transaction (timers accept/delivery/auto-cancel, substatus `new`, release stock, restore credits, cancel open payments), history row per transition, `OrderPaid` / `OrderCancelled` / `PaymentCaptured` dispatched after commit for M7 journals
- [x] Payments: `PaymentProvider` contract + `PaymentRouter` (catalog → driver); `FakePaymentProvider` with a hosted test page and HMAC-signed webhooks (refused in production); webhook → verify → store once → 200 → `ProcessPaymentEvent` job → `ApplyPaymentEvent` (locks the event, idempotent)
- [x] **Mismatch:** amount/currency different from the payment → `payment_mismatch` flag, no transition. **Duplicate:** replayed webhook stored and processed once. **Late capture:** a capture after expiry/cancellation is recorded and flagged `late_capture` for refund; the order stays closed. Bad signature → 400, recorded, never processed
- [x] **Expiry:** `orders:expire-unpaid` (1 min) cancels unpaid orders and gives stock/credits back; `storage:release-expired-reservations` (1 min) safety net; `payments:sync-pending` (5 min) recovers lost webhooks as `sync:` events — all idempotent (each run twice in tests)
- [x] Credits as a payment source through `CreditsWallet` (credits-only orders skip the PSP and take the same capture path; mixed orders; credits restored on cancellation); production binding is empty until the M7 ledger (ADR-016)
- [x] Risk v1 (spec 17.1): blocklist (e-mail domain, IP) → block; orders per hour review > 3 / block > 5; failed payments per IP per hour block > 5; new account (< 24 h) over $50 → review; gift cards/accounts for new buyers → review; credits right after a password/e-mail change → review; card-country mismatch adds score; review at capture → `payment_review`
- [x] Admin (`Orders & risk`): orders with payment-review queue (finance approves → paid, rejects → cancelled + `refund_required` flag; nobody reviews their own purchase or sale), risk flags (resolve), blocklist (audited)
- [x] Buyer: Buy Now → checkout page (quantity, buyer inputs, methods, credits toggle, price lines), fake PSP page, order detail (status, price lines, snapshot, payment, timeline, auto-refresh while unpaid), Purchase Orders with the spec 6.1 tabs
- [x] **Race tests on PostgreSQL** (`tests/Concurrency`, DatabaseTruncation + a second connection): SKIP LOCKED never hands the last storage item to two checkouts; manual stock checkouts serialise on the offer row; event ids are unique at the database level
- [x] 409 tests on PostgreSQL 17 (399 passed, 10 Meilisearch skipped) and SQLite (396 passed, race tests skipped there); Larastan max 0 errors; Pint; TS strict; live HTTP purchase (buyer login → checkout → double submit → fake PSP → paid → Purchase Orders)
- [ ] Refund execution (PSP refunds, `refunds` table) for rejected reviews and late captures — M8 (flags record what to refund)
- [ ] Ledger journals for CREDITS_SPENT / PAYMENT_CAPTURED and a funded Credits Balance — M7 (events and wallet seam are in place)
- [ ] Real PSP drivers, payment methods admin and footer strip — M6b (needs D2)
- [ ] Seller notification on `paid` (inbox + chat + e-mail) — M9/M10; Sold Orders — M8
- [ ] Risk rules needing outside data: proxy/VPN/datacenter IP, card velocity across PSPs, 3-D Secure enforcement — with real PSPs (M6b) / M18

## M5 checklist
- [x] Tables: offers (ULID, attribute_values + attributes_hash, USD unit price, tiers, insurance, listing period, version, soft deletes; CHECK price > 0 on PostgreSQL), offer_status_changes (append-only), offer_price_history (append-only), storage_groups, storage_items (sealed payload, HMAC, freeze flag), storage_import_batches, low_stock_alerts, favorites, browsing_history
- [x] `OfferStateMachine` (spec 6.2): draft → pending_publish / review / live; approve, reject (reason), deactivate, relist, expire, extend, stock-driven active ⇄ out_of_stock, system-deactivate (reason) and restore; every transition writes a history row; product caches refresh after commit
- [x] Create New Offer (spec 1.8): passed service required, catalog product or unit offer validated against the game×category schema, allowed delivery methods (per game×category or category-type defaults), manual stock or storage group of a matching item type, quantity tiers, min/max/integer multiple, delivery ETA, insurance (compulsory per `policy_override.compulsory_insurance_days`), listing duration, publish now / pending publish, receivable amount and profit preview; contact info refused; seller level offer limit; auto-approve levels skip review
- [x] **Duplicates flagged:** same seller + product (or game×category) + delivery method + attributes → `duplicate_of_offer_id`, always sent to review, shown to seller and moderators
- [x] Edit offer: price edits keep history and bump `version`; content edits send a listed offer back to review (non-auto-approve levels); game×category, product and delivery method are fixed (new offer instead)
- [x] Active Offers: service chips, 9 status tabs with counts (all, published, expire soon, low stock, deactivated, pending publish, in review, system deactivated, auto delivery), search by title/ID, sort, page size, batch deactivate / relist / extend / delete, inline unit price / sort / stock
- [x] `offers:expire` (5 min) and `storage:check-low-stock` (15 min), both idempotent
- [x] Moderation (Filament `/admin/offers`): queue with badge, duplicate filter, approve / reject / system-deactivate / restore, bulk approve; nobody moderates their own store (admins included); `/admin/storage-groups` shows counts only
- [x] Storage vault: `SecretVault` envelope encryption (AES-256-GCM, per-item data key wrapped by the master key, key id), HMAC-SHA256 fingerprints with a pepper, keys from `STORAGE_MASTER_KEY` / `STORAGE_HMAC_PEPPER` (required in production, derived from APP_KEY in dev) — ADR-014
- [x] Storage Category: groups (unique names, type locked once filled, low-stock threshold), import by paste / TXT-CSV file / account form, duplicate report (in batch, already in the vault, invalid lines); cross-seller duplicates accepted but frozen on both sides and audited
- [x] Storage List / Recycle Station: masked items, reveal (JSON, `no-store`, Privacy Password, rate-limited, audit-logged, never for reserved/assigned/revoked items), mark invalid, recycle, restore, purge; every page and write behind the Privacy Password
- [x] **Secrets never in logs:** sealed columns hidden from serialisation, audit rows carry counts/ids only, log scrubber on file/stderr channels, secret fields never flashed back on validation errors — one test checks DB dump, audit log, session and log file
- [x] **Low-stock alerts + alert bar:** auto-delivered offers whose group falls below the threshold (or 0) open one alert each; offers flip to out_of_stock at 0 and back on restock; sticky bottom bar on dashboard pages with ×, which hides the bar until the alert resolves and triggers again
- [x] Product page: buy box (recommended = insured first, then price, then seller level boost), quantity with tiers, "+N offers from", Other sellers table (delivery-method chips, sort, lowest-price tag), AggregateOffer JSON-LD; Buy Now disabled until checkout (M6)
- [x] Single offer page `/{locale}/offer/{publicId}` (owner preview of non-public offers, noindex); offer cards on listings of game×categories without catalog products; featured list + live offers on the public store; My Store featured-offer picker (up to `featured_offers_max`)
- [x] Product lowest price / offer counts now come from live offers (`RefreshProductOffers` on every change + `catalog:recount`); suspended/banned stores drop out
- [x] Favorites (offers, products; toggle, My Favorites page) and browsing history (users and guest cookie token, newest first, capped at `browsing_history_limit`, remove one / all)
- [x] Demo seed: 4 more demo stores (`nova_keys`, `arcade_depot`, `quest_supply`, `level_up_mart`, password `password`), ~770 offers covering every product and the hot games' unit categories, seller_demo storage with one low group (alert bar shows)
- [x] 359 tests green (10 Meilisearch parity skipped without a server); Larastan max 0 errors; Pint; TS strict; live HTTP smoke test (seller login → offers → storage with Privacy Password → reveal) and SSR check of product, listing, store and offer pages
- [ ] Image uploads for games/products, store video, and ClamAV/MIME scanning of uploads — not started; to do with the first evidence uploads (M8) or as a follow-up to M5
- [ ] Batch Release (Upload/Download offer list template, spec 1.8 #4) — not started
- [ ] Low-stock inbox notification — with the notification matrix (M9/M10)
- [ ] KMS-backed `SecretVault` driver for production — same stored format (ADR-014)
- [x] PostgreSQL run of the new migrations/tests — done locally on portable PostgreSQL 17 during M6

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
- [x] Featured offers (M5: My Store picker, public Featured list)
- [ ] Store video — with upload scanning (see M5 follow-ups)
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
- [x] Offer cards in listings, buy box, "other sellers" — done in M5

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
- 2026-11-23 — M6 checkout & payments built (branch `m6-checkout-payments`, on top of M5): quotes, checkout, reservations, order state machine up to paid, fake PSP + webhooks, expiry/sync jobs, credits seam, risk v1, payment review admin. Portable PostgreSQL 17.12 set up in C:\dev\tools\pgsql (data C:\dev\tools\pgsql-data, port 5433); M1–M5 suite also verified on it. Bug found by tests and fixed: a capture arriving after we cancelled the payment locally was dropped as stale — it is now recorded and flagged for refund.
- 2026-11-23 — M5 offers & storage built (branch `m5-offers-storage`): offer state machine, create/edit/Active Offers, moderation, encrypted storage vault with HMAC duplicate detection, low-stock alerts + bar, product/offer/store pages with offers, favorites, browsing history. New dev machine: portable PHP 8.4.28 + Composer set up in C:\dev\tools again; repo cloned to C:\Users\Stjg0\x2key0.
- 2026-11-23 — M3 catalog built. Portable Meilisearch 1.57 in C:\dev\tools\meilisearch for local parity tests. SQLite dev DB switched to WAL + busy timeout (concurrent dev server no longer locks seeding).
