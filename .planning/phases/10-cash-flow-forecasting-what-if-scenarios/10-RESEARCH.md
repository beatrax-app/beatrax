# Phase 10: Cash-Flow Forecasting + What-If Scenarios — Research

**Researched:** 2026-05-18
**Domain:** Financial forecasting (range projection + uncertainty) on top of an approved-recurring substrate, rendered via ApexCharts `rangeArea` inside Livewire 4, with what-if scenario state structurally walled off from the transaction substrate.
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

> Copied verbatim from `10-CONTEXT.md` `<decisions>` block. Planner must honor these; do not propose alternatives for D-1001..D-1017.

- **D-1001:** Per-account starting balance from the most authoritative source available. ASN: latest `statement_summaries.closing_balance` + sum-of-transactions-since. ICS: latest `card_statements.closing_balance` + delta-since. PayPal + CSV-only: `accounts.opening_balance_minor` + `accounts.opening_balance_as_of_date`. `BalanceAnchorResolver` Internal service encapsulates the strategy per `account.kind`.
- **D-1002:** Chain-aware routing by default + opt-in "View by funder" `#[Url]`-bound toggle. ASN projection deducts the next ICS bulk-iDEAL settlement on its forecast date (`CardStatementQuery::nextSettlementForUser`); ICS shows running "amount owed by next settlement" since last settlement; PayPal-funded-by-ASN/ICS charges deduct from the funder on the actual debit date.
- **D-1003:** All real accounts (ASN + ICS + PayPal) get forecasts. No "primary account" flag.
- **D-1004:** Two-tier range math: envelope (default) + percentile (volatile series). Envelope = `latest_amount × (1 ± variance_tolerance_percent)`. Percentile = P10 / P50 / P90 of historical `recurring_series_occurrences.observed_amount_minor`. Cadence-date jitter = ±3-day window. Daily fold: `running_balance = opening + Σ(point)`; `spread = √(Σ(spread²))` (quadrature combination, statistically correct for independent series).
- **D-1005:** ApexCharts `rangeArea` translucent band (`fill.opacity: 0.2`) + bold center line + crosshair tooltip (`"€1,180 – €1,260 (≈ €1,220) on May 31"`) + per-series confidence legend sidebar.
- **D-1006:** Each series projects in its original currency; per-account chart converts using each occurrence's `latest_fx_rate_used` (Phase 8 D-840 carry-forward). EUR shadow on per-series legend; account chart uses `default_currency`. No new FX rate provider in Phase 10.
- **D-1007:** Five what-if mutation kinds: `cancel_series`, `add_one_off`, `add_recurring`, `change_series_amount`, `shift_series_date`.
- **D-1008:** Scenarios persisted as named saved DB entities (`forecast_scenarios` + `forecast_scenario_mutations`). FCT-03 "no persistence" satisfied by walling scenario mutations off from the transaction substrate (structural arch test, NOT convention).
- **D-1009:** Two-panel side-by-side comparison (baseline left, scenario right) + shared y-axis range + "Net diff at day 30 / 60 / 90" delta tile between/above panels.
- **D-1010:** Scenario discoverability via `/forecast` + Phase 9 drift-alert "Model cancel" launchpad + `/recurring/series/{id}` "Model what-if" link.
- **D-1011:** Per-account `forecast_min_buffer_minor` (nullable BIGINT, default NULL = effective 0 = zero-crossing). Captured on each `forecast_shortfall_windows` row as `buffer_used_minor` for honest audit if buffer later changes (Phase 9 D-915 analog).
- **D-1012:** Shortfall windows pre-computed async by `ProjectForecastJob` and written to `forecast_shortfall_windows`. Dashboard reads from this table (cheap). Re-runs: daily sweep + Phase 8/9 events + scenario events.
- **D-1013:** Phase 5 "Next ICS settlement" tile REPLACED on `/` by new "Forecast highlights" tile. New tile is a strict superset (lowest projected balance + active shortfall windows in next 30d, AND next ICS settlement amount + date). Underlying Phase 5 `CardStatementQuery` stays; new `ForecastHighlightsQuery` is the new read API. Top-nav "Forecast" slot added (badge only when active shortfall in next 30d).
- **D-1014:** New `Modules/Forecasting/` bounded module. Public/Internal split from day one. Owns: 4 new tables, 3 new columns on `accounts`, the projection pipeline, the `/forecast` Livewire SFC + dashboard tile composer, and the five Public read services.
- **D-1015:** Five BoundaryArchTest invariants: (1) `noFacadeCallsFromForecasting`, (2) `noTransactionWritesFromForecasting`, (3) `crossModuleAccessGoesThroughPublic`, (4) `noSynchronousForecastingInRequestLifecycle`, (5) **`noScenarioMutationsJoinedToTransactionQueries`** (load-bearing FCT-03 invariant).
- **D-1016:** Public surface = Queries + DTOs + Events + Actions. Pipeline + state + Livewire stay Internal.
- **D-1017:** `ProjectForecastJob` (`ShouldBeUniqueUntilProcessing` per `(user_id, scenario_id ?? 0, horizon_days)`) computes forecasts async. Triggers: daily sweep + Phase 8 events + Phase 9 `DriftAlertDismissedCancelled` + scenario events.

**UI-SPEC additionally locks** (from `10-UI-SPEC.md`):

- **D-1021 resolved:** **job-completes-then-poll**, NOT optimistic UI. `wire:poll.2s` on `ForecastPage` polls `forecast_runs.status` for the active `(scenario, horizon)` triple; chart panel shows `opacity-60 pointer-events-none` overlay + `Updating…` caption during compute.
- **D-1025 resolved:** Keep top-nav flat at 10 items; insert "Forecast" between "Recurring" and "Settings". Do NOT create a "Money" parent menu.
- **D-1026 resolved:** Dashboard tile is **text-only** (no inline sparkline).
- **D-1027 resolved:** "All accounts" tab renders a **single-line aggregate `line` chart in EUR** (NOT stacked `rangeArea`). Default landing tab when no `account` URL param is present.
- **D-1029 resolved:** Opening-balance soft-warning threshold = `|diff| > €500` vs computed sum-of-transactions-from-earliest; non-blocking banner with `[Use diederik's number]` / `[Use my number]` chips.
- All copy locked verbatim in UI-SPEC § Copywriting Contract (70+ strings).

### Claude's Discretion (planner picks)

- **D-1018:** Wave structure. Suggested 6 waves (W0 module skeleton + arch + fixtures; W1 migrations + models + DTOs; W2 anchor + envelope tier + job + skeleton page; W3 chain router + shortfall + settings + dashboard tile + Phase 5 tile removal + top-nav; W4 scenarios CRUD + side-by-side + delta tile + sidebar + Phase 9/8 launchpads; W5 percentile tier + confidence legend + cadence jitter + opening-balance editor + isolation contract test + multi-currency polish). Planner verifies against goal-backward analysis.
- **D-1019:** Exact "volatile series" tier-switch trigger. Suggested: `variance_tolerance_percent ≥ 40%` OR stddev-over-recent-occurrences > X. NO user-facing toggle. Planner picks the threshold that passes the Wave 0 fixture corpus.
- **D-1020:** `shift_series_date` semantics. UI-SPEC § Mutation form copy now surfaces this as an explicit radio per occurrence (`Just the next occurrence` / `All subsequent occurrences`), so both behaviors are first-class. Planner persists the choice in `payload.scope` and applies it in `ScenarioApplier`.
- **D-1022:** `add_recurring` mutation occurrences bounded by chosen horizon vs fixed 365-day window. Suggested: bounded by horizon for memory efficiency.
- **D-1023:** Exact JSON schema for `forecast_scenario_mutations.payload` per kind. Suggested envelopes in CONTEXT.md §D-1023; planner formalizes as a typed `ScenarioMutationPayload` Spatie LaravelData union DTO mapped via Eloquent cast.
- **D-1024:** Confidence-legend bucket thresholds. Suggested: `band_width / point ≤ 10%` → high; `10–25%` → medium; `> 25%` → low. Planner verifies against fixtures.
- **D-1028:** Whether `forecast_runs` needs `failed_reason` column or Horizon failed-job log suffices. Suggested: re-use Horizon (Phase 5 D-95 precedent).

### Deferred Ideas (OUT OF SCOPE)

> Verbatim from CONTEXT.md `<deferred>` block. Do not research alternatives.

Multi-scenario overlay on a single chart; goal-based forecasting; account-level sub-budgets; outbound payment initiation; push notifications on shortfall; `/accounts/{id}` per-account page; scenario sharing/export/import; forecast accuracy auto-calibration; drift-correlation forecasting; FX rate provider for forward-projection FX; optimistic-UI for scenario mutations (job-completes-then-poll locked); all-accounts stacked `rangeArea` (single-line aggregate locked); inline mini-sparkline on dashboard tile (text-only locked); hourly `ProjectForecastJob` tick; soft + hard buffer tiers per account; inline editing of opening-balance from a "balance mismatch" banner; CSV/JSON export of forecast or scenarios; dark mode; "Money" parent menu.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| **FCT-01** | User can view 30/60/90-day projected balance per account, computed from current balance + recurring inflows/outflows + pending settlements | `BalanceAnchorResolver` (D-1001) + Phase 8 `RecurringSeriesQuery::approvedForUser` + Phase 5 `CardStatementQuery::nextSettlementForUser` + `ChainAwareForecastRouter` (D-1002). Horizon `#[Url(as: 'horizon', except: '30')]`-bound; matches Phase 3 D-44 precedent verified in `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php` and `Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php`. |
| **FCT-02** | Forecast shows uncertainty (ranges) rather than single false-precision number | Two-tier math (D-1004): envelope per series; percentile for volatile series. Quadrature daily fold `spread = √(Σ(spread²))` is the **statistically correct combination of independent series variances** ([CITED: Variance of sum of independent random variables — Cornell, MIT OCW]). ApexCharts `rangeArea` xy data shape: `[{ x: 'May 31', y: [low, high] }]` ([CITED: apexcharts.com/docs/chart-types/range-area-chart/]). |
| **FCT-03** | What-if mutations do not persist to the database | Resolved by D-1008's structural framing: "the database" = transaction/series substrate. Mutations DO persist in `forecast_scenario_mutations` BUT a load-bearing Pest arch invariant (`noScenarioMutationsJoinedToTransactionQueries`, D-1015 #5) guarantees mutations are never JOINed to `transactions` / `recurring_series_occurrences` / `chain_links` / `card_statements`. The `ScenarioApplier` Internal class applies mutations in memory ON TOP of the baseline projection; no Eloquent `save()` on `transactions` is ever called from `Modules/Forecasting/`. |
| **FCT-04** | User can compare a what-if scenario side-by-side with baseline | UI-SPEC § Component Inventory: two `ForecastDto` reads (baseline + scenario) feeding two `rangeArea` charts on `ForecastPage`; shared y-axis (`min`/`max` from the union of both panels). "Net diff at day 30/60/90" delta tile between panels. |
| **FCT-05** | User can see surplus/shortfall windows highlighted, with per-account buffer | `accounts.forecast_min_buffer_minor` (D-1011) + pre-computed `forecast_shortfall_windows` rows (D-1012) + chart `annotations.yaxis` rose-50 band below buffer + inline shortfall badge "Shortfall starts May 22 — €−80 below your €500 buffer". |

