---
phase: 17
slug: 17-ci-cd-pipeline-code-signing
status: draft
shadcn_initialized: false
preset: not-applicable
created: 2026-05-27
stack: php-laravel-livewire
component_library: flux-ui-2 + bespoke calm-slate primitives
locked_baseline: Skill("sketch-findings-beatrax") — sketches 007 / 008 / 009D / 010B
---

# Phase 17 — UI Design Contract (v1.0.0 Public Release Closeout)

> Visual and interaction contract for the six UI-bearing surfaces this
> phase ships. The sketch-findings-beatrax skill owns *how it looks*; this
> file translates those locked decisions into a single contract the
> planner and executor can implement against without re-asking visual
> questions.
>
> **Surfaces in scope:**
> 1. `/counterparties/{slug}` — type-aware profile (5 variants + unknown fallback)
> 2. `/counterparties` — cards-default + dense-list toggle index
> 3. `/counterparties/triage` — focused single-card queue
> 4. Auto-update banner integration with the existing `SystemAlertsBanner`
> 5. README install-bypass copy (macOS Gatekeeper / Windows SmartScreen / Linux)
> 6. `/help/data-locations` — "Where is my data?" page + export-everything ZIP affordance
>
> **Out of scope (not UI deliverables):** CI/CD workflows, signing
> hooks, smoke tests, `.docs/` content scaffolding, license / SECURITY /
> CONTRIBUTING / CODE_OF_CONDUCT body text, repo-settings walkthrough
> captures.

---

## Design System

| Property | Value | Source |
|----------|-------|--------|
| Tool | none (server-rendered PHP / Livewire — shadcn N/A) | stack is Laravel 12 + Livewire 4 + Volt + Flux 2 + Tailwind v4; no React/Vite/Next.js so the shadcn gate is N/A and the project does not maintain a `components.json` |
| Preset | not applicable | — |
| Component library | **Flux UI 2** (modal / button / input / select / dropdown / kbd primitives) + **bespoke calm-slate component layer** in `resources/css/app.css` `@layer components` (`.side`, `.side-item`, `.side-badge`, `.kbd`, `.status-pill`, `.ptype-chip` already shipped; this phase adds `.t-*` type chips, `.cp-card`, `.frame`, `.recur-card`, `.chain-flow`, `.view-toggle`, `.privacy-banner`, `.iban-row`, `.fee-bar-row`, `.triage-card`, `.suggestion`, `.progress-bar`, `.self-stub`) | sketch-findings-beatrax / component-library.md |
| Icon library | Unicode glyph + emoji set already used in skill sketches (`◆ ≡ ⇉ ›_ ⌗ ⌕ ⋯ ↻ ❋ ↵ ✨ ↑ ↓ ⊘ ✓ ×`) — keep consistent across counterparty surfaces. **Do NOT introduce** Heroicons / Lucide / iconify (none are installed and the local-only constraint forbids fetching webfonts at runtime) | resources/css/app.css review + sketch-findings (no icon library configured) |
| Font | **Inter** (sans, body) + **JetBrains Mono** (mono, identifiers/IBANs/amounts in tables). Local fall-through chain only — no webfont fetch (local-only constraint) | resources/css/app.css already declared |
| Theme strategy | Class-strategy dark mode (`.dark` on `<html>`) — already wired in `resources/views/layouts/app.blade.php`. Light + dark **first-class**; every new token below must have a `.dark` flip | resources/css/app.css + layouts/app.blade.php |

---

## Spacing Scale

All values are multiples of 4 and **already declared as Tailwind v4
`@theme` tokens** in `resources/css/app.css` (lines 75–83). Re-declared
here for the contract record — executor uses the existing tokens, does
NOT introduce new spacing values.

| Token | Value | Usage in this phase |
|-------|-------|---------------------|
| `--space-1` | 4px | Chip dot ↔ label gap, kbd hint internal padding |
| `--space-2` | 8px | Tab-bar item padding-y, badge padding, suggestion-banner internal gaps |
| `--space-3` | 12px | Card padding-x in dense rows, fee-bar gap, recur-card padding |
| `--space-4` | 16px | `.frame` padding, hero-row gaps, triage-section gaps |
| `--space-5` | 20px | Card-grid gap on the index, profile tab body top padding |
| `--space-6` | 24px | Overview tab 2-col grid gap, page section gap on profile + index |
| `--space-8` | 32px | Page-head bottom margin |
| `--space-10` | 40px | Self-account stub vertical padding |
| `--space-12` | 48px | Main app `space-y-12` rhythm — keep unchanged on counterparty surfaces (calm room) |

**Exceptions for this phase:**

- **Triage card max-width = 760px** (centered) — narrower than the
  default app container so kbd shortcuts feel essential (sketch 010B).
- **Self-account stub max-width = 640px** + `margin: 60px auto` —
  also intentionally narrow to read as "this is a dead-end page,
  go to Accounts" (sketch 008 variant D).
