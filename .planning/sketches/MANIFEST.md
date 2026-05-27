# Sketch Manifest

## Design Direction

**Same calm slate room as the existing app, denser inside `/dev/*`.** The Dev
Console reuses the app-wide Linear/Notion sidebar primitive (Phase 16 D-05),
but switches to tighter row padding, monospace identifiers, kbd hints, and
tabular numerics in tables. Light + dark variants are first-class — the
Dev Console isn't a separate visual key.

Reference points kept in mind while sketching:

- **Linear** — sidebar grouping, ⌘K palette behavior, settings page chrome
- **Vercel** — live log panel, env-vars table, project-switcher pattern
- **Raycast** — palette result row, kbd footer hints, source-tag chips
- **GitHub Actions** — live-streaming run cards with collapsible output,
  status icons, durations, re-run buttons

## Reference Points

- Existing `Modules/Core/Resources/views/livewire/top-nav.blade.php` — the
  current top-nav whose role the sidebar inherits.
- Existing `Modules/Core/Resources/views/livewire/dashboard.blade.php` —
  the dashboard mock the sidebar wraps and the palette overlays.
- CLAUDE.md — slate palette, Tailwind v4 + Livewire 4 + Flux UI 2 stack.
- `.planning/PROJECT.md` — "calm, Linear/Notion aesthetic" constraint.

## Sketches

| # | Name | Design Question | Winner | Tags |
|---|------|----------------|--------|------|
| 001 | phase-16-developer-mode | Four-scene Phase 16 surface: app sidebar shape, /dev overview layout, ⌘K palette structure, artisan runner UX | **All C** — sidebar C (sectioned + Dev block), overview C (console pane), palette C (two-pane), runner C (palette-dispatched timeline) | layout, dev-console, palette, runner |
| 002 | phase-16-1-wizard-shell | What does the first-run wizard chrome feel like — frame, progress, primary/skip/exit? | **D** — A's chrome + 620px wider card + C's emoji-row content | wizard, onboarding, layout |
| 003 | phase-16-1-connect-source | How do we walk a non-technical user through "log in → find export → pick format → drop file"? | **C** — Linear mini-steps tile-row + persistent drop zone in the same 720px card | wizard, onboarding, upload |
| 004 | phase-16-1-preview-row | How do payment-type badges + Funding source + inline rename coexist without row noise? | **D** — Leading Type chip (glyph + word) + click-italic rename + per-row confirm/clear suggested categories + bulk confirm | preview, table, badges, categorize |
| 005 | phase-16-1-crowd-merchant | Where does community merchant identification live and what's the contribute flow? | **A+B+C combined** — B is the primary moment-of-use entry (Triage row CTA), A is the browse-all destination (`/community/mystery-merchants`), C is the preferences surface (`Settings → Shared merchant list`); all share one suggest-mapping modal | community, settings, contribution, triage |
| 006 | phase-16-1-2-first-import-step-layout | What composition should the FirstImportStep page take — card geometry, eyebrow weight, sub-card vs single-frame, stacked vs two-column, balance-card placement? | **B** — sub-card per source (framed preview blocks on `bg-subtle`), starting balances in their own framed sub-card below the previews, stacked balance grid (3-up) | wizard, first-import, preview, balances, layout, phase-16-1-2 |
| 007 | phase-17-counterparty-profile-shape-merchant | What's the overall composition of a counterparty profile page (merchant baseline)? Stacked single-column vs hero + 2-column body vs tabbed surface | **C** — compact hero + tab bar (`Overview · Transactions · Chains · Aliases`); Overview shows category breakdown + recurring patterns + 5-row recent activity + funding-chain summary in a 2-col grid | counterparties, profile, layout, phase-17 |
| 008 | phase-17-counterparty-profile-type-variants | How does the 007 winner shape flex across personal / bank / government / self_account types? | **all four — shape holds** — A personal (privacy banner + IBAN-hidden toggle + P2P direction summary + purpose tags); B bank (subtitle disambiguates from account-holder, fee-type mini bars, link to Accounts view); C government (full-width 3-year tax-year card row as headline + tax-type breakdown + Tax years tab); D self-account (stub-redirect page with primary CTA to Accounts view + recent cross-account legs for context, no tab bar). Chains tab dropped for all non-merchant types. | counterparties, profile, types, privacy, phase-17 |
| 009 | phase-17-counterparty-index | How are all counterparties listed — dense Linear list vs card grid vs ledger table? Type-filter chip UX. | **D synthesis** — cards (default) + dense-list toggle via a segmented `Cards | List` widget in the toolbar. Cards = discovery (sparkline + recent activity); List = density. Ledger/bulk-edit variant C dropped. | counterparties, index, filter, phase-17 |
| 010 | phase-17-identify-unknown-flow | Where does the "label this unknown counterparty" CTA live and what does the flow feel like? | _pending_ | counterparties, triage, identification, phase-17 |

## Key Visual Decisions (from sketch 001)

- **Sidebar (1C)** — App-wide left sidebar with section labels (This month /
  Money / Categorization / Tools), a sticky bottom Dev block (visible only
  to `is_developer` users) showing live queue/worker pulse + `⌘.` shortcut,
  and an account row with kebab. Workspace version chip in the brand row.
