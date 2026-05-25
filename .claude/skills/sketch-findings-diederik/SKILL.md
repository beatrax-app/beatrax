---
name: sketch-findings-diederik
description: Validated design decisions, CSS patterns, and visual direction from sketch experiments. Auto-loaded during UI implementation on diederik.
---

<context>
## Project: diederik

**Design direction:** Calm slate Linear/Notion aesthetic, same room as the
existing app, denser inside `/dev/*`. The Dev Console reuses the app-wide
sidebar primitive established by sketch 001 (sectioned + bottom-pinned Dev
block) but switches to tighter row padding, monospace identifiers, kbd
hints, and tabular numerics in tables. Light + dark themes are first-class.

**Reference points** that informed the validated direction:

- **Linear** — sidebar grouping, ⌘K palette behavior, settings-page chrome
- **Vercel** — live log panel, env-vars table, project-switcher pattern
- **Raycast** — palette result row, kbd footer hints, source-tag chips
- **GitHub Actions** — live-streaming run cards with collapsible output

Sketch sessions wrapped: 2026-05-24 (Phase 16 dev console) ·
2026-05-25 (Phase 16.1 first-run wizard + import polish + crowd merchant).
</context>

<design_direction>
## Overall Direction

**Palette** — calm slate, mirroring the live `app.blade.php` token set
(slate-50/100/200/700/900/950). Light mode uses white surfaces on
`#f8fafc` page wash; dark mode flips to `slate-950` page with `slate-900`
surfaces. Accent colors are reserved for state, not decoration:
emerald-600 = OK, amber-700 = warn, rose-700 = fail, blue-600 = info,
plus an `amber` tier chip for destructive actions.

**Typography** — Inter for sans, JetBrains Mono for any identifier the
user might paste into a terminal (command names, file paths, PIDs,
run_ids, log lines). Tabular numerics (`font-feature-settings: 'tnum'`)
everywhere — never let a count visually shift width as it ticks.

**Density** — main app keeps its calm `space-y-12` rhythm. `/dev/*`
drops to `var(--text-sm)` (13px) as the default body size, 8-10px row
padding, and shows kbd chips on hover/focus. The dev console feels like
a power-tool *room inside* the same building, not a separate building.

**Layout** — sectioned left sidebar (≤248 px) on every authenticated
page. Inside `/dev/*` the sidebar narrows to ~220 px and swaps its
section labels for Dev Console items, with a "← Back to app" foot row.

**Interaction model** — ⌘K palette is the *primary* command-entry point,
not a convenience. The artisan runner page is a timeline reader; the
form lives inside the palette flow. Destructive actions always pass
through a triple-gate modal (Dev Mode ON + Advanced session toggle ON +
type `beatrax` to confirm).

**Theming** — full light/dark token coverage in
`sources/themes/default.css`. One localized exception: the `/dev`
overview "console pane" uses fixed dark colors (`#0b1220` background,
`#f1f5f9` text) regardless of theme — it reads as a dedicated console
inside the otherwise-calm-slate room.
</design_direction>

<findings_index>
## Design Areas

| Area | Reference | Key Decision |
|------|-----------|--------------|
| App shell & navigation | `references/app-shell-and-navigation.md` | Sectioned left sidebar with sticky bottom Dev block, visible only to `is_developer` |
| Dev Console surfaces | `references/dev-console-surfaces.md` | `/dev` overview is a single dark "console pane"; `/dev/artisan` is a palette-dispatched timeline |
| Command palette | `references/command-palette.md` | ⌘K two-pane (categories + Recent / results) — primary command-entry point |
| Component library | `references/component-library.md` | Status pills, tier chips, run cards, kbd hints, dense tables, dark-mode tokens |
| Onboarding wizard | `references/onboarding-wizard.md` | 620px centered card on neutral wash, top progress dots, footer privacy pill; connector steps use a glyph mini-tile row + format chips + always-visible drop zone |
| Import preview & categorization | `references/import-preview-and-categorization.md` | Leading Type column (glyph+word chip per PIN/online/transfer/dd/cash); italic-fallback name is itself the click-rename target; category cell three-state (auto/confirmed/uncategorized) with hover ✓/× quick actions + bulk-confirm |
| Community merchant identification | `references/community-merchant-identification.md` | Three-layer surface: dashed "❋ Help others identify this" CTA on Triage rows (primary) + `/community/mystery-merchants` browse destination + `Settings → Shared merchant list` toggles; all share one suggest-mapping modal with live YAML preview that submits as a draft PR from `diederik-bot` |

## Theme

`sources/themes/default.css` — the full token set that all four
reference files rely on. Drop it into a Tailwind v4 project as a
CSS-first import or hand-port the variables into your Tailwind config.

## Source Files

Original sketch HTML is preserved under
`sources/001-phase-16-developer-mode/` for end-to-end reference; the
file is self-contained (single HTML + theme link), opens in a browser,
and demonstrates every winning variant.
</findings_index>

<usage_guidance>
## When to use this skill

Auto-load when:

- Implementing Phase 16 plans (16-01 sidebar / 16-02 rename / 16-03+ Dev Console pages)
- Implementing Phase 16.1 plans (first-run wizard, import preview row affordances, crowd-sourced merchant identification, payment-type classification)
- Adding any new authenticated app surface that needs the sidebar
- Adding a new ⌘K palette source or app action
- Adding a new dev tile, run card, audit row, or destructive-action gate
- Building any wizard / setup / onboarding flow
- Touching the import preview table or `/triage` row
- Building any settings page section that exposes a corpus toggle

Match against the **layer** you're working in — `app-shell-and-navigation.md`
for the sidebar/account/dev block, `dev-console-surfaces.md` for `/dev/*`
pages, `command-palette.md` for the modal itself, `component-library.md`
for cross-cutting primitives.

## What this skill is *not*

- Not a substitute for Phase 16 D-* decisions in
  `.planning/phases/16-developer-mode-ui/16-CONTEXT.md` — that file owns
  *what* ships; this skill owns *how it looks*.
- Not a Tailwind theme file. The token names are sketch-conventional
  (`--color-text-muted`, `--color-emerald-bg`); Tailwind v4 expects
  `@theme` blocks. Hand-port when wiring into the real app.
</usage_guidance>

<metadata>
## Processed Sketches

- 001-phase-16-developer-mode (winner: all C — sectioned sidebar + Dev block, console pane overview, two-pane palette, palette-dispatched runner timeline)
- 002-phase-16-1-wizard-shell (winner: D — 620px centered card on neutral wash + emoji-glyph welcome rows)
- 003-phase-16-1-connect-source (winner: C — four-tile mini-step row + format chips + persistent drop zone in the same wizard card)
- 004-phase-16-1-preview-row (winner: D — leading Type column with glyph+word chip + click-italic rename popover + category cell three-state with hover ✓/× quick-confirm/clear + bulk-confirm)
- 005-phase-16-1-crowd-merchant (winner: A+B+C combined — Triage-row "❋ Help others identify this" as primary entry + `/community/mystery-merchants` browse destination + `Settings → Shared merchant list` toggles, all sharing one suggest-mapping modal with diederik-bot draft-PR flow)
</metadata>
