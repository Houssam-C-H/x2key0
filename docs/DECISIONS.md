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
