# Progress

**Current milestone:** M19 — Launch hardening (next). M1–M18 code complete (follow-ups listed per milestone). Tests run locally on SQLite and on a portable PostgreSQL 17 (C:\dev\tools\pgsql); Docker/Sail still not running.

| # | Milestone | Status | Notes |
|---|---|---|---|
| M0 | Business feasibility (founder) | ⏳ founder | §2.3 decisions open; building on defaults (ADR-001) |
| M1 | Scaffold | ✅ code done · ⏳ Sail run | See checklist below |
| M2 | Identity, levels, settings, certification, roles | ✅ done | See checklist below |
| M3 | Catalog, listing, facets, Meilisearch, sitemaps | ✅ done | See checklist below |
| M4 | Sellers: activation, store, store management | ✅ done | See checklist below |
| M5 | Offers, storage vault, uploads | ✅ code done · uploads open | See checklist below |
| M6 | Checkout & payments (fake PSP + credits seam) | ✅ code done | See checklist below; M6b (real PSPs) waits for D2 |
| M7 | Ledger + points | ✅ code done | See checklist below |
| M8 | Delivery, Sold Orders, refunds | ✅ code done | See checklist below |
| M9 | Completion, settlement, notifications | ✅ code done | See checklist below |
| M10 | Chat | ✅ code done | See checklist below; Reverb live delivery pending |
| M11 | Disputes, chargebacks, violations | ✅ code done | See checklist below; chargeback webhooks wait for real PSPs (M6b) |
| M12 | Service Tickets | ✅ code done | See checklist below |
| M13 | Withdrawals, payout methods, invoices | ✅ code done | See checklist below |
| M14 | Coupons & campaigns | ✅ code done | See checklist below |
| M15 | Affiliate Program | ✅ code done | See checklist below; cash withdrawals for affiliates pending |
| M16 | Reviews & community ratings | ✅ code done | See checklist below |
| M17 | Home CMS, News, Help, footer pages | ✅ code done | See checklist below |
| M18 | Admin & risk completion, reports | ✅ code done | See checklist below |
| M19 | Launch hardening | next | |

## M18 checklist
- [x] **Account deletion** (spec 6.8, Settings › Delete Account): notice + agreement + exactly two different factors (e-mail code valid 10 min, Privacy Password, authenticator); blockers = any non-zero balance (credits, seller, affiliate, in transit), open orders as buyer or seller, open disputes, open withdrawals → `blocked` with the list; otherwise `scheduled` after `account_deletion_cooloff_days` (14) with cancel; the user keeps access during the cool-off
- [x] `accounts:process-deletions` (daily): re-checks blockers, then anonymises (username, name, e-mail, phone, password, 2FA), revokes every session, bans the store, suspends the affiliate account; orders, journals and audit logs stay
- [x] **Manual ledger adjustments** (spec 7.6): finance only, reason ≥ 10 characters, target account (user-owned with a user, or platform) against a platform offset from a fixed list; below `manual_adjustment_dual_approval_amount` (USD 100) posted at once, above it pending until another finance user approves (never your own); MANUAL_ADJUSTMENT journal, every step audited
- [x] **Reports** (admin, finance): date range → GMV, orders paid/completed, platform revenue and take rate, refunds and refund rate, chargebacks (count, amount, ratio), dispute rate, coupon cost (EXP_PROMOTIONS), affiliate cost (EXP_AFFILIATE), margin per category type, top games, top sellers with disputes, and the work queues (payment review, risk flags, escalated disputes, tickets, certifications, offers to review, withdrawals, campaign applications, affiliate videos, adjustments)
- [x] **Users** (admins): suspend / ban / reactivate with a reason (signs the user out), reset 2FA, sign out everywhere; staff never act on themselves; deleted accounts are final; all audited. **Account deletions** list for support
- [x] **Orders**: timeline modal (every status change with actor and reason) and staff "Cancel & refund" for paid orders with nothing delivered (support, finance, admins)
- [x] With M11–M17 staff now resolve payments, risk, disputes, chargebacks, tickets, withdrawals, violations, offers, reviews, campaigns, affiliates, content and money corrections from the admin without touching the database
- [x] 8 new tests (`tests/Feature/Admin`): two-factor deletion, cancel, anonymisation, blockers incl. re-check at the end of the cool-off, seller store closed, dual approval (own request refused, rejection), adjustment validation, user moderation, report figures, admin access; Larastan max 0 errors; Pint; TS strict
- [ ] Data export (GDPR, M19), SMS as a deletion factor, CSV export of reports, scheduled report e-mails, reconciliation dashboard (today: `ledger:reconcile` / `ledger:verify` commands and logs)

