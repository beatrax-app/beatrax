# `Forecasting` — code

The file-level map for the module.

## Directory layout

```
Modules/Forecasting/
├── Public/
│   ├── Actions/             (11 Public actions — scenario CRUD,
│   │                          launchpad, buffer, opening balance)
│   ├── Dto/                 (7 DTOs + 5 ScenarioMutationPayload
│   │                          variants)
│   ├── Enums/               (ScenarioMutationKind, ShiftScope,
│   │                          ForecastPointSet)
│   ├── Events/              (4 events)
│   ├── Exceptions/
│   │   └── OpeningBalanceDivergenceWarning.php
│   └── Services/
│       ├── ForecastQuery.php
│       ├── ScenarioQuery.php
│       └── ForecastHighlightsQuery.php
├── Internal/
│   ├── Pipeline/
│   │   ├── BalanceAnchorResolver.php
│   │   ├── RangeProjector.php
│   │   ├── ChainAwareForecastRouter.php
│   │   ├── ScenarioApplier.php
│   │   ├── DailyFold.php
│   │   ├── ShortfallDetector.php
│   │   ├── Percentile.php
│   │   ├── CadenceJitter.php
│   │   ├── ForecastContribution.php
│   │   └── ProjectionPipeline.php
│   ├── StateMachines/
│   │   └── ForecastRunStateMachine.php
│   ├── Jobs/
│   │   └── ProjectForecastJob.php
│   ├── Listeners/
│   │   ├── ProjectForecastOnRecurringChange.php
│   │   ├── ProjectForecastOnDriftDismissed.php
│   │   └── ProjectForecastOnScenarioChange.php
│   ├── Mapping/
│   │   └── ForecastDtoMapper.php
│   ├── Casts/
│   │   └── ScenarioMutationPayloadCast.php
│   ├── Exceptions/
│   │   └── InvalidForecastRunTransitionException.php
│   ├── Support/
│   │   └── AmountStringParser.php
│   └── Http/Livewire/       (6 SFCs)
├── Models/
│   ├── ForecastRun.php
│   ├── ForecastScenario.php
│   ├── ForecastScenarioMutation.php
│   └── ForecastShortfallWindow.php
├── Database/
│   ├── Migrations/
│   ├── Factories/
│   └── Seeders/
├── Routes/web.php
├── Resources/views/
├── Providers/
│   └── ForecastingServiceProvider.php
└── tests/
```

## Public API

