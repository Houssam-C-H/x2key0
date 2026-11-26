# X2KEY operations runbook

For whoever runs X2KEY in production: what runs where, how to tell it is healthy, and what to do when it is not.
First installation, step by step from an empty server: [DEPLOYMENT.md](DEPLOYMENT.md).
Spec references: Part 23 (security & privacy), Part 25 M19 (launch hardening), Part 27 (settings).

## 1. What must be running

| Process | Command | Notes |
|---|---|---|
| Web | PHP-FPM / FrankenPHP behind HTTPS | `APP_ENV=production`, `APP_DEBUG=false`, `php artisan optimize` after each deploy |
| SSR | `php artisan inertia:start-ssr` | one per web host; pages still work (client-rendered) if it is down |
| Queue | `php artisan horizon` | queues `payments` (first) and `default`: payment events, mail, broadcasts; dashboard at `/horizon` (admins with 2FA) |
| Scheduler | cron `* * * * * php artisan schedule:run` | **one** server; every job uses `onOneServer()` |
| Websockets | `php artisan reverb:start` | live chat; chat falls back to polling if down |
| Search | Meilisearch | `CATALOG_SEARCH_DRIVER=meilisearch`; `php artisan catalog:search-sync` rebuilds |

Scheduled jobs (routes/console.php): payment sync and expiry, storage reservations, order timers, holds release,
ledger verify/reconcile, disputes deadlines, tickets auto-close, coupons expiry, marketing billing, affiliate release,
levels recompute, trending, account deletions, retention pruning, `ops:heartbeat` (every minute), `ops:check` (every 5 minutes).

**Uploads:** run `php artisan storage:link` once per server (and after a fresh checkout) so admin uploads — game pictures, news covers — are served from `/storage/…`. Game pictures: Catalog › Games › a game › Pictures (icon 512×512, cover 960×600, banner 1600×500; only images the publisher allows for resale listings).

**Browser tests** (tests/e2e, Playwright with the installed Microsoft Edge): with the app running, `npm run e2e`. It first runs `php artisan e2e:prepare` (via `docker compose exec` by default; set `E2E_ARTISAN="php artisan"` and `BASE_URL=…` otherwise), which resets the accounts `e2e_buyer`, `e2e_seller`, `e2e_admin` and one test offer — it refuses to run in production. Every journey runs at desktop and phone size and fails if a page scrolls sideways. Report: `storage/e2e-report/index.html`.

