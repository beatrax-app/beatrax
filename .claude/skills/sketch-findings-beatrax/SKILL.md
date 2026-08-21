---
name: sketch-findings-beatrax
description: Validated design decisions, CSS patterns, and visual direction from sketch experiments. Auto-loaded during UI implementation on Beatrax.
---

<context>
## Project: Beatrax

**Design direction:** Calm slate Linear/Notion aesthetic, same room as the
existing app, denser inside `/dev/*`. The Dev Console reuses the app-wide
sidebar primitive established by sketch 001 (sectioned + bottom-pinned Dev
block) but switches to tighter row padding, monospace identifiers, kbd
hints, and tabular numerics in tables. Light + dark themes are first-class.
The counterparty surfaces (`/counterparties` + `/counterparties/{slug}` +
`/counterparties/triage`) follow the same calm-slate room, with a type
taxonomy (merchant / personal / bank / government / self_account /
unknown) that ships its own color language.

**Reference points** that informed the validated direction:

- **Linear** — sidebar grouping, ⌘K palette behavior, settings-page chrome,
  dense list rows with hover-reveal actions
- **Vercel** — live log panel, env-vars table, project-switcher pattern
- **Raycast** — palette result row, kbd footer hints, source-tag chips
- **GitHub Actions** — live-streaming run cards with collapsible output

Sketch sessions wrapped: 2026-05-24 (Phase 16 dev console) ·
2026-05-25 (Phase 16.1 first-run wizard + import polish + crowd merchant) ·
2026-05-27 (Phase 16.1.2 FirstImportStep page composition) ·
2026-05-27 (Phase 17 counterparty profiles + index + triage).
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
type `Beatrax` to confirm).

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
| First-import review step | `references/first-import-review-step.md` | Wide-card (1120px) exception for the FirstImportStep page only; framed sub-card per source on `bg-subtle` (empty sections swap to `surface-2`); framed starting-balance block below the previews holds balance cards in a 3-up CSS grid; commit footer below |
| Counterparty profiles | `references/counterparty-profiles.md` | Tabbed surface (`Overview · Transactions · Chains · Aliases`) flexes across 5 types via tab-bar variation + per-type hero stats; personal type gets a privacy banner + IBAN-hidden default; government adds a `Tax years` headline row; self-account is a stub-redirect to Accounts |
| Counterparty index & triage | `references/counterparty-index-and-triage.md` | `/counterparties` is cards-by-default with a `Cards | List` toggle in the toolbar; `/counterparties/triage` is a dedicated focused queue with confidence-based suggestions + keyboard-first ergonomics (`Y`/`N`/`S`/`→`); modal + inline-editor patterns explicitly rejected |

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
- Implementing Phase 16.1.2 plans (PayPal connector step, preview pagination, FirstImportStep visual polish, regression tests)
- Implementing Phase 17 counterparty plans (17-06 module skeleton + resolver, 17-06b UI surfaces — index, profile pages, triage queue)
- Touching `FirstImportStep`, `consolidated-preview-section`, or `starting-balance-card` views/CSS
- Adding any new authenticated app surface that needs the sidebar
- Adding a new ⌘K palette source or app action
- Adding a new dev tile, run card, audit row, or destructive-action gate
- Building any wizard / setup / onboarding flow
- Touching the import preview table or `/triage` row
- Building any settings page section that exposes a corpus toggle
- Building any counterparty surface: `/counterparties` index, `/counterparties/{slug}` profile, `/counterparties/triage` queue, or related modals / hover affordances
- Touching the type-chip / type-color language across the app (merchant/personal/bank/gov/self/unknown)

Match against the **layer** you're working in — `app-shell-and-navigation.md`
for the sidebar/account/dev block, `dev-console-surfaces.md` for `/dev/*`
pages, `command-palette.md` for the modal itself, `component-library.md`
for cross-cutting primitives, `first-import-review-step.md` for the
wide-card wizard step that hosts the consolidated preview + balances,
`counterparty-profiles.md` for any `/counterparties/{slug}` page work
(including type-aware variations + privacy defaults),
`counterparty-index-and-triage.md` for `/counterparties` and
`/counterparties/triage` (including the cards/list toggle + suggestion
banner pattern).

## What this skill is *not*

- Not a substitute for Phase 16 D-* / Phase 17 D-* / A-* decisions in
  `.planning/phases/<phase-dir>/<NN>-CONTEXT.md` — those files own
  *what* ships; this skill owns *how it looks*.
- Not a Tailwind theme file. The token names are sketch-conventional
  (`--color-text-muted`, `--color-emerald-bg`); Tailwind v4 expects
  `@theme` blocks. Hand-port when wiring into the real app.
- Not a substitute for the Counterparty module's data shape — see
  Phase 17 CONTEXT.md D-43..D-48 + A-04 for the type taxonomy, schema,
  and resolver chain. This skill only owns the visual contract.
</usage_guidance>

<metadata>
## Processed Sketches

- 001-phase-16-developer-mode (winner: all C — sectioned sidebar + Dev block, console pane overview, two-pane palette, palette-dispatched runner timeline)
- 002-phase-16-1-wizard-shell (winner: D — 620px centered card on neutral wash + emoji-glyph welcome rows)
- 003-phase-16-1-connect-source (winner: C — four-tile mini-step row + format chips + persistent drop zone in the same wizard card)
- 004-phase-16-1-preview-row (winner: D — leading Type column with glyph+word chip + click-italic rename popover + category cell three-state with hover ✓/× quick-confirm/clear + bulk-confirm)
- 005-phase-16-1-crowd-merchant (winner: A+B+C combined — Triage-row "❋ Help others identify this" as primary entry + `/community/mystery-merchants` browse destination + `Settings → Shared merchant list` toggles, all sharing one suggest-mapping modal with diederik-bot draft-PR flow)
- 006-phase-16-1-2-first-import-step-layout (winner: B — framed sub-card per source on `bg-subtle` page wash; empty sections swap to `surface-2`; framed starting-balance block below the previews with a 3-up CSS grid; eyebrow weight unchanged; no new theme tokens)
- 007-phase-17-counterparty-profile-shape-merchant (winner: C — tabbed surface `Overview · Transactions · Chains · Aliases`; Overview is a 2-col grid with categories+recurring left, recent-activity+funding-chain summary right)
- 008-phase-17-counterparty-profile-type-variants (winner: all four — 007C shape flexes; A personal w/ privacy banner + IBAN-hidden + P2P direction summary + purpose tags; B bank w/ subtitle disambiguation + fee-type mini-bars; C government w/ full-width tax-year row above the Overview grid + `Tax years` tab; D self-account is a stub-redirect page with primary CTA to Accounts view)
- 009-phase-17-counterparty-index (winner: D synthesis — cards-by-default + dense-list toggle via segmented `Cards | List` widget in toolbar; sparklines in cards give 12mo at-a-glance; ledger/bulk-edit variant C dropped)
- 010-phase-17-identify-unknown-flow (winner: B — single dedicated `/counterparties/triage` queue with confidence-based suggestion banner ["Looks like Ziggo — confidence high" + reasoning], progress bar, keyboard-first ergonomics `Y`/`N`/`S`/`→`; modal + inline-editor patterns rejected, all "Label this" CTAs route to triage)
</metadata>
