---
sketch: 003
name: phase-16-1-connect-source
question: "How do we walk a non-technical user through 'log in → find export → pick format → drop file' without overwhelming them?"
winner: C
tags: [wizard, onboarding, upload, phase-16-1]
---

# Sketch 003: Connect a Data Source (ASN as the example)

## Winner

**Variant C ★ — Linear mini-steps + persistent drop zone.** Same 720px
card as the shell promise. Four glyph mini-tiles across the top
(🔐 Log in · 📑 Open afschriften · 📅 Pick a range · ⬇️ Download) show
the user the whole journey at a glance. Format chips inline below
("CAMT.053 recommended · CSV · MT940"). Drop zone always visible at
the bottom — the destination is never hidden. Card width stays
consistent with the wizard shell (no special-case widening like B).

This shape reuses unchanged for the ICS PDF step and the optional
email OAuth step — only the glyphs, copy, and format chips change.

## Design Question

The connector step is the heart of onboarding — a non-technical user
has to (1) log into their bank's web portal, (2) navigate to a
half-hidden export menu, (3) make a format choice they don't
understand, and (4) get the resulting file from their Downloads folder
into the app.

How do we present that without the user feeling like they're being
asked to do "IT stuff"?

All three variants live inside the locked sketch-002 winner-D shell —
same top progress dots, same footer privacy pill, same `Resume later`
escape hatch.

## How to View

```
open .planning/sketches/003-phase-16-1-connect-source/index.html
```

## Variants

- **A — Numbered vertical accordion.** Same 620px card as the shell.
  Four collapsible numbered rows: log in → find export → pick format
  → drop file. Each row opens when the one above is done. Pro: very
  Notion-like, calm rhythm, mirrors the welcome-step row pattern. Con:
  the drop zone is "below the fold" until step 3 is checked off; the
  user can't see where they're headed.
- **B — Side-by-side annotated guide + drop zone.** Card widens to
  1040px just for this step type; left rail is a guide with tiny
  "screenshots" (mocked as URL chips for now), right rail has the
  format chips + a tall drop zone always visible. Pro: the destination
  is visible from the start, format choice is upfront and clearly
  labelled (with "recommended" badge on CAMT.053). Con: wider than the
  rest of the wizard, breaks the consistent-shell promise; collapses
  to single column at small widths.
- **C — Linear mini-steps + persistent drop zone.** Same 720px card.
  Four small horizontal mini-tiles at the top (🔐 Log in · 📑 Open
  afschriften · 📅 Pick a range · ⬇️ Download), the format chips
  inline below them, the drop zone always visible at the bottom. Pro:
  shows the whole journey at a glance, drop zone is always reachable,
  consistent card width with the rest of the wizard. Con: the mini
  tiles are visually busy — four glyphs in a row competing with each
  other.

## What to Look For

- **Where does the drop zone live?** Below the accordion (A), pinned
  to the right rail (B), or always-visible at the bottom of a vertical
  flow (C)? Which feels least like a hunt?
- **How is the format choice introduced?** Inside accordion step 3
  (A), as labelled chips above the drop zone with "recommended" badge
  on CAMT.053 (B & C). The user might not know what CAMT.053 is — is
  the "recommended" badge enough nudge?
- **Card width consistency vs purpose-fit.** A and C stay near 620–720
  (consistent with the shell), B widens to 1040 just for this step.
  How big a deal is the inconsistency? Worth it for the side-by-side
  context?
- **Skip-this-step affordance.** All three keep it but de-emphasise.
  Does any variant make skipping too easy or too hard?
- **Dark mode.** Flip in the floating tools — the drop zone uses
  dashed borders which can look weak in dark; check it carries.
- **Reuse across ICS/PayPal/email steps.** Whichever wins, the same
  shape applies to step 4 (ICS PDF) and step 5 (optional email OAuth).
  Imagine swapping the body content — does the chrome still fit?

## Pre-emptive notes

- The "screenshots" in Variant B's left rail are placeholder URL chips
  (a coloured dot + the URL). Real implementation can swap in either
  actual cropped illustrations or a thin SVG diagram. The point is the
  visual rhythm.
- The format chips' "recommended" badge intentionally uses the
  emerald-bg token rather than primary — a quiet recommendation, not
  a bossy demand.
- The "Continue →" button is disabled on all three variants because
  no file has been uploaded yet. The wizard advances once parsing
  succeeds (handed off to the existing import preview pipeline).
