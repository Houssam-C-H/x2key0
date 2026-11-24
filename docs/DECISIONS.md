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
**Consequence:** From M6 on, PostgreSQL is required locally for the race tests. Update (M6): a portable PostgreSQL 17 (`C:\dev\tools\pgsql`, no admin rights) runs them until Docker/Sail works.

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

## ADR-026 — Coupons, campaigns and marketing e-mails (2026-11-24)
- **Store coupons change the unit price, platform coupons change the order.** A store coupon is the seller's own discount, so the order item stores the discounted seller-side unit price and every later step (escrow, commission, settlement, refunds) keeps working on "unit price × units". A platform coupon is a separate amount the platform funds into escrow at capture (PLATFORM_COUPON_FUNDED), so the seller is paid as if there were no coupon; its share is tracked in `refunded_discount_platform_amount` and goes back to EXP_PROMOTIONS on refunds and chargebacks.
- **One claim = one redemption**, unique per claim and reversed once; cancelled or fully refunded orders give claims back unless they expired meanwhile.
- **Points buy coupons only** (D9): a platform coupon with `points_cost` is the only way to spend X2K Score; there is no points → credits path.
- **Checkout pre-selects the best eligible coupon of each kind** and lets the buyer change or drop it; the server re-checks everything under lock when the order is created.
- **Campaign badges are computed, not stored**: approved participations of running campaigns are loaded once per request (`CampaignBadges`, scoped binding), so ending a campaign removes the badges without touching offers. The platform subsidy is informational until a pricing rule for it is decided.
- **Marketing e-mails are billed later**: the send is recorded with its price and billed the next day from SELLER_AVAILABLE (MARKETING_FEE, no overdraw); a short balance leaves it Unsettled and it is retried daily. Recipients are past buyers with a completed order who did not turn off seller promotions.

## ADR-025 — Stripe Checkout and websocket chat (2026-11-23)
- **Stripe through Checkout Sessions** (hosted page): no card data touches X2KEY, 3-D Secure handled by Stripe. The session id is the provider payment id. Paid state arrives by signed webhook, by the 5-minute sync job, or by an immediate API lookup when the buyer comes back (throttled per payment) — the redirect itself never changes state (ADR-017 still holds). Keys live only in `.env`.
- **Chat goes live with Reverb** but the socket carries only "conversation changed" signals; the browser fetches the thread over HTTP, so each reader keeps their own redacted view and no message text is pushed. Channel authorisation = conversation participants. Broadcasting happens after commit and failures are logged, never raised; polling remains the fallback.

## ADR-024 — Withdrawals and invoices (2026-11-23)
- **Money leaves Available at request time** (into the user's PAYOUT_IN_TRANSIT), not at approval: the no-overdraw check then runs under the balance row lock and concurrent requests cannot overspend; rejection, cancellation and failure post the reverse journal.
- **Fee = max(platform minimum, method fee)** — Part 27's `withdrawal_fee_amount` is a floor, not an extra charge on top of the method fee. Payouts are in USD until FX exists.
- **Paying is manual for now** (finance marks paid with the provider reference after sending); PSP payout APIs can call the same state machine later. BANK is credited at payment (platform cash out).
- **Invoices are immutable rows** with a copy of the Invoice info at issue time; numbers `X2S-` / `X2W-{year}-{seq}` come from a locked counter row (no gaps, no duplicates). Documents are printable HTML (browser PDF) until background PDF rendering is added.

## ADR-023 — Service tickets (2026-11-23)
- **Statuses follow the observed tabs** (spec 6.5): `pending` = staff to answer, `awaiting_reply` = waiting for the user, `closed`. Only staff replies move pending → awaiting_reply; internal notes never change the status.
- **SLA = first response target**, set when the ticket waits for staff (open, user reply, reopen) from the priority's hours, cleared when staff answer. Priority comes from the topic and whether an order is attached; staff can change it (the target moves with it until the first answer).
- **Auto-close counts from the last staff answer** and is re-checked under the row lock, so a user who answers at the last minute keeps the ticket open.
- **Conversion to a dispute opens the case in the ticket owner's name** with the normal dispute rules (the owner must be the order's buyer or seller and the order disputable now); staff pick reason, resolution and units. A converted ticket cannot be reopened (the case continues in the Dispute Center).
- Attachments use the same private-disk + SHA-256 pattern as dispute evidence; served only to the owner (non-internal messages) and shown to staff in the admin.