- **Services/**
  - `ForecastQuery::forUser($accountId, $horizonDays, $scenarioId,
    $user): ForecastDto` — the projection. A null `$scenarioId` is
    the baseline; a non-null one is the scenario-applied run. Throws
    `NotFoundHttpException` for an account the user does not own.
  - `ScenarioQuery::forUser($user): list<ScenarioDto>`,
    `find($scenarioId, $user): ?ScenarioDto`,
    `mutationsFor($scenarioId, $user)`.
  - `ForecastHighlightsQuery::activeShortfallCountForUser($user)`
    — the sidebar badge query (single COUNT).
  - `ForecastHighlightsQuery::tileFor($user):
    ForecastHighlightsDto`.
- **Actions/**
  - Scenario CRUD: `CreateScenario`, `RenameScenario`,
    `DeleteScenario`, `AddScenarioMutation`,
    `EditScenarioMutation`, `RemoveScenarioMutation`.
  - Launchpad atomic actions:
    `CreateCancellationScenarioForAlert`,
    `CreateCancellationScenarioForSeries`,
    `CreateAmountChangeScenarioForSeries`. Each wraps a
    `CreateScenario` + `AddScenarioMutation` pair in a DB
    transaction.
  - `SetAccountForecastBuffer`, `SetAccountOpeningBalance`.
- **DTOs/**
  - `ForecastDto` — `(accountId, horizonDays, points,
    shortfallWindows, anchor, generatedAt)`.
  - `ForecastPointDto` — `(date, balanceMinor, p10, p50, p90)`.
  - `ScenarioDto`, `ScenarioMutationDto`.
  - `ScenarioMutationPayload/` — five payload variants
    (`AddOneOff`, `AddRecurring`, `CancelSeries`,
    `ChangeSeriesAmount`, `ShiftSeriesDate`).
  - `ForecastHighlightsDto`, `ShortfallWindowDto`,
    `BalanceAnchorDto`, `SeriesConfidenceDto`.
- **Events/**
  - `ForecastShortfallDetected` — `(accountId, userId, windowStart,
    windowEnd, minBalanceMinor)`.
  - `ScenarioCreated`, `ScenarioMutated`, `ScenarioDeleted` —
    each carrying the scenario id + user id.
- **Exceptions/**
  - `OpeningBalanceDivergenceWarning` — raised by
    `SetAccountOpeningBalance` when the manual override diverges
    from the statement anchor beyond threshold.

## Internal services

- `Internal/Pipeline/BalanceAnchorResolver::forAccount($accountId,
  $user): BalanceAnchorDto` — picks the anchor balance.
- `Internal/Pipeline/RangeProjector::project($series, $accountId,
  $asOf, $horizonDays, $user): list<ForecastContribution>` — one
  contribution per occurrence, with the percentile tier's dates marked
  `dateIsUncertain` for `CadenceJitter` to smear at the end of the
  pipeline.
- `Internal/Pipeline/ChainAwareForecastRouter::route` — routes
  contributions through chain links.
- `Internal/Pipeline/ScenarioApplier::apply($contributions,
  $mutations)` — in-memory transform.
- `Internal/Pipeline/DailyFold::fold($contributions, $anchor)` —
  collapses contributions into a daily curve with P10/P50/P90
  bands.
- `Internal/Pipeline/CadenceJitter::apply($contributions,
  $windowStart, $windowEnd, $jitterDays)` — replaces each
  date-uncertain contribution with `2 × jitterDays + 1` replicas,
  clamped into the fold's own walk.
- `Internal/Pipeline/ShortfallDetector::detect($curve, $accountId,
  $scenarioId, $horizonDays, $buffer, $currency, $user)` — finds
  windows below buffer; writes `forecast_shortfall_windows` rows keyed
  by horizon, and dispatches `ForecastShortfallDetected` for a baseline
  run only.
- `Internal/Pipeline/Percentile::p10($values)` / `p50(...)` /
  `p90(...)` — the percentile fan. Three named methods over a closed
  set, not a `compute($values, $level)` that would accept a level the
  bands have no meaning for; the interpolating maths is private.
  Throws `InvalidArgumentException` on an empty list.
- `Internal/Pipeline/ProjectionPipeline::run($user, $scenarioId,
  $accountId, $horizonDays)` — orchestrator.
- `Internal/StateMachines/ForecastRunStateMachine::transition` —
  SOLE sanctioned mutator of `forecast_runs.state`.
- `Internal/Jobs/ProjectForecastJob` — per-`(user, scenario,
  horizon)` projection. Constructor positional
  `(userId, scenarioId, horizonDays)` — dispatched, not container-
  resolved.
- `Internal/Listeners/ProjectForecastOnRecurringChange` /
  `OnDriftDismissed` / `OnScenarioChange` — re-project triggers.
- `Internal/Mapping/ForecastDtoMapper` — `forecast_runs` row →
  `ForecastDto`.
- `Internal/Casts/ScenarioMutationPayloadCast` — Eloquent cast
  that JSON-encodes / decodes the typed payload classes.
- `Internal/Support/AmountStringParser` — parses user-typed
  amount strings (with locale-aware decimal separators) into
  minor units.

## Models + migrations

- `Models/ForecastScenario` — `(user_id, name, ...)`.
- `Models/ForecastScenarioMutation` — `(scenario_id, type,
  payload)` with the typed-payload cast.
- `Models/ForecastShortfallWindow` — per-detected shortfall.
- `Models/ForecastRun` — `(user_id, scenario_id, account_id,
  horizon_days, state, result_json, ...)`. State enforced by
  paired triggers; state machine is the sole mutator.

Migrations:

- `2026_05_19_010001_create_forecast_scenarios_table.php` —
  `user_id` non-nullable, UNIQUE `(user_id, name)`.
- `2026_05_19_010002_create_forecast_scenario_mutations_table.php`
- `2026_05_19_010003_create_forecast_shortfall_windows_table.php`
- `2026_05_19_010004_create_forecast_runs_table.php`
- `2026_05_19_010005_add_forecast_columns_to_accounts.php` —
  adds `forecast_min_buffer_minor` + `opening_balance_minor` to
  `accounts`.
- `2026_05_19_010006_add_result_json_to_forecast_runs.php`.
- `2026_08_27_000001_key_shortfall_windows_by_horizon.php` — adds
  `horizon_days` to `forecast_shortfall_windows` so the five queued
  horizons stop deleting each other's rows.

## Provider wiring

`ForecastingServiceProvider::register()`:

- Singletons every pipeline collaborator, listener, Public action,
  query, and the state machine.
- Queued jobs and Livewire components are intentionally NOT
  singletons.

`ForecastingServiceProvider::boot()`:

- Loads migrations, routes, views (file-/dir-existence guarded).
- Registers six Livewire components under the `forecasting.*`
  namespace.
- Subscribes the four upstream events that trigger re-projection:
  `RecurringSeriesApproved`, `RecurringSeriesCadenceFlipped`,
  `RecurringSeriesRejected`, `RecurringSeriesMetricsRefreshed`
  → `ProjectForecastOnRecurringChange`;
  `DriftAlertDismissedCancelled` → `ProjectForecastOnDriftDismissed`;
  the three scenario events →
  `ProjectForecastOnScenarioChange`.
- Registers the sidebar badge composer via the ViewFactory
  contract (no `view()` helper) with a boot-scoped memo, merging the
  count into `navCounts` under the `forecast` key.
