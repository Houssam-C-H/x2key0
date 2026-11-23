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
- Local PostgreSQL 17 (portable, for the race tests in `tests/Concurrency` and CI parity): start with `C:\dev\tools\pgsql\bin\pg_ctl -D C:\dev\tools\pgsql-data -l C:\dev\tools\pgsql-data\server.log -o "-p 5433 -c listen_addresses=localhost" start`; run tests with `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=x2key_test DB_USERNAME=sail DB_PASSWORD=password php vendor/bin/pest`. On SQLite the race tests skip.
- Local Meilisearch (portable, for the parity test or `CATALOG_SEARCH_DRIVER=meilisearch`): `C:\dev\tools\meilisearch\meilisearch.exe --db-path C:\dev\tools\meilisearch\data\ms --http-addr 127.0.0.1:7700 --no-analytics`. Without it, `MeilisearchParityTest` is skipped.

## Where things are
- Policy defaults (Part 27): `config/x2key.php`, read via `App\Shared\Support\Settings`.
- Money: `App\Shared\Money\Amount` + `MoneyCast`. Audit: `App\Shared\Audit\{Auditable,AuditLogger}`.
- Dashboard sidebar + routes: `App\Shared\Navigation\DashboardMenu` (single source, see ADR-004).
- UI strings: `lang/{en,fr,ar}.json` (a test enforces identical keys). Pages: `resources/js/Pages/{Public,Auth,Dashboard,Settings,Shared,...}`.
- Identity: `App\Domains\Identity` (User model, Fortify actions, sessions/devices, privacy password, phone verification). Certification: `App\Domains\Certification`.
- Auth = Fortify headless (ADR-007): Fortify owns POST routes, we own GET pages with the same route names.
- A real screen replaces a dashboard placeholder via the `$implemented` map in `routes/web.php`, keeping the `dash.{menu key}` route name.
- `Model::shouldBeStrict()` is on outside production: factories must set every column; actions return `->refresh()`ed models.
- Demo accounts after `php artisan migrate:fresh --seed` (local): `admin_demo` / `buyer_demo` / `seller_demo`, password `password` (admin must enable 2FA before /admin opens; seller_demo has an open store, offers and Storage with one low group). Extra demo stores: `nova_keys`, `arcade_depot`, `quest_supply`, `level_up_mart`.
- Catalog: `App\Domains\Catalog` — models, `Schema\{AttributeSchema,BuyerInputSchema}`, `Search\*` (ADR-011), `Support\{CatalogReader,CatalogPresenter,CatalogCache}`. Public controller: `Http\Controllers\Public\CatalogController`. Tests use `Tests\Support\CatalogFixture` (hand-countable facets).
- Never name a column `attributes` (Eloquent internal); product values live in `attribute_values`.
- Sellers: `App\Domains\Sellers` — status changes only via `StateMachines\SellerProfileStateMachine` (ADR-013), actions in `Actions\*`, props in `Support\SellerPresenter`. Seller Center controllers in `Http\Controllers\Seller` (read the profile with `ResolvesSeller`, never a cached relation). Seller routes get the `seller` middleware from the menu audience.
- Seller-written text: validate with `App\Shared\Validation\NoContactInfo`.
- Offers: `App\Domains\Offers` — status only via `StateMachines\OfferStateMachine` (ADR-015), create/edit in `Actions\SaveOffer`, batch/inline in `Actions\BulkUpdateOffers`, product price caches via `Actions\RefreshProductOffers`, props in `Support\OfferPresenter`. Tests: `Tests\Support\OfferFixture`.
- Storage vault: `App\Domains\Storage` — secrets only through `Contracts\SecretVault` (ADR-014); never log, flash or put payloads in Inertia props; reveals are audited JSON. Stock/alerts via `Actions\RefreshStorageStock`. Tests unlock Storage pages with `unlockPrivacy($user)` (tests/Pest.php).
- Favorites / browsing history: `App\Domains\Engagement`.
- Checkout & orders: `App\Domains\Checkout` (`Actions\CreateOrder`, `Support\QuoteCalculator`), `App\Domains\Orders` — status only via `StateMachines\OrderStateMachine`, stock holds in `Support\StockReservations`. Payments: `App\Domains\Payments` — drivers behind `Contracts\PaymentProvider` + `Support\PaymentRouter`; webhook events applied only by `Actions\ApplyPaymentEvent`; credits only through `Contracts\CreditsWallet` (ADR-016). Risk: `App\Domains\Risk\Support\RiskEvaluator`. Tests: `Tests\Support\CheckoutFixture`, `InMemoryCreditsWallet`.
- Ledger: `App\Domains\Ledger` — postings only through `Actions\PostJournal` (ADR-018), posted in the same transaction as the business change; payment journals in `Actions\PostOrderPayments`; `ledger:verify` / `ledger:reconcile`. Points: `App\Domains\Points` (`Actions\PostPointsJournal`, `AwardOrderPoints`). Tests: `Tests\Support\Ledger` (fund credits, read balances).
- Delivery: `App\Domains\Delivery` — `Actions\AutoDeliver` (Storage), `DeliverOrder` (manual + milestones), `RevealOrderSecrets` (audited); refunds only through `Payments\Actions\RefundOrder` (proportional sources, ADR-019); order rules for buttons in `Orders\Support\OrderRules`; both order pages share `Orders\Support\OrderPresenter`. Tests: `tests/Feature/Delivery`.
- Completion & money out: orders complete only through `OrderStateMachine::complete` (settles via `Ledger\Actions\SettleOrder`, awards points); seller money is released by `Ledger\Actions\ReleaseHolds` (ADR-020). Notifications: `Announcements\Support\Notifier::notify($userId, $type, $category, $data)` with `notify.{type}.title|body` strings; announcement centre in `Announcements\Support\AnnouncementCenter`; buyer statements in `Ledger\Support\Statements`.
- Chat: `App\Domains\Messaging` — open conversations only via `Actions\OpenConversation` (order chat from the `OrderPaid` listener), post only via `Actions\SendMessage` (redaction, risk flag, auto-reply), readers via `Support\ChatPresenter`, online status via `Support\Presence`; contact-info masking is `App\Shared\Support\ContactInfo::redact` (ADR-021).
- Dev payments: fake PSP page `/fake-psp/{id}` (Pay / Decline / Cancel); `buyer_demo` can buy any demo offer.
- Disputes: `App\Domains\Disputes` — status only via `StateMachines\DisputeStateMachine`; open with `Actions\OpenDispute`, party actions in `Actions\DisputeParticipation`, outcomes only through `Actions\ResolveDispute` (refunds after completion: `RefundCompletedOrder`); rules in `Support\DisputeRules`; an open dispute or chargeback blocks the order (`Support\OpenCases`, ADR-022). Seller funds freeze/recover: `Ledger\Actions\SellerFunds`. Chargebacks: `Payments\Actions\ApplyChargeback`. Violations: `App\Domains\Violations` (`RecordViolation`, `DecideViolation`, rules seeded by `ViolationRuleSeeder`). Tests: `tests/Feature/Disputes`.
- Tickets: `App\Domains\Tickets` — status only via `StateMachines\TicketStateMachine`; every user/staff action in `Actions\TicketDesk` (open, replies, internal notes, close, reopen, rate, assign, priority, convert to dispute); auto-close `Actions\AutoCloseTickets` (ADR-023). Tests: `tests/Feature/Tickets`.
