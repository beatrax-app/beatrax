# `Forecasting` — architecture

The `Forecasting` module projects the user's near-term cash-flow
across 30 / 60 / 90 days, surfaces shortfall windows when a projected
balance crosses the per-account buffer threshold, and lets the user
model what-if scenarios (cancel a subscription, change an amount,
add a one-off, shift a date) without ever mutating the underlying
ledger.

## What this module is for

A statement view tells the user where they've been; a forecast tells
them where they're going. The module reads the
canonical recurring series from [`Recurring`](../recurring/architecture.md),
runs a deterministic projection per scenario over the chosen horizon,
folds the per-day contributions into a balance curve, and surfaces
the result as percentile bands (so a noisy recurring series shows up
as a wider band, not a fake single line). When the curve crosses the
buffer, a `ForecastShortfallDetected` event is raised; the user sees
the shortfall window on the chart and, optionally, on the dashboard
highlights tile.

The scenario isolation boundary is strict: scenarios live in
`forecast_scenarios` + `forecast_scenario_mutations`; they NEVER write
to `transactions`, `recurring_series`, or `chain_links`. The arch
invariant `noScenarioMutationsJoinedToTransactionQueries` blocks any
JOIN that would couple the scenario substrate to the ledger.

What the module explicitly does NOT do:

- It never mutates a user's recurring series. The scenario layer is
  an in-memory transform on top of the routed baseline contributions.
- It never persists a scenario's projected balance curve into the
  ledger. Every projection is a per-run artefact saved to
  `forecast_runs.result_json`.
- It never speaks to the network. The projection is local-only;
  the only outbound surface in the codebase is the auto-update
  channel in `Core`.

## Module boundary

`Public/` exposes the cross-module surface:

