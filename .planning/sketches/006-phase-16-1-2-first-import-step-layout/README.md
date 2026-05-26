---
sketch: 006
name: phase-16-1-2-first-import-step-layout
question: "What composition should the FirstImportStep page take — card geometry, eyebrow weight, sub-card per source vs single-frame, stacked vs two-column, and where do the starting-balance cards belong?"
winner: B
tags: [wizard, first-import, preview, balances, layout, phase-16-1-2]
---

# Sketch 006: FirstImportStep page layout

## Design Question

Phase 16.1.2 (D-14, D-16) gates two CSS plans on this sketch — A-3
(starting-balance section tidy) and A-4 (preview body-block layout).
The user couldn't articulate the issue without seeing variants, so
this sketch explores the **whole page composition** — not isolated
cards — so the winner can drive both CSS plans at once.

Specifically:

- **Card geometry** — how does each per-source preview live inside the
  locked-wide 1120px wizard card?
- **Eyebrow weight** — `🏦 FROM YOUR BANK STATEMENT · 84 ROWS · ✓ READY`
  is the current shape; how should it feel across variants?
- **Sub-card per source vs single-frame** — are sources their own
  framed boxes or sit flush in the page card?
- **Stacked vs two-column** — does the page stay linear-scroll or
  split into previews + balances rail?
- **Starting-balance card placement** — bottom of page (today),
  bottom in their own framed sub-card, inline per-source, or pinned
  sticky rail right?

All four variants live inside the locked sketch-002-D wizard chrome
(top progress dots, footer privacy pill, Resume later). They share
real data: ASN bank ready (84 rows · 5 sample) · ICS card empty ·
PayPal ready (44 rows · 5 sample) · three balance cards (ASN
detected, ICS manual-entry, PayPal in conflict state).

## How to View

```
open .planning/sketches/006-phase-16-1-2-first-import-step-layout/index.html
```

Tab between variants (or press `1`–`4`). The toolbar bottom-right
switches light/dark and clamps the viewport so you can sanity-check
narrower widths (the wide-card exception is 1120px — variants C and
D break at viewports below ~960px).

## Variants

- **A — Single-frame stack (today, tidied).** Same shape as the
  current implementation. One big wizard card hosts: lede →
  three preview sections in a vertical stack → `STARTING BALANCES`
  section with eyebrow + lede + horizontal grid of three balance
  cards → commit footer. Tidied: balance grid (3-up) instead of the
  full-width stack the live app currently uses. Pro: cleanest read,
  one room. Con: long scroll, balances live "at the end".
- **B — Sub-card per source.** Each per-source preview is its own
  framed sub-card (border + radius + subtle bg-subtle fill). The
  empty ICS section gets a different fill so its emptiness reads.
  Starting balances live in their own framed sub-card below.
  Pro: stronger visual chunking; scanning is easier. Con: extra
  borders compete with the wizard card frame; framing the empty
  state can feel heavy.
- **C — Inline balances (per-source two-column).** Each source
  becomes a two-column sub-card: preview rows on the left (~65%),
  *that account's* starting-balance card on the right (~35%). The
  empty ICS row pairs an empty-state with the ICS manual-entry
  card; the PayPal row pairs the rows with the conflict card.
  No separate balance section. Pro: pairs each account with its
  balance — fewer eye-jumps; supports the "one source = one row"
  mental model. Con: cramped at narrower widths; less room for
  preview rows themselves; assumes 1:1 source↔account (true today
  but not eternally).
- **D — Sticky balance rail.** Wide card splits ~64/36 — preview
  sections stacked left, balance cards in a sticky right rail that
  follows scroll. Pro: balances are always visible while reading
  rows; commit confidence builds linearly. Con: feels like an
  admin panel rather than a calm-Notion wizard; sticky positioning
  inside a card is a sharper visual departure from sketches 002/003.

## What to Look For

- **Where does the eye go first?** Compare A's flat hierarchy vs
  B's frame-on-frame. Does B make the page easier to skim or just
  noisier?
- **The empty ICS section.** In A it's a quiet line in the stack.
  In B it's a framed-but-grey sub-card. In C it pairs with the
  ICS manual-entry balance card on the right (the most useful
  pairing). In D it's a quiet line on the left while the ICS
  manual-entry card sits in the rail.
- **The conflict balance card.** It's the densest balance state —
  two radios + helper. In A/B it sits in a 3-up grid (tight). In
  C it's the right-column of the PayPal row (more breathing room).
  In D it stacks vertically in the rail (also tight, but visible
  while scrolling).
- **Eyebrow weight.** `FROM YOUR BANK STATEMENT · 84 ROWS · ✓ READY`
  — all-caps tracked label + count + emerald ready badge. Does the
  framed sub-card in B make the eyebrow feel redundant (you
  already see "this is a section")? Does C's eyebrow-then-table
  inside a row work or fight?
- **Scroll length.** A and B both scroll linearly. C is slightly
  shorter because pairs sit two-column. D is the shortest because
  the balances rail is independent of preview length.
- **Dark mode.** Flip the mode switcher. B and C rely on a
  `bg-subtle` frame fill which can wash out in dark; check it
  carries.
- **Narrow viewport.** Use the 768 button. C and D both break at
  some point — does the sketch make obvious where each variant
  needs a fallback (probably "C collapses to A pattern" and
  "D moves the rail above the previews").
- **Commit footer.** It's the same in all four — does it still feel
  like the closing CTA, or do C/D's framing decisions make it feel
  detached from the preview content?

## Pre-emptive Notes

- **Width is locked.** Every variant uses the same `.wiz-card.wide`
  (1120px max-width) — the one wizard-card exception documented in
  `onboarding-wizard.md`. The sketch does not re-litigate width.
- **No new theme tokens** (D-17). Everything reuses the existing
  slate-50/100/200/700/900/950 token set + emerald/amber/rose/blue
  state colors from `themes/default.css`. The sketch winner has to
  stay within these tokens.
- **Three balance states are shown.** ASN = detected (Confirm/Edit
  buttons), ICS = manual-entry (inputs), PayPal = conflict (radios).
  This is deliberate — the layout has to read well for the densest
  state, not just the simplest one.
- **Sub-card vs single-frame in B/C** uses `--color-bg-subtle` as
  the sub-card fill. The wizard-card sits on `--color-bg-subtle` as
  the *page wash*, so the sub-cards are effectively the same color
  as the page they sit on — they read as borrowing the wash to
  carve out frames. Adjust to `--color-surface-2` if the user wants
  more separation.
- **Variant C assumes 1:1 source↔account.** Today that's true (one
  ASN account, one ICS account, one PayPal account). If a user
  later imports two ASN bank accounts in one step, C's pairing
  story complicates. A/B/D scale unchanged.
- **Variant D's sticky rail** uses `position: sticky` inside the
  page card. If the user reduces window height aggressively, the
  rail becomes scrollable inside itself (`max-height: 100vh-60px;
  overflow: auto`). The sketch demonstrates this — try shrinking
  the window height with D selected.
- **ConnectPaypalStep visuals are NOT in this sketch** (D-15) —
  it follows the locked sketch-003 connector contract.
