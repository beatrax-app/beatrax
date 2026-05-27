# Sketch Wrap-Up Summary

## Session 3 — 2026-05-27 (Phase 16.1.2)

**Sketches processed:** 1 (006)
**Design areas added:** 1 (First-import review step)
**Skill output (append):** `./.claude/skills/sketch-findings-diederik/`

### Included Sketches

| # | Name | Winner | Design Area |
|---|------|--------|-------------|
| 006 | phase-16-1-2-first-import-step-layout | B — sub-card per source (framed `bg-subtle`), empty-state fill swaps to `surface-2`, starting balances in their own framed sub-card with a 3-up CSS grid | First-import review step |

### Excluded Sketches

_None._

### Design Direction (Phase 16.1.2)

**The FirstImportStep page composition.** The single wizard step that
exercises the 1120px wide-card exception now has a settled visual
language: each per-source preview is its own **framed sub-card** on
the page-wash color (`--color-bg-subtle`), reading as "carved out of
the page" rather than stacked on it. Empty sections swap their fill
to `--color-surface-2` so emptiness reads quietly. Starting balances
live in **their own framed sub-card** below the previews, with the
balance cards laid out in a **3-up CSS grid** so the densest state
(PayPal conflict, two radios + helper) renders at the same width as
the simplest (detected: a value + Confirm button) — siblings, not
stranded states. The wizard chrome (top progress dots, footer privacy
pill, "Resume later") and the eyebrow shape stay identical to every
other step; only the body composition is new.

### Key Decisions (Phase 16.1.2)

#### First-import review step (006)

- **Wide-card exception** — `wiz-card.wide { max-width: 1120px }` for
  this step only. Every other wizard step stays at 620px.
- **Sub-card per source** — each preview section is framed with
  `border + radius-lg + bg-subtle fill + 22/24 padding`. The
  preview-section eyebrow lives inside the sub-card. Sub-cards stack
  vertically with `margin-top: 18px`.
- **Empty-state fill swap** — `.source-subcard.empty` flips the fill
  to `--color-surface-2`. ~one-token quiet difference, no extra copy
  needed.
- **Starting-balance block** — own framed sub-card below the
  previews (`margin-top: 28px`). Holds an eyebrow + one-line lede +
  a `grid-template-columns: repeat(3, 1fr); gap: 16px` grid of
  balance cards. Replaces the live app's full-width stack.
- **Eyebrow weight unchanged** — uppercase `letter-spacing: 0.06em`
  label + faint count + color-coded status badge (`ready` =
  emerald, `empty` = amber, `filtered` = faint, `error` = rose).
- **Commit footer** — `flex justify-content: space-between` with the
  counter on the left and the primary CTA on the right; `border-top`
  divider above it; `margin-top: 28px` separating it from the
  balance block.
- **No new theme tokens** — locked slate-50/100/200/700/900/950 +
  emerald/amber/rose/blue state palette only (D-17).
- **Responsive collapse contract** — 3-up balance grid drops to 2-up
  at ≤1024px and to 1-up at ≤720px; wide-card padding tightens at
  phone widths. (CSS plan picks exact breakpoints.)
- **Excluded compositions** (A/C/D in sketch 006) — single-frame
  stack (A: hierarchy too flat), inline balance pairing (C: assumes
  1:1 source↔account, breaks if a user imports two ASN accounts in
  one step), sticky balance rail (D: admin-panel feel, off-family
  with sketches 002/003).

### Implications for Phase 16.1.2 implementation

- **A-3 + A-4 may merge into one CSS plan.** Both target the same
  composition contract; the planner decides based on file-overlap on
  `resources/css/app.css` and `first-import-step.blade.php`.
- **`consolidated-preview-section` blade does not need internal
  changes for the sub-card.** Wrap each section in
  `<section class="source-subcard {{ $section->status === 'empty'
  ? 'empty' : '' }}">` at the `first-import-step.blade.php` level.
  The component's internals stay as-is.
