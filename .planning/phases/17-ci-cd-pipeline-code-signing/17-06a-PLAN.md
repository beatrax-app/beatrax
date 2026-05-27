---
phase: 17-ci-cd-pipeline-code-signing
plan: 06a
type: execute
wave: 2
depends_on:
  - 17-05a
files_modified:
  - resources/css/app.css
  - Modules/Counterparties/Resources/views/components/type-chip.blade.php
  - Modules/Counterparties/Resources/views/components/cp-card.blade.php
  - Modules/Counterparties/Resources/views/components/filter-chips.blade.php
  - Modules/Counterparties/Resources/views/components/frame.blade.php
  - Modules/Counterparties/Resources/views/components/chain-flow.blade.php
  - Modules/Counterparties/Resources/views/components/iban-row.blade.php
  - Modules/Counterparties/Resources/views/components/privacy-banner.blade.php
  - Modules/Counterparties/Resources/views/components/self-stub.blade.php
  - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
  - Modules/Counterparties/Providers/CounterpartiesServiceProvider.php
autonomous: true
requirements:
  - gap-counterparty-module-ui-shell
requirements_addressed:
  - gap-counterparty-module-ui-shell
must_haves:
  truths:
    - "resources/css/app.css extends the existing @layer components block with the full Counterparty inventory (per-type t-* classes, .cp-card, .frame, .triage-*, .privacy-banner, .iban-row, .self-stub, .filter-chips, .view-toggle, .suggestion, .progress-bar, .help-locations family)"
    - "Personal-type pink (#fce7f3 / #be185d / #831843) is hard-coded as the only non-token color introduced by Phase 17 (load-bearing per UI-SPEC § Color)"
    - "Every CSS selector has a dark-mode flip (.dark .selector) using dark token equivalents"
    - "8 x-component Blade files render under `<x-counterparties::*>` (type-chip, cp-card, filter-chips, frame, chain-flow, iban-row, privacy-banner, self-stub)"
    - "Each x-component declares aria attributes per UI-SPEC § Accessibility Contract"
    - "Sidebar shows Counterparties between Chains and Recurring; Triage entry shows an amber count badge when unknowns > 0"
    - "All copy on x-components is verbatim from 17-UI-SPEC.md (no paraphrasing)"
  artifacts:
    - path: "resources/css/app.css"
      provides: "Extended @layer components with the full UI-SPEC Counterparty inventory"
    - path: "Modules/Counterparties/Resources/views/components/*.blade.php"
      provides: "8 x-components consumed by the 3 Livewire pages in Plan 17-06b"
    - path: "Modules/Core/Resources/views/livewire/app-sidebar.blade.php"
      provides: "Sidebar with Counterparties + Triage entries; Triage carries amber unknown-count badge"
  key_links:
    - from: "Plan 17-06b Livewire views"
      to: "Plan 17-06a x-components"
      via: "<x-counterparties::*> Blade tags"
    - from: "Sidebar Triage badge"
      to: "CounterpartyTriageQueue (lands in 17-06b)"
      via: "DI in sidebar component's render() — temporarily NULL-tolerant in 17-06a; 17-06b wires the real count"
---