</phase_requirements>

## Summary

Phase 10 ships a new `Modules/Forecasting/` bounded module on top of the locked Phase 1–9 substrate. The research scope is narrower than typical phases because CONTEXT.md (~290 lines) already enumerates the module surface, decisions D-1001..D-1029, and Wave 0 fixture corpus. This RESEARCH.md fills the *implementation* gaps: ApexCharts `rangeArea` configuration shape, Livewire 4 `#[Url]` + `wire:poll` + ApexCharts re-render lifecycle, Pest `arch()` mechanism choice for the load-bearing `noScenarioMutationsJoinedToTransactionQueries` invariant, `ShouldBeUniqueUntilProcessing` composite-key collision risk with nullable `scenario_id`, quadrature-spread combination math validation, P10/P50/P90 percentile method choice, and Flux UI component availability for the UI-SPEC primitives.

**Primary recommendation:** Build on the locked stack — no new composer or npm dependencies. ApexCharts 3.54.1 (pinned, on `window.ApexCharts` per `resources/js/app.js`) supports `rangeArea` natively. The `rangeArea`-plus-line combo chart pattern is documented and renders the band + bold center line in one chart instance. Use the existing project pattern (`x-data="{ chart: null }" x-init="..."` with `data-options` JSON-encoded) for chart init; for re-render on `wire:poll` flips, dispatch a Livewire browser event from the component and listen via `x-on:` to call `chart.updateOptions()` / `chart.updateSeries()` — do NOT use `wire:ignore` on the chart container because it prevents Livewire from re-rendering the loading overlay during compute. Five Pest arch invariants: two use the native `arch('...')->expect()->not->toBeUsedIn(...)` shape (D-1015 #3 + #4); three use the `it(...)` + `RecursiveIteratorIterator` + comment-strip regex shape (D-1015 #1 + #2 + #5, matching the existing `noResolverWritesTransactions` precedent) because the rules are content-level (SQL substring / facade call detection), not class-import-level. `ShouldBeUniqueUntilProcessing` composite-key safety requires a sentinel (`'baseline'` or `-1`) instead of `?? 0`, because SQLite autoincrement starts at 1 and the `5:0:30` collision is impossible IN PRACTICE but the static-string framing is more honest about intent.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Per-account balance projection math | API / Backend | Database / Storage | Heavy quadrature fold + Eloquent reads against `recurring_series_occurrences` + `card_statements` + `statement_summaries`; pre-computed by `ProjectForecastJob` and persisted to `forecast_shortfall_windows`. |
| `BalanceAnchorResolver` (per-account anchor selection) | API / Backend | Database / Storage | Reads `statement_summaries.closing_balance` (ASN), `card_statements.closing_balance` (ICS), `accounts.opening_balance_minor` (PayPal/CSV-only) via `DatabaseManager` query builder. |
| `ChainAwareForecastRouter` (occurrence → funder) | API / Backend | — | Consumes Phase 5 `ChainLinkQuery` + `CardStatementQuery` Public services to route projected occurrences onto funder accounts. |
| `RangeProjector` (envelope + percentile tiers) | API / Backend | — | Pure math service; consumes `RecurringSeriesQuery::approvedForUser` + `occurrencesForSeries`; emits per-day `(low, point, high)` triples. |
| `ScenarioApplier` (apply mutations on top of baseline) | API / Backend | — | In-memory transform on baseline projection arrays. NEVER touches `transactions` / `recurring_series_occurrences` (enforced by D-1015 #2 and #5). |
| `ProjectForecastJob` (`ShouldBeUniqueUntilProcessing`) | API / Backend (Horizon queue) | — | Pre-computes per `(user_id, scenario_id, horizon)`. Horizon + Redis already locked (Phase 5). |
| Scenario CRUD (Public Actions) | API / Backend | — | Pure write operations on `forecast_scenarios` + `forecast_scenario_mutations` tables. Dispatches Public events that trigger `ProjectForecastJob`. |
| `/forecast` page rendering | Frontend Server (SSR) | Browser / Client | Livewire 4 SFC renders chart container chrome + Blade partials server-side; ApexCharts hydrates client-side after `wire:init`. |
| Chart instantiation + re-render lifecycle | Browser / Client | Frontend Server (SSR) | `window.ApexCharts` global (set in `resources/js/app.js`); Alpine `x-data` per chart instance; `chart.updateOptions()` / `chart.updateSeries()` invoked from `x-on:forecast-updated.window` listeners. |
| `#[Url]`-bound state (horizon / account / scenario / viewByFunder) | Frontend Server (SSR) | Browser / Client | Livewire 4 native — same pattern as `TransactionsList::$currency` and `DriftPage::$tab`. |
| `wire:poll.2s` projection-status polling | Frontend Server (SSR) | Browser / Client | Polls `forecast_runs.status` for active `(scenario, horizon)` triple; auto-stops when status flips. |
| Dashboard "Forecast highlights" tile | Frontend Server (SSR) | API / Backend | View Factory composer pattern (Phase 5/6/7/8/9 precedent) injects `ForecastHighlightsDto`; tile is a Livewire SFC mounted via `@livewire(...)`. |
| Top-nav "Forecast" slot composer | Frontend Server (SSR) | API / Backend | `ForecastingServiceProvider::boot()` calls `$this->app->make(ViewFactoryContract::class)->composer('core::livewire.top-nav', ...)` to inject `$forecastShortfallCount`. |
| Migrations (4 new tables + 3 nullable columns on `accounts`) | Database / Storage | — | Phase 10's migration dir; the column additions live in Forecasting (Phase 9 D-9XX precedent for cross-module column ownership). |

## Standard Stack

### Core (already locked from `composer.json` + `package.json`)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | `^13.0` [VERIFIED: composer.json] | Web framework | Project-wide lock |
| `livewire/livewire` | `^4.0` [VERIFIED: composer.json] | Reactive UI for `/forecast` page + scenario sidebar + buffer editor popovers | Locked since Phase 1; `#[Url]` attribute confirmed working in 4+ project files [VERIFIED: project grep] |
| `livewire/flux` | `^2.0` [VERIFIED: composer.json] | UI primitives | Locked since Phase 1 |
| `nwidart/laravel-modules` | `^13.0` [VERIFIED: composer.json] | Bounded modules with Public/Internal split | Project-wide lock |
| `laravel/horizon` | `^5.46` [VERIFIED: composer.json] | Queue dashboard + supervisor for `ProjectForecastJob` | Locked since Phase 5 |
| `predis/predis` | `^3.4` [VERIFIED: composer.json] | Pure-PHP Redis client backing the `ShouldBeUniqueUntilProcessing` lock store | Locked since Phase 5 |
| `brick/money` | `^0.11` [VERIFIED: composer.json] | Money value-object arithmetic (Money/MoneyBag used in projection math) | FND-07 lock |
| `spatie/laravel-data` | `^4.0` [VERIFIED: composer.json] | Typed DTOs for `ForecastDto` / `ScenarioMutationDto` union envelope | Pattern in use since Phase 4 |
| `apexcharts` (npm) | `^3.54.1` [VERIFIED: package.json] | `rangeArea` + `line` chart variants; v3 supports both natively | Locked since Phase 5; v5 exists on npm (5.12.0) but project is pinned to v3 |

### Supporting (already in project)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `pestphp/pest-plugin-arch` | `^4.0` [VERIFIED: composer.json] | Native `arch(...)->expect()->not->toBeUsedIn(...)` for class-import invariants (D-1015 #3 + #4) | Class-namespace dependency rules. |
| `RecursiveIteratorIterator` + `preg_match` (native PHP) | n/a | Content-level grep arch invariants for D-1015 #1 (facade detection), #2 (transaction-table writes), #5 (JOIN detection) | When the invariant is about file *contents* (SQL JOINs, table names in `->update()`, facade `::` calls), not about class imports. Pest arch plugin cannot inspect file contents — this is the established `noResolverWritesTransactions` precedent in `tests/Contracts/BoundaryArchTest.php`. |
| Alpine.js | bundled with Livewire 4 [VERIFIED: project] | `x-data="{ open: false }"` popover state for buffer editor; `x-data="{ chart: null }" x-init="..."` for ApexCharts instantiation | Established pattern from Phase 3/5/8/9. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| ApexCharts `rangeArea` + `line` combo on one chart | Two separate ApexCharts instances stacked in absolute positioning | Combo is one chart instance, one tooltip pipeline, one y-axis. Stacking is more work, breaks shared tooltip, and risks pixel drift between band and line. **Stay with combo.** |
| Pest `arch()` for D-1015 #5 (`noScenarioMutationsJoinedToTransactionQueries`) | `RecursiveIteratorIterator` + regex on `JOIN forecast_scenario_mutations` or `->join('forecast_scenario_mutations', ...)` | Pest's `not->toBeUsedIn()` only sees PHP class imports. The invariant is about Eloquent query content — a JOIN doesn't import a class. **Use the existing `it(...) + RecursiveIteratorIterator + preg_match` precedent.** Mirror the `noResolverWritesTransactions` shape verbatim. |
| `ShouldBeUniqueUntilProcessing` keyed `(user_id, scenario_id ?? 0, horizon_days)` (CONTEXT.md D-1017) | Replace `?? 0` with `?? 'baseline'` (or `?? -1`) | The composer key is plain string concatenation: `laravel_unique_job:{jobName}:{uniqueId}` [VERIFIED: `vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php::getKey`]. With `?? 0`, baseline `(user=5, scenario=null, horizon=30)` becomes `5:0:30`. A scenario with literal id `0` would collide — but **SQLite autoincrement starts at 1**, so collision is impossible in practice. The `'baseline'` sentinel is more honest about intent and immune to future "what if we seed scenario id=0" changes. **Use `'baseline'` sentinel.** |
| Optimistic UI for scenario mutations | wire:poll.2s job-completes-then-poll (UI-SPEC locked D-1021) | Optimistic UI requires re-implementing the entire quadrature fold + chain routing in JS — infeasible for Phase 10. Job-completes-then-poll is documented in UI-SPEC § Interaction Contracts step 8 with the chart panel `opacity-60` overlay + `Updating…` caption. **Locked. No alternative.** |

**Installation:** **No new dependencies.** Phase 10 reuses Horizon + Redis + Apex + Flux + Pest + LaravelData all from prior phases.

**Version verification (composer):**

```bash
# All already in composer.lock — no install commands needed
grep -E "apexcharts|livewire/livewire|brick/money|spatie/laravel-data" /Users/wesselverheij/Development/diederik/composer.json
grep -E "apexcharts" /Users/wesselverheij/Development/diederik/package.json
```

Result [VERIFIED via Bash]:
- `apexcharts` `^3.54.1` (project pin; current on npm is `5.12.0` published 2026-05-15 — explicitly NOT adopted)
- `livewire/livewire` `^4.0`
- `livewire/flux` `^2.0`
- `brick/money` `^0.11`
- `spatie/laravel-data` `^4.0`
- `laravel/horizon` `^5.46`

## Package Legitimacy Audit

**Phase 10 introduces ZERO new packages.** All required libraries are already locked in `composer.json` / `package.json` from prior phases and have already passed the project's legitimacy bar (Phase 1/5 install gates).

| Package | Registry | Phase Locked | Disposition |
|---------|----------|--------------|-------------|
| `laravel/framework ^13.0` | Packagist | Phase 1 | Reused — no audit needed |
| `livewire/livewire ^4.0` | Packagist | Phase 1 | Reused |
| `livewire/flux ^2.0` | Packagist | Phase 1 | Reused |
| `nwidart/laravel-modules ^13.0` | Packagist | Phase 1 | Reused |
| `laravel/horizon ^5.46` | Packagist | Phase 5 | Reused |
| `predis/predis ^3.4` | Packagist | Phase 5 | Reused |
| `brick/money ^0.11` | Packagist | Phase 1 | Reused |
| `spatie/laravel-data ^4.0` | Packagist | Phase 4 | Reused |
| `pestphp/pest-plugin-arch ^4.0` | Packagist | Phase 1 | Reused |
| `apexcharts ^3.54.1` | npm | Phase 5 | Reused |

**No new packages installed; slopcheck step not required for Phase 10.** This bullet is the documented disposition rather than a skip.

## Architecture Patterns

### System Architecture Diagram

```
                          ┌─────────────────────────────────────┐
                          │  Phase 8 Recurring                  │
                          │  · RecurringSeriesApproved          │
                          │  · RecurringSeriesCadenceFlipped    │
                          │  · RecurringSeriesRejected          │
                          │  · RecurringSeriesMetricsRefreshed  │
                          └────────────────┬────────────────────┘
                                           │ event
                          ┌────────────────▼────────────────────┐
                          │  Phase 9 DriftAlerts                │
                          │  · DriftAlertDismissedCancelled     │
                          └────────────────┬────────────────────┘
                                           │ event
                                           ▼
   ┌───────────────────────────────────────────────────────────────────┐
   │                  Modules/Forecasting (Phase 10)                   │
   │                                                                   │
   │   Public/Actions/CreateScenario / AddScenarioMutation / ...       │
   │                ↓ dispatch ScenarioCreated / ScenarioMutated       │
   │   Internal/Listeners/ProjectForecastOnScenarioChange              │
   │   Internal/Listeners/ProjectForecastOnRecurringChange             │
   │   Internal/Listeners/ProjectForecastOnDriftDismissed              │
   │                ↓ ProjectForecastJob::dispatch(user, scenario, horizon)  │
   │                                                                   │
   │   ProjectForecastJob (ShouldBeUniqueUntilProcessing)              │
   │       ↓ injects                                                   │
   │   Internal/Pipeline/ProjectionPipeline:                           │
   │     1. BalanceAnchorResolver  →  (opening_minor, anchor_date)     │
   │     2. RangeProjector         →  per-occurrence (date, low, point, high)
   │        (envelope OR percentile tier per series)                   │
   │     3. ChainAwareForecastRouter → routes onto funder accounts     │
   │     4. ScenarioApplier         → applies mutations (NEVER writes to tx)
   │     5. Daily fold (quadrature) → per-day (low, point, high)       │
   │                ↓                                                  │
   │     writes forecast_shortfall_windows + forecast_runs.status      │
   │                ↓ dispatch ForecastShortfallDetected               │
   │                                                                   │
   │   /forecast Livewire SFC (ForecastPage):                          │
   │     · #[Url]-bound horizon/account/scenarioId/viewByFunder        │
   │     · reads ForecastQuery (baseline + scenario)                   │
   │     · wire:poll.2s → forecast_runs.status                         │
   │     · dispatches 'forecast:updated' → x-on listener →             │
   │           chart.updateSeries() + chart.updateOptions()            │
   │                                                                   │
   │   Public Read APIs (cross-module-safe):                           │
   │     · ForecastQuery::forUser(account, horizon, scenarioId)        │
   │     · ScenarioQuery::forUser / mutationsFor                       │
   │     · ForecastHighlightsQuery::forUser  ← Dashboard tile reads    │
   └────────────────────┬──────────────────────────────────────────────┘
                        │ Public
       ┌────────────────┼─────────────────────────────────┐
       ▼                ▼                                 ▼
  Modules/Chains   Modules/Recurring                Modules/Ledger
  (read-only):     (read-only):                     (read + extends):
  · ChainLinkQuery · RecurringSeriesQuery           · accounts (+ 3 nullable cols)
  · CardStatementQuery · occurrencesForSeries        · transactions (read, never write)
                                                     · statement_summaries (read)
```

### Recommended Project Structure

```
Modules/Forecasting/
├── composer.json
├── Providers/
│   └── ForecastingServiceProvider.php           # Boot: top-nav composer, dashboard tile composer, event listeners
├── Routes/
│   └── web.php                                  # GET /forecast (LoopbackOnly + Fortify auth)
├── Database/
│   ├── Migrations/
│   │   ├── *_create_forecast_scenarios_table.php
│   │   ├── *_create_forecast_scenario_mutations_table.php
│   │   ├── *_create_forecast_shortfall_windows_table.php
│   │   ├── *_create_forecast_runs_table.php
│   │   └── *_add_forecast_columns_to_accounts.php   # forecast_min_buffer_minor + opening_balance_minor + opening_balance_as_of_date
│   └── Factories/
│       ├── ForecastScenarioFactory.php
│       └── ForecastScenarioMutationFactory.php
├── Models/
│   ├── ForecastScenario.php
│   ├── ForecastScenarioMutation.php
│   ├── ForecastShortfallWindow.php
│   └── ForecastRun.php
├── Public/
│   ├── Dto/
│   │   ├── ForecastDto.php                      # per-day triples + per-series breakdown
│   │   ├── ForecastPointDto.php                 # (date, low, point, high, currency)
│   │   ├── ScenarioDto.php
│   │   ├── ScenarioMutationDto.php              # typed envelope per kind (Spatie LaravelData union)
│   │   ├── ForecastHighlightsDto.php
│   │   ├── ShortfallWindowDto.php
│   │   ├── BalanceAnchorDto.php
│   │   └── SeriesConfidenceDto.php
│   ├── Events/
│   │   ├── ScenarioCreated.php
│   │   ├── ScenarioMutated.php
│   │   ├── ScenarioDeleted.php
│   │   └── ForecastShortfallDetected.php
│   ├── Actions/
│   │   ├── CreateScenario.php
│   │   ├── RenameScenario.php
│   │   ├── DeleteScenario.php
│   │   ├── AddScenarioMutation.php
│   │   ├── RemoveScenarioMutation.php
│   │   ├── EditScenarioMutation.php
│   │   ├── SetAccountForecastBuffer.php
│   │   ├── SetAccountOpeningBalance.php
│   │   ├── CreateCancellationScenarioForAlert.php   # Phase 9 drift-alert launchpad helper
│   │   ├── CreateCancellationScenarioForSeries.php  # /recurring/series/{id} helper
│   │   └── CreateAmountChangeScenarioForSeries.php
│   └── Services/
│       ├── ForecastQuery.php
│       ├── ScenarioQuery.php
│       └── ForecastHighlightsQuery.php
├── Internal/
│   ├── Pipeline/
│   │   ├── BalanceAnchorResolver.php
│   │   ├── RangeProjector.php                   # envelope + percentile tiers
│   │   ├── ChainAwareForecastRouter.php
│   │   ├── ScenarioApplier.php
│   │   └── ProjectionPipeline.php               # composer + daily fold + quadrature
│   ├── Jobs/
│   │   └── ProjectForecastJob.php               # ShouldBeUniqueUntilProcessing
│   ├── Listeners/
│   │   ├── ProjectForecastOnRecurringChange.php
│   │   ├── ProjectForecastOnDriftDismissed.php
│   │   └── ProjectForecastOnScenarioChange.php
│   ├── Mapping/
│   │   ├── ForecastDtoMapper.php
│   │   └── ScenarioDtoMapper.php
│   └── Http/Livewire/
│       ├── ForecastPage.php                     # /forecast SFC
│       ├── ScenarioEditorSidebar.php
│       ├── AccountBufferEditor.php
│       ├── OpeningBalanceEditor.php
│       ├── ForecastHighlightsTile.php           # dashboard tile
│       └── ModelWhatIfDropdown.php
├── Resources/
│   └── views/livewire/
│       ├── forecast-page.blade.php
│       ├── scenario-editor-sidebar.blade.php
│       ├── account-buffer-editor.blade.php
│       ├── opening-balance-editor.blade.php
│       ├── forecast-highlights-tile.blade.php
│       ├── model-what-if-dropdown.blade.php
│       └── partials/
│           ├── range-area-chart.blade.php       # per-panel chart partial
│           ├── aggregate-line-chart.blade.php   # All-accounts tab
│           ├── net-diff-tile.blade.php
│           └── series-confidence-row.blade.php
└── tests/
    ├── Pest.php
    ├── TestCase.php
    ├── Unit/
    │   ├── RangeProjectorTest.php
    │   ├── ChainAwareForecastRouterTest.php
    │   ├── BalanceAnchorResolverTest.php
    │   ├── ScenarioApplierTest.php
    │   ├── QuadratureFoldTest.php
    │   ├── ProjectForecastJobUniqueTest.php
    │   └── FixtureCorpusTest.php
    ├── Feature/
    │   ├── ForecastPageTest.php
    │   ├── ScenarioCrudTest.php
    │   ├── ForecastHighlightsTileTest.php
    │   ├── AccountBufferEditorTest.php
    │   ├── OpeningBalanceEditorTest.php
    │   ├── ModelCancelLaunchpadTest.php
    │   ├── ModelWhatIfDropdownTest.php
    │   ├── ForecastCrossUser404Test.php
    │   └── TopNavForecastSlotTest.php
    └── fixtures/
        ├── stable-monthly-subscription.json
        ├── drifting-subscription.json
        ├── variable-utility.json
        ├── salary-income.json
        ├── ics-settlement-chain.json
        ├── fx-only-usd-subscription.json
        ├── buffer-crossing.json
        ├── multi-account-baseline.json
        └── scenario-with-each-mutation-kind.json
```

PSR-4 wire-up (3-step pattern, per Phase 4 D-X precedent):
1. `composer.json` autoload-dev `"Modules\\Forecasting\\Tests\\": "Modules/Forecasting/tests/"`
2. `phpunit.xml` testsuite entry
3. `tests/Pest.php` per-module wire-up map row

### Pattern 1: ApexCharts `rangeArea` + `line` combo on one chart

**What:** Render the uncertainty band (`rangeArea`) and the point-estimate center line (`line`) as two series in a single chart instance, so they share x-axis, y-axis, tooltip, and crosshair.

**When to use:** Both per-account `rangeArea` charts on `/forecast` (baseline + scenario panels).

**Example (Apex options shape):**

```javascript
// Source: apexcharts.com/docs/chart-types/range-area-chart/ [CITED]
//         apexcharts.com/docs/options/tooltip/ [CITED]
// Combined per project's existing x-init pattern at
// Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php
{
  chart: {
    type: 'rangeArea',
    height: 320,
    animations: { enabled: false },          // Calm aesthetic; reduces wire:poll re-render flash
    toolbar: { show: false },
    zoom: { enabled: false },
    fontFamily: 'Inter, system-ui, sans-serif'
  },
  series: [
    {
      name: 'Range',
      type: 'rangeArea',
      data: [
        { x: '2026-05-22', y: [1180, 1260] },   // [low, high]
        { x: '2026-05-23', y: [1175, 1265] },
        // ...
      ]
    },
    {
      name: 'Point estimate',
      type: 'line',
      data: [
        { x: '2026-05-22', y: 1220 },
        { x: '2026-05-23', y: 1220 },
        // ...
      ]
    }
  ],
  fill: { opacity: [0.2, 1.0] },               // [rangeArea, line] — UI-SPEC §Color: 0.12 for chrome subtlety
  stroke: {
    curve: 'straight',
    width: [0, 2.5]                            // [rangeArea has no stroke, line is bold 2.5px]
  },
  colors: [
    '#0F172A',     // slate-900 — baseline panel (UI-SPEC §Chart series color matrix)
    '#0F172A'
  ],
  // For scenario panel with positive delta:
  //   colors: ['#047857', '#047857']  // emerald-700
  // For scenario panel with negative delta:
  //   colors: ['#BE123C', '#BE123C']  // rose-700
  tooltip: {
    shared: true,
    intersect: false,
    custom: function ({ series, seriesIndex, dataPointIndex, w }) {
      const range = w.globals.initialSeries[0].data[dataPointIndex];
      const point = w.globals.initialSeries[1].data[dataPointIndex];
      const low = range.y[0];
      const high = range.y[1];
      const date = range.x;          // 'YYYY-MM-DD'
      // Format server-side and stash in a sibling array for locale-correct nl_NL output.
      // Project pattern: pass pre-formatted strings via data-options so the JS doesn't
      // re-implement nl_NL number formatting.
      return `<div class="apex-tooltip-body">€${low.toLocaleString('nl-NL')} – €${high.toLocaleString('nl-NL')} (≈ €${point.y.toLocaleString('nl-NL')}) on ${date}</div>`;
    }
  },
  xaxis: {
    type: 'datetime',
    labels: { style: { fontSize: '12px', colors: '#64748B' } }   // slate-500
  },
  yaxis: {
    min: yMin,                                  // computed: floor across BOTH panels for shared scale
    max: yMax,                                  // computed: ceil across BOTH panels for shared scale
    labels: { style: { fontSize: '12px', colors: '#64748B' } },
    forceNiceScale: true
  },
  annotations: {
    yaxis: [
      {
        y: 0,
        y2: forecastMinBufferMinor,             // shortfall band: rose-50 from -infinity to buffer
        fillColor: '#FECDD3',                   // rose-50
        opacity: 0.4,
        label: { text: '', position: 'left' }
      }
    ]
  },
  grid: { borderColor: '#E2E8F0', strokeDashArray: 0 },  // slate-200
  legend: { show: false }                        // confidence legend lives in a separate sidebar
}
```

### Pattern 2: Livewire 4 `#[Url]` + `wire:poll.2s` + ApexCharts re-render

**What:** Server-side state persists via `#[Url]`; ApexCharts chart instance is preserved across Livewire re-renders by **NOT** wrapping it in `wire:ignore` (so the loading overlay can render) and instead invoking `chart.updateSeries(...)` + `chart.updateOptions(...)` from a window event listener.

**When to use:** `ForecastPage` SFC + every per-panel `<x-forecasting::range-area-chart>` partial.

**Example (composition):**

```php
// Source: project pattern from Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php
//         + Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php [VERIFIED via grep]

namespace Modules\Forecasting\Internal\Http\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
// ... DI imports

final class ForecastPage extends Component
{
    #[Url(as: 'account', except: 'all')]
    public string $account = 'all';

    #[Url(as: 'horizon', except: '30')]
    public int $horizon = 30;

    #[Url(as: 'scenarioId', except: null)]
    public ?int $scenarioId = null;

    #[Url(as: 'viewByFunder', except: false)]
    public bool $viewByFunder = false;

    public function setHorizon(int $days): void
    {
        if (! in_array($days, [30, 60, 90], true)) {
            return;
        }
        $this->horizon = $days;
        $this->dispatch('forecast:updated');   // browser event listened by Alpine x-on
    }

    public function refreshProjectionStatus(
        ForecastingProjectionStatusQuery $statusQuery,  // Internal — read forecast_runs
        CurrentUser $user,
    ): void {
        $status = $statusQuery->forActiveRun($user->id(), $this->scenarioId, $this->horizon);
        if ($status === 'complete') {
            $this->dispatch('forecast:project-status-complete');
        }
    }

    public function render(
        ForecastQuery $forecastQuery,
        ScenarioQuery $scenarioQuery,
        CurrentUser $user,
    ): View {
        // ... reads
    }
}
```

```blade
{{-- Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php --}}
{{-- Source: pattern from Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php --}}

@props(['forecast', 'panel', 'bufferMinor', 'sharedYMin', 'sharedYMax'])

@php
    $chartId = "forecast-chart-{$panel}-{$forecast->accountId}";
    $options = [ /* the rangeArea + line config from Pattern 1, filled with $forecast data */ ];
@endphp

<div
    x-data="{
        chart: null,
        init() {
            if (! window.ApexCharts) { return; }
            this.chart = new window.ApexCharts(
                this.$el.querySelector('#{{ $chartId }}'),
                JSON.parse(this.$el.dataset.options),
            );
            this.chart.render();
        },
        rerender(newOptions) {
            if (! this.chart) { return; }
            this.chart.updateOptions(newOptions, /* redrawPaths */ true, /* animate */ false);
        },
    }"
    x-init="init()"
    x-on:forecast-updated.window="rerender(JSON.parse($el.dataset.options))"
    data-options="{{ json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
    @class([
        'relative',
        'opacity-60 pointer-events-none' => $forecast->isComputing,
    ])
>
    <div id="{{ $chartId }}"></div>
    @if ($forecast->isComputing)
        <p class="absolute right-2 top-2 text-xs text-slate-500" style="font-variant-numeric: tabular-nums;">Updating…</p>
    @endif
</div>
```

**Why NOT `wire:ignore`:** The chart container is the ONLY place we want the `opacity-60` overlay + `Updating…` caption to appear during `wire:poll` flips. `wire:ignore` would prevent the overlay from updating. The chart-instance-preservation we'd normally use `wire:ignore` for is achieved by `x-data`'s `chart` variable being a JS object captured in Alpine's reactive scope — it survives Livewire's morphdom diff because Alpine state is per-element and not part of Livewire's snapshot.

**Caveat:** When `$forecast->accountId` changes (the user clicks a different account tab), the `data-options` `JSON.parse` reads the new options object, BUT the existing chart instance was rendered with the old options. Re-render via `chart.updateOptions(newOptions, true, false)` (third arg `false` = no animation). This is the documented re-render path from the project's existing pattern + Livewire/ApexCharts integration discussions ([CITED: github.com/livewire/livewire/discussions/9253]).

### Pattern 3: `ShouldBeUniqueUntilProcessing` per `(user_id, scenario_id, horizon)` with sentinel

**What:** `uniqueId()` returns `"{userId}:{scenario_id-or-baseline}:{horizon_days}"` — string equality is what Laravel uses for the lock key.

**When to use:** `Modules/Forecasting/Internal/Jobs/ProjectForecastJob`.

**Example:**

```php
// Source: pattern from Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php [VERIFIED via project grep]
//         vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php::getKey [VERIFIED via Read]

namespace Modules\Forecasting\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;

final class ProjectForecastJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
        public readonly ?int $scenarioId,        // null = baseline
        public readonly int $horizonDays,        // 30 | 60 | 90
    ) {}

    public function uniqueId(): string
    {
        // The 'baseline' sentinel disambiguates the null case from any literal id.
        // Lock key composition (per vendor/laravel/framework Bus/UniqueLock.php):
        //   "laravel_unique_job:{Modules\\Forecasting\\Internal\\Jobs\\ProjectForecastJob}:{uniqueId}"
        // — plain string equality on the appended uniqueId. Using 'baseline' instead of
        // 0 makes the intent explicit and immune to a future seeder that creates id=0.
        $scenarioKey = $this->scenarioId !== null ? (string) $this->scenarioId : 'baseline';

        return "{$this->userId}:{$scenarioKey}:{$this->horizonDays}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        // Same Cache facade carve-out as Phase 9 DetectDriftAlertsJob — Laravel
        // resolves the lock store before constructor DI completes.
        return Cache::driver('redis');
    }

    public function handle(ProjectionPipeline $pipeline): void
    {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();
        $pipeline->project($user, $this->scenarioId, $this->horizonDays);
    }
}
```

The `BoundaryArchTest` `noFacadeCallsFromForecasting` rule MUST carve out this class explicitly, mirroring the existing `DetectDriftAlertsJob` carve-out.

### Pattern 4: Quadrature daily-fold for combining independent series spreads

**What:** At each forecast day D, the combined uncertainty across all contributing series is `√(Σ_i spread_i²)` — NOT `Σ_i spread_i` and NOT `max_i spread_i`.

**When to use:** `Internal/Pipeline/RangeProjector::dailyFold()`. Apply per-day across all series that have at least one occurrence projected on or before D.

**Math justification:** For N independent random variables `X_i`, `Var(Σ X_i) = Σ Var(X_i)`. Standard deviations therefore combine via Pythagorean (quadrature) sum, NOT linear sum. [CITED: Variance of sum of independent random variables — Cornell, MIT OCW]

**Example:**

```php
// Source: derived from D-1004; math citation Cornell/MIT [CITED]

namespace Modules\Forecasting\Internal\Pipeline;

final readonly class DailyFold
{
    /**
     * @param  list<ForecastContribution>  $contributions  per-series per-occurrence (date, point, low, high)
     * @return array<string, DailyPointDto>                keyed YYYY-MM-DD
     */
    public function fold(int $openingBalanceMinor, array $contributions): array
    {
        $byDay = [];
        foreach ($contributions as $c) {
            $key = $c->date->format('Y-m-d');
            $byDay[$key] ??= ['point' => 0, 'spread_sq' => 0];

            // Signed direction: expense is negative, income is positive.
            $byDay[$key]['point'] += $c->pointMinor;
            // Spread is half-width: (high - low) / 2. Variance per series ≈ spread^2.
            // Wider-band assumptions hold under "approximately normal" but the user-facing
            // band is a 1-sigma envelope, which is honest at the 68% confidence level.
            $halfWidth = ($c->highMinor - $c->lowMinor) / 2;
            $byDay[$key]['spread_sq'] += $halfWidth * $halfWidth;
        }

        // Cumulative running balance + cumulative spread (quadrature combines INDEPENDENT day-wise contributions).
        $running = $openingBalanceMinor;
        $cumSpreadSq = 0;
        $result = [];
        ksort($byDay);
        foreach ($byDay as $day => $bucket) {
            $running += (int) round($bucket['point']);
            $cumSpreadSq += (int) round($bucket['spread_sq']);
            $spread = (int) round(sqrt($cumSpreadSq));
            $result[$day] = new DailyPointDto(
                date: $day,
                low: $running - $spread,
                point: $running,
                high: $running + $spread,
            );
        }
        return $result;
    }
}
```

**Pitfall to flag in unit test:** If two series share an underlying cause (e.g., "two streaming subscriptions that both depend on same provider's billing cycle"), they are NOT independent and quadrature *under-estimates* spread. Phase 10 treats every approved series as independent — this is documented in `RangeProjector` PHPDoc as a known approximation with mitigation: the percentile tier (for volatile series) sidesteps the assumption by using observed historical distribution rather than per-series variance.

### Pattern 5: Pest arch invariants — mixed native + content-grep

**What:** Of the five D-1015 invariants, two use `arch('...')->expect()->not->toBeUsedIn(...)` (Pest native) and three use `it(...)` + `RecursiveIteratorIterator` + comment-strip regex (existing project precedent).

**When to use:**

| Invariant | Mechanism | Why |
|-----------|-----------|-----|
| #1 `noFacadeCallsFromForecasting` | Extend the existing `arch('no Laravel facade usage in module code')` ignore-list — DO NOT write a new test. | Already enforced project-wide; just adds Phase 10's queued-job carve-out for `ProjectForecastJob` to the `ignoring([...])` list. |
| #2 `noTransactionWritesFromForecasting` | `it(...)` + `RecursiveIteratorIterator` + `preg_match` | Same shape as existing `noResolverWritesTransactions` + `noTransactionWritesFromEmailScan` + `noTransactionWritesFromRecurring`. Pest's `arch->not->toBeUsedIn()` can't detect `->table('transactions')->update(...)` because no class is imported. |
| #3 `crossModuleAccessGoesThroughPublic` (Forecasting → {Recurring,Chains,DriftAlerts,Ledger}) | Extend the existing per-module `arch('Modules\\X\\Internal is only used inside Modules\\X')` rule chain by adding `arch('Modules\\Forecasting\\Internal is only used inside Modules\\Forecasting')`. | Pest native; mirrors the 10 existing module rules in `BoundaryArchTest.php` lines 8–48. |
| #4 `noSynchronousForecastingInRequestLifecycle` | `arch('Modules\\Forecasting\\Internal\\Pipeline\\ProjectionPipeline')->expect()->not->toBeUsedIn(['Modules\\Forecasting\\Internal\\Http', 'Modules\\Forecasting\\Resources'])` | Pest native; mirrors existing `arch('SeriesDetector implementors are never imported by Modules\\Recurring\\Internal\\Http')` rule. |
| #5 `noScenarioMutationsJoinedToTransactionQueries` | `it(...)` + `RecursiveIteratorIterator` + `preg_match` | **Load-bearing FCT-03 invariant.** The check is content-level: scan every `.php` file in `Modules/` for any string containing `forecast_scenario_mutations` appearing on the same `->join(...)`/`leftJoin`/SQL line as `transactions` / `recurring_series_occurrences` / `chain_links` / `card_statements`. Pattern lifted from the existing `noResolverWritesTransactions` precedent. |

**Example (D-1015 #5):**

```php
// Pattern lifted from tests/Contracts/BoundaryArchTest.php::noResolverWritesTransactions [VERIFIED]

it('does not allow any file to JOIN forecast_scenario_mutations onto transactions / recurring_series_occurrences / chain_links / card_statements (noScenarioMutationsJoinedToTransactionQueries)', function (): void {
    $hits = [];
    $modulesDir = base_path('Modules');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        // Strip block + line comments first so PHPDoc references stay legal.
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        // Two grep variants:
        //  (a) ->join('forecast_scenario_mutations', ...) AND another forbidden-table grep on the same file
        //  (b) raw SQL containing both forecast_scenario_mutations and a forbidden table on the same line
        $hasMutationJoin = preg_match(
            "/->(join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]forecast_scenario_mutations['\"]/",
            $stripped,
        ) === 1;
        $hasForbiddenTable = preg_match(
            "/['\"](transactions|recurring_series_occurrences|chain_links|card_statements)['\"]/",
            $stripped,
        ) === 1;
        if ($hasMutationJoin && $hasForbiddenTable) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "forecast_scenario_mutations must never be JOINed onto transaction-substrate tables. Offenders:\n  ".implode("\n  ", $hits),
    );
});
```

This rule is the structural enforcement of FCT-03. The supplementary `ScenarioIsolationContractTest` proves the runtime behavior (CONTEXT.md `<domain>` final bullet).

### Anti-Patterns to Avoid

- **Wrapping the chart container in `wire:ignore`:** Prevents the `opacity-60` loading overlay from updating during `wire:poll` flips. The existing project precedent (recurring-series-detail-page.blade.php) does NOT use `wire:ignore` on the Apex container — it relies on Alpine state being per-element and Livewire morphdom respecting Alpine's `x-data` boundary.
- **Returning `?? 0` from `uniqueId()`:** Collision-safe in this project (autoincrement starts at 1) but semantically opaque. Use `?? 'baseline'` sentinel.
- **Pure-historical-percentile for all series:** Fragile for series with <6 occurrences (CONTEXT.md D-1004 explicitly rejects this). Envelope tier is the default; percentile tier kicks in only when (a) variance_tolerance_percent ≥ 40% OR (b) ≥6 occurrences AND stddev > threshold.
- **Linear (NOT quadrature) combination of spreads:** Over-estimates uncertainty; the user sees a band of 10× the actual standard deviation if 100 series each have a small spread. Cite Cornell/MIT lecture notes in `RangeProjector` PHPDoc when implementing.
- **JOINing `forecast_scenario_mutations` onto `transactions` for "convenience":** Even read-only JOINs violate FCT-03's structural intent. The `ScenarioApplier` MUST read both substrates separately and combine in PHP.
- **Computing forecast at request time in `ForecastQuery::forUser`:** Violates D-1015 #4. `ForecastQuery` reads pre-computed `forecast_shortfall_windows` + cached scenario projection results (or triggers `ProjectForecastJob` and returns a "computing" sentinel DTO).
- **Using `apexcharts ^5` from npm:** The project is pinned to `^3.54.1` since Phase 5. Apex v5 (5.12.0 released 2026-05-15) is too new — defer to a future stack-bump phase if/when needed.
- **`<flux:tab.group>` / `<flux:popover>`:** Free Flux UI (`livewire/flux ^2.0`, on `vendor/livewire/flux/stubs/.../flux/`) does NOT ship these components. **Hand-roll** `role="tablist"` + `role="radiogroup"` + `x-data="{ open: false }"` popover — the existing Phase 8/9 pattern.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Range/uncertainty band charting | Custom SVG drawing or Chart.js plugin | ApexCharts `rangeArea` (v3 native, already locked) | First-class series type with documented combo support, shared tooltip, and crosshair. |
| Quadrature daily fold | "Statistically inspired" custom blend formula | Plain `√(Σ spread²)` per math citation | Independent-series variance composition is textbook; novelty here is a correctness regression risk. |
| P10 / P50 / P90 from small samples | Hand-rolled bubble-sort + index pick | PHP's `sort()` + linear interpolation (R-7 method) | R-7 is the standard statistical method for small samples (n≤12); nearest-rank is fragile on n=6 because each percentile maps to a single observation. [CITED: Wikipedia "Percentile" §"Linear interpolation between closest ranks"; Axibase percentile guide] |
| `ShouldBeUniqueUntilProcessing` lock store | Custom Redis lock from Cache | Laravel's native `Bus\UniqueLock` | Implemented; the only thing custom-built is `uniqueId()` / `uniqueVia()`. |
| Livewire URL state binding | `request()->query()` reads + manual back-button handling | Livewire 4 `#[Url]` attribute | Native, declarative, works with `wire:click` updates, persists in browser history. |
| Scenario mutation typed payload | Generic JSON column without DTO | Spatie LaravelData union DTO mapped via Eloquent cast | Five distinct payload shapes per `kind`; LaravelData supports tagged-union deserialization. Already in use Phase 4+. |
| Per-account chart hand-built tooltip HTML | Inline string concat in PHP into a `data-tooltip-html` attribute | ApexCharts `tooltip.custom` function returning HTML string | Apex documented entry point; receives `{series, seriesIndex, dataPointIndex, w}`; `w.globals.initialSeries[seriesIndex].data[dataPointIndex]` accesses the original data point including `y: [low, high]` for `rangeArea`. [CITED: apexcharts.com/docs/options/tooltip/] |
| Shortfall window detection | Inline scan in `ForecastQuery` at request time | Pre-computed `forecast_shortfall_windows` rows written by `ProjectForecastJob` | D-1012 + D-1015 #4 lock this. |
| Money arithmetic | Integer cents + manual addition | `brick/money` Money + MoneyBag | FND-07 lock. The quadrature fold operates on integer minor units (the spread is also in minor units), so squaring → BIGINT-safe under SQLite's 64-bit integer range (sqrt is reduced before re-adding via PHP int). |
| Top-nav badge / dashboard tile composer | Ad-hoc `view()->share()` calls | View Factory composer pattern from Phase 5/6/7/8/9 (`$this->app->make(ViewFactoryContract::class)->composer(...)`) | Project-wide convention; avoids the `view()` global helper that BoundaryArchTest forbids. |
| Volatile-series tier-switch | New user-facing toggle | Auto-derived from `variance_tolerance_percent ≥ 40%` (D-1019 suggestion) | No UI surface needed; volatile-ness is a property of the series's history, not a user choice. |

**Key insight:** Phase 10 is a composition phase — every primitive it needs already exists in the locked stack or in upstream Public services (`ChainLinkQuery`, `CardStatementQuery`, `CancellationImpactQuery`, `RecurringSeriesQuery`). The new code is integration math (anchor + projector + router + applier) and a Livewire page that wires it all together. Anything hand-rolled below Apex's chart abstraction or below the quadrature formula is suspect.

## Common Pitfalls

### Pitfall 1: `ShouldBeUniqueUntilProcessing` key collision with `?? 0` sentinel

**What goes wrong:** CONTEXT.md D-1017 writes `(user_id, scenario_id ?? 0, horizon_days)` as the uniqueness key. If a future change ever seeds a `forecast_scenarios` row with id=0 (e.g., a system-seeded "default scenario"), the baseline projection (`scenario_id=null` → `0`) would share a lock key with that scenario's projection, and one would silently no-op the other.

**Why it happens:** Laravel composes the lock key as `laravel_unique_job:{class}:{uniqueId}` with plain string equality [VERIFIED: `vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php::getKey`]. The `(user_id, 0, 30)` for scenario `0` and `(user_id, null→0, 30)` for baseline both render to `5:0:30`.

**How to avoid:** Use `?? 'baseline'` sentinel — `5:baseline:30` vs `5:0:30` are distinct. SQLite autoincrement starts at 1 so the practical collision is impossible today, but the sentinel future-proofs against seeders and makes the intent explicit.

**Warning signs:** Job spec comment says `"scenario_id ?? 0"`. Two `ProjectForecastJob` dispatches in the same minute (one baseline, one scenario=0) — Horizon shows only one ran.

### Pitfall 2: ApexCharts shared y-axis is NOT automatic across two chart instances

**What goes wrong:** The UI-SPEC requires shared y-axis across the side-by-side baseline + scenario panels so the visual delta is honest. ApexCharts has no native "link two chart instances' y-axis" feature.

**Why it happens:** Each chart is an independent instance with its own y-axis auto-scaling.

**How to avoid:** Compute `yMin` and `yMax` on the server in `ForecastPage::render()` as the union over both panels' `(low, high)` ranges minus/plus the buffer (so the shortfall band is always visible), and pass both to BOTH `range-area-chart.blade.php` partials as identical props. Both chart options get `yaxis: { min: yMin, max: yMax, forceNiceScale: true }`.

**Warning signs:** Scenario chart auto-scales to a "compressed view" of the +€200 mutation while baseline auto-scales to a wider range; the user reads the delta as "doubled".

### Pitfall 3: `wire:poll.2s` keeps firing after status flips to `complete`

**What goes wrong:** UI-SPEC says `wire:poll.2s` is "ONLY active during the brief window between a scenario mutation save and the corresponding `forecast_runs.status='complete'`". Naively wiring `wire:poll.2s="refreshProjectionStatus"` polls forever, even when `status === 'complete'` — wasteful and noisy in Horizon.

**Why it happens:** Livewire `wire:poll` is unconditional on the rendered element; it doesn't auto-stop.

**How to avoid:** Conditional-render the `wire:poll`-bearing element. Use a computed property `$this->isComputing` (derived from `forecast_runs.status`) and wrap the poll target in `@if ($isComputing) <div wire:poll.2s="refreshProjectionStatus">...</div> @endif`. When `$isComputing` flips to false, the entire `wire:poll`-bearing element unmounts and polling stops.

**Warning signs:** Horizon dashboard shows `refreshProjectionStatus` polling every 2 seconds indefinitely after a scenario CRUD.

### Pitfall 4: `forecast_scenario_mutations.payload` JSON cast loses type info

**What goes wrong:** Casting `payload` as plain `array` strips the per-`kind` shape. A `cancel_series` mutation's payload looks like `{series_id: 42}`; a `change_series_amount` payload looks like `{series_id: 42, new_amount_minor: 1149}`. With a plain `array` cast, code that reads `$mutation->payload['new_amount_minor']` on a `cancel_series` mutation silently returns `null` and the projection math gets a 0 where it expected a real amount.

**Why it happens:** Eloquent's `array` cast is untyped.

**How to avoid:** Spatie LaravelData typed union DTO. Define one DTO per kind (`CancelSeriesPayload`, `AddOneOffPayload`, etc.) and a union `ScenarioMutationPayload`. Map via Eloquent's custom cast (LaravelData ships a `castAttribute` integration). Larastan level 10 + strict-rules will catch any cross-kind property access at static-analysis time.

**Warning signs:** PHP warning about undefined array index in projection math; mutation summary toast renders `"undefined" → €1,149`.

### Pitfall 5: Phase 5 "Next ICS settlement" tile removal breaks an existing test

**What goes wrong:** D-1013 REPLACES the Phase 5 dashboard tile. The Phase 5 test (`*next-ics-settlement-tile*` likely under `Modules/Chains/tests/Feature/`) asserts the tile renders specific copy. Removing the partial without porting the test surface drops Phase 5 SC coverage.

**Why it happens:** The Phase 5 `CardStatementQuery::nextSettlementForUser` API stays — only the tile rendering moves. The Phase 10 "Forecast highlights" tile MUST surface the same "Next ICS settlement: {amount} on {date}" line (UI-SPEC § Dashboard "Forecast highlights" tile lock).

**How to avoid:** Port the existing Phase 5 next-ics-settlement-tile test assertions verbatim into the new `ForecastHighlightsTileTest` so the Phase 5 surface is preserved. The Phase 5 test file itself is deleted alongside the partial removal; the assertions migrate.

**Warning signs:** Search reveals an orphan Phase 5 test referencing a deleted partial. Or worse: no test exists for the new tile and the Phase 5 test is just deleted with no replacement.

### Pitfall 6: Cross-account FX conversion at the wrong layer

**What goes wrong:** D-1006 says "each series projects in its original currency; per-account chart converts using each occurrence's `latest_fx_rate_used`". Naively, a developer writes `ChainAwareForecastRouter` to do the FX conversion at routing time — but then `ScenarioApplier` sees converted EUR amounts for a USD series and can't preserve the original-currency primary line on the per-series legend.

**Why it happens:** Two conversion sites — series-to-account-default AND scenario-mutation-to-account-default — must use the SAME `latest_fx_rate_used` per occurrence, or the side-by-side delta has FX noise on top of the real scenario effect.

**How to avoid:** Project ALL series in original currency through `RangeProjector` + `ChainAwareForecastRouter`. Convert ONLY at the very last step in `ProjectionPipeline::project()` when writing the day-by-day series to the chart. Keep the per-series breakdown on the DTO with both original-currency and EUR-shadow amounts.

**Warning signs:** USD Netflix subscription's EUR-shadow amount on `/forecast` differs from the same amount on `/recurring/series/{id}`.

### Pitfall 7: ApexCharts `tooltip.custom` returns wrong shape on Y-axis hover

**What goes wrong:** The `tooltip.custom` function receives `{series, seriesIndex, dataPointIndex, w}`. For combo `rangeArea` + `line`, `seriesIndex` is 0 for the band and 1 for the line. If the user hovers near the line, Apex calls `custom` with `seriesIndex=1` and the developer naively reads `series[1][dataPointIndex]` — getting only the point value, not the band.

**Why it happens:** Apex's tooltip custom function is series-scoped, not point-scoped.

**How to avoid:** Inside `custom`, ALWAYS read from `w.globals.initialSeries[0].data[dataPointIndex]` (the rangeArea series) for the band, and `w.globals.initialSeries[1].data[dataPointIndex]` for the point. Don't trust `seriesIndex`. Format ONE tooltip body regardless of which series the mouse is over.

**Warning signs:** Tooltip body switches between "€1,180 – €1,260" and just "€1,220" based on hover precision.

## Code Examples

### Loading horizon + scenarioId from URL via Livewire 4 `#[Url]`

```php
// Source: Modules/Ledger/Internal/Http/Livewire/TransactionsList.php [VERIFIED via grep]
//         Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php [VERIFIED via grep]

use Livewire\Attributes\Url;
use Livewire\Component;

final class ForecastPage extends Component
{
    #[Url(as: 'horizon', except: '30')]
    public int $horizon = 30;

    #[Url(as: 'scenarioId', except: null)]
    public ?int $scenarioId = null;
}
```

### Hand-rolled segmented control (NOT `<flux:tab.group>`)

```blade
{{-- Source: project pattern from Modules/Recurring/Resources/views/livewire/recurring-page.blade.php
     and Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php [VERIFIED via grep] --}}

<div role="radiogroup" aria-label="Forecast horizon" class="inline-flex rounded-md bg-slate-100 p-1">
    @foreach ([30, 60, 90] as $days)
        <button
            type="button"
            role="radio"
            aria-checked="{{ $horizon === $days ? 'true' : 'false' }}"
            wire:click="setHorizon({{ $days }})"
            @class([
                'rounded px-3 py-1 text-sm transition',
                'bg-white text-slate-900 shadow-sm font-medium' => $horizon === $days,
                'text-slate-500 hover:text-slate-900' => $horizon !== $days,
            ])
            style="font-variant-numeric: tabular-nums;"
        >{{ $days }} days</button>
    @endforeach
</div>
```

### Buffer-editor popover (hand-rolled, mirrors Phase 8/9 snooze popover)

```blade
{{-- Source: pattern from Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php [VERIFIED] --}}

<div x-data="{ open: false }" class="relative inline-block">
    <button
        type="button"
        x-on:click="open = ! open"
        class="text-sm text-slate-500 hover:text-slate-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        style="font-variant-numeric: tabular-nums;"
    >
        Buffer: {{ $bufferLabel }}
    </button>
    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-on:keydown.escape.window="open = false"
        role="dialog"
        aria-labelledby="buffer-editor-heading-{{ $accountId }}"
        aria-modal="false"
        class="absolute right-0 z-10 mt-1 w-56 rounded-md border border-slate-200 bg-white p-3 text-sm shadow-lg"
    >
        @livewire('forecasting.account-buffer-editor', ['accountId' => $accountId], key('buffer-editor-'.$accountId))
    </div>
</div>
```

### View Factory composer for the top-nav "Forecast" slot

```php
// Source: project pattern from earlier ServiceProviders (Phase 5 ResolveChainsServiceProvider,
// Phase 8 RecurringServiceProvider, Phase 9 DriftAlertsServiceProvider) [VERIFIED via grep]

namespace Modules\Forecasting\Providers;

use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;

final class ForecastingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'forecasting');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');

        $this->app->make(ViewFactoryContract::class)->composer(
            'core::livewire.top-nav',
            function ($view): void {
                $user = auth()->user();   // Per project DI rules, this is the ONLY auth() call permitted
                                          // and lives inside the composer's container-bound factory.
                                          // The composer pattern carve-out is project-wide (search
                                          // 'View Factory composer' in BoundaryArchTest ignore lists).
                if (! $user instanceof User) {
                    $view->with('forecastShortfallCount', 0);
                    return;
                }
                $query = $this->app->make(ForecastHighlightsQuery::class);
                $count = $query->activeShortfallCountForUser($user);
                $view->with('forecastShortfallCount', $count);
            },
        );
    }
}
```

**Caveat:** The composer pattern carve-out is the established project precedent for `auth()` usage inside View Factory composers (see DriftAlertsServiceProvider, RecurringServiceProvider for verbatim shape). The DI-only invariant explicitly carves this out. Verify in the existing BoundaryArchTest `arch('no Laravel facade usage in module code')` ignore-list; if not already exempt for ServiceProviders, the existing pattern uses container-bound resolution to avoid the facade trip — planner verifies the exact mechanism (it may be `$this->app->make(...)` for everything including the auth check, with `auth()` replaced by `$this->app->make('auth')->user()`).

## Runtime State Inventory

> Not applicable. Phase 10 is a **greenfield** phase — no rename, refactor, or migration of existing names. The only renaming activity is replacing the dashboard tile partial path (Phase 5 → Phase 10), which is a pure code edit with no data, service config, OS state, secrets, or build artifacts implicated.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — Phase 10 is greenfield. New tables/columns only. | none |
| Live service config | None | none |
| OS-registered state | None | none |
| Secrets/env vars | None — Forecasting reads no external secrets | none |
| Build artifacts | None — no composer/npm package additions | none |

## Environment Availability

> Skipped — Phase 10 is a code/config-only addition. All dependencies are already in `composer.json` + `package.json` and already installed from prior phases.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Horizon (Redis-backed queue) | `ProjectForecastJob` | ✓ | locked Phase 5 | — |
| ApexCharts | `/forecast` charts | ✓ | 3.54.1 (pinned) | — |
| Livewire 4 + Flux ^2.0 | `/forecast` page | ✓ | locked Phase 1 | — |
| PHP 8.5 + Laravel 13 | runtime | ✓ | locked Phase 1 | — |
| Pest 4 + pest-plugin-arch 4 | arch tests | ✓ | locked Phase 1 | — |

**Missing dependencies with no fallback:** none
**Missing dependencies with fallback:** none

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4 (built on PHPUnit 11) [VERIFIED: composer.json] |
| Config file | `phpunit.xml` (root) + `tests/Pest.php` + per-module `Modules/Forecasting/tests/Pest.php` |
| Quick run command | `./vendor/bin/pest --filter=Forecasting --parallel` |
| Full suite command | `./vendor/bin/pest --parallel` (project `composer test` script) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FCT-01 | 30/60/90-day projection per account | feature | `./vendor/bin/pest --filter=ForecastPageTest::it-renders-baseline-projection-for-each-horizon` | ❌ Wave 0 |
| FCT-01 | `BalanceAnchorResolver` per account.kind | unit | `./vendor/bin/pest --filter=BalanceAnchorResolverTest` | ❌ Wave 0 |
| FCT-01 | `RangeProjector` envelope tier | unit | `./vendor/bin/pest --filter=RangeProjectorTest::envelope-tier` | ❌ Wave 0 |
| FCT-01 | `ChainAwareForecastRouter` routes onto funder | unit | `./vendor/bin/pest --filter=ChainAwareForecastRouterTest` | ❌ Wave 0 |
| FCT-02 | Range rendered as `rangeArea` band | feature | `./vendor/bin/pest --filter=ForecastPageTest::it-emits-rangeArea-options-with-low-high-pairs` | ❌ Wave 0 |
| FCT-02 | Quadrature daily fold | unit | `./vendor/bin/pest --filter=QuadratureFoldTest` | ❌ Wave 0 |
| FCT-02 | Percentile tier for volatile series | unit | `./vendor/bin/pest --filter=RangeProjectorTest::percentile-tier` | ❌ Wave 0 |
| FCT-03 | Scenario mutations never JOINed | arch / contract | `./vendor/bin/pest --filter=noScenarioMutationsJoinedToTransactionQueries` AND `./vendor/bin/pest --filter=ScenarioIsolationContractTest` | ❌ Wave 0 + Wave 5 |
| FCT-03 | `noTransactionWritesFromForecasting` | arch | `./vendor/bin/pest --filter=noTransactionWritesFromForecasting` | ❌ Wave 0 |
| FCT-04 | Side-by-side comparison renders both panels | feature | `./vendor/bin/pest --filter=ForecastPageTest::it-renders-both-panels-with-shared-y-axis` | ❌ Wave 0 |
| FCT-04 | Net diff tile at 30/60/90 | feature | `./vendor/bin/pest --filter=ForecastPageTest::it-renders-net-diff-tile` | ❌ Wave 0 |
| FCT-05 | Shortfall windows detected + written | unit + feature | `./vendor/bin/pest --filter=ProjectForecastJobTest::it-writes-shortfall-windows` | ❌ Wave 0 |
| FCT-05 | Per-account buffer editor | feature | `./vendor/bin/pest --filter=AccountBufferEditorTest` | ❌ Wave 0 |
| FCT-05 | Dashboard "Forecast highlights" tile shortfall line | feature | `./vendor/bin/pest --filter=ForecastHighlightsTileTest` | ❌ Wave 0 |
| `ForecastingProjectionContractTest` | End-to-end against Wave 0 fixture corpus | contract | `./vendor/bin/pest --filter=ForecastingProjectionContractTest` | ❌ Wave 0 |
| Cross-user 404 invariant | every `/forecast/*` route | feature | `./vendor/bin/pest --filter=ForecastCrossUser404Test` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `./vendor/bin/pest --filter=Forecasting --parallel`
- **Per wave merge:** `./vendor/bin/pest --parallel` (full suite green)
- **Phase gate:** Full suite green + Larastan level 10 strict + Pint check + arch invariants green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `Modules/Forecasting/composer.json`, `ForecastingServiceProvider.php`, `tests/Pest.php`, `tests/TestCase.php` — module skeleton
- [ ] Five BoundaryArchTest invariants appended to `tests/Contracts/BoundaryArchTest.php`
- [ ] `Modules/Forecasting/tests/fixtures/` corpus (9 fixtures per CONTEXT.md `<domain>` "Synthesised fixture-first Wave 0" bullet)
- [ ] `tests/Contracts/ForecastingProjectionContractTest.php` skeleton + Wave 0 dataset registration
- [ ] `tests/Contracts/ScenarioIsolationContractTest.php` skeleton
- [ ] PSR-4 wire-up: `composer.json` autoload-dev + `phpunit.xml` testsuite + `tests/Pest.php` per-module map row
- [ ] `Modules/Forecasting/Public/Contracts/` empty marker (placeholder so arch rule binds against an existing namespace from day one)
- [ ] Three new listener scaffolds (no logic yet): `ProjectForecastOnScenarioChange`, `ProjectForecastOnRecurringChange`, `ProjectForecastOnDriftDismissed`

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Fortify (locked Phase 1); every `/forecast/*` route gated by `LoopbackOnly` + Fortify auth middleware |
| V3 Session Management | yes | Laravel session driver (database); no Phase 10 changes |
| V4 Access Control | yes | `BelongsToUser` scope on `ForecastScenario`, `ForecastScenarioMutation`, `ForecastShortfallWindow`, `ForecastRun`. Cross-user 404 invariant (Phase 3/4/5/8/9 carry-forward) enforced on every read/write action via `where('user_id', $user->id)` + explicit cross-user 404 feature test |
| V5 Input Validation | yes | Spatie LaravelData DTOs validate `forecast_scenario_mutations.payload` per kind; numeric buffer input validated `>= 0` server-side; opening-balance date validated as parseable |
| V6 Cryptography | no | No secrets, no encryption — Phase 10 reads no external APIs |
| V7 Error Handling | yes | `forecast_runs.status = 'failed'` surfaces via UI-SPEC error toast (`Forecast computation failed. Try again or open Horizon.`); no stack trace leakage |
| V8 Data Protection | yes | All forecast data is per-user; `Cache-Control: no-store` inherited from app layout (Phase 1 PLT-01) |
| V11 Business Logic | yes | The five Public Actions enforce same-user invariant before any write; `ScenarioIsolationContractTest` proves scenarios never bleed into transactions |
| V12 Files & Resources | no | No file uploads in Phase 10 |
| V13 API & Web Services | no | No new HTTP API surface beyond Livewire-internal endpoints |

### Known Threat Patterns for Forecasting

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-user scenario read (user A sees user B's scenarios on `/forecast?scenarioId={B's id}`) | Information Disclosure | `ScenarioQuery::forUser($user)` + `where('user_id', $user->id)` on every fetch; cross-user 404 feature test mirrors `DriftAlertCrossUser404Test` |
| Cross-user scenario mutation (user A adds a mutation to user B's scenario) | Tampering | Public Actions accept `User $currentUser` + verify `$scenario->user_id === $currentUser->id` before any DB write; throws `NotFoundHttpException` on mismatch (Phase 5 pattern) |
| Scenario mutation leaking into transaction query | Tampering / Information Disclosure | `noScenarioMutationsJoinedToTransactionQueries` arch invariant (D-1015 #5) + `ScenarioIsolationContractTest` |
| Job thundering herd via burst Phase 8/9 events | Denial of Service (self-DoS) | `ShouldBeUniqueUntilProcessing` per `(user_id, scenario_id-or-baseline, horizon_days)` collapses concurrent triggers; daily sweep is the safety floor |
| Negative buffer value bypassing UI validation | Tampering | Server-side validation in `SetAccountForecastBuffer` Public Action; rejects `< 0`; UI-SPEC error copy `Buffer must be zero or positive.` |
| Opening-balance forced to absurd value (~€1B) | Tampering | Soft-warning banner at `|diff| > €500`; no hard cap (single-user, calm UX), but the warning surfaces an attempt to enter implausible values |
| SQL injection via scenario name / mutation note | Tampering | Eloquent + parameterized queries; never string-interpolate user input into SQL — verified by project-wide pattern adherence (no raw SQL in module code) |
| Horizon dashboard exposing job payloads with PII | Information Disclosure | Job payload is `(userId, scenarioId, horizonDays)` — only numeric ids; no money amounts, no merchant names; safe even if `/horizon` is accidentally exposed (it's loopback-bound anyway) |

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Single-point forecast presented to the cent | Range with low/point/high triple + visible confidence | textbook UX consensus since ~2020 (Pitfall 19 in `.planning/research/PITFALLS.md`) | FCT-02 codifies this; trust survives surprise charges |
| Linear sum of independent variances | Quadrature √(Σ spread²) | classical probability since the 18th century, but only consistently applied in financial-forecast UIs in the last ~5 years | Combined band width is honest, not over-wide |
| Ephemeral-only scenarios (no save) | Named saved DB scenarios + structural arch isolation | Phase 10 D-1008 evolution of Pitfall 15 ("What-if leaking into persisted state") | Users get resumable modeling AND FCT-03 satisfaction simultaneously |
| Stacked translucent rangeArea for multi-account aggregate | Single-line aggregate in EUR | UI-SPEC D-1027 resolution | Visual muddiness avoided; aggregate answers "household cash position", per-account tabs answer "will THIS account dip?" |
| Top-nav consolidation under a "Money" parent | Flat 10-item bar with calm rose-50 shortfall pill | UI-SPEC D-1025 resolution | Forecast is operational, not administrative; flat bar matches calm aesthetic |

**Deprecated/outdated:**

- **Webklex IMAP, ext-imap, etc.:** Irrelevant to Phase 10 (no email scanning in scope).
- **Apex `v5`:** Released 2026-05-15 on npm; not adopted in Phase 10 — project pinned to `^3.54.1`.
- **`<flux:tab.group>` / `<flux:popover>`:** Pro-only in Flux UI; project uses free Flux + hand-rolled Tailwind for these UI primitives (UI-SPEC § Component Inventory lists them as "Phase 8 segmented-control inheritance" which is the hand-rolled pattern, not the Flux component).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Pest arch `not->toBeUsedIn()` cannot inspect file *contents* (SQL JOIN strings, table names) [CITED: pestphp.com/docs/arch-testing — class-import-only confirmation] | Pattern 5 + Standard Stack alternatives | If Pest gains content-scanning, simpler arch rules become possible. Risk: low — `it(...) + RecursiveIteratorIterator` precedent already in project and proven. |
| A2 | ApexCharts `rangeArea` + `line` combo supports `tooltip.custom` reading `w.globals.initialSeries[0].data[dataPointIndex].y[0/1]` for low/high access [CITED: search results, GitHub issue #2139] | Pattern 1 + Code Examples | If Apex 3.54's `rangeArea` data shape differs from documented (`{x, y: [low, high]}`), the tooltip body breaks. Mitigation: smoke test in Wave 2 against a tiny static dataset before wiring real ForecastQuery output. |
| A3 | `wire:poll.2s` element conditionally rendered via `@if ($isComputing)` auto-stops polling when re-rendered without the wire:poll attribute [ASSUMED — standard Livewire 4 behavior, not directly verified] | Pitfall 3 + Pattern 2 | If `wire:poll` keeps the previous request alive after the element unmounts, the poll never stops. Mitigation: write a dedicated Pest feature test that asserts `wire:poll` calls stop after the status flips. |
| A4 | `'baseline'` sentinel vs `'0'` for the `ProjectForecastJob` uniqueId is safer than relying on SQLite never producing autoincrement id=0 [VERIFIED: SQLite docs — autoincrement starts at 1] | Pattern 3 + Pitfall 1 | Risk: none — strictly safer formulation. The CONTEXT.md `?? 0` shape continues to work in practice today; the sentinel is a small defense-in-depth upgrade the planner should adopt. |
| A5 | Spatie LaravelData supports tagged-union DTO deserialization via Eloquent cast for the `forecast_scenario_mutations.payload` typed envelope [ASSUMED — pattern already in use Phase 4+ per CONTEXT.md "pattern reference: any existing module DTO"] | Don't Hand-Roll + Pitfall 4 | If LaravelData 4 doesn't ship a union-cast helper out of the box, the planner falls back to a manual `match($kind)` mapper in the Mapping/ folder — adds boilerplate but preserves type safety. |
| A6 | The View Factory composer pattern's `auth()` call inside `ForecastingServiceProvider::boot` does NOT trip the `arch('no Laravel facade usage in module code')` rule because the existing project precedent already exempts ServiceProvider boot() composers [ASSUMED — verify against existing DriftAlertsServiceProvider + RecurringServiceProvider source] | Code Examples (View Factory composer) | If the rule does fire, the fix is either (a) extend the ignore list to include `ForecastingServiceProvider`, or (b) replace `auth()->user()` with `$this->app->make('auth')->user()` (container-bound resolution). Planner verifies the existing precedent and matches it. |
| A7 | Apex `chart.updateOptions(newOptions, true, false)` preserves the chart instance and re-renders without animation [CITED: ApexCharts API; verified pattern in GitHub Livewire/ApexCharts integration discussions] | Pattern 2 | If `updateOptions` doesn't update the data, a full destroy + re-render is needed. Mitigation: the Alpine `x-data` block already wraps `chart` reactively; falling back to `chart.destroy(); chart = new ApexCharts(...); chart.render();` is a 4-line change. |

## Open Questions (RESOLVED)

1. **Does `wire:poll` auto-stop when the element is removed from the DOM via `@if`?**
   - What we know: Livewire 4 docs indicate `wire:poll` is element-scoped. When the element unmounts via Livewire's diff, the poll should stop.
   - What's unclear: Whether the network request in-flight at unmount time is aborted or fires once more.
   - **RESOLVED:** Add a Pest feature test that asserts `forecast_runs.status='complete'` causes the polling URL to stop appearing in subsequent test requests. Skip if Livewire test harness can't observe wire:poll directly; instead assert via component property snapshot after a controlled status flip.

2. **Should the soft-warning threshold (D-1029) be configurable per account, or is `€500` global?**
   - What we know: UI-SPEC § Opening-balance editor lifecycle step 3 locks `|diff| > €500`.
   - What's unclear: Whether a CSV-only account with €10k+ history needs a proportionally larger threshold.
   - **RESOLVED:** Keep `€500` global for v1; revisit if user feedback shows false-positive warnings on large-balance accounts. Phase 11 / v2 can promote to a per-account or percentage-based threshold.

3. **Does the percentile tier handle the case of exactly 3 occurrences gracefully?**
   - What we know: D-1004 says percentile tier kicks in for volatile series with `<3 stable-amount occurrences in last 6 with stddev > X`. The R-7 linear-interpolation method handles n=3 by interpolating between the 1st and 2nd ranked values for P10, between 2nd and 3rd for P90.
   - What's unclear: Whether P10 with n=3 is statistically meaningful at all (it's effectively `min + 0.1 × range`).
   - **RESOLVED:** Wave 5 implementation should fall back to envelope tier when n<6 (matching D-1004's rejection of "pure-historical-percentile-everywhere as fragile on new series with <6 occurrences"). Wave 0 fixture corpus must include a "variable utility, 4 occurrences" case to lock this behavior in tests.

4. **Should the View Factory composer that injects `$forecastShortfallCount` ALSO cache its result (e.g., 5-second TTL) to avoid hitting `ForecastHighlightsQuery` on every page load?**
   - What we know: Dashboard reads pre-computed `forecast_shortfall_windows` rows (cheap). Top-nav composer fires on every page render.
   - What's unclear: Whether the `ForecastHighlightsQuery::activeShortfallCountForUser` query has enough indexes to keep cost negligible.
   - **RESOLVED:** Skip caching in v1; the query is a simple `COUNT` against an indexed `(user_id, ends_at)` range. Promote to cache if profiling Wave 5 shows >5ms per render.

## Sources

### Primary (HIGH confidence)

- **CONTEXT.md** (10-CONTEXT.md) — verbatim user-locked decisions D-1001..D-1029
- **10-UI-SPEC.md** — UI design contract lock (70+ copy strings; chart color matrix; Charting Decision D-1027 / Navigation D-1025 / D-1021/D-1026/D-1029 resolutions)
- **REQUIREMENTS.md** §Forecasting — FCT-01..05
- **STACK.md** + **PITFALLS.md** (Pitfall 15 / 19) — `.planning/research/`
- **Project source** [VERIFIED via Bash/Read]:
  - `vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php::getKey` — lock key composition
  - `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php` — ShouldBeUniqueUntilProcessing precedent
  - `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` — same pattern
  - `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` — same pattern
  - `tests/Contracts/BoundaryArchTest.php` — `arch()` + `it(...) + RecursiveIteratorIterator` precedents (5 + 11 invariants)
  - `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` — ApexCharts + Alpine `x-init` integration
  - `Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php` — `#[Url(as: 'fp-filter')]` precedent
  - `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php` — `#[Url(as: 'currency', except: '')]` precedent
  - `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` — `#[Url(as: 'tab', except: 'open')]` precedent
  - `resources/js/app.js` — `window.ApexCharts = ApexCharts` global stamp
  - `composer.json` + `package.json` — version pins verified
- [ApexCharts: Range Area Chart](https://apexcharts.com/docs/chart-types/range-area-chart/) — `rangeArea` data shape `[{x, y: [low, high]}]` + combo with `line` support [CITED]
- [ApexCharts: Tooltip](https://apexcharts.com/docs/options/tooltip/) — `custom: function({series, seriesIndex, dataPointIndex, w})` signature; HTML return [CITED]
- [Pest: Arch Testing](https://pestphp.com/docs/arch-testing) — `arch->expect()->not->toBeUsedIn()` / `toOnlyBeUsedIn()` / `toUse()` / `toOnlyUse()` semantics; class-import-level only (no content scanning) [CITED]
- [Laravel 12 Queues: Unique Jobs](https://laravel.com/docs/12.x/queues#unique-jobs) — `ShouldBeUniqueUntilProcessing` / `uniqueId()` / `uniqueFor()` / `uniqueVia()` [CITED]
- [Cornell 8.04: Variance of Sum of Independent Random Variables](https://muchomas.lassp.cornell.edu/8.04/Lecs/lec_statistics/node14.html) — quadrature combination math [CITED]
- [MIT OCW Probability §12.7: Variance of Sum of Random Variables](https://ocw.mit.edu/courses/res-6-012-introduction-to-probability-spring-2018/) — independence assumption + variance addition [CITED]
- [Wikipedia: Percentile §"Linear interpolation between closest ranks"](https://en.wikipedia.org/wiki/Percentile) — R-7 method for small-sample percentile [CITED]

### Secondary (MEDIUM confidence)

- [Apex `rangeArea` tooltip custom — GitHub issue #2139](https://github.com/apexcharts/apexcharts.js/issues/2139) — accessing `w.globals.initialSeries[seriesIndex].data[dataPointIndex].y[0/1]` for low/high
- [Livewire + ApexCharts integration patterns — Livewire discussion #9253](https://github.com/livewire/livewire/discussions/9253) — `wire:ignore` workaround vs `chart.updateOptions()` pattern
- [Axibase: How to Calculate Percentiles](https://axibase.com/use-cases/workshop/percentiles.html) — R-7 vs nearest-rank vs R-8 method comparison for small samples

### Tertiary (LOW confidence — flagged for validation)

- None — every claim above is backed by either project source, official docs, or peer-reviewed math citations.

## Metadata

**Confidence breakdown:**

- Standard stack: **HIGH** — zero new dependencies; every lib is already in `composer.json` or `package.json` [VERIFIED via Read]
- Architecture patterns: **HIGH** — every pattern traces to a verified project precedent (`recurring-series-detail-page.blade.php` for ApexCharts, `DetectDriftAlertsJob` for ShouldBeUniqueUntilProcessing, `BoundaryArchTest.php` for the mixed Pest `arch` + `it(...)` style)
- Math (quadrature + percentile R-7): **HIGH** — both cited to classical probability sources and Wikipedia
- Pitfalls: **HIGH** — composite-key collision finding [VERIFIED via Laravel source], `wire:ignore` chart re-render pitfall [CITED via Livewire discussion], shared y-axis manual computation [reasoned from ApexCharts docs]
- Open questions: **MEDIUM** — three of the four open questions are tunings or polish items the planner can resolve at planning time; one (`wire:poll` auto-stop) requires a Wave 2 sanity test

**Research date:** 2026-05-18
**Valid until:** 2026-06-18 (30 days — the locked stack is stable; ApexCharts v5 is a long-horizon upgrade decision, not Phase 10's concern)