**E-mail:** all mail uses the branded theme (resources/views/vendor/mail, logo `public/brand/x2key-logo-email.png`). Important inbox notifications are also e-mailed (`Notifier::EMAILED`; users can switch off order / store / ticket e-mails in Settings › Account, money, dispute and safety ones always go). Mail is queued, so a queue worker must run. Any SMTP provider works without code: e.g. Resend — `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME=resend`, `MAIL_PASSWORD=<API key>`, `MAIL_FROM_ADDRESS=no-reply@<your-domain>` (verify the domain's SPF/DKIM in the provider first).

**Speed (production checklist):**
- After each deploy: `php artisan optimize` and `php artisan filament:optimize` (config, routes, views, events, Filament components cached); `npm run build` assets are content-hashed.
- PHP: OPcache on (`docker/opcache.ini`), `opcache.validate_timestamps=0` in production, **no Xdebug/PCOV** (they are dev-only and slow every request several times over), `APP_DEBUG=false`.
- Cache, sessions and queues on Redis (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`).
- Web server: compress text responses (gzip/brotli) and send `Cache-Control: public, max-age=31536000, immutable` for `/build/*` and `/fonts/*` (hashed file names), e.g. nginx `location /build/ { expires max; add_header Cache-Control "public, immutable"; gzip_static on; }`.
- `php artisan perf:pages [/path …]` prints queries, database time and total time per page and flags repeated queries; run it after big changes. Every foreign key has an index (`ForeignKeyIndexesTest` on PostgreSQL).

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

### Web push (installable app)

Generate keys once with `php artisan push:keys` and put `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` (and `VAPID_SUBJECT=mailto:support@<your-domain>`) in `.env`, then `config:cache`. Never change them afterwards: every device would have to subscribe again. Push needs HTTPS and a running queue worker.

### Alerts and uptime

- `ops:check` (every 5 minutes) sends failing checks to `OPS_ALERT_EMAIL` and/or a Slack incoming webhook `OPS_SLACK_WEBHOOK`: once when a check fails, every `OPS_ALERT_REPEAT_MINUTES` (60) while it stays down, once when it recovers. Messages contain check names and short details only.
- These alerts need the app to be running. Add an **external uptime monitor** (UptimeRobot, Better Stack, Pingdom…) that calls `https://<your-domain>/health` every minute (with `HEALTH_TOKEN` for full detail) and alerts by SMS/e-mail when it fails or is unreachable — that also covers the whole server being down.

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
- **Logical, automatic**: `php artisan ops:backup` runs nightly at 03:15 from the scheduler wherever backups are required (production by default, `BACKUPS_REQUIRED`). It writes the database (`pg_dump` custom format) and every uploaded file (`storage/app/public` + `private` as `uploads.tar.gz`), each with its SHA-256 and a `manifest.json`, to `BACKUP_DISK` under `backups/<UTC stamp>/`, and deletes sets older than `BACKUP_RETENTION_DAYS` (14). Point `BACKUP_DISK` at a bucket **in another account or provider** than the app (e.g. an `s3` disk with its own keys); the default `backups` disk is local and only for development. The `backup` health check fails when the last good backup is older than `BACKUP_MAX_AGE_HOURS` (26), so a silent stop raises an alert.
- `scripts/backup.sh` remains for one-off dumps from outside the app.
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

## 6b. Two-step verification lockouts

- **A user lost their authenticator:** they log in with a recovery code ("Use a recovery code" on the code screen). Without one, staff reset it in the admin panel: Users › the user › **Reset 2FA** (reason required, audited) after checking the person owns the account.
- **The only admin is locked out** (staff cannot reset their own account): on the server run
  `php artisan users:reset-two-factor <username> --reason="…"` (audited as a console action), then the admin sets 2FA up again before /admin opens.

## 7. Before launch (open items)

- Legal texts written with a lawyer replace the draft pages (Terms, Buyer Protection, Seller Agreement, Prohibited
  Items, Privacy, Cookie, Refund, DMCA, GDPR, Pricing, IP, Affiliate Terms) — the seeded pages say "Draft".
- Founder decisions D1–D12 (docs/OPEN_QUESTIONS.md), real PSPs (D2), KYC provider (D11), SMS provider.
- ClamAV scanning of uploads (files are stored with `scan_status = pending`).
- Restore drill done and logged; load test passed; one-week staging soak.

## 8. Social login (Google, Facebook)

Buttons appear on the login and sign-up pages only for providers whose keys are set (ADR-033). Callback URLs use `APP_URL`, so set it to the public `https://` address first.

**Google** — Google Cloud Console › APIs & Services:
1. OAuth consent screen: External, app name X2KEY, support e-mail, logo, links to the privacy policy and terms; scopes `openid`, `email`, `profile`; publish the app.
2. Credentials › Create credentials › OAuth client ID › Web application. Authorised redirect URI: `https://<your-domain>/auth/google/callback` (add `http://localhost/auth/google/callback` for local testing).
3. Put the values in `.env`: `GOOGLE_CLIENT_ID=…`, `GOOGLE_CLIENT_SECRET=…`.

**Facebook** — developers.facebook.com › My Apps › Create app › "Authenticate and request data from users with Facebook Login":
1. Use cases › Facebook Login › Customise: add the `email` permission.
2. Settings: Valid OAuth Redirect URIs `https://<your-domain>/auth/facebook/callback`.
3. App settings › Basic: privacy policy URL, terms URL, data deletion instructions URL (`https://<your-domain>/en/page/privacy`), app icon; switch the app to Live.
4. `.env`: `FACEBOOK_CLIENT_ID=<App ID>`, `FACEBOOK_CLIENT_SECRET=<App secret>`.

After changing `.env` in production run `php artisan config:cache`. Test: log out, "Continue with Google", then Settings › Security › Connected accounts shows the link.


## 9. Interface languages (Spanish, German, Portuguese, Turkish)

English, French and Arabic are **content languages**: fully translated, and sellers and staff type descriptions,
notices and CMS pages in them. Spanish, German, Portuguese and Turkish are **interface languages**: the screens every
visitor sees (header, menus, home page, footer, search, cookie banner, language picker) are translated, and every other
string shows in English until a translator fills it in. Seller and CMS content falls back to English.

- Progress: `php artisan lang:missing` (translated / total per language).
- Send work to a translator: `php artisan lang:missing es --export=es.csv` → a CSV with `key, english, translation`
  (opens in Excel / Google Sheets). Keep words that start with a colon (`:count`, `:amount`) exactly as they are.
- Bring it back: `php artisan lang:missing es --import=es.csv`. Lines whose placeholders changed are skipped and
  listed. Commit `lang/es.json`, then deploy.
- A new language: add it to `x2key.locales` in `config/x2key.php` (`content => false`) and create `lang/<code>.json`
  (a test checks that every configured language has a file).

## 10. Live chat, queue and scheduler

Three background processes run next to the website. In Docker they are the `reverb`, `queue` and `scheduler`
services in `compose.yaml` (`docker compose up -d` starts them); on a server, run each under Supervisor/systemd.

| Process | Command | Without it |
|---|---|---|
| Websockets (live chat) | `php artisan reverb:start --host=0.0.0.0 --port=8080` | chat falls back to refreshing every 5 s |
| Queue worker | `php artisan queue:work --queue=payments,default --tries=3` | no notification e-mails, no web push, payment webhooks are not applied |
| Scheduler | `php artisan schedule:work` (or cron `* * * * * php artisan schedule:run`) | no auto-complete, auto-cancel, backups, price alerts, FX rates |

Settings: `BROADCAST_CONNECTION=reverb`; `REVERB_APP_ID/KEY/SECRET` (random, any values); `REVERB_HOST/PORT/SCHEME` =
where the **app** reaches Reverb (Docker: `reverb`, `8080`, `http`); `VITE_REVERB_HOST/PORT/SCHEME` = where the
**browser** reaches it (production: your domain, `443`, `https`, proxied to Reverb). The page's security policy
allows the `VITE_REVERB_*` address. Run `npm run build` after changing any `VITE_*` value.
Check: `npx playwright test tests/e2e/live.spec.ts --project=desktop` (two browsers, message arrives over the websocket).
After deploying PHP changes restart the workers (`php artisan queue:restart`, `php artisan reverb:restart`).

## 11. Crypto payments (NOWPayments, ADR-041)
- In the NOWPayments dashboard: create the API key and the IPN secret, put them in `.env` as `NOWPAYMENTS_API_KEY` / `NOWPAYMENTS_IPN_SECRET`, then `php artisan config:clear` and `php artisan queue:restart`.
- Register the IPN URL `{APP_URL}/webhooks/nowpayments`. It must be reachable from the internet: on a laptop, use a tunnel (e.g. `cloudflared tunnel --url http://localhost`) and set `APP_URL` to the tunnel address, or test with `NOWPAYMENTS_SANDBOX=true` and a sandbox account.
- Without IPNs nothing completes: the order stays "pending payment" and expires after the payment window. Check Admin › Payments › Provider events.
- Refunds: each crypto refund raises a `manual_crypto_refund` risk flag (Admin › Risk flags) with the amount and invoice. Send the crypto from the NOWPayments dashboard to the buyer, then resolve the flag.
- A payment that ends `partially_paid` stays pending; settle it with the buyer in the NOWPayments dashboard (top-up or refund) — X2KEY never captures a partial payment.