## M17 checklist
- [x] Tables: home_modules (12 codes, order, on/off, title, item count), home_module_items (pins), news_articles, help_articles, pages (footer column), seo_link_rows; `game_categories.trend_score`, `games.hot_score`
- [x] **Home page from the CMS** (spec 1.3): Hero with the 8 category tiles · Browsing History (per viewer, never cached) · Hot Game (Currency / Items / Accounts / Gift Cards × top games) · Trending Top up (featured tile + grid) · Trending Gift Cards · Trending Video Games · Community ratings · Affiliate banner · New Game (4 columns of the newest game×category pages) · Game News · Payment strip · Footer; enabled modules in staff order, title and item count per module; before seeding the 12 defaults are used
- [x] **Pins**: staff pin games, game×categories, comments or articles into a module; pinned items come first, then the automatic picks
- [x] **`home:refresh-trending`** (hourly): trend score = 10 × orders paid in 7 days (not cancelled/refunded) + views in 7 days (offer and product browsing history); hot score per game = sum of its categories
- [x] **News** (`/news`, `/news/{slug}`: published and due only, related posts), **Help center** (`/help` by section, `/help/{slug}` with the section's articles), **CMS pages** (`/page/{slug}`); Markdown rendered server-side with raw HTML stripped and unsafe links removed; all in the sitemap
- [x] **Footer** from CMS pages per column (built-in links until a column has pages; Help, News and Affiliate point to their sections) + SEO keyword row; cached, refreshed on every content change (HomeCache version bump)
- [x] **Bookmark prompt** under the header ("press Ctrl+D / ⌘D", phone wording, "No more prompts" stored on the account or in a cookie, × hides it for the visit)
- [x] Admin (moderators + support, admins): Home modules (order, on/off, title, count), Pinned home items, News (cover upload, game, draft/published, schedule), Help center, Pages & footer, SEO links
- [x] Seeders: `HomeModuleSeeder` (always), `DemoContentSeeder` (local: 12 footer pages — legal texts marked as drafts for M19 —, 7 help articles, 4 news, SEO row)
- [x] 11 new tests (`tests/Feature/Content`): default and staff module order, on/off and titles, trending scores + pins + 7-day window, per-viewer history, news visibility, safe Markdown, help and pages, footer refresh, bookmark prompt, payment strip, sitemap, admin access; Larastan max 0 errors; Pint; TS strict
- [ ] Affiliate banner QR code image, carousels with arrows/dots (today: scrollable rows), PWA install prompt, per-game news pages, real legal texts (M19)

## M16 checklist
- [x] Table `order_comments` (one per order: rating, Delivery Speed · Service Quality · Item Description 1–5, text, seller reply, visible/hidden with reason, featured, edited); `seller_stats.positive_rate_90d_bps`; seller level thresholds `min_positive_rate_bps`, `max_dispute_loss_bps`, `max_violation_points`; points rule `comment` (10)
- [x] **Comment** a completed order within `comment_window_days` (30): positive / neutral / negative, optional sub-scores and text (≤ 1000, no contact details — reviews are public); once per order; earns the `comment` points rule once; the seller is notified; the buyer is invited when the order completes
- [x] **Edit** by the buyer inside the window until the seller replies; **reply** by the seller once (buyer notified)
- [x] **Purchase Order Comment** (`/buy/comments`): To comment / Commented, rating filter, feedback score table (7 d / 30 d / all × positive / neutral / negative), % positive, edit; "Leave a comment" on the order page
- [x] **Sell Order Comments** (`/seller/comments`): score table, % positive lifetime and 90 days, Transaction level (average) with the three sub-scores, filter, reply; buyer names masked (`fau***uvu`)
- [x] **Ratings**: positive rate = positive / (positive + negative), lifetime and last 90 days, from visible comments only, refreshed on every change; store page shows both rates and the latest reviews
- [x] **Community ratings** on the home page: featured comments first, then recent (90 d) positive comments with ≥ 20 characters, one per seller, with the seller's positive %
- [x] **Moderation** (moderators + admins): hide with a reason / show again (ratings follow), feature visible positive comments
- [x] **Levels recompute** (`levels:recompute`, daily): seller stats from orders (completed count, completion rate = completed / (completed + seller cancellations), 30-day sales, average delivery minutes) and comments; seller level = highest level whose thresholds all hold (orders, completion, positive rate, dispute-loss rate, violation points), notification on change, `level_locked` respected; buyer level from lifetime completed spend, also right after each completed order
- [x] 16 new tests (`tests/Feature/Reviews`): eligibility and window, once per order, points, contact-info guard, edit/reply rules, rates and score table, 90-day window, moderation effects, community selection, level thresholds (7 cases), locked levels, buyer levels, admin access; Larastan max 0 errors; Pint; TS strict
- [ ] Comment photos, review search on product/offer pages, abuse tracking of buyers who only leave negatives, staff level override UI with reason (today: Filament field + audit)

## M15 checklist
- [x] Tables: affiliates (code, inviter for level 2, status, rank, per-affiliate rates / attribution days), affiliate_links, affiliate_referrals (one per user, attribution window), affiliate_commissions (per affiliate × order, held → approved, reversed amount), affiliate_video_records, creative_assets; FKs for `users.referred_by_affiliate_id` and `orders.affiliate_id` on PostgreSQL
- [x] **Enrol** on Manage or the public landing; an invited user's inviter becomes the parent (level 2); custom code (4–16 letters/digits); **promotional links** to any page of this site only (`/r/{code}/{slug}`), each with clicks and sign-ups
- [x] **Attribution**: `/r/{code}` counts the click and sets a first-click cookie for the attribution window (later clicks never replace it); at sign-up the user is tied to the affiliate (also from a code typed on the sign-up form); blocked: suspended affiliates and sign-ups from the affiliate's own devices
- [x] **Commission** when a referred buyer's order completes inside the window: base = platform net (commission + buyer fees kept − platform coupon kept); level 1 = 20 %, level 2 = 3 % (settings, overridable per affiliate); none when the affiliate is the buyer or the seller; AFFILIATE_ACCRUAL (EXP_AFFILIATE → AFFILIATE_PENDING), once per affiliate × order
- [x] **Release** after the order's seller hold + `affiliate_hold_extra_days` (7): `affiliate:release-commissions` (hourly) posts AFFILIATE_RELEASE (pending → available)
- [x] **Reversal**: a refund of a completed order recalculates the commission on the lower base and reverses the difference; a lost chargeback reverses everything (from pending, or from available, which may go negative)
- [x] **Transfer to Wallet**: available balance → Credits Balance from USD 10 (AFFILIATE_TO_CREDITS, no overdraw, idempotent, recent password)
- [x] **My Affiliates** (direct / indirect, masked "S****a", register date, country, orders, recent transaction, commission); **Alliance Bill** per month and type, read from the ledger (commission, second level, video, reversals, net); **Promotional video records** (YouTube / TikTok / Twitch / other, https only) with staff approval and reward (AFFILIATE_VIDEO_REWARD) or rejection; **Creative Assets** by theme and language with copy-to-clipboard
- [x] Public `/{locale}/affiliate` landing (rates from settings, 4 steps, FAQ); admin (merchant managers + admins): Affiliates (suspend / activate, rank, rates), Video reviews, Creative assets; demo: buyer_demo is affiliate `DEMO2026`
- [x] 11 new tests (`tests/Feature/Affiliate`): links and cookie, sign-up attribution (link, typed code, same-device block), two-level accrual and idempotency, window / seller / suspended exclusions, release, transfer minimum and balance, reversal math, videos, masked lists, bill, admin access; Larastan max 0 errors; Pint; TS strict
- [ ] Affiliate **cash withdrawals** (today: transfer to Credits Balance; the withdrawal tables already carry `source = affiliate`, the wizard and rules are seller-only)
- [ ] Same-payment-fingerprint block (needs PSP card fingerprints), Alliance Bill PDF, home-page affiliate banner + QR code, "earned this week" ticker

## M14 checklist
- [x] Tables: coupons (platform | seller, fixed | percent, scope, limits, points price, validity), coupon_claims, order_coupon_redemptions (unique per claim, reversed once), campaigns, campaign_participations, marketing_emails; `orders.refunded_discount_platform_amount`
- [x] **Pricing** (`CouponPricing`, spec 14.1 / 7.2): a store coupon lowers every unit's seller-side price (the order item keeps that price, so escrow, settlement and refunds stay "unit price × units"); a platform coupon takes an amount off the order (fixed or capped percent, never more than the seller-side subtotal); stacking max one store + one platform coupon, non-stackable coupons alone; scope by game / category type / game×category, minimum order, buyer level, currency
- [x] **Checkout**: the best eligible store and platform coupon are pre-selected and can be changed or removed; price lines Discount Amount / Platform Discount; claims re-checked under lock and redeemed in the order transaction
- [x] **Money**: PLATFORM_COUPON_FUNDED at capture (EXP_PROMOTIONS → ORDER_ESCROW, so escrow holds the full seller-side price); refunds, completed-order dispute refunds and chargebacks give the platform share back to EXP_PROMOTIONS; cancel / full refund restores claims that have not expired
- [x] **Coupon Center** (`/coupons/shopping`, `/coupons/platform`): claimable public store codes grouped by store, platform coupons (free, or bought with X2K Score — D9: points only ever buy coupons), My coupons with status; store page lists public codes and takes any code (public or private "Recommend")
- [x] **Seller coupon codes** (`/seller/coupons`, `/seller/coupons/private`): batch create (one per line, 4–20 chars, unique per store), % off (≤ 90 %), whole store or one approved game×category, use limit, expiry; edit expiry; end selected
- [x] **Campaigns** (spec 14.2): admin creates campaigns (type, games, max offers per seller, window); sellers apply with live offers on `/seller/campaigns`, withdraw or re-apply; merchant managers approve or reject with a reason (inbox notification); approved offers show the campaign badge while it runs
- [x] **Marketing e-mails** (spec 14.3): 4 templates → up to 6 live offers + optional private code → past buyers with a completed order in a date range who allow seller promotions → preview (recipients, cost = count × $0.001 rounded up, caps left) → send (queued mail per recipient, en/fr/ar); caps 1,000/day and 10,000/month per seller; `marketing:settle` bills the next day from the Available balance (MARKETING_FEE: SELLER_AVAILABLE → REV_MARKETING) and leaves the send Unsettled while the balance is short; Mail promotion records
- [x] Admin (merchant managers + admins): Platform coupons, Campaigns, Campaign applications; demo data (`DemoMarketingSeeder`: welcome $2, 10 % for 100 points, SAVE10 public, LOYAL20 private, an open flash sale)
- [x] `coupons:expire-claims` (hourly), `marketing:settle` (daily 03:30)
- [x] 28 new tests (`tests/Feature/Coupons`, 613 in total): pricing and ledger (escrow, promotions, verify), claim limits, points, stacking, other people's claims, restore on cancel, seller codes, campaigns and badges, marketing caps, opt-out, billing; Larastan max 0 errors; Pint; TS strict
- [ ] Platform subsidy of campaign prices (`platform_subsidy_bps` is shown, not yet posted to EXP_PROMOTIONS), campaign placements on the home page, coupon ROI report
- [ ] Marketing e-mail unsubscribe link with a signed token (today: link to account settings), bounce handling

## Stripe, live chat, order progress (after M13)
- [x] **Stripe Checkout driver** (`StripePaymentProvider`, M6b part): hosted payment page, `checkout.session.*` webhooks verified with `STRIPE_WEBHOOK_SECRET`, session lookup for `payments:sync-pending` and right after the buyer returns (state read from Stripe's API, never from the redirect), refunds on the session's PaymentIntent with the journal key as idempotency key; method `stripe_card` offered only when `STRIPE_SECRET` is set; tests never call Stripe (keys forced empty in phpunit.xml)
- [x] **Live chat over Reverb websockets** (ADR-021 updated): every new message (user, auto-reply, system) broadcasts a text-free `conversation.updated` signal on the participants-only private channel `conversation.{id}`; the page re-fetches its own redacted thread; polling stays as fallback (5 s without websockets, 30 s safety net); a Reverb outage never breaks sending
- [x] **Order progress bar** at the top of both order pages: Placed → Paid (or Payment review) → In progress → Delivered → Completed with times, the next deadline under the current step, ISSUE when a dispute is open, red end step for cancelled/refunded
- [ ] Stripe webhook endpoint registration in the dashboard (production) and the footer payment strip; other PSPs (D2)

## M13 checklist
- [x] Tables: payout_method_catalog (PayPal, Payoneer, USDT-TRC20, bank transfer: fee bps + fixed, minimum, fields), payout_methods (details encrypted, masked label, default, soft removal), withdrawal_requests (amount, fee, payout, masked destination, idempotency key), withdrawal_status_history (append-only), invoice_profiles (tax id encrypted), invoice_sequences, invoices (immutable)
- [x] `WithdrawalStateMachine` (spec 6.6): requested → approved → processing → paid | failed; requested → rejected | cancelled; history per transition; staff never act on their own withdrawal
- [x] Money (spec 7.2): WITHDRAWAL_REQUESTED (Available → PAYOUT_IN_TRANSIT at request), WITHDRAWAL_RELEASED (reject / cancel), WITHDRAWAL_PAID (in transit → BANK − payout, REV_PAYOUT_FEE − fee), WITHDRAWAL_FAILED (back to Available)
- [x] **Concurrent withdrawals are safe**: the request posts under the balance row lock with a no-overdraw guard (spec 7.4), so the total withdrawn never exceeds Available; idempotency key per user (double submit → same request); PostgreSQL race tests in `tests/Concurrency/WithdrawalRaceTest.php`
- [x] Rules: open store + payouts enabled (finance) + identity verified + 2FA (spec 6.3); amount ≥ max(`withdrawal_min_amount`, method minimum); fee = max(`withdrawal_fee_amount`, method bps + fixed); requesting and adding an account need a recently confirmed password
- [x] Withdrawal Management (spec 1.8): balance breakdown (Available, Pending, Revenue Reserve, Not Available, Store Deposit, being withdrawn), wizard with fee/payout preview and confirmation, records (last 3 months / all) with remarks and cancel; Withdrawal Account (add with per-method fields, default, remove)
- [x] Invoices (Part 16): withdrawal voucher per paid withdrawal (`X2W-{year}-{seq}`), monthly service invoice per seller for commission + payout fees (`X2S-{year}-{seq}`, `invoices:issue-monthly`, idempotent), gap-free numbering from a locked counter, Invoice info snapshot; printable page (save as PDF); Invoice menu pages
- [x] Admin (finance): withdrawal queue (approve, reject, processing, paid with reference, failed; payout details decrypted on demand and audited), payout method fees, invoices
- [x] 574 tests (556 passed, 18 skipped on SQLite); Larastan max 0 errors; Pint; TS strict
- [ ] Payouts in other currencies (FX), PSP payout APIs (automatic paid/failed), PDF files generated in the background
- [ ] Affiliate withdrawals (source = affiliate) — M15

## M12 checklist
- [x] Tables: tickets (ULID, topic, subject, topic form answers, optional order, dispute link, priority, status, assignee, SLA target, first response, last replies, close info, CSAT), ticket_messages (internal notes, system lines; append-only), ticket_attachments (private disk + SHA-256), ticket_status_history (append-only), canned_replies (en/fr/ar)
- [x] `TicketStateMachine` (spec 6.5): pending ⇄ awaiting_reply → closed; closed → pending on reopen; history row per transition, system lines on close/reopen
- [x] `TicketDesk`: open (8 topics from spec 1.9; catalog request follow-up form with required game, platform, area, server, product type, keywords), user reply (awaiting → pending, new SLA target), staff reply (pending → awaiting, first response recorded, auto-assign), internal notes (no status change, never shown to the user), close (user/staff), reopen within `ticket_reopen_days` (7), one CSAT rating 1–5 after close, assign, priority change (SLA recomputed), **convert an order ticket to a dispute** in the owner's name (dispute rules apply, link kept, ticket closed)
- [x] Priority by topic: high for refund / after-sales / payment questions about an order, low for catalog requests, normal otherwise; first-response targets `ticket_sla_hours_high|normal|low` (4 / 24 / 72 h)
- [x] `tickets:autoclose` (15 min, idempotent): awaiting_reply tickets whose last staff answer is older than `ticket_autoclose_hours` (72) close as "closed automatically"; re-checked under the row lock so a late user reply wins
- [x] Support Center (spec 1.9): All · Pending · Awaiting Reply · Closed with counts (sidebar tabs), rows "topic : subject", order number, created, last reply, state; new ticket page with topic forms, optional own order, attachments; ticket page with thread, files, close, reopen (until date), rating; side box with customer / merchant / dispute service e-mails; "Contact support" on both order pages (pre-selects the order)
- [x] Admin (support + admins): queue sorted by status and SLA target (overdue red, filters: status, priority, topic, assigned to me, overdue), full thread modal incl. internal notes and files, reply with canned replies (in the user's language) or internal note, assign + priority, close, convert to dispute; canned replies CRUD; inbox notifications on staff reply and close
- [x] **Acceptance tests** (`tests/Feature/Tickets`): full lifecycle with history, internal notes hidden, auto-close only past the window and only once, user reply resets the clock, reopen window, single rating, order priority and conversion to a dispute, HTTP flows (tabs, catalog form validation, attachments owner-only), staff access
- [x] 560 tests (544 passed, 16 skipped on SQLite); Larastan max 0 errors; Pint; TS strict
- [ ] E-mail copies of ticket replies and an e-mail-in channel — with the notification preferences / e-mail milestone
- [ ] Antivirus scan of attachments (`scan_status = pending`), SLA breach alerts to staff

## M11 checklist
- [x] Tables: disputes (ULID, reason, requested resolution/qty, proposal, frozen amount, deadlines), dispute_messages (original + redacted, internal notes, system lines), dispute_evidence (private disk + SHA-256), dispute_decisions (one per case, final), dispute_status_history (append-only), chargebacks, violation_rules, violations; holds gain `frozen_by`
- [x] `DisputeStateMachine` (spec 6.4): open → awaiting_counterparty (72 h deadline) → negotiating → resolved / escalated → resolved; any open → cancelled (opener withdraws); history row + system line per transition
- [x] Opening rules (`DisputeRules`): buyer on paid (after the deadline: not received / other), delivering, delivered, completed within `post_completion_dispute_days` (accounts: `account_recovered` until the recovery window ends); "not received" on a delivered order only in the contact → report window (48 h + 48 h); seller only `buyer_unresponsive` on paid/delivering; one open case per order; evidence required for not working / already used / wrong region / account recovered
- [x] **An open case blocks the order**: completion (buyer or auto-confirm), cancellation, cancel remainder and clearing ISSUE are refused; paid/delivering orders show ISSUE until the case closes
- [x] Negotiation: reply (first counterparty answer → negotiating), proposals (refund full / partial, replacement, redelivery), accept the request or the other side's proposal (agreement, no fault), escalate from negotiating, withdraw; contact details masked like chat (ADR-021); `disputes:escalate-overdue` (5 min, idempotent, re-checked under lock)
- [x] **Outcome executor** (`ResolveDispute`), in one transaction with the case close: refund full/partial before completion (escrow; delivered units taken back; all refunded → `refunded`; partial delivery done → `delivered`), replacement/redelivery (units back to the seller, delivered → delivering, new deadline), no refund / withdrawn (resume, at least 24 h left to confirm)
- [x] **After completion** (`RefundCompletedOrder`): REFUND from the seller (frozen money of the case → unreleased holds, pending/reserve → Available, may go negative) + commission and fee revenue reversed proportionally; order stays completed with reduced commission/net; points reversed when nothing is kept
- [x] **Seller money of a completed order under a case** (`Ledger\Actions\SellerFunds`): holds stop releasing (`frozen_by`), released money → SELLER_FROZEN ("Not Available"), back on close (FUNDS_FREEZE / FUNDS_UNFREEZE journals)
- [x] Staff decision (support; admins too, never on their own order): outcome, units, refund destination, seller fault, public rationale, internal notes; seller fault → `disputes_lost` + `dispute_lost` violation; buyer abuse: 3 lost disputes in 90 days → `buyer_dispute_abuse` risk flag
- [x] **Chargebacks** (`ApplyChargeback`, spec 9.4): open (CHARGEBACK: PSP clearing out, loss + fee expenses; freeze; completion blocked), won (reversal, unfreeze), lost (completed: recovered from the seller up to the net; unfinished: escrow out, the rest to buyer credits, order → refunded); idempotent per provider case id; finance records them in the admin until PSP webhooks exist (M6b)
- [x] **Violations** (spec 17.2 / 6.7): seeded rules (dispute_lost, seller_cancel, late_delivery automatic; off-platform contact, prohibited listing, fake evidence, fraud by staff); open → appealed → upheld | revoked, open → upheld after `violation_appeal_days` (`violations:process`, hourly); penalties on uphold: rule penalty (listing suspension of the offer, store suspension, ban) and points thresholds (`violation_points_store_suspend` 30, `violation_points_ban` 60); active points mirrored in `seller_stats.violation_points`
- [x] Dispute Center (spec 1.9): As Buyer / As Seller, Open/Closed counters, quick filters (response required · under review · awaiting other party), search, case page (summary, request, proposal, actions, thread, evidence, decision), open-a-dispute page from the order, "Open a dispute" / case link on both order pages, timeline lines for dispute and chargeback events; Dispute Statistics per game × category (disputes, share, dispute rate, refund resolution rate)
- [x] Violation Center (spec 1.8): counters, filters, active points vs thresholds, appeal form
- [x] Admin: dispute queue (escalated first, full thread incl. internal notes, internal note, message parties, decide), chargebacks (record, won, lost), violations (raise, uphold, revoke), violation rules (points, penalty, enabled); inbox notifications for every dispute / violation / chargeback event (en/fr/ar)
- [x] **Acceptance tests** (`tests/Feature/Disputes`): every outcome before and after completion checked for order state and journals, `ledger:verify` clean after each; chargebacks won/lost on unfinished and completed orders; violations auto / appeal / threshold; HTTP flows
- [x] 550 tests (534 passed, 16 skipped on SQLite); Larastan max 0 errors; Pint; TS strict
- [ ] Chargeback webhooks and the auto evidence pack PDF (timeline, IP/device, reveal logs, chat) — with real PSPs (M6b)
- [ ] Amount-based partial refunds (today: by units); antivirus scan of dispute evidence; staff chat access from the dispute page
- [ ] Run the new migrations/tests on PostgreSQL (the portable install is not on this machine)

## M10 checklist
- [x] Tables: conversations (order | pre_sale), participants (read marker, mute), messages (original `body` + `body_redacted`, flags, kind text | system | auto_reply | auto_hours)
- [x] **Order chat** created when the order is `paid` (idempotent, system message); **pre-sale chat** from "Contact seller" on the public store page (one per buyer × store; not for yourself or non-public stores); "Chat about this order" on both order pages
- [x] `/chat` (spec Part 20): conversation list with All · Unread · Read, search, last message, unread counts, thread, safety banner, online dot, mute; participants only (anyone else gets 404)
- [x] **Contact-info redaction** (`ContactInfo::redact`): e-mails (also spelled out), links/domains, messenger invites and handles, phone numbers in any digit script; English, French and Arabic cases tested. The counterparty reads `body_redacted`; the sender sees the original with a "hidden" notice; flagged `contact_info`
- [x] 3 redacted messages in 24 h → open risk flag `chat_contact_info` (once per day)
- [x] **Presence:** `users.last_seen_at` heartbeat (≤ 60 s) + `online_threshold_seconds` (120); green dot in chat, on the store page and on offer rows (`seller.online`)
- [x] **Auto-reply** (Store Management): first message of a conversation; off-hours message (window may wrap past midnight, platform time zone) at most once per 12 h per conversation
- [x] Header chat badge = conversations with unread messages (shared prop `chat.unread`)
- [x] Message length ≤ 2000, rate limits (send 30/min, poll 120/min, start 20/min)
- [x] 30 new tests (`tests/Feature/Messaging`); Larastan max 0 errors; Pint; TS strict
- [ ] **Live delivery over Reverb** (private/presence channels, Echo): the page polls every 5 s for now — see ADR-021
- [ ] Attachments (images/PDF, scan, signed URLs 5 min), support chat, staff chat access with audit, staff view of the transcript in the Filament order timeline
- [ ] "Online sellers" catalog filter; e-mail copy of notifications and notification preferences; seller sound/toast on new order

## M9 checklist
- [x] Tables: holds (settlement / insurance / reserve, release time, source hold), announcements, user_inbox
- [x] **Completion** (spec 6.1): the buyer confirms (`POST /buy/orders/{id}/confirm`) or the confirmation window ends (`orders:process-timers` completes the order as "The system auto confirm"); `delivered → completed` in one transaction with settlement, points and seller stats; `OrderDelivered` / `OrderCompleted` events
- [x] **ORDER_SETTLEMENT** (spec 7.2): ORDER_ESCROW + base, SELLER_PENDING − net, REV_COMMISSION − commission; base = unit price × units kept (a partly refunded order settles only the delivered units); commission = the seller level's rate; order amounts updated to the real figures
- [x] **Holds** (spec 7.5): net held for max(category days, seller level days) + new-seller extra (7 days while < 20 completed orders); accounts also wait for the recovery-protection window; **insured offers release in 3 equal installments** over the insurance period (exact split, never before the hold ends)
- [x] `holds:release` (15 min, idempotent per hold): HOLD_RELEASE moves pending → available minus the level's **rolling reserve**; the reserve comes back after `reserve_release_days` (RESERVE_RELEASE). Seller balances (Available · Pending · Revenue Reserve) on Sold Orders equal the ledger
- [x] **Points on completion:** `AwardOrderPoints` runs in the completion transaction (floor(USD × 10 × level multiplier), once per order)
- [x] **Announcement centre** (spec 12.2): `/announcements?filter=all|unread|read|starred` + category chips; platform announcements by audience (everyone / buyers / sellers), schedule and expiry, with read/star state created on first interaction; system notifications from order and money events (new order, delivered, completed, cancelled, earnings and reserve released, low stock — the M5 follow-up) translated at display time; mark read/unread, star, mark all read; **header bell badge** with the unread count on every page
- [x] Staff compose announcements in the admin (support, merchant managers, admins)
- [x] **Credits Balance**, **Points Record**, **Funding Details** (spec 1.7 / 7.3) read from the ledgers: month selector, running balance, inflow/outflow totals, type filter, **CSV export** (formula-injection guard); every figure equals the ledger
- [x] **Property test:** random orders (delivered / confirmed / auto-confirmed / cancelled and refunded) with time passing, 3 seeds: the ledger verifies after every step; at the end holds and reserves are released and PSP clearing = seller net + commission + fees, escrow 0
- [x] Bug found by tests and fixed: first read or star of an announcement crashed (mass assignment of `user_id` on a guarded model)
- [x] 491 tests (PostgreSQL 481 passed, SQLite 475 passed; the rest skip where they cannot run); Larastan max 0 errors; Pint; TS strict
- [ ] E-mail and live (Reverb) delivery of notifications — with chat (M10); notification preferences UI
- [ ] Disputes can block completion and hold funds (`SELLER_FROZEN`) — M11 (the hook is marked in `OrderStateMachine::complete`)
- [ ] Withdrawals from the Available Balance — M13; coupons spending points — done in M14; affiliate accrual on completion — done in M15; purchase-order comments — done in M16
- [ ] Segment targeting of announcements and a Redis-cached unread count — later

## M8 checklist
- [x] Tables: deliveries, delivery_storage_items (unique storage item), delivery_secrets (sealed), delivery_evidence (private disk + SHA-256), service_milestones, secret_reveals (append-only), refunds, auto_delivery_logs; orders gain refunded credits / card / fee columns
- [x] **Auto delivery from Storage** (spec 6.1): the items reserved at checkout are assigned to the order item the moment the order is paid (also after a staff-approved review), logged in Storage › Delivery Logs, and the order goes to `delivered` — all in the capture transaction; a failure is logged and the seller delivers by hand
- [x] Manual delivery per method (spec 8.2): digital key / code / file (one sealed code per unit), account transfer (one sealed account per unit), manual top-up and send gift (note required, optional screenshot), service (progress milestones, 100 % delivers; longer confirmation window); partial delivery → `delivering`, the timeline shows units
- [x] Seller sub-status `new → restocking → in_trading` (⇄ `issue`) with timeline lines "Seller changed order status to: …"; accepting closes the `accept_due` timer
- [x] **Reveal** (spec 8.3): buyer endpoint decrypts what was delivered (storage items + seller-typed secrets), `no-store`, rate-limited, needs a password confirmed in the last `reveal_requires_reauth_minutes`; one `secret_reveals` row per secret with IP and user agent. Order pages (buyer and seller) never carry the secrets
- [x] Delivered side effects: `confirm_due_at` (72 h, services 168 h) and `confirm_due` timer; accounts also get `recovery_protection_end`
- [x] **Cancellations & refunds:** seller cancels a paid undelivered order; buyer cancels after the delivery deadline; auto-cancel when the SLA grace ends (`orders:process-timers`, idempotent); buyer stops a partial delivery and gets the rest back ("cancel remainder"). `RefundOrder`: refunded amount = seller-side units + the matching share of buyer fees, returned **proportionally to the sources** (credits part → credits, card part → PSP) or all as credits when the buyer chooses; one REFUND journal, one refund row per destination, PSP refund with an idempotency key; seller cancellations count in `orders_cancelled_by_seller`
- [x] Payment review rejection and **late captures** now refund through the PSP at once (M6 only flagged them); refund journals keep escrow, fee revenue, PSP clearing and credits exact
- [x] Sold Orders (spec 1.8): tabs with counts (All · Pending delivery · Pending feedback · Delivered · Canceled), filters (game, category, order number, product title, internal remarks, from/to), sub-status chips, internal remarks and acquisition cost (seller-private); unpaid orders never appear; order page shows buyer inputs only after payment
- [x] Storage › Delivery Logs page; buyer order page with deliveries, reveal, cancel / cancel remainder, refunds and timeline
- [x] Suspended sellers are read-only on orders
- [x] **Bug found by tests and fixed (M5 schema):** `storage_items.assigned_order_item_id` was unique, so no order of two or more keys could be delivered. Replaced by a plain index; "assigned once" is still guaranteed by the column and by `delivery_storage_items.storage_item_id` being unique
- [x] 460 tests (PostgreSQL 450 passed, SQLite 444 passed; Meilisearch and PostgreSQL-only tests skip where they cannot run); Larastan max 0 errors; Pint; TS strict
- [ ] Order confirmation by the buyer, completion, settlement, holds, points on completion — M9
- [ ] Disputes, evidence from the buyer, chargebacks — M11; seller notification on `paid` and delivery reminders — M9/M10
- [ ] Antivirus scan of evidence files (ClamAV) — `scan_status` is `pending` until the scanner exists; files are served from the private disk with `nosniff`
- [ ] Real PSP refund flows and refund webhooks (`processing` → `succeeded`) — M6b

## M7 checklist
- [x] Tables: ledger_accounts (code × owner × currency, chart of accounts from spec 7.1), ledger_journals (unique idempotency key), ledger_postings, ledger_balances; points_accounts, points_journals, points_postings, points_balances, points_rules
- [x] PostgreSQL guards: deferred constraint triggers refuse any money or points journal that does not sum to zero at COMMIT; row triggers make journals and postings append-only; CHECK amount ≠ 0. Same rules in PHP (models refuse update/delete) so SQLite is covered too
- [x] `PostJournal` — the only writer of postings: idempotent per key (same key + other type refused), zero-sum per currency, repeated accounts merged, balance rows locked in account-id order, no-overdraw guard for user balances (invariant 4.3 #2); nests in the caller's transaction
- [x] Wiring (in the same transaction as the business change): CREDITS_SPENT at checkout and CREDITS_RESTORED on cancellation (through `LedgerCreditsWallet`, which replaces the M6 placeholder), PAYMENT_CAPTURED when an order is fully paid (PSP part into escrow + service charge/add-on/surcharge to REV_BUYER_FEE, so escrow holds exactly the seller-side price), LATE_CAPTURE for captures of closed orders
- [x] Points ledger (X2K Score): `PostPointsJournal` (same rules), `AwardOrderPoints` — floor(USD × `points_per_usd` × buyer level multiplier), once per order, exact reversal; rule seeded from Part 27 (10 / USD, × level)
- [x] `ledger:verify` (nightly): journals balance per currency, postings in their account's currency, stored balances = sum of postings (money and points), no overdrawn user liability — exit 1 + critical log on any problem
- [x] `ledger:reconcile {provider} {date}` (daily): captured payments vs PSP_CLEARING postings, and captured payments without a journal
- [x] Admin (finance, read-only): Ledger › Journals (lines per journal) and Accounts (balance, owed)
- [x] Demo: buyer_demo starts with $25.00 promotional credits (PROMO_CREDIT journal) to try credits at checkout
- [x] **Property tests:** 3 seeds × 40 random steps (fund credits, checkouts with/without credits and both methods, captures, declines, expiries) checking after every step: ledger verifies clean, no buyer credits below zero, escrow = seller-side price of paid orders + credits of unpaid orders + late captures, PSP clearing = captured payments, credits conserved; points: 2 seeds × 25 random earns/reversals, zero-sum and balance = earned − reversed
- [x] PostgreSQL tests: unbalanced journal refused at commit, append-only enforced, concurrent credits spends serialised on the balance row (no double spend)
- [x] 437 tests: PostgreSQL 17 427 passed, SQLite 421 passed (race and trigger tests skip there); Larastan max 0 errors; Pint; TS strict. Bug found by the PostgreSQL run and fixed: trigger functions survive `migrate:fresh`, so they are created with CREATE OR REPLACE
- [ ] Settlement, holds, HOLD_RELEASE, points earning on completion — M9 (`AwardOrderPoints` is ready to call)
- [ ] REFUND journals and refund execution — M8; Funding Details / Points Record / Credits pages — M9
- [ ] Manual adjustments with dual approval — M18; EXP_PSP_FEES with real PSP fee data — M6b

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
- [x] Ledger journals for CREDITS_SPENT / PAYMENT_CAPTURED and a ledger-backed Credits Balance — done in M7
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
- [x] Low-stock inbox notification — done in M9
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
- 2026-11-23 — M10 chat built (branch `m10-chat`, on top of M9): order + pre-sale conversations, redaction in en/fr/ar with risk flag on repeats, presence dots, auto-reply, unread badge, `/chat` page (polling). Dev server note: Sail now loads `docker/opcache.ini` (20 s → ~2 s requests on a Windows bind mount).
- 2026-11-23 — M9 completion & settlement built (branch `m9-completion-settlement`, on top of M8): confirm / auto-confirm, ORDER_SETTLEMENT, holds with insurance installments and rolling reserve, points on completion, announcement centre with bell badge, Credits / Points / Funding statements with CSV, seller balances. Bug found by tests and fixed: first read/star of an announcement crashed.
- 2026-11-23 — M8 delivery & refunds built (branch `m8-delivery-refunds`, on top of M7): auto delivery from Storage, manual delivery per method, reveal with audit, Sold Orders, cancellations and proportional refunds, order timers. Bug found by tests and fixed: M5's unique index on `storage_items.assigned_order_item_id` blocked every multi-unit auto delivery.
- 2026-11-23 — M7 ledger & points built (branch `m7-ledger`, on top of M6): PostJournal + triggers, credits wallet on the ledger, payment journals wired into checkout/capture/cancel/late capture, points ledger, verify/reconcile, property tests. Note: the local `.env` was replaced with the Sail version (Redis, Meilisearch, Mailpit hosts) — local test runs need `CATALOG_SEARCH_DRIVER=database SCOUT_DRIVER=null`.
- 2026-11-25 — `main` fast-forwarded to M17. M18 account deletion, dual-approval ledger adjustments, reports, user moderation and order staff actions built (branch `m18-admin-risk`).
- 2026-11-24 — `main` fast-forwarded to M16. M17 home CMS (12 modules, pins, trending job), News, Help center, CMS pages and footer, bookmark prompt built (branch `m17-home-content`).
- 2026-11-24 — `main` fast-forwarded to M15. M16 reviews, community ratings and level recompute built (branch `m16-reviews`).
- 2026-11-24 — `main` fast-forwarded to M14 (all milestone branches contained). M15 Affiliate Program built (branch `m15-affiliate`). Found while testing: a queued attribution cookie leaked into the next request handled by the same process, so the cookie is now attached to the redirect itself.
- 2026-11-24 — M14 coupons & campaigns completed (branch `m14-coupons`, on top of `m13-withdrawals`; the backend WIP — pricing, redemptions, ledger shares — was finished with checkout UI, Coupon Center, seller codes, campaigns, marketing e-mails, admin and tests). PostgreSQL-only bugs from M11/M13 found by the full PostgreSQL run and fixed: refunding a completed order left its units counted as delivered (DB check `order_items_qty_consistent`); the audit log wrote string keys (payout method types) into the numeric `subject_id`; adding a payout account used `FOR UPDATE` with `COUNT(*)`. Suite: 613 tests, PostgreSQL 603 passed / SQLite 594 passed, rest skipped.
- 2026-11-23 — M13 withdrawals, payout methods and invoices built (branch `m13-withdrawals`, from `main`).
- 2026-11-23 — M12 service tickets built (branch `m12-tickets`, on top of `m11-disputes`).
- 2026-11-23 — M11 disputes, chargebacks and violations built (branch `m11-disputes`, on top of `m10-chat`). Bugs found by tests and fixed: a seller could clear the ISSUE sub-status of an order under dispute; resetting the confirmation timer after a dispute used a mass assignment the strict models refuse.
- 2026-11-23 — M6 checkout & payments built (branch `m6-checkout-payments`, on top of M5): quotes, checkout, reservations, order state machine up to paid, fake PSP + webhooks, expiry/sync jobs, credits seam, risk v1, payment review admin. Portable PostgreSQL 17.12 set up in C:\dev\tools\pgsql (data C:\dev\tools\pgsql-data, port 5433); M1–M5 suite also verified on it. Bug found by tests and fixed: a capture arriving after we cancelled the payment locally was dropped as stale — it is now recorded and flagged for refund.
- 2026-11-23 — M5 offers & storage built (branch `m5-offers-storage`): offer state machine, create/edit/Active Offers, moderation, encrypted storage vault with HMAC duplicate detection, low-stock alerts + bar, product/offer/store pages with offers, favorites, browsing history. New dev machine: portable PHP 8.4.28 + Composer set up in C:\dev\tools again; repo cloned to C:\Users\Stjg0\x2key0.
- 2026-11-23 — M3 catalog built. Portable Meilisearch 1.57 in C:\dev\tools\meilisearch for local parity tests. SQLite dev DB switched to WAL + busy timeout (concurrent dev server no longer locks seeding).
