---
sketch: 001
name: phase-16-developer-mode
question: "What does the Phase 16 Developer Mode UI feel like across its four highest-leverage surfaces — app sidebar, /dev overview, ⌘K palette, artisan runner?"
winner:
  sidebar: "C"   # Sectioned + bottom-pinned Dev block
  overview: "C"  # Console pane — dark headline + sparkline + tail
  palette: "C"   # Two-pane categories + recent
  runner: "C"    # Palette-dispatched timeline + triple-gate modal
tags: [layout, sidebar, dev-console, palette, runner, dashboard]
---

# Sketch 001: Phase 16 Developer Mode UI

## Design Question

Four nested visual questions, all in one HTML so they read as one product:

1. **Sidebar** — what shape does the new app-wide Linear-style sidebar take that
   replaces the existing top-nav (Phase 16 D-05)? Flat list, sectioned, or
   sectioned with a bottom-pinned Dev block?
2. **/dev overview** — what does landing on `/dev` look like? Tiles + audit
   list, compact strip + live log, or a single console pane?
3. **⌘K palette** — Raycast single-list with source chips, grouped sections,
   or two-pane categories?
4. **Artisan runner** — form on top + cards below, split (form left / feed
   right), or palette-dispatched timeline?

All four answer the same higher-level brief: **same calm slate room as the
main app, denser inside `/dev/*`** — Linear references for the sidebar +
palette, Vercel for logs/env, Raycast for palette behavior, GitHub Actions
for run cards.

## How to View

```
open .planning/sketches/001-phase-16-developer-mode/index.html
```

Use the top scene tabs (1 App sidebar / 2 /dev overview / 3 ⌘K palette /
4 Artisan runner). Each scene exposes a row of A/B/C variant tabs. Bottom-
right toolbar toggles Light/Dark/System theme and constrains viewport
width to 1280 / 1440 / Full.

## Variants

### Scene 1 — App sidebar

- **A: Flat list** — every nav item in one column. Cheapest to ship; no Dev
  affordance (Dev Mode reached via ⌘K only).
- **B: Sectioned** — four labels (This month / Money / Categorization /
  Tools). Slower vertical scan-time, better grouping.
- **C: Sectioned + bottom Dev block** — same as B plus a sticky bottom block
  with workspace version, Dev-only "Open Dev Console" link with `⌘.` hint
  and live queue/worker pulse, and an account row with kebab.

### Scene 2 — /dev overview

- **A: Tiles + recent audit** — 5 live tiles in a row + audit table + open
  alerts side card. Linear-settings feel.
- **B: Compact strip + log tail** — 6 metrics in a single horizontal strip,
  full-width live log tail as the page subject, right rail with worker
  pulse + alerts. Vercel feel.
- **C: Console pane** — single dark pane with headline metrics, sparkline,
  live tail rolled into one focal subject; recent runs + alerts collapse
  to small cards below.

### Scene 3 — ⌘K palette

- **A: Raycast single-list** — one list, source chip per row, results fuzz
  across all sources at once.
- **B: Grouped sections** — Views / Dev commands · safe-tier only /
  Actions, with `⌘1/⌘2/⌘3` jumps to sections.
- **C: Two-pane** — left rail of categories + Recent shortcuts, right rail
  of matching rows. Linear's command palette.

### Scene 4 — Artisan runner

- **A: Form top, cards below** — declarative arg form at top, run cards
  stack below as they fire. GitHub Actions feel.
- **B: Split (form L / feed R)** — sticky form on the left, scrollable card
  feed on the right.
- **C: Palette-dispatched timeline** — no persistent form; commands fire
  from ⌘K and materialize as a grouped-by-day timeline. Includes the
  triple-gate modal overlay for destructive-tier confirmation.

## What to Look For

- Does the **same room, denser** intuition hold? Sidebar + dashboard view
  should still read as the existing app; the Dev Console should feel
  *connected*, not separate.
- **Information density** — is the dev tile/strip/table chrome too tight,
  or just right for power-tool scanning?
- **Sidebar Dev affordance** — does variant C's Dev block belong, or
  should `⌘K` be the only entry point (A/B)?
- **Palette source signaling** — chip-on-the-right (A), section headers
  (B), or category sidebar (C) — which makes "this is a dev command, not
  a navigation item" most obvious?
- **Runner ergonomics** — is the form-on-top (A) or split (B) more
  comfortable when a long command is streaming? Does C's
  palette-dispatched approach feel powerful or annoying?
- **Triple-gate modal** (Scene 4 / Variant C) — language, prominence,
  primary-button-disabled-until-typed pattern.
- **Light + dark** — toggle in the bottom-right toolbar. Confirm both
  themes read calmly; flag any contrast or color issues.

## Decisions to Make

Per scene, pick the winning variant (A/B/C) — or cherry-pick across
variants for a synthesis (e.g. *"B's sidebar grouping + C's bottom Dev
block but only the Dev Console link, no live pulse"*).
