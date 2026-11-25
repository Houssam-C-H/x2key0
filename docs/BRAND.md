# X2KEY brand guidelines (charte graphique)

The rules for how X2KEY looks and sounds, on the website and anywhere else. The design tokens in
`resources/css/app.css` implement this document; if they disagree, fix one of them.

> **Trademark (founder decision D12):** "X2KEY" and this mark still need a trademark search before launch. Nothing
> here may reuse Z2U's name, logo, artwork, colours-as-a-whole, copy or catalog content (spec Part 2.4).

## 1. Positioning

- **What we are:** a marketplace where independent sellers offer game currency, top-ups, items, accounts, keys and
  gift cards, and where the buyer's money is held until the order is delivered.
- **Who it is for:** players (16–35, mobile-first, often multilingual: English, French, Arabic) who want a fair
  price fast, and small sellers who want buyers without running a shop.
- **What we promise:** *safe trades, fast delivery, fair prices.* Trust comes first; speed and price follow.
- **Mood:** confident and energetic, but calm where money is involved. Bold on discovery pages (home, games),
  quiet and clear in checkout, orders, wallet and disputes.

## 2. Logo

**The official logo** (supplied by the brand owner, `resources/brand-src/x2key-logo-source.png`): a square
**mark** (a black X / crossed-chevron emblem between two red triangles) and the **wordmark** `X2KEY` in heavy
geometric capitals with a stylised red **2**. Black + red on light backgrounds, white + red on dark.

The source PNG is vectorised by `python scripts/trace-logo.py` (OpenCV contour trace) into
`resources/js/Components/Brand/logo-paths.json` and the SVG files below. Re-run it, then
`python scripts/brand-icons.py`, whenever the source logo changes. Never redraw the logo by hand or set the wordmark
in a font.

| Asset | File | Use |
|---|---|---|
| Logo, light backgrounds | `public/brand/x2key-logo.svg` | documents, e-mails, partners |
| Logo, dark backgrounds | `public/brand/x2key-logo-white.svg` | dark banners, video |
| Mark, colour | `public/brand/x2key-mark.svg` | avatars, social profile |
| Mark, one colour | `public/brand/x2key-mark-mono.svg` | print, stamps, watermarks (takes the text colour) |
| In the app | `Components/Brand/Logo.tsx` (`Logo`, `LogoMark`, `tone="light" \| "dark"`) | header, footer, auth, hero watermark |
| Raster icons | `public/favicon.ico`, `public/brand/icon-*.png`, `apple-touch-icon.png` | browsers, home screens (mark on a white tile) |

Always write the name **X2KEY** in running text (never "X2Key", "x2key" or "X2 KEY").

**Clear space:** one quarter of the mark's height on every side. **Minimum size:** mark 16 px; full logo 96 px
wide. Below 640 px screen width the header shows the mark alone.

**Do not:** recolour the red, swap black and red, rotate, skew, outline or add shadows, put it on busy photos
without a solid backing, or separate the mark from the wordmark except where the mark alone is listed above.

## 3. Colour

Brand colours are **red, black and white**. Every pair used for **text** meets WCAG 2.2 AA (4.5:1); fills and
icons meet 3:1. Checked values:

| Role | Token | Hex | Use | Contrast |
|---|---|---|---|---|
| Brand red | `brand-red` | `#ed1c24` | the logo only | white on it 4.4:1 (graphic use only, never for text) |
| Primary | `primary` | `#d91a21` | buttons, links, badges, active states, focus | 5.1:1 on white, 4.7:1 on canvas; white on it 5.1:1 |
| Primary hover | `primary-hover` | `#b9141a` | button hover | white on it 6.6:1 |
| Primary soft | `primary-soft` | `#fef0f0` | selected chips, icon tiles, badges | primary text on it 4.6:1 |
| Deep red | `brand-deep` | `#7a0f14` | hero and banner gradients (primary → deep) | white on it 11:1 |
| Glow | `brand-glow` | `#ff6b70` | red accents on dark (hero highlight) | 6.6:1 on black |
| Ink | `ink` | `#111111` | body text, headings, logo black | 18.9:1 on white |
| Muted | `muted` | `#666666` | secondary text, captions | 5.7:1 on white, 5.3:1 on canvas |
| Line | `line` | ink at 10 % | borders, dividers | — |
| Canvas | `canvas` | `#f6f6f6` | page bands behind cards | — |
| Black | `surface-dark` | `#141414` | hero base, news band, dark cards | white on it 18.4:1 |
| Black 2 | `surface-dark-2` | `#1f1f1f` | cards on dark | — |
| Success | `success` | `#15803d` | paid, delivered, positive | 5.0:1 |
| Danger | `danger` | `#be123c` | errors, destructive actions (kept distinct from brand red) | 6.3:1; white on it 6.3:1 |
| Warning | `warning` | `#d97706` | warning fills and borders, rating stars | 3.2:1 (non-text) |
| Warning text | `warning-ink` | `#b45309` | warning text, pending badges | 5.0:1 |

