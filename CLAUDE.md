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