- **`starting-balance-card` blade does not change.** Only its
  parent's wrapping `.starting-balance-stack` flips from full-width
  stack to 3-up grid.
- **`BuildConsolidatedPreviewQuery` is untouched by the visual
  plans.** The pagination plan (A-2) is independent of the
  composition plan(s).

### Files written this session

- `./.claude/skills/sketch-findings-diederik/references/first-import-review-step.md`
- `./.claude/skills/sketch-findings-diederik/sources/006-phase-16-1-2-first-import-step-layout/{index.html,README.md}`
- `./.claude/skills/sketch-findings-diederik/SKILL.md` — design-area
  row + processed-sketches list + auto-load triggers + layer-routing
  note extended
- `./.planning/sketches/WRAP-UP-SUMMARY.md` — this section prepended

CLAUDE.md routing line for `sketch-findings-diederik` was already in
place from session 1 and didn't need editing.

---

## Session 2 — 2026-05-25 (Phase 16.1)

**Sketches processed:** 4 (002, 003, 004, 005)
**Design areas added:** 3 (Onboarding wizard, Import preview & categorization, Community merchant identification)
**Skill output (append):** `./.claude/skills/sketch-findings-diederik/`

### Included Sketches

| # | Name | Winner | Design Area |
|---|------|--------|-------------|
| 002 | phase-16-1-wizard-shell | D — 620px centered card + emoji rows | Onboarding wizard |
| 003 | phase-16-1-connect-source | C — glyph mini-tile row + persistent drop zone | Onboarding wizard |
| 004 | phase-16-1-preview-row | D — leading Type chip + click-italic rename + confirm-suggest categories with bulk-confirm | Import preview & categorization |
| 005 | phase-16-1-crowd-merchant | A+B+C combined — Triage row CTA (primary) + `/community/mystery-merchants` destination + `Settings → Shared merchant list` toggles | Community merchant identification |

### Excluded Sketches

_None._

### Design Direction (Phase 16.1)

**Calm Linear/Notion aesthetic continues, applied to the user-facing
first-run flow.** The onboarding wizard introduces a 620px centered-
card chrome that propagates to every step — top progress dots, body
card, footer privacy pill — and a connector-step body pattern (glyph
mini-tile row + format chips + always-visible drop zone) that
reuses unchanged for bank statements, credit card PDFs, and
optional email OAuth.

The import preview gets three new affordances layered into the row
without becoming noisy: a leading Type column (glyph+word chip per
payment type), an inline rename popover triggered by clicking the
italic-fallback name itself, and a three-state Category cell (auto-
suggested / confirmed / uncategorized) with hover quick-actions and a
bulk-confirm shortcut.

A new crowd-sourced merchant identification corpus is surfaced in
three places: the moment-of-use CTA on Triage rows, a dedicated
browse destination, and a Settings section with toggles — all
sharing one suggest-mapping modal that hides the "this is a PR to
a YAML file in a Git repo" awkwardness behind a friendly form and
a diederik-bot draft-PR flow.

### Key Decisions (Phase 16.1)

#### Onboarding wizard (002 + 003)

- **620px centered card** on `--color-bg-subtle` page wash; top bar
  with brand + progress-dots + "Resume later"; bottom-right `Skip`
  ghost + `Continue →` primary inside the card; footer with privacy
  pill + "Need help?" link.
- **Welcome step body:** eyebrow + H1 + lede + three emoji-glyph
  rows (🏦 bank · 💳 card · ✉️ receipts/optional). Each row has a
  44×44 glyph tile + title + one-line description.
- **Connector step body** (ASN / ICS / email): glyph mini-tile row
  showing the whole journey (🔐 Log in · 📑 Open afschriften · 📅
  Pick a range · ⬇️ Download), format chips with quiet "recommended"
  badge on the preferred format, drop zone always visible at the
  bottom of the card.

#### Import preview & categorization (004)