- **Tab-bar item padding `8px 14px`** — the 14px horizontal isn't on
  the scale; it is intentional per sketch 007C to keep tabs visually
  paired without forcing 16px which would over-space them.
- **Triage card padding 28px** — slightly larger than `--space-6`
  (24px) per sketch 010B to give the focused queue more breathing
  room than dense list rows.

---

## Typography

The four declared roles (covers the 3-4 sizes / 2 weights gate). All
sizes already exist as `--text-*` tokens in `resources/css/app.css`
(lines 65–72); executor uses them directly.

| Role | Size | Weight | Line Height | Usage |
|------|------|--------|-------------|-------|
| Body | 15px (`--text-base`) | 400 | 1.5 | Counterparty index card text, profile body, triage-card body, /help/data-locations prose |
| Label | 13px (`--text-sm`) | 500 | 1.4 | Tab-bar items, side-item labels, page-sub text, button labels, kbd-hint surround text, type-chip label |
| Heading | 22px (`--text-xl`) | 600 | 1.25 | Profile hero name, triage page-head, /counterparties page title |
| Display | 28px (`--text-2xl`) | 600 | 1.2 | Hero stat values (`12-month total`, `Net received`, `2026 YTD`), tax-year card totals on government profile |

**Footnote sizes** (used inline; not separate roles): `--text-xs`
(12px, footnotes), `10-10.5px` (kbd, count chips, tier chips, section
labels — uppercase + tracked). These match component-library.md and
are explicitly NOT new typography roles — they're stylistic tokens
already enforced by existing components.

**Tabular numerals** mandatory on:
- All counterparty money stats (12-mo total, Avg/mo, Net received, fee-bar totals, tax-year totals)
- Sparkline values on cards
- IBAN strings (use `--font-mono`)
- Triage progress copy (`23 of 61 · 38 % · ~15 min remaining`)
- `/health` route is not a UI surface so no rule applies

Use `font-variant-numeric: tabular-nums` or the existing `.tabular` / `.tnum` utility.

---

## Color

**60/30/10 split** mirrors the live `resources/css/app.css` token set.
This is not negotiable for Phase 17 — every counterparty surface lives
inside the same calm-slate room as the dashboard and Dev Console.

| Role | Light value | Dark value | Usage |
|------|-------------|------------|-------|
| Dominant (60%) | `#ffffff` (`--color-bg`) | `#020617` slate-950 | Page background, profile body, triage card surface, /help/data-locations body |
| Secondary (30%) | `#f8fafc` slate-50 (`--color-bg-subtle`) + `#f1f5f9` slate-100 (`--color-surface-2`) | `#0b1220` + `#1e293b` slate-800 | Sidebar, frames around Overview sections, cards, hover rows, tab-bar inactive count chip, suggestion-banner body track |
| Accent (10%) | `#0f172a` slate-900 (`--color-primary`) | `#f1f5f9` slate-100 | **Inverted slate fill** — primary button (`Open account view →`, `Yes, link to Ziggo`), active filter chip, active tab-bar count chip, sparkline last-bar emphasis |
| Destructive | `#be123c` rose-700 (`--color-rose`) | `#f43f5e` rose-500 | `Hide from this list`, `⊘ Mark as ignored` (rose tint, not solid fill), failed-state pills |

**Accent reserved ONLY for** (explicit list, no expansion):

1. Primary CTA button fill (one per page max — `Open <type> account view →` on self-stub; `Yes, link to <name> ↵` in triage suggestion banner)
2. Active tab-bar bottom-border (1px on the tab-bar rail under the active tab)
3. Active filter-chip background + active view-toggle-button background
4. Sparkline **last bar** opacity flip from 0.6 to 1.0 (recency emphasis)
5. Active sidebar `Counterparties` and `Triage` items (existing `.side-item.active` rule)

**Per-type counterparty chip colors** — the type taxonomy ships its
own color language (sketch 008 + counterparty-index-and-triage). These
are **not** part of the 60/30/10 accent budget; they are categorical
metadata, used identically across the index, profile hero, triage,
sidebar badge, and any future cross-module surface (chain flow,
recurring list, etc.).

| Type | Background | Foreground | Border | Avatar gradient |
|------|------------|------------|--------|-----------------|
| `merchant` | `var(--color-blue-bg)` | `var(--color-blue)` | `color-mix(in srgb, var(--color-blue) 24%, transparent)` | per-brand (Amazon orange `#ff9900→#cc7700`, AH orange, Netflix red, Bol emerald, KPN blue, etc. — fallback `#2563eb→#1e40af`) |
| `personal` | `#fce7f3` | `#be185d` | `color-mix(in srgb, #be185d 24%, transparent)` | pink `#ec4899→#be185d` |
| `bank` | `var(--color-amber-bg)` | `var(--color-amber)` | `color-mix(in srgb, var(--color-amber) 24%, transparent)` | amber `#f59e0b→#b45309` |
| `government` | `#e2e8f0` | `#334155` | `#cbd5e1` | slate `#64748b→#334155` |
| `self_account` | `var(--color-surface-2)` | `var(--color-text-muted)` | `var(--color-border)` | muted `#94a3b8→#64748b` |
| `unknown` | `transparent` | `var(--color-text-muted)` | `1px dashed var(--color-border-strong)` | placeholder `?` glyph on `--color-surface-2` |

