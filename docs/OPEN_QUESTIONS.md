# Open questions

## Founder decisions (spec §2.3) — all open, defaults in use
D1 entity country · D2 PSPs · D3 launch categories · D4 languages · D5 settlement currency · D6 who holds funds · D7 fees · D8 credits top-up · D9 points conversion · D10 affiliate basis · D11 KYC provider · D12 brand clearance

## Spec inconsistencies to resolve
1. **Points redemption:** D9 says points → platform coupons only, never cash; §1.10 / §7.7 / Part 27 also describe redeeming 50 000 points → Credits Balance (stored value). Pick one. *Working assumption: D9 (coupons only); credits redemption behind a disabled flag.*
2. **Affiliate basis:** D10 says % of platform revenue; Part 15 / 5.15 / Part 27 say 20 % of platform *net profit* + 3 % level 2; §7.2 example uses 10 %. *Working assumption: net-profit basis per Part 15, rates from settings.*
3. **Order number format:** §1.6 notes `X` + 10 digits (observed); Part 5 says `X2K{yyMMdd}{seq}`. *Working assumption: Part 5.*
4. **Order sub-status:** §6.1 lists `delivering` as a substatus in the transition table but the enum in 5.6 is `new|restocking|in_trading|issue`. *Working assumption: 5.6 enum; "Delivering" chip = `in_trading`.*
5. **Offer `suspended` status** appears in 6.2 but not in the 5.4 enum. *Working assumption: map to `system_deactivated`.*
6. **Routes:** §1.2 records Z2U URL patterns; Part 20 defines X2KEY's own localized routes. *Working assumption: Part 20.*
7. **Section numbering:** Part 6 orders 6.9 before 6.7; milestones are Part 25, not Part 22 as §0.2 says. Cosmetic.
8. **Offer currency:** spec 1.8 lets sellers price in USD/EUR/GBP/CAD/AUD; M5 prices offers in USD only (ADR-015) until FX rates exist. *Confirm whether non-USD listing prices are needed at launch.*
9. **Offer re-review on edit:** spec does not say whether editing a published offer needs review again. *Working assumption: content edits (title, description, attributes) do for levels without auto-approve; price/stock edits never do.*
10. **Service charge on credits:** spec 7.2 applies the buyer fee to the whole order including the credits part; Z2U's exact rule is unknown. *Working assumption: whole order (ADR-017); method surcharges only on the PSP part.*
11. **Payment review rejections:** money captured for a rejected or late-captured order must be refunded. *Working assumption: flagged in M6, refunded by finance through the M8 refund flow.*

## Environment
- Repo moved out of OneDrive to `C:/dev/x2key` (2026-11-22).