- **Column order:** Type · Date · Counterparty · Funding source ·
  Category · Amount. Type leading so vertical scanning reveals
  payment-shape patterns.
- **Type chip:** glyph + word, color-tokenized per type (⛁ PIN
  violet · ⌘ Online blue · ↔ Transfer slate · ⤓ Direct debit amber
  · € Cash dark-amber).
- **Inline rename:** italic-fallback name is itself the click
  target; popover with "Remember for future imports" learning-rule
  checkbox.
- **Category cell three-state:** auto-suggested (dashed italic chip
  with hover ✓/× quick-actions) → confirmed (solid chip with
  emerald ✓ prefix) → uncategorized ("+ Pick a category" ghost
  button).
- **Bulk-confirm** button in legend with live count chip; **live
  footer counter** ("N confirmed, M auto-suggested, K uncategorized")
  so import-readiness is visible at a glance.

#### Community merchant identification (005)

- **Three-layer surface:** B (Triage row CTA — primary moment-of-
  use entry) + A (`/community/mystery-merchants` browse destination)
  + C (`Settings → Shared merchant list` preferences + toggles +
  corpus stats).
- **Single shared suggest-mapping modal** with mystery code (read-
  only) + friendly name + optional category hint + optional region
  + live YAML preview + diederik-bot draft-PR submission +
  explicit "you're anonymous unless you choose to be" reassurance.
- **Three Settings toggles:** use shared list (auto-name) · offer
  contribution buttons on Triage · pull updates on app updates.

### Implications for Phase 16.1 implementation

- **Card-width consistency** must hold across the wizard chrome.
  The single permitted exception is the embedded import-preview
  step, which relaxes from 620 to ~1120px to fit the preview
  table. No other per-step width variation.
- **Auto-categorization** must always commit to a *tentative* state
  the user can confirm or clear — never silently set a category as
  if the user had picked it. The dashed-italic styling is the
  contract.
- **Payment-type classification** is both a UI signal *and* a
  ledger field. The chip rendering is downstream; the classifier
  must run during import so the field is available to any view that
  needs it (transactions list, dashboard summaries, future search).
- **Crowd-sourced corpus contract:** only `pattern` + `name` +
  optional `category` + optional `region` ship in the YAML.
  Amounts, dates, account IDs never leave the local machine.
- **diederik-bot PR submission** abstraction needs a GitHub App or
  service-account token. Design assumes the abstraction exists; the
  modal copy promises anonymity.

### Files written this session

- `./.claude/skills/sketch-findings-diederik/references/onboarding-wizard.md`
- `./.claude/skills/sketch-findings-diederik/references/import-preview-and-categorization.md`
- `./.claude/skills/sketch-findings-diederik/references/community-merchant-identification.md`
- `./.claude/skills/sketch-findings-diederik/sources/002-phase-16-1-wizard-shell/{index.html,README.md}`
- `./.claude/skills/sketch-findings-diederik/sources/003-phase-16-1-connect-source/{index.html,README.md}`
- `./.claude/skills/sketch-findings-diederik/sources/004-phase-16-1-preview-row/{index.html,README.md}`
- `./.claude/skills/sketch-findings-diederik/sources/005-phase-16-1-crowd-merchant/{index.html,README.md}`
- `./.claude/skills/sketch-findings-diederik/SKILL.md` — design-area table + processed-sketches list + auto-load triggers extended
- `./.planning/sketches/WRAP-UP-SUMMARY.md` — this section appended

CLAUDE.md routing line for `sketch-findings-diederik` was already in
place from session 1 and didn't need editing.

---

## Session 1 — 2026-05-24

**Sketches processed:** 1
**Design areas:** 4 (App shell & navigation, Dev Console surfaces, Command palette, Component library)
**Skill output:** `./.claude/skills/sketch-findings-diederik/`

## Included Sketches

| # | Name | Winner | Design Area |
|---|------|--------|-------------|
| 001 | phase-16-developer-mode | All C variants | App shell & navigation · Dev Console surfaces · Command palette · Component library |

