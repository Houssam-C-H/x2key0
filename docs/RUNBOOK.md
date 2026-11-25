# X2KEY operations runbook

For whoever runs X2KEY in production: what runs where, how to tell it is healthy, and what to do when it is not.
Spec references: Part 23 (security & privacy), Part 25 M19 (launch hardening), Part 27 (settings).

## 1. What must be running

| Process | Command | Notes |
|---|---|---|
| Web | PHP-FPM / FrankenPHP behind HTTPS | `APP_ENV=production`, `APP_DEBUG=false`, `php artisan optimize` after each deploy |
| SSR | `php artisan inertia:start-ssr` | one per web host; pages still work (client-rendered) if it is down |
| Queue | `php artisan horizon` | payment events, mail, broadcasts; Horizon dashboard at `/horizon` (admins) |
| Scheduler | cron `* * * * * php artisan schedule:run` | **one** server; every job uses `onOneServer()` |
| Websockets | `php artisan reverb:start` | live chat; chat falls back to polling if down |
| Search | Meilisearch | `CATALOG_SEARCH_DRIVER=meilisearch`; `php artisan catalog:search-sync` rebuilds |

Scheduled jobs (routes/console.php): payment sync and expiry, storage reservations, order timers, holds release,
ledger verify/reconcile, disputes deadlines, tickets auto-close, coupons expiry, marketing billing, affiliate release,
levels recompute, trending, account deletions, retention pruning, `ops:heartbeat` (every minute), `ops:check` (every 5 minutes).

## 2. Health and alerts

- `GET /up` — the framework is booting (load balancer check).
- `GET /health` — 200 `{"status":"ok"}` or 503 `{"status":"fail"}`. With `?token=$HEALTH_TOKEN` it lists each check:
  database, cache, scheduler heartbeat (< 5 min), failed jobs (last hour), last `ledger:verify`, payment events stuck > 15 min.
  Point the uptime monitor at `/health` (without the token).
- `ops:check` logs one **CRITICAL** line per failed check, and `ledger:verify` logs CRITICAL on any imbalance.
  Route the `critical` level to the on-call channel: `LOG_STACK=daily,slack` with `LOG_SLACK_WEBHOOK_URL`, level `critical`.

| Alert | First steps |
|---|---|
| scheduler | Is cron running `schedule:run` on exactly one host? `php artisan schedule:list`. Jobs are idempotent: running a late job is safe. |
| queue | `php artisan horizon:status`; inspect failed jobs in Horizon; fix the cause, then `php artisan queue:retry all`. |
| ledger | **Stop payouts** (finance: pause the withdrawal queue). `php artisan ledger:verify` prints each problem. Never edit balances: correct with a manual adjustment (Admin › Ledger › Manual adjustments, dual approval above USD 100). |
| payments | Check the PSP status page and webhook delivery; `php artisan payments:sync-pending` pulls state from the PSP. Events are stored once and applied once (safe to replay). |
| database / cache | Provider console; app returns 503 until back. |

## 3. Security settings to check at every deploy

- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS only; HSTS is sent automatically in production over HTTPS.
- `SESSION_SECURE_COOKIE` (defaults to on in production), `SESSION_ENCRYPT=true` recommended.
- CSP is on for the site (nonce-based scripts) and looser only for `/admin`. To trial a change: `CSP_REPORT_ONLY=true`.
- Staff must have 2FA (enforced on `/admin`); review staff roles monthly (Admin › Users › filter by role).
- Secrets: `APP_KEY`, vault keys (storage items), PSP keys, `HEALTH_TOKEN` live only in the secret manager.
- Rate limits: every write has a baseline (120/min per user, 10/min per IP on sign-up / password reset / 2FA);
  routes keep tighter limits, counted per route.

## 4. Backups and restore

- **Continuous**: managed PostgreSQL with point-in-time recovery (PITR), 7–14 days.
- **Logical**: `scripts/backup.sh` daily to separate storage (14 days kept, SHA-256 alongside).
- **Drill** (monthly, and before launch): `scripts/restore-drill.sh <dump>` on a non-production server. It restores,
  checks migrations and runs `ledger:verify`. Record the duration (RTO) and the backup age (RPO) in the drill log.
- Files: private disk (evidence, invoices, storage uploads) must be backed up with the same retention.

Restore in an incident: restore PITR to a new instance at the last good time → run `ledger:verify` → point the app at
it → replay PSP webhooks received after that time (`payments:sync-pending`) → reconcile (`ledger:reconcile`).

## 5. Load testing

`k6 run -e BASE_URL=<staging> tests/load/smoke.js` (profiles: `smoke`, `load` 50 users, `peak` 200 users).
Targets: p95 < 800 ms, < 1 % errors. Run before launch and after big releases, never against production.
Then a one-week staging soak with the scheduler and queue running before go-live (spec M19 acceptance).

## 6. Privacy operations

- **Data export**: users download their data from Settings › Account (audited, 3 per hour).
- **Deletion**: Settings › Delete account → 14-day cool-off → `accounts:process-deletions` anonymises. Staff can see
  requests in Admin › Users › Account deletions; blocked requests list why.
- **Retention**: `privacy:prune` daily per `config/x2key.php` `retention_days`. Money, orders, journals, disputes,
  tickets, reviews and audit logs are kept (legal records).

## 7. Before launch (open items)

- Legal texts written with a lawyer replace the draft pages (Terms, Buyer Protection, Seller Agreement, Prohibited
  Items, Privacy, Cookie, Refund, DMCA, GDPR, Pricing, IP, Affiliate Terms) — the seeded pages say "Draft".
- Founder decisions D1–D12 (docs/OPEN_QUESTIONS.md), real PSPs (D2), KYC provider (D11), SMS provider.
- ClamAV scanning of uploads (files are stored with `scan_status = pending`).
- Restore drill done and logged; load test passed; one-week staging soak.
