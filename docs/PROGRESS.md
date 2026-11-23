# Progress

**Current milestone:** M3 — Catalog (next). M1 and M2 code complete; Docker/Postgres local run still pending (WSL2 admin install).

| # | Milestone | Status | Notes |
|---|---|---|---|
| M0 | Business feasibility (founder) | ⏳ founder | §2.3 decisions open; building on defaults (ADR-001) |
| M1 | Scaffold | ✅ code done · ⏳ Sail run | See checklist below |
| M2 | Identity, levels, settings, certification, roles | ✅ done | See checklist below |
| M3 | Catalog, listing, facets, Meilisearch | next | |
| M4–M19 | — | not started | |

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
