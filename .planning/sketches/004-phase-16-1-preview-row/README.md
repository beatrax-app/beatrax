---
sketch: 004
name: phase-16-1-preview-row
question: "How do payment-type badges, Funding source, and inline rename for fallback-description rows coexist without making the row noisy?"
winner: D
tags: [preview, table, badges, rename, categorize, phase-16-1]
---

# Sketch 004: Import Preview Row Affordances

## Winner

**Variant D ★ — Leading type chip (glyph + word) + click-italic-text
rename + per-row category confirm/clear with bulk-confirm.**

Column order: Type · Date · Counterparty · Funding source · Category · Amount.

- **Type column (new, leading)** — Chip with both glyph and word
  (⛁ PIN · ⌘ Online · ↔ Transfer · ⤓ Direct debit · € Cash), colored
  to the type token. Sits as its own column so you can vertical-scan
  payment shape across all rows.
- **Counterparty column** — Plain name. For rows where the import
  pipeline only had a description fallback (e.g. `BCK*SHELL PIETER
  NIEUW`, `CCV*KIOSK 7438`), the italic-muted text becomes the
  click-target itself. Click → rename popover with a "Remember for
  future imports" checkbox that seeds a learning rule.
- **Funding source column** — Monospace pill-tag with the masked
  account IBAN (e.g. `NL91 ASNB · 4321`).
- **Category column (new affordances)** — Three states render
  differently:
  - **Auto-suggested** (the import pipeline guessed a category):
    dashed-border italic chip. Hover the row to reveal **✓** confirm
    and **×** clear quick-action buttons. ✓ commits the category, ×
    drops it back to uncategorized.
  - **Confirmed** (user-validated or manually-set): solid chip with a
    quiet `✓` prefix in emerald.
  - **Uncategorized**: ghost "**+** Pick a category" button that
    upgrades to a real affordance on hover.
- **Bulk "Confirm all suggestions"** button in the legend bar with a
  live count chip — confirms every auto-suggested row at once.
- **Live footer counter** ("N confirmed, M auto-suggested, K
  uncategorized") so the user can see their import-readiness state
  before committing.

This shape is reused for the standalone import preview surface AND
for the wizard's first-import-preview step (sketch 002's chrome wraps
a wider preview here — the card width relaxes from 620 to ~1120 just
for the table step).

## Design Question

The import preview row now has to do three new jobs at once:

1. Show a **payment-type signal** — PIN/terminal · Online · Transfer ·
   Direct debit · Cash — so the user can mentally separate "I tapped
   my card at Albert Heijn" from "Spotify pulled my monthly subscription"
   from "ATM withdrawal".
2. Surface a **Funding source** (the own-account IBAN, post the sketch
   2 rename in Phase 16 commit `7e37921`).
3. Offer **inline rename** on rows that hit the description-fallback
   path (cryptic strings like `BCK*SHELL PIETER NIEUW` or
   `CCV*KIOSK 7438`) — with an optional "remember for future imports"
   that seeds a learning rule.

Doing all three naively turns the row into a Christmas tree. How do
we layer them?

## How to View

```
open .planning/sketches/004-phase-16-1-preview-row/index.html
```

The floating tools include two convenience buttons that open the
rename popover for variants A and B so you can see the editor flow
without hunting.

## Variants

- **A — Leading payment-type glyph + hover pencil on fallback rows.**
  A single-character payment-type glyph (⛁ PIN / ⌘ Online / ↔ Transfer
  / ⤓ Direct debit / € Cash) sits to the left of the counterparty
  name, colored to its type. The glyph has a tooltip explaining the
  type. On rows whose counterparty is the italic-muted fallback, a
  pencil button (✎) appears on hover and opens a rename popover with
  a checkbox for "remember this for future imports". Pro: very quiet,
  leans on the calm-slate palette, doesn't add a second token per row.
  Con: single-glyph types are easy to glance past — the legend is
  doing real work.
- **B — Trailing payment-type chip + click-the-italic-text to rename.**
  Full word chips (PIN · Online · Transfer · Direct debit · Cash)
  sit *after* the counterparty name, colored to type. Funding source
  shown as a pill-like monospace tag. Italic fallback names become
  the click target themselves — clicking opens the same rename popover.
  No separate pencil button. Pro: types are unmistakable; no learning
  curve. The fallback-as-affordance is elegant — the affordance is the
  thing you'd want to fix. Con: chip-per-row adds visual weight; the
  table feels denser overall.
- **C — Left-edge accent stripe + ⌥-click to rename.**
  Payment type is conveyed by a 3px colored stripe down the left edge
  of each row. No glyph, no chip — pure positional encoding. Funding
  source rendered as monospace text inline (no tag). Rename hint
  appears on hover as a tiny "⌥-click to rename" affordance. Pro: by
  far the calmest, basically invisible until you scan vertically.
  Con: accessibility risk — color-only encoding is fragile; rename
  modifier-key affordance is power-user-y and bad for non-technical
  users (the whole audience of this phase).

## What to Look For

- **Type encoding strength.** Glyph (A) vs chip (B) vs stripe (C).
  Imagine scanning the table fast — which lets you immediately spot
  the one direct-debit hiding among ten PIN transactions?
- **Cognitive cost per row.** A adds one inline icon; B adds a chip
  and a tag; C adds only a colored stripe. Which feels overloaded?
- **Rename affordance discoverability.** Pencil-on-hover (A) is
  conventional but easy to miss; click-the-italic-text (B) feels
  invitational because the italic *signals* "not good enough yet";
  ⌥-click (C) needs you to know the convention exists.
- **Funding source weight.** Plain monospace (A, C) vs pill-tag (B).
  When the user has 2 accounts these rows will all show different
  values; when they have 1 account it's repetitive. Which scales
  better to both?
- **Dark mode.** Flip the mode switcher — chip backgrounds (B) use
  rgba-tinted variants; stripe (C) reads differently against the
  dark page wash. Does any variant feel weaker in dark?
- **The popover.** Both A and B open it from different anchors but
  it has identical contents (input + "remember this for future
  imports" checkbox + cancel/save). Worth looking at: is the
  remember-rule copy clear enough that the user knows what they're
  opting into?

## Pre-emptive notes

- The fallback row is the *primary* rename target. We're not adding
  rename to "Albert Heijn 1245" rows — that's already a real
  counterparty name. Only italic-fallback rows get the affordance.
  All three variants reflect that.
- "Funding source" is the column we renamed in Phase 16 commit
  `7e37921` (was "Source"). Same IBAN content, new label.
- The legend bar at the top of each variant is a discoverability
  prop — it would live in the empty-state / first-import-only UI in
  the real app, not as a permanent header.
- Variant C's "⌥-click" rename is intentionally an underbaked
  affordance — included as the calm extreme so the contrast with A
  and B is honest. It probably needs upgrading regardless of whether
  the stripe encoding wins.