Personal-type pink (`#fce7f3` / `#be185d` / `#831843` privacy-banner
text) is the **only** non-token color introduced by Phase 17, and it is
load-bearing for the privacy default — the visual differentiation
signals "this is personal, treated differently" before the user reads
the banner copy. Hard-code these three hex values consistently (do NOT
abstract behind a `--color-pink` token — none of the existing app uses
pink and the privacy semantics must not leak elsewhere).

**State colors** stay locked to the existing 4-color system:
- emerald = OK / positive net / recurring detected / triage progress fill
- amber = warning / bank fees / destructive tier chip / sidebar Triage count badge when >0 / "you're on an old version" banner
- rose = fail / destructive button / failed-state status pill
- blue = info / running / merchant chip / sparkline default bar fill

No purple, no teal, no new state color. Sketch-findings-beatrax
"What to Avoid" rule is strict.

---

## Copywriting Contract

All copy is verbatim — executor uses these strings exactly, only
substituting `{name}` / `{N}` / `{slug}` style placeholders.

### Counterparty index — `/counterparties`

| Element | Copy |
|---------|------|
| Page title | `Counterparties` |
| Page sub (calm) | `{N} entities` |
| Page sub (with unknowns) | `{N} entities · {U} need identification` *(the "U need identification" half is a link to `/counterparties/triage`)* |
| Search placeholder | `Search by name, alias, or IBAN…` |
| Search kbd hint | `/` (focus search) |
| Sort link | `Sort: {current-sort}` (default: `Total 12mo ↓`) |
| View toggle | `▦ Cards` / `≡ List` |
| Filter chip — All | `All {N}` |
| Filter chips — types | `Merchants {N}`, `Personal {N}`, `Banks {N}`, `Government {N}`, `Self {N}`, `Unknown {N}` |
| Unknown card CTA | `❋ Label this counterparty` *(routes to `/counterparties/triage` with this unknown queued first)* |
| Self-row main label | `Routing only` |
| Self-row sub | `no spend / no income` |
| Self-row action | `Open account →` |
| Card stat labels | `12 mo`, `Avg / mo` *(merchant/bank/gov default)* · `Net received` *(personal)* |
| Card recent-line format | `{dd MMM} · {short description}` followed by signed amount |
| Empty state heading | `No counterparties yet` |
| Empty state body | `Counterparties appear here automatically as you import transactions. Import a statement to get started.` *(primary CTA: `Import a statement →` linking to the Imports page)* |
| Loading state | `Loading {N} counterparties…` *(only shown if first paint takes >300ms)* |

### Counterparty profile — `/counterparties/{slug}` (all types)

| Element | Copy |
|---------|------|
| Tab bar — merchant | `Overview`, `Transactions`, `Chains`, `Aliases` |
| Tab bar — personal | `Overview`, `Transfers`, `Aliases` + right-tab-note `— no funding chains for personal contacts` |
| Tab bar — bank | `Overview`, `Entries`, `Aliases` + right-tab-note `— bank-fee counterparty doesn't generate funding chains` |
| Tab bar — government | `Overview`, `Payments`, `Tax years`, `Aliases` + right-tab-note `— no funding chains for government counterparties` |
| Tab bar — self_account | (none — body is stub redirect) |
| Tab bar — unknown | `Overview`, `Transactions`, `Aliases` *(no Chains; no tab-note; Overview prominently surfaces the Label-this CTA)* |
| Overview Categories empty | `No categories yet — uncategorized transactions appear in {link to Categorization}.` |
| Overview Recurring empty | `No recurring patterns detected.` |
| Overview Funding chain empty (merchant) | `No funding chain detected yet. Imports of ASN + PayPal data are required for funding-chain resolution.` *(link: `Open Chains review →`)* |
| Overview Recent activity heading | `Recent activity` with `See all {N} →` link switching to the Transactions tab |
| Hero edit affordance | `Edit display name` *(opens an inline rename, mirroring the Phase 16.1 `RenameCounterpartyPopover` pattern)* |
| "Tax years" tab body intro (government) | `Annual breakdown across all years with activity. Current year is emphasized.` |
| Pending-assessment chip (gov) | `⚠ {assessment label} due {dd MMM yyyy} · €{amount}` *(amber tint)* |

### Personal-type profile additions (privacy defaults — LOAD-BEARING)