<objective>
Ship the Counterparty UI shell — CSS, x-components, and sidebar additions. NO Livewire pages or queries in this plan (those land in 17-06b); NO cross-module click-through (that's 17-06c).

Purpose: This is the visual/markup foundation for the Counterparty surfaces. Splitting it from the Livewire-page work means UI shell can be reviewed visually + tested for CSS regressions without entangling with route/query/test scaffolding.

Output: A complete x-component library + extended CSS layer + sidebar navigation. The 8 x-components render in isolation (verified via `php artisan view:cache`) but are not yet consumed by Livewire pages. Sidebar navigation works (Counterparties link routes to a 404 until 17-06b lands the route — acceptable; this is a within-phase wave dependency).

**Important per CLAUDE.md skill auto-load:** Phase 17 counterparty surfaces auto-trigger `Skill("sketch-findings-diederik")` (or `sketch-findings-beatrax` after the Plan 17-14 skill rename). The 17-UI-SPEC.md document in this phase is the authoritative source for every visual decision and supersedes the sketch findings — but during read_first, the executor should glance at the sketch-findings skill for design tokens / patterns that informed UI-SPEC so analogous classes follow established CSS conventions.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md
@resources/css/app.css
@Modules/Core/Resources/views/livewire/app-sidebar.blade.php
@Modules/Onboarding/Resources/views/components/consolidated-preview-section.blade.php
@Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php

<interfaces>
<!-- x-component contracts per UI-SPEC -->
<x-counterparties::type-chip type="merchant|personal|bank|government|self_account|unknown">
<x-counterparties::cp-card :counterparty :recent>
<x-counterparties::filter-chips :active :counts>
<x-counterparties::frame>...</x-counterparties::frame>
<x-counterparties::chain-flow :nodes>
<x-counterparties::iban-row :iban :revealed=false>
<x-counterparties::privacy-banner>
<x-counterparties::self-stub :account>

<!-- CSS inventory (full list from UI-SPEC § Component Inventory) -->
.t-merchant / .t-personal / .t-bank / .t-gov / .t-self / .t-unknown
.type-chip + variants
.cp-card + .cp-card.unknown
.cp-head / .cp-stats / .cp-spark / .cp-recent
.view-toggle + .view-toggle button.active
.filter-chips + .chip + .chip-dot + .chip-count + per-type dot classes
.frame + .frame-tight + .frame.surface
.recur-card + .recur-cadence + .recur-cadence.amber
.chain-flow + .chain-node + .chain-arrow + per-source node-glyph classes
.fee-bar-row + family
.privacy-banner (with hard-coded personal pink)
.iban-row + .iban-label + .iban-hidden
.tax-year-row + .tax-year-card + .tax-year-card.current
.self-stub + .stub-icon + .stub-actions
.triage-shell + .triage-card + .triage-head + .triage-iban + .triage-meta + .triage-section + .triage-actions
.suggestion + .suggestion.medium + .suggestion.low
.progress-bar + .progress-fill
.help-locations + family (scoped here for reuse by Plan 17-09's /help/data-locations page)
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: CSS components — extend @layer components with the full UI-SPEC inventory</name>
  <files>resources/css/app.css</files>
  <read_first>
    - resources/css/app.css (current — locate the existing `@layer components` block and the `--text-*`, `--space-*`, `--color-*` token sets; new component classes append to the existing block)
    - .planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md § "Component Inventory" + § "Color" + § "Spacing Scale" (FULL READ — every class declaration + every hex value)
    - Sketch findings skill `Skill("sketch-findings-diederik")` (auto-loaded per CLAUDE.md for Phase 17 counterparty surfaces) — glance to confirm class-name conventions align with prior sketch decisions; UI-SPEC is authoritative if there's a conflict
  </read_first>
  <action>Append to `resources/css/app.css` `@layer components` block (DO NOT introduce new tokens — every value uses existing `--text-*` / `--space-*` / `--color-*` tokens or the documented exceptions in UI-SPEC § Spacing Scale). Add the FULL inventory from UI-SPEC § Component Inventory verbatim:

    - **Per-type tokens**: `.t-merchant`, `.t-personal`, `.t-bank`, `.t-gov`, `.t-self`, `.t-unknown` (with the per-type background/foreground/border declared in UI-SPEC § Color)
    - **Chips**: `.type-chip` base shape + per-type variants
    - **Cards**: `.cp-card` + `.cp-card.unknown` variant (dashed border); `.cp-head` / `.cp-stats` / `.cp-spark` / `.cp-recent`
    - **View toggle**: `.view-toggle` + `.view-toggle button.active`
    - **Filter chips**: `.filter-chips` + `.chip` + `.chip-dot` + `.chip-count` + per-type dot classes
    - **Frame**: `.frame` + `.frame-tight` + `.frame.surface`
    - **Recurring badges**: `.recur-card` + `.recur-cadence` + `.recur-cadence.amber`
    - **Chain flow**: `.chain-flow` + `.chain-node` + `.chain-arrow` + per-source node-glyph classes
    - **Fee bars**: `.fee-bar-row` + family
    - **Privacy banner**: `.privacy-banner` — hard-code the personal-type pink hex values `#fce7f3 / #be185d / #831843` per UI-SPEC § Color (the only non-token color introduced by Phase 17 — load-bearing)
    - **IBAN row**: `.iban-row` + `.iban-label` + `.iban-hidden`
    - **Tax-year cards**: `.tax-year-row` + `.tax-year-card` + `.tax-year-card.current`
    - **Self stub**: `.self-stub` + `.stub-icon` + `.stub-actions`
    - **Triage**: `.triage-shell` + `.triage-card` + `.triage-head` + `.triage-iban` + `.triage-meta` + `.triage-section` + `.triage-actions`
    - **Suggestions**: `.suggestion` + `.suggestion.medium` + `.suggestion.low`
    - **Progress**: `.progress-bar` + `.progress-fill`
    - **Help locations**: `.help-locations` + family (scoped here for reuse by Plan 17-09's /help/data-locations page)

    Every selector has a dark-mode flip (`.dark .selector`) using the dark token equivalents per UI-SPEC § Color (dark column). No GSD vocabulary in CSS comments. Comments describe present-tense purpose of each class group.</action>
  <verify>
    <automated>grep -q "\\.t-merchant" resources/css/app.css && grep -q "\\.privacy-banner" resources/css/app.css && grep -q "#be185d" resources/css/app.css && grep -q "\\.triage-card" resources/css/app.css && grep -q "\\.help-locations" resources/css/app.css && grep -q "\\.cp-card" resources/css/app.css && grep -q "\\.suggestion" resources/css/app.css && ! grep -Ei "\\.planning/|PLAN\\.md|RESEARCH\\.md|\\bD-[0-9]{2,3}\\b|gsd[-_]" resources/css/app.css</automated>
  </verify>
  <done>All UI-SPEC Component Inventory classes appended to @layer components; personal-pink hex values present exactly as UI-SPEC dictates; dark-mode flips present; no new tokens introduced; passes leakage grep; `npm run build` (or Vite equivalent) succeeds without errors.</done>
</task>

<task type="auto">
  <name>Task 2: 8 x-components + sidebar additions + service-provider component registration</name>
  <files>Modules/Counterparties/Resources/views/components/type-chip.blade.php, Modules/Counterparties/Resources/views/components/cp-card.blade.php, Modules/Counterparties/Resources/views/components/filter-chips.blade.php, Modules/Counterparties/Resources/views/components/frame.blade.php, Modules/Counterparties/Resources/views/components/chain-flow.blade.php, Modules/Counterparties/Resources/views/components/iban-row.blade.php, Modules/Counterparties/Resources/views/components/privacy-banner.blade.php, Modules/Counterparties/Resources/views/components/self-stub.blade.php, Modules/Core/Resources/views/livewire/app-sidebar.blade.php, Modules/Counterparties/Providers/CounterpartiesServiceProvider.php</files>
  <read_first>
    - Modules/Core/Resources/views/livewire/app-sidebar.blade.php (current — find the Money section where Chains + Recurring sit; new Counterparties + Triage entries land between them per UI-SPEC § Sidebar additions)
    - Modules/Onboarding/Resources/views/components/consolidated-preview-section.blade.php (analog — Blade x-component shape, escape-all-output, role/aria attributes)
    - Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php (analog — Tailwind literal classes; focus-ring utility chain `focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900`)
    - .planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md (FULL READ — every component contract + every copy string + every aria attribute)
    - Sketch findings skill `Skill("sketch-findings-diederik")` (auto-loaded per CLAUDE.md) — confirm Blade-component conventions align; UI-SPEC is authoritative if conflict
  </read_first>
  <action>Step A — Blade x-components: create the 8 component files under `Modules/Counterparties/Resources/views/components/`. Each is a `<?php ?>`-prefixed Blade file using props inheritance (Laravel 12 component conventions). Patterns to follow:
    - All `aria-label`, `role`, `aria-pressed` attributes per UI-SPEC § Accessibility Contract
    - All copy verbatim from UI-SPEC § Copywriting Contract
    - Focus rings via `focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900` chain
    - `<x-counterparties::iban-row>` uses Alpine for the Show/Hide toggle: `x-data="{ revealed: false }"` + `x-show="revealed"` / `x-show="!revealed"`. The revealed IBAN gets `aria-live="polite"`.
    - `<x-counterparties::privacy-banner>` renders the verbatim copy `🔒 This is a personal contact. IBAN and personal details are hidden by default and never shared in exports.`
    - `<x-counterparties::self-stub>` renders the verbatim heading `This isn't really a counterparty` + the substituted body + primary CTA `Open {Account name} account view →` + secondary `Hide from this list` + `Recent cross-account legs` (max 3 below).

    Step B — Sidebar additions: modify `Modules/Core/Resources/views/livewire/app-sidebar.blade.php`. Find the Money section (existing `Chains` + `Recurring` entries). Insert `Counterparties` between Chains and Recurring per UI-SPEC § Sidebar additions: use Unicode glyph `◉` consistent with existing nav glyphs (or whichever existing glyph matches per sidebar conventions discovered during read). Add `Triage` item (placement: planner picks — under Money or near the existing transaction-level Triage entry from Phase 16.1, reconciling to a single combined-count badge per UI-SPEC). Triage badge: `background: var(--color-amber-bg); color: var(--color-amber); font-weight: 600;` when count > 0.

    Sidebar Triage badge data source: in 17-06a, the badge query is NULL-tolerant — render `0` (or hide the badge) until 17-06b lands `CounterpartyTriageQueue`. Add a TODO-style fallback `{{ $unknownCount ?? 0 }}` that 17-06b replaces with the real DI'd count. Anti-leak: do not name the upcoming class — describe the dependency in a Blade comment that the noGsdLeakage scan tolerates (e.g., `{{-- count populated from injected service when available --}}`).

    Step C — Service provider registration: modify `Modules/Counterparties/Providers/CounterpartiesServiceProvider.php` to register the x-components: in `boot()`, call `$blade->componentNamespace('Modules\\Counterparties\\Resources\\views\\components', 'counterparties');` (via injected `Illuminate\View\Compilers\BladeCompiler $blade`) so `<x-counterparties::type-chip ... />` resolves. (Discover the project's preferred component registration mechanism — `Blade::component(...)` individually or namespace registration — during read_first; mirror whichever neighboring modules use; DI-only — no facade calls.)

    No new Livewire components yet (those land in 17-06b). All copy verbatim from UI-SPEC. DI-only. No `@php` blocks containing facade calls. Every Tailwind class is a direct literal string (no interpolation).</action>
  <verify>
    <automated>find Modules/Counterparties/Resources/views/components -name "*.blade.php" | wc -l | grep -q "^[[:space:]]*8$" && grep -q "Counterparties" Modules/Core/Resources/views/livewire/app-sidebar.blade.php && grep -q "componentNamespace.*counterparties" Modules/Counterparties/Providers/CounterpartiesServiceProvider.php && ! grep -Ei "\\.planning/|PLAN\\.md|RESEARCH\\.md|\\bD-[0-9]{2,3}\\b|gsd[-_]" Modules/Counterparties/Resources/views/components/*.blade.php Modules/Core/Resources/views/livewire/app-sidebar.blade.php Modules/Counterparties/Providers/CounterpartiesServiceProvider.php && php artisan view:cache 2>&1 | tail -1 | grep -qi "cached\\|success"</automated>
  </verify>
  <done>All 8 x-components materialized; sidebar updated with both Counterparties + Triage entries; Blade namespace registration in service provider; `php artisan view:cache` succeeds; all files pass the leakage grep.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Blade x-component → consumer view | Props are escaped via `{{ }}`; aria-attributes accurate per UI-SPEC |
| sidebar Triage badge → unknown-count source | Badge data is NULL-tolerant in 17-06a; 17-06b wires the real DI'd query |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-06a-01 | Information disclosure | x-component prop containing user-controlled IBAN rendering without escape | mitigate | All Blade interpolation uses `{{ }}`; `{!! !!}` is forbidden project-wide per CLAUDE.md/CONTRIBUTING.md |
| T-17-06a-02 | Tampering | unauthorized CSS additions introducing new color tokens | accept | UI-SPEC § Color is the locked color palette; CODEOWNERS gating on resources/ would block drive-by edits (Plan 17-12 enforces) |
</threat_model>

<verification>
After both tasks:

1. `php artisan view:cache` succeeds
2. CSS appended cleanly with all required classes
3. 8 x-component files exist
4. Sidebar shows Counterparties + Triage entries
5. noGsdLeakage arch invariant green (when Plan 17-08 lands)
</verification>

<success_criteria>
- All 7 must_haves true
- All copy verbatim from UI-SPEC
- Personal-pink hex hard-coded in CSS as UI-SPEC mandates
- Dark-mode flips present for every new selector
- Sidebar additions render even before 17-06b lands routes (with placeholder count)
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-06a-SUMMARY.md` capturing: the final 8 x-component file paths, the CSS line-count delta, the final sidebar markup change, and the placeholder mechanism used for the Triage count before 17-06b lands.
</output>