- **Services/**
  - `ForecastQuery::baseline($user, $accountId, $horizon)` /
    `forScenario($user, $scenarioId, $accountId, $horizon)` — the
    read-side queries that return `ForecastDto` instances.
  - `ScenarioQuery::list($user)`, `forId($id, $user)` — scenario
    metadata reads.
  - `ForecastHighlightsQuery::activeShortfallCountForUser($user)` /
    `tileFor($user)` — the dashboard tile + top-nav badge reads.
- **Actions/**
  - Scenario CRUD: `CreateScenario`, `RenameScenario`,
    `DeleteScenario`, `AddScenarioMutation`,
    `EditScenarioMutation`, `RemoveScenarioMutation`.
  - Launchpad atomic actions:
    `CreateCancellationScenarioForAlert` (from a drift alert),
    `CreateCancellationScenarioForSeries`,
    `CreateAmountChangeScenarioForSeries`. Each wraps a
    `CreateScenario` + `AddScenarioMutation` pair in a DB
    transaction.
  - `SetAccountForecastBuffer::__invoke($accountId, $bufferMinor,
    $user)` — the per-account buffer the shortfall detector
    compares against.
  - `SetAccountOpeningBalance::__invoke($accountId, $openingMinor,
    $user)` — manual opening-balance override used when the
    statement-derived anchor needs correcting.
- **DTOs/**
  - `ForecastDto` + `ForecastPointDto` — the projection result
    shape.
  - `ScenarioDto` + `ScenarioMutationDto` + the typed payload
    classes under `Dto/ScenarioMutationPayload/`
    (`AddOneOffPayload`, `AddRecurringPayload`,
    `CancelSeriesPayload`, `ChangeSeriesAmountPayload`,
    `ShiftSeriesDatePayload`).
  - `ForecastHighlightsDto`, `ShortfallWindowDto`,
    `SeriesConfidenceDto`, `BalanceAnchorDto`.
- **Events/**
  - `ForecastShortfallDetected` (`accountId, userId, windowStart,
    windowEnd`). Consumed by `Desktop::DispatchOsNotification`.
  - `ScenarioCreated`, `ScenarioMutated`, `ScenarioDeleted` —
    raised by the scenario CRUD actions; consumed by the
    `ProjectForecastOnScenarioChange` listener to re-run the
    projection.
- **Exceptions/**
  - `OpeningBalanceDivergenceWarning` — raised by
    `SetAccountOpeningBalance` when the manual override diverges
    from the statement anchor by more than the documented threshold.

`Internal/` houses the projection pipeline:

- **Internal/Pipeline/BalanceAnchorResolver** — picks the anchor
  balance for the account (statement-derived; user-overridden
  opening balance when present).
- **Internal/Pipeline/RangeProjector** — produces
  `ForecastContribution` instances per recurring series across the
  horizon, with `CadenceJitter` modelling cadence drift.
- **Internal/Pipeline/ChainAwareForecastRouter** — routes
  contributions through the chains (PayPal-funded charges show up
  on the funder account, ICS purchases reduce the ICS card-statement
  balance + later show up on ASN at settlement).
- **Internal/Pipeline/ScenarioApplier** — applies the scenario's
  mutations on top of the routed contributions; pure in-memory
  transform.
- **Internal/Pipeline/DailyFold** — folds contributions into a
  daily balance curve.
- **Internal/Pipeline/ShortfallDetector** — finds windows where
  the curve crosses the buffer; writes
  `forecast_shortfall_windows` rows.
- **Internal/Pipeline/Percentile** — the percentile fan
  computation (P10 / P50 / P90 bands).
- **Internal/Pipeline/ProjectionPipeline** — orchestrates the
  pipeline; the entry point the queued job calls.
- **Internal/StateMachines/ForecastRunStateMachine** — SOLE
  sanctioned mutator of `forecast_runs.state`.
- **Internal/Jobs/ProjectForecastJob** — per-`(user, scenario,
  horizon)` queued projection.
- **Internal/Listeners/ProjectForecastOnRecurringChange** /
  `OnDriftDismissed` / `OnScenarioChange` — re-project triggers.
- **Internal/Mapping/ForecastDtoMapper** — `forecast_runs` row →
  `ForecastDto`.
- **Internal/Http/Livewire/** — six SFCs (ForecastPage,
  AccountBufferEditor, ForecastHighlightsTile,
  ScenarioEditorSidebar, ModelWhatIfDropdown,
  OpeningBalanceEditor).

## Key services + events

- `ProjectionPipeline::run($user, $scenarioId, $accountId,
  $horizonDays)` — the orchestrator. Steps:
  1. `BalanceAnchorResolver::resolve` — pick the starting balance.
  2. `RangeProjector::project` — build `ForecastContribution`
     instances from recurring series + scheduled exceptions.
  3. `ChainAwareForecastRouter::route` — apply chain-aware
     routing.
  4. (if scenario) `ScenarioApplier::apply` — transform the
     routed contributions.
  5. `DailyFold::fold` — collapse to a daily balance curve with
     P10/P50/P90 bands.
  6. `ShortfallDetector::detect` — find windows below buffer;
     write rows + dispatch `ForecastShortfallDetected`.
  7. Persist `forecast_runs.result_json` for the read-side query.
- `ForecastRunStateMachine::transition($run, $next)` — the
  `pending → running → complete | failed` lifecycle; the sole
  writer of `forecast_runs.state`.
- `ProjectForecastOnRecurringChange::handle($event)` — fans out
  one `ProjectForecastJob` per `(user, scenario, horizon)` when
  Recurring metrics refresh.
- `ProjectForecastOnDriftDismissed::handle($event)` — same when a
  drift alert is dismissed as cancelled.
- `ProjectForecastOnScenarioChange::handle($event)` — same when
  the user creates / edits / deletes a scenario.
- `ForecastShortfallDetected` — raised by `ShortfallDetector`;
  consumed by `Desktop` for OS notifications.

## Data flow

The projection trigger:

```
Recurring metrics refresh (or drift dismissed, or scenario change)
  → ProjectForecastOn{RecurringChange|DriftDismissed|ScenarioChange}
  → fan-out: per (user, scenario, horizon)
      → dispatch ProjectForecastJob
```

The projection itself:

```
ProjectForecastJob::handle()
  → ForecastRunStateMachine: pending → running
  → ProjectionPipeline::run
       → BalanceAnchorResolver
       → RangeProjector (with CadenceJitter)
       → ChainAwareForecastRouter
       → ScenarioApplier (only when scenarioId != null)
       → DailyFold (P10/P50/P90)
       → ShortfallDetector
            → write forecast_shortfall_windows rows
            → dispatch ForecastShortfallDetected
  → persist forecast_runs.result_json
  → ForecastRunStateMachine: running → complete
```

The user-facing surface:

```
/forecast
  → ForecastPage Livewire SFC
       → per-account tab + 30/60/90 horizon control
       → ForecastQuery::baseline($user, $accountId, $horizon)
       → ForecastQuery::forScenario(...) if user picked one
       → render rangeArea chart with P10/P50/P90 + shortfall band

/forecast scenario editor sidebar
  → ScenarioEditorSidebar SFC
       → ScenarioQuery::list($user)
       → user adds / edits / removes mutations
            → Public Action → dispatch ScenarioMutated
                 → ProjectForecastOnScenarioChange re-runs projection

dashboard
  → ForecastHighlightsTile SFC
       → ForecastHighlightsQuery::tileFor($user)
top-nav badge
  → composer reads ForecastHighlightsQuery::activeShortfallCountForUser
```