| Element | Copy |
|---------|------|
| Privacy banner | `🔒 This is a personal contact. IBAN and personal details are hidden by default and never shared in exports.` |
| IBAN row label | `IBAN` |
| IBAN hidden display | `····  ····  ····  ····` *(mono, letter-spaced 0.4em, `--color-text-faint`)* |
| Show IBAN button | `Show IBAN` *(toggles to `Hide IBAN` when revealed; re-hides on page navigation away)* |
| Recurring replacement | `No recurring detected — personal transfers rarely follow a strict cadence; even regular rent splits may shift dates.` |
| Add purpose tag | `+ Add tag` *(dashed-border chip)* |

### Self-account stub

| Element | Copy |
|---------|------|
| Heading | `This isn't really a counterparty` |
| Body | `{Account name} appears here because it shows up in your transactions as the funding leg between accounts. But it's **your own account**, not someone you transact with.\n\nOpen the account view for balance, statements, and full transaction history.` |
| Primary CTA | `Open {Account name} account view →` |
| Secondary | `Hide from this list` |
| Recent legs label | `Recent cross-account legs` *(max 3 rows below the stub for context)* |

### Counterparty triage — `/counterparties/triage`

| Element | Copy |
|---------|------|
| Page head | `Triage unknown counterparties` |
| Progress copy | `{seen} of {total} · {percent} % · ~{minutes} min remaining` |
| Triage IBAN line | `{formatted IBAN with country prefix + bank ID + masked tail}` *(e.g. `NL · ·· INGB ···· ···· 47`)* |
| Triage meta | `{count} transactions · €{total} total · last seen {dd MMM}` |
| Suggestion banner — high confidence | `✨ Looks like **{name}** — confidence high` |
| Suggestion banner — medium | `✨ Maybe **{name}** — confidence medium` |
| Suggestion banner — low (rare) | `Pattern match: **{name}** — confidence low. Verify before linking.` |
| Suggestion reasoning sub-line | `{1-sentence rationale}` *(load-bearing — never render the banner without it; example: `all 3 transactions use the Mollie iDEAL processor on the same IBAN; Ziggo uses Mollie for most NL collections`)* |
| Suggestion accept | `Yes, link to {name} ↵` *(primary button; binds to **Y** key)* |
| Suggestion reject | `No, not {name}` *(secondary button; binds to **N** key)* |
| Recent transactions label | `Recent transactions on this IBAN` |
| Manual-label section | `Or label manually` |
| Manual-label inputs | placeholder `Display name…` + select with options `Merchant`, `Personal`, `Bank`, `Government` *(no Self option — derived from IBAN-matches-user-account)* |
| Skip action | `↷ Skip for now` *(binds to **S** key; resurfaces in next session)* |
| Ignore action | `⊘ Mark as ignored` *(permanent — this counterparty stays unknown forever; rose-tinted not rose-fill)* |
| Next button | `Next ▸` *(binds to **→** key)* |
| Previous link | `↑ Previous unknown` |
| Footer counter | `{labeled} already labeled · {remaining} to go` |
| kbd hint row | `Y yes · N no · S skip · → next` |
| Empty queue state | `🎉 All caught up — every counterparty is labeled.` *(secondary: `Back to counterparties →`)* |

### Auto-update banner — integrates into existing `SystemAlertsBanner`

`SystemAlertsBanner` already supports `critical` / `warning` / default
severities (see `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php`).
Phase 17 adds **three new alert kinds** (recorded in
`system_alerts.kind` per existing convention; rendered through
`partials/system-alert-message.blade.php`).

| Alert kind | Severity | Copy | Actions |
|------------|----------|------|---------|
| `update.available` | default (slate) | `Update available — beatrax {newVersion} is ready. It will install on next launch.` | `Install on next launch` *(default, no click required)* · `Skip this version` *(secondary; persists per-user in `user_preferences` per D-21)* · `Release notes →` *(opens GitHub release page via OpenExternalUrlAction)* |
| `update.stale` | warning (amber) | `You're on version {currentVersion} — version {latestVersion} has been available for 30 days. Update now.` | `Update now` *(primary)* · `Remind me later` *(snoozes 7 days)* |
| `update.critical` | critical (rose) | `Critical update available — version {newVersion} fixes {1-line summary}. Install as soon as possible.` | `Install on next launch` *(no skip option for critical)* |

These re-use the existing `acknowledge({id})` wire action verbatim;
no new banner Livewire component is needed. The "Skip this version"
secondary action calls a new wire method `skipVersion({alertId})` that
writes to `user_preferences` and acknowledges the alert in one step.

### README install-bypass copy

The README install section is a **first-class deliverable** per
Amendment A-05. Three platform sections + a verification section, all
written with brand-aware tone. Headings + body copy verbatim:

#### macOS section

> **Heading:** `Installing on macOS`
>
> **Intro:** `beatrax is an independent app. macOS will warn you the
> first time you open it — that's expected.`
>
> **Steps:**
> 1. `Open the downloaded **beatrax.dmg** and drag beatrax into your Applications folder.`
> 2. `Right-click **beatrax** in Applications and choose **Open**.`
> 3. `When macOS asks "are you sure?", click **Open**.` *(screenshot of the actual dialog goes here)*
> 4. `From now on, double-clicking beatrax launches it normally.`
>
> **Alternative (Terminal one-liner):**
> ```
> xattr -d com.apple.quarantine /Applications/beatrax.app
> ```
>
> **Footnote:** `Like most independent macOS apps, beatrax isn't signed with an Apple Developer ID — we don't pay Apple $99/year just to avoid the first-launch dialog. [Why we made this choice →]({link to .docs/legal/license-rationale.md#no-paid-signing})`

