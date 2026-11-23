# X2KEY
Multi-vendor digital game goods marketplace, IA modelled on Z2U. Full spec: docs/X2KEY_SPEC.md (read the relevant Part before each task).

## Non-negotiable rules
- Money = integer minor units (bigint) + ISO currency. Never floats. Use brick/money.
- Every money movement (payments, credits, coupons, affiliate, withdrawals) = one balanced journal (Part 7). Never UPDATE a balance column directly.
- Points (X2K Score) are NOT money: separate points ledger, same append-only rules.
- Order/dispute/ticket/withdrawal status changes go ONLY through their state machine classes (Part 6). Each transition writes a history row.
- Webhooks and scheduled jobs must be idempotent.
- Storage items (keys, account credentials, codes) are encrypted at rest; every reveal is audit-logged.
- Catalog is game-centric: Game → GameCategory (game × category type) → Product → Offer.
- Domain code lives in app/Domains/<Domain>. Controllers/Filament stay thin.
- Every milestone ships with Pest tests; `composer test` and `composer stan` must pass.
- Current milestone: docs/PROGRESS.md
- Matching Z2U's architecture ≠ copying: never reuse Z2U's name, logo, artwork, copy, policies or catalog content.

## Dev environment (Windows)
- Portable PHP 8.4 + Composer live in `C:\dev\tools` (not on PATH by default). In Git Bash: `export PATH="/c/dev/tools/php:/c/dev/tools:$PATH"`.
- Local `.env` uses SQLite until Docker works; `.env.example` is the Sail/PostgreSQL config.
- Commands: `composer test` · `composer stan` · `composer lint` / `composer fix` · `npm run typecheck` · `npm run build` (client + SSR) · `php artisan inertia:start-ssr`.
- Sail (once Docker Desktop + WSL2 run): `cp .env.example .env && php artisan key:generate && ./vendor/bin/sail up -d`.

## Where things are
- Policy defaults (Part 27): `config/x2key.php`, read via `App\Shared\Support\Settings`.
- Money: `App\Shared\Money\Amount` + `MoneyCast`. Audit: `App\Shared\Audit\{Auditable,AuditLogger}`.
- Dashboard sidebar + routes: `App\Shared\Navigation\DashboardMenu` (single source, see ADR-004).
- UI strings: `lang/{en,fr,ar}.json` (a test enforces identical keys). Pages: `resources/js/Pages/{Public,Auth,Dashboard,Settings,Shared,...}`.
- Identity: `App\Domains\Identity` (User model, Fortify actions, sessions/devices, privacy password, phone verification). Certification: `App\Domains\Certification`.
- Auth = Fortify headless (ADR-007): Fortify owns POST routes, we own GET pages with the same route names.
- A real screen replaces a dashboard placeholder via the `$implemented` map in `routes/web.php`, keeping the `dash.{menu key}` route name.
- `Model::shouldBeStrict()` is on outside production: factories must set every column; actions return `->refresh()`ed models.
- Demo accounts after `php artisan migrate:fresh --seed` (local): `admin_demo` / `buyer_demo`, password `password` (admin must enable 2FA before /admin opens).
