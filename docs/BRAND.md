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

**The mark** is the name drawn as a picture: an **X** whose first stroke is a **key** — round bow, shaft, two
teeth — crossed by a straight bar, on a violet → magenta tile. A key because every trade ends with something being
unlocked (a code, an account, a top-up); an X because it is the first letter of the name.

| Asset | File | Use |
|---|---|---|
| Mark, colour | `public/brand/x2key-mark.svg` | favicon (SVG), avatars, app icon, social profile |
| Mark, one colour | `public/brand/x2key-mark-mono.svg` | print, stamps, watermarks (takes the text colour) |
| Logo (mark + wordmark) | `resources/js/Components/Brand/Logo.tsx` | site header and footer, e-mails, documents |
| Raster icons | `public/favicon.ico`, `public/brand/icon-*.png`, `apple-touch-icon.png` | browsers, home screens (re-render with `python scripts/brand-icons.py`) |

**Wordmark:** `X2KEY` set in Sora ExtraBold (800), tight tracking; the **2** takes the accent colour (violet
`#5b3df5` on light, lavender `#a594ff` on dark). Never type the name as "X2Key", "x2key" or "X2 KEY" in running
text — always **X2KEY**.

**Clear space:** keep empty space around the logo equal to one quarter of the mark's height on every side.
**Minimum size:** mark 16 px (favicon); full logo 96 px wide. Below 360 px screen width the header shows the mark alone.

**Do not:** recolour the tile, rotate or skew the mark, add shadows or outlines, put the colour mark on busy photos
(use the one-colour mark or a solid backing), separate the key from the bar, or redraw the wordmark in another font.

## 3. Colour

Every pair used for **text** meets WCAG 2.2 AA (4.5:1); fills and icons meet 3:1. Checked values:

| Role | Token | Hex | Use | Contrast |
|---|---|---|---|---|
| Primary | `primary` | `#5b3df5` | buttons, links, active states, focus ring | 6.1:1 on white; white on it 6.1:1 |
| Primary hover | `primary-hover` | `#4a2ee0` | button hover | white on it 7.6:1 |
| Primary soft | `primary-soft` | `#eeeaff` | selected chips, highlights | primary text on it 5.2:1 |
| Magenta (brand) | `brand-magenta` | `#db2777` | logo gradient end, rare accents | white on it 4.6:1 |
| Lavender (brand) | `brand-lavender` | `#a594ff` | accents on dark (the "2" in the header) | 7.6:1 on night |
| Ink | `ink` | `#12121a` | body text, headings | 18.6:1 on white |
| Muted | `muted` | `#6b6f80` | secondary text, captions | 5.0:1 on white, 4.6:1 on canvas |
| Line | `line` | ink at 12 % | borders, dividers | — |
| Canvas | `canvas` | `#f4f5fa` | page background behind cards | — |
| Night | `surface-dark` | `#0b0e1f` | header, footer, hero, news band | white on it 19.2:1 |
| Night 2 | `surface-dark-2` | `#151a33` | cards on dark | — |
| Success | `success` | `#15803d` | paid, delivered, positive | 5.0:1 (was `#16a34a`, 3.3:1) |
| Danger | `danger` | `#e11d48` | errors, destructive buttons, discounts | 4.7:1; white on it 4.7:1 |
| Warning | `warning` | `#d97706` | warning fills and borders, rating stars | 3.2:1 (non-text) |
| Warning text | `warning-ink` | `#b45309` | warning text, pending badges | 5.0:1 |

**Category colours** (Hot Game cards and chips; white text on each ≥ 4.5:1): Currency `#c2410c`, Items `#7c3aed`,
Accounts `#334155`, Gift cards `#0369a1`, Top-up `#db2777`, Video games `#047857`.

**Proportions:** mostly white and canvas, ink text, one primary action per screen; night for framing (header,
footer, hero); magenta and category colours sparingly. Money states always use the semantic colours, never brand
colours.

## 4. Typography

| Role | Font | Weights | Notes |
|---|---|---|---|
| Display (h1–h3, logo, prices in hero) | **Sora** (variable) | 700, 800 | geometric, wide; titles only |
| Text (everything else) | **Inter** (variable) | 400, 500, 600 | very legible at small sizes, tabular numbers for prices |
| Arabic | **Noto Sans Arabic** (variable) | 400–700 | used for body and headings when `dir="rtl"` |

All three are self-hosted (`@fontsource-variable/*`): no third-party request, allowed by the site's CSP, `font-display: swap`.

**Scale** (root 14 px): body 14 px / 1.5; small 12 px; h3 18 px; h2 24 px; h1 30–36 px (60 px in the home hero).
Line length 60–80 characters for reading text (help, news, legal). Numbers and prices: Inter with
`tabular-nums` in tables.

## 5. Shape, space and components

- **Grid:** 4 px base; page width 1280 px (`max-w-7xl`), 16 px side padding on mobile.
- **Radius:** 6 px for cards, inputs and buttons; full pill for secondary actions and chips; 14/64 of the size for the logo tile.
- **Elevation:** one soft shadow for cards; no stacked shadows.
- **Buttons:** primary = filled violet, one per view; secondary = outlined pill; destructive = danger fill;
  disabled = 50 % opacity. Minimum touch target 40 × 40 px.
- **Focus:** 2 px primary outline with offset, never removed.
- **RTL:** use logical properties only (`ms-`, `pe-`, `start-`); icons that imply direction mirror in Arabic.

## 6. Icons and imagery

- **Icons:** one line set (`resources/js/Components/Icon.tsx`), 24 px grid, rounded caps, 1.75–2 px stroke,
  currentColor. No mixed icon styles.
- **Game art:** only images the publisher allows for resale listings, or plain gradient tiles (violet → night) with
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
2. Text contrast ≥ 4.5:1, icons and borders ≥ 3:1; checked in light and on night backgrounds.
3. One primary button; destructive actions confirm.
4. Works at 360 px wide and in Arabic (RTL).
5. Strings in `lang/en.json`, `fr.json`, `ar.json`.