#### Windows section

> **Heading:** `Installing on Windows`
>
> **Intro:** `beatrax is an independent app. Windows SmartScreen will warn you the first time you open it — that's expected.`
>
> **Steps:**
> 1. `Run the downloaded **beatrax-setup.exe**.`
> 2. `When you see "Windows protected your PC", click **More info**.` *(screenshot of the SmartScreen dialog goes here)*
> 3. `Click **Run anyway**.`
> 4. `From now on, beatrax launches normally from the Start menu.`
>
> **Footnote:** `SmartScreen reputation builds up over time as more people open beatrax. After a few weeks, the warning will stop appearing for new users automatically. [Why we made this choice →]({same legal link})`

#### Linux section

> **Heading:** `Installing on Linux`
>
> **Intro:** `beatrax ships as both an AppImage (portable) and a .deb (Debian / Ubuntu native).`
>
> **AppImage:**
> ```
> chmod +x beatrax-*.AppImage
> ./beatrax-*.AppImage
> ```
>
> **.deb (Debian / Ubuntu / Mint):**
> ```
> sudo dpkg -i beatrax-*.deb
> ```
>
> *(No footnote — Linux has no signing-friction equivalent.)*

#### Verification section (for users who want assurance)

> **Heading:** `Verifying the download`
>
> **Body:** `Every release publishes SHA-256 checksums and an Ed25519-signed manifest. If you'd like to verify integrity:`
> ```
> sha256sum beatrax-{version}-{platform}.{ext}
> ```
> `Then compare against the checksum file published with the release. For the deeper "is this manifest authentic?" check, see [the verification runbook →]({link to .docs/runbooks/verify-release.md}).`

### /help/data-locations page

| Element | Copy |
|---------|------|
| Page title | `Where is my data?` |
| Intro | `beatrax stores everything on this device. Nothing is sent to a server, nothing syncs to the cloud, nothing leaves your machine without you exporting it.` |
| Section 1 heading | `Your data lives here` |
| Section 1 body | `**SQLite database:** {resolved path from UserDataPathService}\n**OAuth secrets:** {resolved path}\n**Brand assets + caches:** {resolved path}` *(monospace paths, with a copy-to-clipboard button next to each)* |
| Section 2 heading | `Export everything` |
| Section 2 body | `Bundle every byte beatrax has stored about you into a single .zip you can back up, archive, or move to another machine.` |
| Section 2 CTA (Dev Mode ON) | `Export everything as ZIP` *(primary button; reuses the Dev Mode export-everything action per D-30)* |
| Section 2 fallback (Dev Mode OFF) | `Dev Mode is off. To export your data, either:\n1. Enable Dev Mode in Settings, then return here, **or**\n2. Manually copy the folders above using your file manager.` *(no CTA — just instructional)* |
| Section 3 heading | `Deleting your data` |
| Section 3 body | `To remove beatrax and every trace of your data:\n1. Drag beatrax to the Trash / uninstall via Add or Remove Programs.\n2. Delete the folders listed above.\n\nThere's no telemetry to opt out of and no remote account to close.` |

### Generic empty / error / destructive copy

