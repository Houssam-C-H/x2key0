# Architecture Decision Records

## ADR-001 — Build on spec defaults while M0 is open (2026-11-22)
**Context:** Founder decisions D1–D12 (spec §2.3) are unanswered.
**Decision:** Engineering proceeds with every documented default (FakePaymentProvider, USD settlement, en/fr/ar, PSP-held design, 8% commission, 3% min $0.30 buyer fee, no credits top-up, points → coupons only).
**Consequence:** All of these live in `settings` / config so they can change without code rewrites.