## ADR-022 — Disputes, chargebacks and violations (2026-11-23)
- **The case closes before the order moves.** `ResolveDispute` / `ApplyChargeback::lost` set the case status first, then call `OrderStateMachine` in the same transaction, so the order's open-case guard (`OpenCases::blocks`) refuses everyone else (buyer confirm, auto-confirm, cancellations, clearing ISSUE) while letting the outcome through.
- **Refunds of delivered units take them back:** `delivered_qty` is reduced before RefundOrder runs, so delivered + refunded never exceeds quantity (PostgreSQL CHECK) and settlement keeps using units kept = quantity − refunded.
- **Order transitions added to spec 6.1:** paid|delivering → refunded (dispute full refund) and delivered → delivering (replacement). A refund after completion keeps the order `completed` (the spec has no completed → refunded); its commission and seller net are reduced and the timeline shows the refund.
- **Money after completion comes back in a fixed order:** the case's frozen money, then the order's unreleased holds (newest first; pending or reserve, hold amounts reduced), then Available, which may go negative (the seller owes the platform). Commission and buyer-fee revenue are reversed in proportion to the units refunded (the last refund takes what is left).
- **Freezing does not move held money:** unreleased holds get `frozen_by` and are skipped by `holds:release`; only money already in Available moves to SELLER_FROZEN, so hold amounts and pending balances stay consistent.
- **Chargebacks:** the PSP's debit is a platform loss at once (EXP_CHARGEBACK_LOSS + fee expense); a lost case recovers up to the seller net (completed) or the escrow of the kept units (unfinished; anything above the charged-back amount goes back to the buyer's credits). Commission and fees are not clawed back separately — what is not recovered stays a loss. No violation is raised automatically for chargebacks (fraud is often the buyer's).
- **Violations count once upheld:** open/appealed violations are shown but carry no points; thresholds and rule penalties apply on uphold (staff or the appeal-window job). Penalties use the existing state machines with a null (system) actor.
- **Partial refunds are by units** (the spec also mentions amounts); amount-based partial refunds need a different settlement base and wait for a real need.

## ADR-021 — Chat storage, redaction and live delivery (2026-11-23)
- **Both bodies are stored:** `body` (what the sender typed, kept for staff and disputes) and `body_redacted` (only when something was hidden). The counterparty is only ever sent `body_redacted ?? body`; the sender sees their own original with a notice. Redaction is a mask (`••••`), not a refusal, so a buyer's honest question is never lost.
- **Repeat offenders:** redacted messages within 24 h reach 3 → one open `chat_contact_info` risk flag per day for staff; no automatic punishment yet (violations arrive with M17).
- **Conversations:** one per order (unique `order_id`, created by the `OrderPaid` listener) and one pre-sale per buyer × store. Read state is a per-participant marker (`last_read_message_id`); unread = messages by others after it. System lines are stored as translation keys + params and translated for each reader.
- **Presence = `last_seen_at`** (touched by any authenticated request at most once a minute) compared with `online_threshold_seconds`; no separate presence service until Reverb is wired.
- **Live delivery is polling for now** (`GET /chat/{id}/poll`, 5 s, throttled). Reverb is installed but not configured on dev machines; swapping the poll for an Echo subscription needs only a `MessageSent` broadcast event and a channel authorisation (participants), so the data model does not change.
- **Auto-reply** uses the seller's Store Management settings: first message once, off-hours message at most once per 12 h per conversation; working hours use the platform time zone until sellers get a time zone setting.

## ADR-020 — Settlement, holds and the inbox (2026-11-23)
- **Settlement is computed from what was kept, not what was ordered:** base = unit price × (quantity − refunded units); commission uses the seller level's current rate (the quote at checkout is only an estimate and is overwritten). Buyer fees stay platform revenue (REV_BUYER_FEE) from capture on.
- **One hold mechanism for everything:** the seller net becomes one `settlement` hold, or N `insurance` holds for insured offers (spec 7.1's `SELLER_INSURED_PENDING` is not used: installments are separate holds on SELLER_PENDING, which keeps the money in one account and the schedule in data). The rolling reserve is withheld when a hold is released and becomes its own `reserve` hold. All three kinds are released by the same idempotent job.
- **Hold length** = max(category default, seller level days) + new-seller extra, because the spec gives both a category table (7.5) and a per-level value (3.3); the stricter wins. Account sales also wait for the recovery-protection window.
- **Completion needs no open dispute** — the guard arrives with disputes (M11); until then every delivered order can complete.
- **Inbox:** system notifications are rows with a type and placeholders (translated when read, so each user sees their own language); platform announcements are rows in `announcements` seen through the audience rules, and only gain a `user_inbox` row when a user reads or stars them. Unread count = unread notifications + visible announcements without a read row.
- **Statements are read straight from postings** (no cached balances in the UI), so the pages cannot drift from the ledger.

## ADR-019 — Delivery, reveal and refund conventions (2026-11-23)
- **Auto delivery runs inside the capture transaction** (`CapturePath` and staff approval), after `markPaid`: the order is `paid` then `delivered` in one commit, with two history rows. If the reserved items are missing it logs a failure and the order stays `paid` for manual delivery.
- **Delivered secrets are never in page data.** Buyer and seller pages show counts only; the buyer fetches them from an audited JSON endpoint that needs a password confirmed within `reveal_requires_reauth_minutes` (session `auth.password_confirmed_at`, 423 + confirm URL otherwise). Sellers cannot read delivered storage items back (they are `assigned`).
- **Refund amount** = unit price × units + a fee share: all remaining fees when a whole undelivered order is refunded, `floor(fees × units / quantity)` otherwise (so the last refund never exceeds what was paid). Sources are refunded proportionally; a card part already refunded is never refunded again (bounded by what each source has left). Refund journal keys are `REFUND:order:{id}:{n}` (n = refund journals of the order, computed under the order lock) and `REFUND:payment:{id}` for late captures.
- **Who can cancel what:** nothing delivered → seller any time; buyer only after `delivery_due_at`; the system after the SLA grace (`delivery_due` + `auto_cancel_after_sla_hours`). Anything delivered can only be stopped as "cancel remainder" (partial delivery, after the deadline) — the rest goes through disputes (M11).
- **Sold Orders show paid orders only**; buyer inputs are decrypted for the seller only when `paid_at` is set (spec 8.2).
- **Evidence files** are stored on the private `local` disk with SHA-256 and served only to the buyer and seller of the order (`nosniff`, inline); virus scanning is a follow-up (`scan_status = pending`).

## ADR-018 — Ledger conventions (2026-11-23)
- **Accounts** are (code, owner_type, owner_id, currency); platform accounts use owner_id 0 so the unique index holds. PSP clearing is per provider: `PSP_CLEARING:{provider}`. Debit +, credit −; user liabilities are displayed as owed = −balance.
- **Posting in the business transaction:** journals are posted by the same transaction that changes the order or payment (no after-commit listeners), so the ledger can never miss or double a movement; idempotency keys make retries harmless. Keys: `CREDITS_SPENT:order:{id}`, `CREDITS_RESTORED:order:{id}`, `PAYMENT_CAPTURED:order:{id}`, `LATE_CAPTURE:payment:{id}`.
- **Capture journal per order, not per payment:** PAYMENT_CAPTURED moves every captured PSP payment into escrow and recognises all buyer-paid fees (service charge, add-on, method surcharge) in REV_BUYER_FEE in one journal, so escrow holds exactly the seller-side price for every split of credits and card (spec 7.2 generalised). PSP costs (EXP_PSP_FEES) come with real PSP fee data (M6b).
- **Balances** are a projection updated with an atomic increment inside PostJournal under row locks taken in account-id order; `ledger:verify` recomputes them from postings. Nothing else writes `ledger_balances`.
- **Points** use the same mechanics in a separate ledger (no currency): USER(u) holds points (positive = owned), ISSUED/REDEEMED/EXPIRED are the platform side. Earned points = floor(total paid in USD × per-USD rate × level multiplier); reversal takes back exactly the earned amount and may go negative (clawback).
- **Database guards on PostgreSQL** (deferred zero-sum constraint triggers, append-only triggers) back the PHP checks; tests that need them live in `tests/Concurrency` because they only fire on COMMIT.

## ADR-016 — Credits Balance behind a wallet seam until the ledger (2026-11-23)
**Context:** Spec M6 needs credits as a payment source, but credits balances only exist once the ledger lands (M7: PostJournal, CREDITS_SPENT wiring), and D8 rules out top-ups.
**Decision:** Checkout talks to `Payments\Contracts\CreditsWallet` (`available`, `spend`, `restore`, idempotent per key `CREDITS_SPENT:order:{id}` / `CREDITS_RESTORED:order:{id}`). M6 bound an always-empty wallet; M7 replaced it with `LedgerCreditsWallet` (ADR-018), which posts the journals under row locks. Tests that simulate wallet behaviour (a concurrent spend) bind an in-memory wallet. `OrderPaid`, `OrderCancelled` and `PaymentCaptured` are dispatched after commit so M7 can post PAYMENT_CAPTURED / REFUND without touching checkout code.

## ADR-017 — Checkout & payment conventions (2026-11-23)
- **Fees:** buyer service charge = max(3 %, $0.30) on the whole order after discounts, whatever pays for it (spec 7.2 example); category add-on fee from `policy_override.addon_fee_bps`; payment-method surcharge (`fee_bps` + `fee_fixed`) on the PSP part only, so credits are never surcharged. All half-up on integer cents.
- **One payment per source:** a `credits` payment (captured at checkout) and at most one PSP payment for the rest. The order is paid when the PSP part is captured (or immediately when credits cover everything); capture-time risk then decides `paid` vs `payment_review`.
- **Webhooks:** the signature is the authentication (CSRF-exempt `/webhooks/{provider}`); verified events are stored with a normalised copy and applied by a queued job that locks the event row, so processing is exactly-once. Return URLs never change state; the order page polls.
- **The PSP is the truth for money:** a capture arriving for a payment we already cancelled (expired order) is recorded as captured and flagged `late_capture`; the order stays closed. Refunds are executed in M8 from `refund_required` / `late_capture` flags.
- **Fake PSP:** dev/test only (`FakePaymentProvider`, refused in production, hosted page at `/fake-psp/{id}`), HMAC-signed webhooks with `FAKE_PSP_WEBHOOK_SECRET` (derived from APP_KEY when empty). Test methods `fake_card` / `fake_wallet` are seeded outside production only.
- **Reservations:** manual stock is taken from `offers.manual_stock_qty` under the offer row lock and remembered on the order item; storage items are reserved with `SKIP LOCKED` on PostgreSQL. Expiry releases both; paid orders keep storage items (no deadline) for delivery in M8.
- **Buyer inputs** are sealed with SecretVault (ADR-014), never flashed, never serialised; the seller sees them after `paid` (M8).
- **Race tests** live in `tests/Concurrency` (DatabaseTruncation, PostgreSQL only, a second connection holds locks).

## ADR-014 — Storage vault: envelope encryption, peppered fingerprints, portable columns (2026-11-23)
**Decision:** `SecretVault` (bound in `AppServiceProvider`) seals every storage secret with its own random 256-bit data key (AES-256-GCM, 96-bit IV), and wraps that key with the master key using the key id (`v1`) as associated data. Duplicates are detected with `HMAC-SHA256(pepper, normalised value)` — keys/codes ignore case, spaces and dashes; accounts are keyed by lower-cased login. Keys come from `STORAGE_MASTER_KEY` / `STORAGE_HMAC_PEPPER` (base64, 32 bytes); the app refuses to start the vault in production without them; in dev/test they are derived from `APP_KEY` with HKDF (separate info strings), never `Crypt` directly.
**Storage:** ciphertext (`iv‖tag‖data`) and wrapped key are base64 in `text` columns, not `bytea` — portable across SQLite and PostgreSQL (PDO returns `bytea` as streams). Unique `(seller_profile_id, payload_hmac)` refuses duplicates inside one vault; the same fingerprint at another seller is accepted but both items are frozen (`flagged_at`, excluded from stock) and audited for risk review (spec 8.1 / Part 13).
**Secrets never in logs:** sealed columns are `$hidden`; `SealedSecret` hides itself from dumps; audit rows carry ids and counts only; a Monolog processor (`ScrubSecrets`, `tap` on file/stderr channels) redacts secret-bearing keys; `items`, `account`, `payload` and password fields are in `dontFlash`; reveals are JSON responses with `Cache-Control: no-store`, never Inertia props or flash data.
**Later:** a KMS driver replaces the local wrap/unwrap only; the stored format and fingerprints stay the same (the pepper must never change).

## ADR-015 — Offer conventions (2026-11-23)
- Offers are priced in USD (the settlement currency, D5) for now: product caches, price filters and sorting compare integer cents without FX. Seller-chosen listing currencies (USD/EUR/GBP/CAD/AUD in spec 1.8) wait for `fx_rates`.
- "Live" = `active` offer of a public store (active/vacation) in a visible game×category. Product `lowest_price_amount_cached` / `offer_count_cached` are refreshed after commit by `RefreshProductOffers` on every change that can affect a live offer, and recomputed for all products by `catalog:recount` (catches store suspensions). Game×category offer counts = product offers + live offers without a product.
- Recommended order (buy box, offers table, listings, store): insured first (spec 1.8 #10), then unit price, then seller level `rank_boost`, then oldest.
- Review: levels without `auto_approve` send new offers, relists and content edits of listed offers to `pending_review`; price and stock edits never need review. Flagged duplicates always go to review. The level's `max_active_offers` counts active, out-of-stock and in-review offers.
- Transitions added to spec 6.2: `active|out_of_stock → pending_review` (content edit) and `pending_publish → deactivated`; the spec's `suspended` is `system_deactivated` (OPEN_QUESTIONS #5); staff restore it to `deactivated` and the seller relists.
- Game×category, product and delivery method of an offer are immutable (spec 6.2: a new offer instead). Attribute values of catalog-product offers are copied from the product.
- Favorites and browsing history store public ULIDs in requests and numeric ids in tables; guest history uses a random 40-char cookie token (`x2k_history`), never an IP.