| Element | Copy |
|---------|------|
| **Primary CTA verb-noun** *(global default for unspecified buttons)* | use the explicit copy in the tables above; do not default-fallback |
| **Generic empty state heading** (if a new section lands without specific copy) | `Nothing here yet` |
| **Generic empty state body** | `{specific instruction for the surface; never just "no data"}` |
| **Generic error state** | `Something went wrong loading {what}. Try refreshing — if it keeps happening, open the Dev Console (⌘.) and check the logs.` |
| **Destructive confirmation — `Hide from this list`** (self-account) | inline confirm via Flux dropdown — no triple-gate (it's reversible from Settings) |
| **Destructive confirmation — `Mark as ignored`** (triage) | inline confirm: `Mark as ignored permanently? You can unhide ignored counterparties from Settings → Counterparties.` *(no triple-gate — also reversible)* |
| **Destructive confirmation — `Delete counterparty`** *(if surfaced)* | **not surfaced in v1.0** — counterparties are derived from transactions and pruned by `CounterpartyGarbageCollectorJob`; manual deletion deferred to v1.1 |

---

## Component Inventory

New components Phase 17 introduces. All live in
`Modules/Counterparties/Resources/views/` or as additions to the
existing `@layer components` block in `resources/css/app.css`.

### CSS components (`resources/css/app.css` `@layer components`)

| Class | Purpose | First used on |
|-------|---------|---------------|
| `.t-merchant`, `.t-personal`, `.t-bank`, `.t-gov`, `.t-self`, `.t-unknown` | Per-type chip backgrounds + borders | Profile hero, index cards, triage |
| `.type-chip` | Base chip shape: `display: inline-flex; gap: 4px; padding: 2px 8px; border-radius: var(--radius-full); font-size: 10.5px; font-weight: 500; border: 1px solid;` | Same |
| `.cp-card` (+ `.cp-card.unknown` variant) | Index card grid item | `/counterparties` cards view |
| `.cp-head`, `.cp-stats`, `.cp-spark`, `.cp-recent` | Card internal layout | Same |
| `.view-toggle` (+ `.view-toggle button.active`) | Segmented Cards / List toggle | `/counterparties` toolbar |
| `.filter-chips`, `.chip`, `.chip-dot`, `.chip-count`, `.dot-merchant`/`-personal`/etc. | Type filter chip row | `/counterparties` toolbar; reusable on triage filter |
| `.frame`, `.frame-tight`, `.frame.surface` | Bordered card frames inside profile Overview | `/counterparties/{slug}` Overview tab |
| `.recur-card`, `.recur-cadence` (+ `.recur-cadence.amber`) | Recurring pattern card | Overview Recurring section |
| `.chain-flow`, `.chain-node`, `.chain-arrow` (+ `.node-glyph.asn`/`.ics`/`.paypal`/etc.) | Compact funding chain visualization | Overview Funding chain section |
| `.fee-bar-row`, `.fee-label`, `.fee-bar-track`, `.fee-bar-fill`, `.fee-total` | Horizontal fee mini-bars | Bank-type profile Overview |
| `.privacy-banner` | Pink privacy notice | Personal-type profile top |
| `.iban-row`, `.iban-label`, `.iban-hidden` | IBAN reveal toggle | Personal-type profile |
| `.tax-year-row`, `.tax-year-card`, `.tax-year-card.current` | Full-width 3-up year cards | Government-type profile |
| `.self-stub`, `.stub-icon`, `.stub-actions` | Self-account dead-end page | Self-account profile |
| `.triage-shell`, `.triage-card`, `.triage-head`, `.triage-iban`, `.triage-meta`, `.triage-section`, `.triage-actions` | Triage queue card | `/counterparties/triage` |
| `.suggestion` (+ `.suggestion.medium`, `.suggestion.low`) | Confidence-based banner inside triage card | `/counterparties/triage` |
| `.progress-bar`, `.progress-fill` | Triage progress | `/counterparties/triage` |
| `.help-locations`, `.path-row`, `.path-mono`, `.copy-path-btn` | /help/data-locations layout | `/help/data-locations` |

### Livewire / Blade components

| Component | Path | Purpose |
|-----------|------|---------|
| `Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php` + matching `CounterpartyIndex.php` Volt SFC | `/counterparties` | Index page (cards-default + list toggle) |
| `Modules/Counterparties/Resources/views/livewire/counterparty-profile.blade.php` + `CounterpartyProfile.php` | `/counterparties/{slug}` | Profile shell, type-branches into sub-views |
| `Modules/Counterparties/Resources/views/livewire/profile-tabs/{merchant,personal,bank,government,unknown}.blade.php` | included by profile shell | Per-type Overview tab body |
| `Modules/Counterparties/Resources/views/livewire/counterparty-triage.blade.php` + `CounterpartyTriage.php` | `/counterparties/triage` | Focused queue with keyboard handlers |
| `Modules/Counterparties/Resources/views/components/{type-chip,cp-card,filter-chips,frame,chain-flow,iban-row,privacy-banner,self-stub}.blade.php` | Blade x-component primitives | Reusable across counterparty surfaces (and consumable by other modules once exported) |
| `Modules/Core/Resources/views/livewire/help/data-locations.blade.php` + `HelpDataLocations.php` Volt SFC | `/help/data-locations` | "Where is my data?" page |
| Existing `Modules/Core/.../system-alerts-banner.blade.php` | unchanged structurally | Three new `system_alerts.kind` rows added: `update.available` / `update.stale` / `update.critical` |
| Existing `Modules/Core/.../app-sidebar.blade.php` | **modified** | New section item `Counterparties` between Chains and Recurring per D-46; new sidebar `Triage` item with amber badge when count > 0 (per counterparty-index-and-triage.md) |

### Sidebar additions

- Add `Counterparties` item to the **Money** section (or new placement
  per D-46 "between Chains and Recurring"). Icon: `◉` (or similar
  Unicode primitive consistent with existing nav glyphs).
- Add `Triage` item (top-level under Money? — planner picks; the
  contract requires only that it exists, carries a combined-count amber
  badge `background: var(--color-amber-bg); color: var(--color-amber);
  font-weight: 600` when `>0`, and routes to `/counterparties/triage`).
  The Phase 16.1 transaction-level triage already has a Triage entry
  point; planner reconciles into a single combined-count badge.

---

## Interaction Contracts

### Keyboard shortcuts (triage page — load-bearing)

| Key | Action | Notes |
|-----|--------|-------|
| `Y` | Accept the current suggestion (link to suggested merchant) | Only when a suggestion banner is rendered |
| `N` | Reject the current suggestion (suggestion dismisses; manual-label section gains focus) | Only when a suggestion banner is rendered |
| `S` | Skip for now (re-queue at end of session) | Always |
| `→` | Next unknown in queue | Always |
| `Esc` | Close triage (return to `/counterparties`) | Always |

All bindings must respect the existing carve-out from
`resources/views/layouts/app.blade.php` — when focus is inside
`INPUT` / `TEXTAREA` / `contenteditable`, the keys go to the field, not
the triage handler. Document this carve-out in the executor's task
notes.

### Index page interactions

| Trigger | Behavior |
|---------|----------|
| `/` key from anywhere on the index | Focus the search box |
| Click `▦ Cards` / `≡ List` in view-toggle | Switch view mode, persist in `user_preferences` (per-user, key `counterparty_index_view = cards|list`, default `cards`) |
| Click filter chip | Filter the grid/list to that type; URL gains `?type={slug}` query param so links share state |
| Click an unknown card's `❋ Label this counterparty` CTA | Route to `/counterparties/triage?queue_first={counterparty_id}` — that unknown surfaces first |
| Click self-account row's `Open account →` | Route to `/accounts/{account_slug}` (existing Accounts surface) |
| Click `{U} need identification` in page-sub | Route to `/counterparties/triage` |
| Click any other card / row | Route to `/counterparties/{slug}` |

### Profile page interactions

| Trigger | Behavior |
|---------|----------|
| Click tab | Switch tab body without page reload (Livewire `$tab` property) |
| Click `See all {N} →` in Recent activity | Programmatically switch to Transactions tab |
| Click `Open Chains →` in Funding chain summary | Switch to Chains tab |
| Click `Show IBAN` on personal profile | Reveal IBAN; button toggles to `Hide IBAN`; auto-hides on next page mount |
| Click hero "Edit display name" | Open inline rename via existing `RenameCounterpartyPopover` pattern (Phase 16.1) |
| Profile loaded with `self_account` type | Render stub redirect — do NOT render tabs, do NOT render hero stats |
| URL hash `#tab=transactions` (etc.) | Open profile with that tab pre-selected |

### Auto-update banner interactions

| Trigger | Behavior |
|---------|----------|
| Click `Skip this version` | New wire method `skipVersion({alertId})` records skipped version in `user_preferences`, acknowledges the alert; banner does not re-appear for the same `latestVersion` |
| Click `Install on next launch` | Acknowledge alert; on next launch `electron-updater.quitAndInstall()` fires |
| Click `Release notes →` | Open GitHub release page via existing `OpenExternalUrlAction` (https + github.com allow-list — Phase 16.1.5 plumbing) |
| Banner kind `update.stale` triggers | After 30 days on a stale `currentVersion` (hardcoded threshold per D-22) |
| Banner kind `update.critical` triggers | When `release.yml` publishes a release tagged with the `critical` label *(planner refines exact mechanism in Plan 17-05)* |

---

## Accessibility Contract

| Surface | Requirement |
|---------|-------------|
| Type chips | Aria label `Counterparty type: {type}` on each chip; color alone never carries meaning (label text is always present) |
| Privacy banner | `role="region"` with `aria-label="Privacy notice for personal contact"` |
| IBAN hidden display | `aria-label="IBAN hidden — click Show IBAN to reveal"` on the dotted display; revealed IBAN gets `aria-live="polite"` so screen readers announce on toggle |
| Triage suggestion banner | `role="region"` `aria-label="Suggested match"`; suggestion accept button has `aria-keyshortcuts="Y"` |
| Triage manual-label section | `<fieldset>` + `<legend>Or label manually</legend>`; inputs have explicit `<label for>` |
| Triage kbd hints | `<kbd>` elements (not styled spans) — semantic |
| View toggle | `role="group"` `aria-label="View mode"`; buttons have `aria-pressed="true|false"` |
| Filter chips | `role="group"` `aria-label="Filter by type"`; chips are `<button>` with `aria-pressed` |
| Self-account stub | `role="region"` `aria-label="Not a real counterparty"` |
| Auto-update banner | Reuses existing `role="alert"` / `role="status"` mapping (critical=alert, others=status) already in `system-alerts-banner.blade.php` |
| /help/data-locations copy-path buttons | `aria-label="Copy {path-type} path to clipboard"` |
| All interactive elements | Visible focus ring (use the existing `focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900` Tailwind utility chain already in `system-alerts-banner.blade.php`) |
| Color contrast | Minimum WCAG AA — verify per-type chip foreground / background pairs meet 4.5:1 for label text. Personal pink `#be185d` on `#fce7f3` and Bank amber `#b45309` on `#fef3c7` are at the threshold; if Pest snapshot tests flag a contrast regression, deepen the foreground by one step |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none — stack is Livewire/Flux, shadcn N/A | not required |
| Flux UI 2 (Livewire-native, vendored at `vendor/livewire/flux/`) | `flux::modal`, `flux::button`, `flux::input`, `flux::select`, `flux::dropdown`, `flux::kbd` (used inline) | not required — first-party Livewire team package; trust level matches Laravel core |
| Third-party shadcn registries | none — none declared in CONTEXT.md or RESEARCH.md | not applicable |

**Registry vetting gate:** N/A. No third-party blocks declared.

---

## Locked-Decision Provenance

For audit traceability — every contract value above derives from one of:

| Decision | Source |
|----------|--------|
| Counterparty type taxonomy (5+1 types) | CONTEXT.md Amendment A-04 + Section I |
| Resolution chain (7 steps) | CONTEXT.md Section I |
| Profile tabbed shape (Overview / Transactions / Chains / Aliases baseline) | sketch 007 winner C → counterparty-profiles.md |
| Per-type tab-bar variations | sketch 008 winners A/B/C/D → counterparty-profiles.md |
| Personal-type privacy banner + IBAN-hidden default | sketch 008 variant A → counterparty-profiles.md (load-bearing per CONTEXT.md Section I privacy rule) |
| Government tax-year full-width row | sketch 008 variant C → counterparty-profiles.md |
| Self-account stub redirect | sketch 008 variant D → counterparty-profiles.md |
| Index cards-default + list toggle | sketch 009 winner D synthesis → counterparty-index-and-triage.md |
| Type-filter chip row | sketch 009D → counterparty-index-and-triage.md |
| Sparkline 12-month at-a-glance | sketch 009D → counterparty-index-and-triage.md |
| Unknown card dashed-border CTA | sketch 009D → counterparty-index-and-triage.md |
| Triage focused single-card queue | sketch 010 winner B → counterparty-index-and-triage.md (modal + inline-editor patterns explicitly rejected) |
| Confidence-based suggestion banner with reasoning sub-line | sketch 010B → counterparty-index-and-triage.md |
| Keyboard-first ergonomics (Y / N / S / →) | sketch 010B → counterparty-index-and-triage.md |
| Auto-update banner integration with SystemAlertsBanner | CONTEXT.md Section C (D-19..D-22 + A-06) |
| "Skip this version" persistence per-user | D-21 |
| 30-day stale threshold | D-22 |
| README install-bypass copy as first-class deliverable | CONTEXT.md Amendment A-05 + Section K |
| /help/data-locations + export-everything affordance | CONTEXT.md D-30 (REL-08) |
| Tailwind v4 CSS-first tokens (already shipped) | resources/css/app.css lines 36–129 |
| Sidebar primitive | app-shell-and-navigation.md |
| Component primitives (kbd, status-pill, dense tables, dark-mode tokens) | component-library.md |

---

## Out-of-Scope Confirmation (per CONTEXT.md `<deferred>`)

Explicitly NOT in Phase 17 UI-SPEC, but called out so the executor
doesn't accidentally drift into them:

- Counterparty CSV export per profile → v1.1
- Monthly digest email → v1.1
- Side-by-side comparison view → v1.1
- User-facing counterparty merge UI → v1.1 (Dev Mode action only in v1.0)
- Counterparty delete action → v1.1 (garbage-collected only in v1.0)
- Modal-from-transaction-row labeling pattern → REJECTED (sketch 010A) — every "Label this" entry routes to `/counterparties/triage`
- Inline-editor labeling on the index → REJECTED (sketch 010C)
- Ledger-style table with bulk-select on index → REJECTED (sketch 009C)
- Stacked single-column profile → REJECTED (sketch 007A)
- Per-row modal contextual labeling → REJECTED (sketch 010A)
- Auto-discovering counterparties without a type → REJECTED (taxonomy is load-bearing)
- Showing real profile pages for `self_account` types → REJECTED (always stub-redirect)
- Exposing personal IBANs in lists / URLs / page titles / exports → REJECTED (privacy banner + hidden-default is mandatory)
- Chains tab for non-merchant types → REJECTED (drop the tab + add right-of-tab-bar note)

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS *(verbatim CTAs / empty / error / destructive copy declared for all 6 surfaces)*
- [ ] Dimension 2 Visuals: PASS *(component inventory + locked-decision provenance map every visual element to a source)*
- [ ] Dimension 3 Color: PASS *(60/30/10 declared; accent reserved-for list explicit; per-type chip colors derive from existing tokens + one load-bearing exception (personal pink) justified)*
- [ ] Dimension 4 Typography: PASS *(4 roles declared, all from existing `--text-*` tokens; tabular-numerics rule explicit)*
- [ ] Dimension 5 Spacing: PASS *(multiples of 4 only; 4 exceptions named + justified)*
- [ ] Dimension 6 Registry Safety: PASS *(N/A — no shadcn, no third-party registry blocks)*

**Approval:** pending