- **/dev overview (2C)** — Single big dark "console pane" with three
  headline metrics (worker heartbeat + sparkline, queue, last command),
  rolled-up live tail underneath. Recent runs + open alerts collapse to
  small cards below. The dark pane is the only zinc-ish departure from the
  app's calm slate; everything around it stays the same room.
- **⌘K palette (3C)** — Two-pane layout: left rail of categories (All /
  Views / Dev commands / Actions) + a Recent shortcuts list, right rail
  shows matching rows with source chips. `Tab` toggles between rails.
- **Artisan runner (4C)** — No persistent form; commands fire from `⌘K`
  and materialize as a grouped-by-day timeline of run cards. Filter chips
  (All / Running / Failed / Destructive) at the top. Destructive-tier
  confirmation lives in a triple-gate modal (Dev Mode ON + Advanced ON +
  type `beatrax`).

## Key Visual Decisions (from Phase 16.1 sketches 002–005)

- **Wizard shell (002D)** — Centered card (620px) on a slate-50 page
  wash. Top bar: brand + progress-dots strip + "Resume later". Card:
  generous padding, primary + ghost buttons anchored bottom-right.
  Footer: privacy pill + "Need help?" link. Same chrome wraps every
  wizard step. For the embedded import-preview step the card relaxes
  to ~1120px just for the table.
- **Connector step (003C)** — Inside the wizard card: four glyph
  mini-tiles across the top (🔐 Log in · 📑 Open afschriften · 📅
  Pick a range · ⬇️ Download), format chips inline with a quiet
  "recommended" badge on CAMT.053, drop zone always visible at the
  bottom. Reused unchanged for ICS PDF and optional email OAuth
  steps.
- **Preview row (004D)** — New leading "Type" column with chips that
  combine glyph + word (⛁ PIN · ⌘ Online · ↔ Transfer · ⤓ Direct
  debit · € Cash). Counterparty column plain; italic-fallback names
  are themselves the click-target for inline rename (popover with
  "remember for future imports" checkbox that seeds a learning rule).
  Funding source as monospace pill-tag with masked IBAN. Category
  column has three states: dashed-italic auto-suggested chip with
  hover ✓/× quick actions, solid confirmed chip with ✓ prefix, or
  ghost "+ Pick a category" button. Bulk "Confirm all suggestions"
  button with live count chip in the legend; live footer counter
  (confirmed / auto-suggested / uncategorized).
- **Crowd merchant ID (005 A+B+C)** — Three-layer surface for the
  community-contributed merchant corpus. Primary entry is the
  dashed "❋ Help others identify this" button on each mystery-code
  row in `/triage`. Browse-all destination is `/community/
  mystery-merchants` (sidebar item with a count badge). Preferences
  live under `Settings → Shared merchant list` with three toggles.
  All three open the same modal that hides the YAML-PR-to-Git-repo
  awkwardness behind a friendly form and a "submits as draft PR from
  diederik-bot" reassurance.

## Key Visual Decisions (from Phase 16.1.2 sketch 006)

- **FirstImportStep page composition (006B)** — Inside the locked-wide
  1120px wizard card, each per-source preview becomes its own framed
  sub-card (border + radius + `--color-bg-subtle` fill). The empty ICS
  sub-card uses `--color-surface-2` fill instead so its emptiness reads
  without competing with ready sources. Starting balances live below
  the preview sub-cards in their own framed sub-card with a
  `🧮 STARTING BALANCES · N ACCOUNTS DETECTED` eyebrow + one-line lede,
  and a 3-up CSS grid of balance cards (`grid-template-columns:
  repeat(3, 1fr); gap: 16px`). The 3-up grid replaces the current
  full-width stack so the densest state (PayPal conflict card with two
  radio rows + helper) reads at the same width as the simpler detected
  and manual-entry cards.
- **Eyebrow shape carries unchanged** — `🏦 FROM YOUR BANK STATEMENT ·
  84 ROWS · ✓ READY` (uppercase tracked label + count + emerald ready
  badge) stays as-is. The framed sub-card doesn't make the eyebrow feel
  redundant; the eyebrow is what labels the source, the frame is what
  groups its rows.
- **Sub-card frames use the page wash** — `--color-bg-subtle` (the page
  wash the wizard card already sits on) doubles as the sub-card fill.
  Sub-cards read as "carved out of the wizard card", not "stacked
  cards on cards".
- **No new theme tokens** — winner stays inside the locked
  slate-50/100/200/700/900/950 + emerald/amber/rose/blue state palette
  per phase 16.1.2 D-17.

## Implications for downstream phases

- The palette (⌘K) is the **primary command-entry point**, not a convenience
  layer. This raises the bar for Fuse.js setup quality, registry coverage,
  and keybind reliability (Phase 16 D-40 / D-41 / D-42).
- The `/dev/artisan` page becomes much lighter — no form scaffolding to
  build for arg-schemas; the palette is the input surface. The page itself
  is a timeline reader.
- The dark "console pane" on /dev overview is a localized exception to
  the otherwise-light slate palette; theme tokens should include a
  `--color-console-bg` that's stable across light/dark modes (always dark).

