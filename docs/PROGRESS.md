# Progress

**Current milestone:** M1 — Scaffold (code complete; Docker/Postgres verification pending)

| # | Milestone | Status | Notes |
|---|---|---|---|
| M0 | Business feasibility (founder) | ⏳ founder | §2.3 decisions open; building on defaults (ADR-001) |
| M1 | Scaffold | ✅ code done · ⏳ Sail run | See checklist below |
| M2 | Identity, levels, settings, certification | next | |
| M3–M19 | — | not started | |

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