## Excluded Sketches

_None._

## Design Direction

**Same calm slate room as the existing app, denser inside `/dev/*`.**
Linear's settings-page chrome + Notion's sidebar grouping inform the
overall layout; Vercel/Raycast inform the dev affordances. Light + dark
are first-class. A single localized exception: the `/dev` overview
"console pane" uses fixed dark surfaces regardless of theme so the
overview reads as a console inside an otherwise calm room.

## Key Decisions

### Layout

- **App-wide left sidebar** replaces the existing top-nav, sectioned
  (This month / Money / Categorization / Tools) with a sticky bottom
  Dev block visible only to `is_developer`.
- **Dev Console swap inside `/dev/*`** — the sidebar narrows to ~220 px
  and replaces its nav with the Dev Console items (Overview / Artisan /
  Audit / Logs / Queue / Doctor / SQL / Horizon / System) plus a "←
  Back to app" foot.

### Surfaces

- **`/dev` overview** is a single dark "console pane" — worker
  heartbeat sparkline + queue + last-command headline metrics rolled
  up with an inline live tail. Recent runs + open alerts collapse to
  small calm-slate cards below.
- **`/dev/artisan`** is a palette-dispatched timeline — no persistent
  form; commands fire from `⌘K` and materialize as day-grouped run
  cards. Filter chips at the top.
- **Triple-gate modal** for destructive actions (Dev Mode ON +
  Advanced toggle ON + type `beatrax`).

### Interaction model

- **`⌘K` palette** is the **primary** command-entry point, not a
  convenience layer. Two-pane (categories + Recent / results), sources
  = NavigationRegistry + DevCommandRegistry (safe-tier only) +
  AppActionRegistry. Powered by Fuse.js client-side.
- **`⌘.` shortcut** opens `/dev` directly (separate from `⌘K`).

### Visual system

- Calm slate palette mirroring the live app tokens.
- Inter for sans, JetBrains Mono for any identifier the user might
  paste into a terminal.
- Tabular numerics everywhere counts are shown.
- Status pills (ok/warn/fail/muted) + tier chips (safe/destructive) as
  the two cross-cutting state primitives.
- No drop shadows on flat surfaces; shadows reserved for modals + palette.
- 120/180 ms motion budget, ease-smooth cubic-bezier.

## Implications for downstream phases

- **Phase 16 D-15 (declarative arg-form schemas)** now lives inside the
  palette flow, not on a runner page. Two compatible paths flagged in
  `command-palette.md` Future Enhancement — pick one before planning
  the runner.
- **Phase 16 D-40 (Fuse.js)** is load-bearing — the palette quality
  determines the artisan runner UX. The phase plan should bump its
  rigor here.
- **Theme tokens** — `default.css` in this wrap-up is sketch-conventional.
  Hand-port into Tailwind v4 `@theme` block under
  `resources/css/app.css` when wiring to the real app.

## Files written

- `./.claude/skills/sketch-findings-diederik/SKILL.md`
- `./.claude/skills/sketch-findings-diederik/references/app-shell-and-navigation.md`
- `./.claude/skills/sketch-findings-diederik/references/dev-console-surfaces.md`
- `./.claude/skills/sketch-findings-diederik/references/command-palette.md`
- `./.claude/skills/sketch-findings-diederik/references/component-library.md`
- `./.claude/skills/sketch-findings-diederik/sources/themes/default.css`
- `./.claude/skills/sketch-findings-diederik/sources/001-phase-16-developer-mode/index.html`
- `./.claude/skills/sketch-findings-diederik/sources/001-phase-16-developer-mode/README.md`
- `./.planning/sketches/WRAP-UP-SUMMARY.md`
- `CLAUDE.md` — auto-load routing line appended

---

## Session 4 — 2026-05-27 (Phase 17 counterparty surfaces + skill rename diederik → beatrax)

