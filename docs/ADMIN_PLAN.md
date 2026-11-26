# Admin panel: implementation plan

Status 2026-11-26. Compared against spec Part 18 (Admin), Part 27 (policy defaults) and the features added since.

## What the admin has today

43 screens: catalog (games, categories, service types, products, platforms, regions), offers moderation, Storage
groups (counts only), orders (approve / reject / cancel, timeline popup), disputes (decide), chargebacks, tickets
(reply, assign, convert), withdrawals, invoices, ledger (read-only, manual adjustments with dual approval),
coupons, campaigns, affiliates (activate, videos, creative assets), reviews, violations, content (home modules,
news, help, pages, SEO rows), announcements, users (roles, sign out), sellers (suspend, ban, Storage switch), risk
flags and blocklist, reports, risk overview, dashboard widgets.

## What is missing

Size: **S** = about half a day, **M** = 1–2 days, **L** = 3+ days. Each item ships with tests, an audit log entry
for every staff change, and role checks.

### Phase 1: Run the business day to day

| # | Screen | Why | Size |
|---|---|---|---|
| 1 | **Platform settings** | Fees, holds, windows, risk limits and every other Part 27 value can only be changed in code today. The settings store and its audit trail already exist; this adds the screen: tabs (Orders, Money, Risk, Sellers, Marketing), the default shown next to each value, a reason required, and money settings that need a second admin's approval. | M |
| 2 | **Audit log** | Every staff action is recorded but nobody can read it. Read-only list: who, what, which record, before/after, filters by staff member, action and date. | S |
| 3 | **User page (360°)** | The user screen only edits roles. Add: suspend / ban / reinstate with a reason, reset two-step verification (now command-line only), sessions and devices, login history, orders, credits and points balance, tickets, disputes, violations, staff notes. | L |
| 4 | **Order page (360°)** | The timeline is a popup. Full page: status history, payments, refunds, deliveries (counts, never secrets), chat, disputes, ledger journals, risk result, with the existing actions on top. | M |
| 5 | **Payments and refunds** | No payment screen at all. Payments list, payment-provider event log (webhooks, re-apply safely), refunds list, failed payments. | M |
| 6 | **Chat moderation** | Staff can't read a conversation when handling a dispute or a report. Read-only transcript from the order, dispute or ticket, flagged-message queue, every opening audit-logged. | M |

### Phase 2: Money and growth

| # | Screen | Why | Size |
|---|---|---|---|
| 7 | **Points** | Spec item missing: points rules, a user's points history, manual adjustments through the points ledger (dual approval above a limit). | M |
| 8 | **Affiliate commissions and bills** | Only accounts, videos and creative assets exist. Add commissions (pending, released, reversed), monthly bills, transfers to credits. | M |
| 9 | **Payment methods catalog** | Turn checkout methods on and off and set countries, limits and fees without a deploy. | M |
| 10 | **Seller page (360°)** | Sales stats, assigned merchant manager, payouts on/off, level history, the seller's API tokens (view and revoke), Storage access, price alerts on their offers. | M |

### Phase 3: Operations and live updates

| # | Screen | Why | Size |
|---|---|---|---|
| 11 | **Live notification bell** | The bell counter updates only when a page loads. Push a signal on the user's private channel (it already exists) so the bell and staff queue counts update instantly. | S |
| 12 | **Queue and e-mail health** | Failed jobs with retry, queue sizes, sent-mail log, push subscription counts. Today a stuck queue is invisible. | M |
| 13 | **Operations page** | Backups (list, last run), health checks, exchange rates, last run of each scheduled job. | S |
| 14 | **Search and languages** | Searches with no results (to add aliases to games), and translation progress per language from `lang:missing`. | S |
| 15 | **Staff roles matrix** | Five staff roles exist but there is no overview of who can do what; per-role menus and a "my assignments" queue. | M |

### Phase 4: Faster staff work

| # | Screen | Why | Size |
|---|---|---|---|
| 16 | **Global search** | Only 2 screens are searchable from the top bar. Find a user, order number, offer, ticket or dispute from anywhere. | S |
| 17 | **Bulk actions** | Only 1 screen has them. Approve or reject offers in bulk, assign tickets, export selections. | S |
| 18 | **Report exports** | CSV export for each report and a weekly e-mail summary to admins. | S |

## Order of work

Phase 1 first (about 8–10 working days): it covers the things staff need every day and the settings that
otherwise need a developer. Then phases 2 → 3 → 4. Each item is its own branch, merged when its tests pass.
Item 11 (live bell) is small and can go first if you want the live experience straight away.
