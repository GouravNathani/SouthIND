# SouthIND — theme preview

One realistic dashboard screen rendered in **3 colour skins × 2 modes**, so the
look can be judged before any product code is written.

```bash
npm install
npm run dev      # http://localhost:5199
```

## The six variants

| Skin | Dark | Light |
|---|---|---|
| **A · Midnight** — emerald neon | near-black, frosted glass, neon `#00e5a0` | cool paper, deepened emerald `#05875e` |
| **B · Temple** — South Indian heritage | sandalwood, temple gold `#d4af37`, kumkum | ivory paper, antique bronze `#8a6d1f`, kumkum |
| **C · Ledger** — clean fintech | neutral slate, blue `#3b82f6` | white, blue `#2563eb` |

Every combination has a shareable link:

```
?theme=midnight|temple|ledger &mode=dark|light &device=desktop|tablet|mobile
```

## Why it is built this way

- **A theme is only a set of CSS custom properties** (`src/themes.css`). Markup and
  component CSS never name a colour — they read `var(--accent)`, `var(--surface)`,
  `var(--r-lg)`. That is why six variants cost the same to maintain as one, and why
  all three skins can ship in the product with a runtime switcher.
- **Contrast is checked per mode.** Neon `#00e5a0` is ~1.6:1 on white and bright
  `#d4af37` is ~1.9:1 on cream, so both light modes deepen their accent rather than
  reusing the dark one. Accents are used for amounts and links, not just button
  fills, so each must clear 4.5:1 on its own `--bg`.
- **The frame is a CSS container**, so the Desktop/Tablet/Mobile toggle causes a
  genuine reflow — the icon rail becomes a bottom tab bar, the transactions table
  becomes cards, and the balance card reorders to the top where a phone user needs it.
- **Motion is real, not decorative** — spring rail expansion, shared-layout active
  indicators, staggered rows, and tallying amounts that deliberately never start
  from ₹0. Everything collapses to instant under `prefers-reduced-motion`.

`src/themes.css` is the deliverable — it transfers verbatim into
`User/src/styles/tokens.css`, and maps 1:1 into Tailwind v4's `@theme inline`.