**Sketches processed:** 4 (007, 008, 009, 010)
**Design areas added:** 2 (Counterparty profiles · Counterparty index & triage)
**Skill renamed:** `sketch-findings-diederik` → **`sketch-findings-beatrax`** (per Phase 17 D-40..D-42; brand alignment with composer.json + nightworksio/beatrax repo)
**Skill output (rename + append):** `./.claude/skills/sketch-findings-beatrax/`

### Included Sketches

| # | Name | Winner | Design Area |
|---|------|--------|-------------|
| 007 | phase-17-counterparty-profile-shape-merchant | C — tabbed surface (`Overview · Transactions · Chains · Aliases`); Overview is a 2-col grid with categories+recurring left, recent-activity+funding-chain summary right | Counterparty profiles |
| 008 | phase-17-counterparty-profile-type-variants | all four — 007C shape flexes; per-type hero stats; tab bar varies (Chains dropped for non-merchants, `Tax years` gained for gov); privacy-first defaults for personal (banner + IBAN-hidden); self-account is a stub-redirect | Counterparty profiles |
| 009 | phase-17-counterparty-index | D synthesis — cards-by-default + dense-list toggle via `Cards | List` segmented widget in toolbar; sparklines in cards give 12mo at-a-glance signal; ledger/bulk-edit variant C dropped | Counterparty index & triage |
| 010 | phase-17-identify-unknown-flow | B — single dedicated `/counterparties/triage` queue with confidence-based suggestion banner ("Looks like Ziggo — confidence high" + reasoning), progress bar, keyboard-first (`Y`/`N`/`S`/`→`); modal + inline-editor patterns rejected | Counterparty index & triage |

### Excluded Sketches

_None._

### Design Direction (Phase 17 counterparty)

**Same calm-slate room, with a new type-color language.** The five
counterparty types (merchant blue, personal pink, bank amber,
government slate, self gray) plus the dashed-border unknown fallback
give every counterparty surface a consistent visual key. The profile
page uses the **007C tabbed surface** as the canonical shape and
flexes per-type via tab-bar variation + hero-stat composition —
crucially, **Chains tab is dropped for every non-merchant type** with
a tab-note explaining why ("— no funding chains for personal contacts").
**Personal counterparties get privacy-first defaults**: a pink-tinted
banner at the top + IBAN hidden behind a "Show IBAN" button + categories
replaced with user-authored purpose tags. **Government counterparties
get a full-width 3-year tax-year card row** above the Overview grid
(2026 YTD / 2025 final / 2024) with the current year card emphasized
and a pending-assessment chip when applicable. **Self-account
counterparties are stub-redirect pages** that route to the Accounts
view rather than rendering a real profile.

