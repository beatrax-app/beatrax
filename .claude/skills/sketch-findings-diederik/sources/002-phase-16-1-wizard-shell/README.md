---
sketch: 002
name: phase-16-1-wizard-shell
question: "What does the first-run wizard chrome feel like — frame, progress, primary/skip/exit?"
winner: D
tags: [wizard, onboarding, layout, phase-16-1]
---

# Sketch 002: First-Run Wizard Shell + Welcome Step

## Design Question

The first-run wizard is the very first thing a non-technical user sees
after signup. Its chrome — frame, progress indicator, primary action,
skip-this-step, resume-later — propagates to every step that follows
(connect bank, connect card, optional email, first preview, done).

What shape should that chrome take?

## How to View

```
open .planning/sketches/002-phase-16-1-wizard-shell/index.html
```

Switch variants from the tab bar at the top. Toggle light/dark from the
floating tools in the bottom-right. The `📱 vw` button cycles a viewport
clamp (full → 1280 → 768 → 375 → full) so you can sanity-check responsive
behaviour without resizing the window.

## Winner

**Variant D ★ — A's chrome + wider card (620px) + C's emoji rows.**
Centered card on a slate-50 wash with top progress dots, footer privacy
pill, and ghost+primary buttons anchored bottom-right inside the card.
Welcome content is one calm lede plus three emoji-glyph rows (🏦 bank ·
💳 card · ✉️ receipts/optional). Width is 620px — wider than a stock
Stripe-Checkout card so the three-row preview breathes, but narrower
than the Notion-style 720px so the eye doesn't have to track across.
Inner padding 44/48/32.

This chrome propagates to every other wizard step (003, downstream).

## Variants

- **A — Centered card on neutral page.** Stripe-Checkout / Plaid-Link
  shape. Narrow card (520px) floats on a slate-50 page wash. Top bar
  carries the brand, progress dots, and a quiet "Resume later". Footer
  carries the privacy reassurance. Pro: very calm, very focused. Con:
  uses a lot of empty real estate on the sides; multi-step structure is
  conveyed only through the small dots strip.
- **B — Split: context (left) + step (right).** Linear-onboarding shape.
  40/60 split — left side carries the full step list (with done /
  current / upcoming states), the privacy pill, the help link, and the
  build number; right side carries the actual step content with room for
  inline detail. Pro: orientation is always visible — the user never
  loses track of how many steps remain. Two extra "what you'll need /
  what we don't need" tiles do real reassurance work. Con: more dense;
  needs more thought on small viewports (collapses to single column).
- **C — Single column, generous whitespace.** Notion-onboarding shape.
  Top bar carries the progress as a thin bar (not dots). The body is
  one wide centered column (680px max) with very generous vertical
  rhythm and decorative emoji glyphs alongside the three "what's next"
  rows. Footer bar anchors the primary action plus the privacy pill.
  Pro: by far the most calm; reads almost like a marketing landing
  page. Con: less efficient — the user has to scroll for primary CTA;
  emoji glyphs are a vibe choice that may not survive a serious icon
  system.

## What to Look For

- **Where does the primary CTA live?** Inside the card (A), inline at
  the bottom of the right panel (B), or pinned to a footer bar (C)?
  Which feels like the most natural "I'm ready" surface?
- **How clear is "step 2 of 6"?** Tiny dots strip (A), full ordered
  list with state (B), thin progress bar with number (C).
- **Where does "skip this step" sit?** As an inline ghost button next
  to Continue (A), as a small ghost after the primary (B), as a paired
  button in the footer (C). Which one tempts skipping the least without
  hiding it?
- **Privacy line.** All three carry a privacy pill but in different
  positions. Top, bottom-left, footer. Where does that reassurance
  feel like it belongs?
- **Dark mode.** Flip the mode switcher. Variant C's full-bleed
  surfaces depend on the theme more than A's centered card. Does any
  variant feel weaker in dark?
- **Empty content stress test.** The welcome step has a lot to say,
  but later steps (e.g. "Done") will be much sparser. Mentally swap
  the body content for two sentences plus a single CTA — does the
  chrome still feel right?

## Pre-emptive notes

- The progress representation here is "step 2 of 6" but the real count
  may be 5 or 7 depending on which steps land. All three variants
  scale; A's dot strip gets wider, B's stepper gets longer, C's bar
  recomputes the percent.
- The brand mark is a placeholder gradient "d" — real brand SVG lands
  in Phase 19 (public release boundary). Don't grade the logo.
- Variant A's card style and Variant C's footer-bar pattern can be
  cherry-picked together in a synthesis pass if neither wins outright.