**Category tints** (category cards, Hot Game headers: the colour at 9 % on white, the icon in full colour):
Currency `#c2410c`, Top-up `#be185d`, Items `#6d28d9`, Boosting = primary, Accounts `#334155`, Video games
`#047857`, Gift cards `#0369a1`, Coupons = warning-ink.

**Proportions:** a white site with ink text; red for the one main action, badges, the last word of section titles
and rules; black + deep-red gradient for the hero banner. Money states always use the semantic colours.

## 4. Typography

| Role | Font | Weights | Notes |
|---|---|---|---|
| Everything (Latin) | **Poppins** | 400, 500, 600, 700, 800 | geometric, matches the logo; titles 700, body 400 |
| Arabic | **Noto Sans Arabic** (variable) | 400–700 | used for body and headings when `dir="rtl"` |

Self-hosted (`@fontsource/poppins`, `@fontsource-variable/noto-sans-arabic`): no third-party request, allowed by the
site's CSP, `font-display: swap`.

**Section titles:** the last word in primary red, followed by a thin red rule to the edge ("Select **Categories**").

**Scale** (root 14 px): body 14 px / 1.5; small 12 px; h3 18 px; h2 24–30 px; h1 30–36 px (60 px in the home hero).
Line length 60–80 characters for reading text. Prices: `tabular-nums` in tables.

## 5. Shape, space and components

- **Grid:** 4 px base; page width 1280 px (`max-w-7xl`), 16 px side padding on mobile.
- **Radius:** 6 px for cards, inputs and buttons; full pill for secondary actions and chips; 14/64 of the size for the logo tile.
- **Elevation:** one soft shadow for cards; no stacked shadows.
- **Buttons:** primary = filled red, one per view; secondary = outlined pill; destructive = danger fill;
  disabled = 50 % opacity. Minimum touch target 40 × 40 px.
- **Focus:** 2 px primary outline with offset, never removed.
- **RTL:** use logical properties only (`ms-`, `pe-`, `start-`); icons that imply direction mirror in Arabic.

## 6. Icons and imagery

- **Icons:** one line set (`resources/js/Components/Icon.tsx`), 24 px grid, rounded caps, 1.75–2 px stroke,
  currentColor. No mixed icon styles.
- **Game art:** only images the publisher allows for resale listings, or plain gradient tiles (generated from the game name) with
  the game name — that is the default when no art is licensed.
- **Illustrations:** flat, two or three brand colours, no 3D mascots copied from other marketplaces.
- **Photos:** real players and devices, natural light; no stock "hacker in a hoodie", no imagery suggesting cheating.

## 7. Voice

Plain, short and honest. Say what happens and what the user can do next.

- **Do:** "Your payment is held until you confirm delivery." · "Paste the address of an X2KEY page."
- **Don't:** hype ("INSANE DEALS!!!"), blame ("You entered invalid data"), or legal jargon in the UI.
- Same meaning in en / fr / ar (`lang/*.json` keep identical keys); French uses « » and a space before `: ? !`.

## 8. Checklist for new screens

1. Uses tokens only (no raw hex in components).
2. Text contrast ≥ 4.5:1, icons and borders ≥ 3:1; checked in light and on dark backgrounds.
3. One primary button; destructive actions confirm.
4. Works at 360 px wide and in Arabic (RTL).
5. Strings in `lang/en.json`, `fr.json`, `ar.json`.