The **counterparty index** ships as **cards-by-default with a
`Cards | List` toggle** in the toolbar. Cards include a 12-month
sparkline + recent-activity preview; list rows are dense Linear-style.
The toolbar also hosts the **type-filter chip row** (same dot+count
language used across the app's filter UIs) and a search box with a
`/` keyboard shortcut hint.

For unknown counterparties, **a single dedicated `/counterparties/triage`
queue** replaces the more obvious "contextual modal" or "inline editor"
patterns. The queue is keyboard-first (`Y`/`N`/`S`/`→`), focuses on one
unknown at a time with a confidence-based suggestion banner that
surfaces reasoning ("Mollie iDEAL processor on the same IBAN; Ziggo
uses Mollie for most NL collections"), and has a progress bar with
ETA. Every "Label this counterparty" CTA elsewhere in the app routes
here — no modal, no inline editor.

### Key Decisions (Phase 17)

#### Type taxonomy & color language (007, 008)

- Five types + one unknown fallback. Type chip colors are reused
  identically across profile pages, index cards/list, filter chips,
  and triage banners — one color system, multiple surfaces.
- Personal pink (`#fce7f3` bg / `#be185d` text) is intentionally warmer
  than the other types to read as "human, not commercial."
- Unknown uses dashed-border treatment everywhere (chip, avatar, card)
  to communicate "incomplete state" visually.

#### Profile shape (007 winner C → 008 confirmation)

- **Tabbed surface** beats stacked-single-column and 2-col body. Each
  detail (Transactions / Chains / Aliases) gets a dedicated page so
  the Overview can stay focused.
- **Overview tab is a 2-col grid** — categories+recurring left,
  recent-activity+funding-chain-summary right. Each summary block
  has a "Open Chains →" / "See all 12 →" link that programmatically
  switches the tab.
- **Per-type hero stat composition** — merchant gets 12mo+avg/mo;
  personal gets net-received; bank gets 12mo+net-of-interest; gov
  gets YTD+last-final-year; self is minimal.

#### Privacy-first for personal (008 A)

- **Privacy banner** above the hero on `#fce7f3` background — sets
  the expectation immediately.
- **IBAN hidden by default** — `····  ····  ····  ····` with a
  `Show IBAN` toggle that auto-hides on page leave.
- **Purpose tags replace categories** — P2P transfers don't fit
  spending categories. Tags are user-authored: `birthday`, `rent split`,
  `groceries shared`, with count badges.

#### Index composition (009 D synthesis)

- **Cards-default** for discovery (sparkline + recent activity reveal
  cadence at a glance).
- **List-toggle** for density (Linear-style rows, hover-reveal actions).
- **`Cards | List` segmented control** in toolbar between the chip row
  and the sort link.
- **Ledger/bulk-edit dropped** — bulk operations belong in Dev Mode if
  ever needed (Phase 17 D-43..D-48 do not include user-facing bulk).

#### Triage flow (010 B)

- **Single path, not three**. A's modal + C's inline editor both
  rejected. All "Label this" CTAs route to `/counterparties/triage`.
- **Confidence-based suggestion** with reasoning surfaced — builds
  trust in the suggestion engine.
- **Keyboard-first ergonomics** — `Y`/`N`/`S`/`→` shortcuts shown in
  the action footer.
- **Two escape valves** — "Mark as ignored" (permanent) and
  "Skip for now" (resurfaces in next session).
- **Sidebar Triage badge** uses amber when count > 0 — combined
  count for transaction-level + counterparty-level pending.

### Skill rename (Phase 17 D-40..D-42)

- Directory renamed `sketch-findings-diederik` → `sketch-findings-beatrax`
- SKILL.md frontmatter `name:` + `description:` updated
- SKILL.md context block updated to "## Project: beatrax"
- CLAUDE.md routing line updated to reference the new skill name
- All existing references + sources preserved unchanged (only the
  enclosing directory + a few labels changed); 8 prior reference
  files + 6 prior source-sketch directories carry over as-is.

### Theme

No new theme tokens. The four new sketches use only the existing slate
palette + emerald/amber/rose/blue state palette + the per-type chip
colors which are derived from the same token system. `default.css`
unchanged.

### Files Written

- `./.claude/skills/sketch-findings-beatrax/` (renamed from `sketch-findings-diederik/`)
- `./.claude/skills/sketch-findings-beatrax/SKILL.md` (updated — name/description/context/findings_index/usage_guidance/metadata)
- `./.claude/skills/sketch-findings-beatrax/references/counterparty-profiles.md` (new)
- `./.claude/skills/sketch-findings-beatrax/references/counterparty-index-and-triage.md` (new)
- `./.claude/skills/sketch-findings-beatrax/sources/007-phase-17-counterparty-profile-shape-merchant/` (new)
- `./.claude/skills/sketch-findings-beatrax/sources/008-phase-17-counterparty-profile-type-variants/` (new)
- `./.claude/skills/sketch-findings-beatrax/sources/009-phase-17-counterparty-index/` (new)
- `./.claude/skills/sketch-findings-beatrax/sources/010-phase-17-identify-unknown-flow/` (new)
- `./.planning/sketches/WRAP-UP-SUMMARY.md` (appended)
- `CLAUDE.md` — auto-load routing line updated to reference renamed skill
