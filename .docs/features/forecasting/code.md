# `Forecasting` — code

The file-level map for the module.

## Directory layout

```
Modules/Forecasting/
├── Public/
│   ├── Actions/             (9 Public actions — scenario CRUD,
│   │                          launchpad, buffer, opening balance)
│   ├── Dto/                 (10 DTOs + 5 ScenarioMutationPayload
│   │                          variants)
│   ├── Enums/               (ForecastHorizon, ScenarioMutationKind,
│   │                          ShiftScope)
│   ├── Events/              (4 events)
│   ├── Http/Livewire/
│   │   ├── ForecastHighlightsTile.php
│   │   ├── ModelWhatIfDropdown.php
│   │   └── OpeningBalanceEditor.php
│   └── Services/
│       ├── ForecastQuery.php
│       ├── ScenarioQuery.php
│       ├── NetWorthQuery.php
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
│   │   ├── ForecastDtoMapper.php
│   │   └── ForecastWindow.php
│   ├── Casts/
│   │   └── ScenarioMutationPayloadCast.php
│   ├── Enums/
│   │   ├── ForecastPointSet.php
│   │   ├── ScenarioFormField.php
│   │   ├── ScenarioTemplate.php
│   │   └── SeriesConfidence.php
│   ├── Exceptions/
│   │   ├── ForecastResultEncodingException.php
│   │   ├── ForecastRunNotFoundException.php
│   │   ├── InvalidForecastRunTransitionException.php
│   │   └── OpeningBalanceDivergenceWarning.php
│   ├── Support/
│   │   ├── AmountStringParser.php
│   │   ├── BufferFloor.php
│   │   ├── ForecastChartView.php
│   │   ├── ScenarioHorizonBounds.php
│   │   └── ScenarioSeriesResolver.php
│   └── Http/Livewire/       (ForecastPage, AccountBufferEditor,
│                              ScenarioEditorSidebar)
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
    $user, $viewByFunder = false): ForecastDto` — the projection. A null `$scenarioId` is
    the baseline; a non-null one is the scenario-applied run. Throws
    `NotFoundHttpException` for an account the user does not own.
  - `ScenarioQuery::forUser($user): list<ScenarioDto>`,
    `find($scenarioId, $user): ?ScenarioDto`,
    `mutationsFor($scenarioId, $user)`.
  - `ForecastHighlightsQuery::activeShortfallCountForUser($user)`
    — the sidebar badge query (single COUNT).
  - `ForecastHighlightsQuery::forUser($user):
    ForecastHighlightsDto` — the dashboard tile read.
- **Actions/**
  - Scenario CRUD: `CreateScenario`, `RenameScenario`,
    `DeleteScenario`, `AddScenarioMutation`,
    `EditScenarioMutation`, `RemoveScenarioMutation`.
  - Launchpad atomic action: `CreateScenarioFromTemplate`, over the
    `ScenarioTemplate` vocabulary. It wraps a `CreateScenario` +
    `AddScenarioMutation` pair in a DB transaction.
  - `SetAccountForecastBuffer`, `SetAccountOpeningBalance` (whose
    `positionOn()` also answers the editor's suggestion chip — see
    [opening-balance-suggestion.md](opening-balance-suggestion.md)).
- **DTOs/**
  - `ForecastDto` — `(accountId, accountName, defaultCurrency,
    horizonDays, scenarioId, asOf, todayBalanceMinor, points,
    seriesConfidence, isComputing, runFailed, isStale,
    unconvertedCurrencies)`. The last names the currencies left out
    of the curve for want of a rate, the same way the calendar day
    panel and the trend card name theirs. There is no
    `shortfallWindows` on it: the windows are their own read, keyed by
    horizon, and `ForecastChartView` fetches them beside the curve.
  - `ForecastPointDto` — `(date, lowMinor, pointMinor, highMinor,
    currency)`. The band is a low/point/high triple, not a percentile
    triple: the percentiles are consumed inside `RangeProjector` and never
    reach the read side.
  - `ScenarioDto`, `ScenarioMutationDto`.
  - `ScenarioMutationPayload/` — five payload variants
    (`AddOneOff`, `AddRecurring`, `CancelSeries`,
    `ChangeSeriesAmount`, `ShiftSeriesDate`).
  - `ForecastHighlightsDto`, `ShortfallWindowDto`,
    `BalanceAnchorDto`, `SeriesConfidenceDto` — the last carrying a
    `SeriesConfidence` enum and a `monthlyEquivalentMinor`, because the
    legend line is suffixed "/mo". The enum itself is
    `Internal/Enums/`, so a reader of this Public DTO who wants to
    switch on `confidence` is naming an Internal type to do it.
- **Events/**
  - `ForecastShortfallDetected` — `(userId, accountId, scenarioId,
    startsAt, endsAt, lowestBalanceMinor, currency, bufferUsedMinor)`.
  - `ScenarioCreated`, `ScenarioMutated`, `ScenarioDeleted` —
    each carrying the scenario id + user id.
- **Exceptions** — `OpeningBalanceDivergenceWarning`, raised by
  `SetAccountOpeningBalance` when the manual override diverges from
  the statement anchor beyond threshold. It is the one part of this
  surface that does not sit under `Public/`: the class is in
  `Internal/Exceptions/`, and the only code that catches it is this
  module's own `OpeningBalanceEditor`, which turns it into the
  confirm banner. A caller in another module would have to name it
  to catch it, and could not.

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
- `Internal/Pipeline/DailyFold::fold($openingBalanceMinor,
  $contributions, $asOf, $horizonDays, $defaultCurrency, $rates):
  DailyFoldResult` — collapses contributions into a daily curve with
  P10/P50/P90 bands, converting each non-matching currency through
  `$rates` (a `CrossCurrencyTotal::ratesTo()` map the pipeline fetches
  once per target currency). `DailyFoldResult` carries the day map and
  the currency codes no rate reached.
- `Internal/Pipeline/CadenceJitter::apply($contributions,
  $windowStart, $windowEnd, $jitterDays)` — replaces each
  date-uncertain contribution with `2 × jitterDays + 1` replicas,
  clamped into the fold's own walk.
- `Internal/Pipeline/ShortfallDetector::detect($dailyPoints, $accountId,
  $scenarioId, $horizonDays, $effectiveBufferMinor, $currency, $user)` —
  finds windows below the floor; writes `forecast_shortfall_windows` rows
  keyed by horizon, and dispatches `ForecastShortfallDetected` for a
  baseline run only. A **null** `$effectiveBufferMinor` means no floor is in
  force: the previous rows are still cleared and none are written.
  `Internal/Support/BufferFloor` decides which accounts get one.
- `Internal/Pipeline/Percentile::p10($values)` / `p50(...)` /
  `p90(...)` — the percentile fan. Three named methods over a closed
  set, not a `compute($values, $level)` that would accept a level the
  bands have no meaning for; the interpolating maths is private.
  Throws `InvalidArgumentException` on an empty list.
- `Internal/Pipeline/ProjectionPipeline::project($user, $scenarioId,
  $horizonDays)` — orchestrator. Every account the user owns is projected in
  one pass, so it takes no account id.
- `Internal/StateMachines/ForecastRunStateMachine::start` / `complete` /
  `fail` — SOLE sanctioned mutator of `forecast_runs.status`.
- `Internal/Jobs/ProjectForecastJob` — per-`(user, scenario,
  horizon)` projection. Constructor positional
  `(userId, scenarioId, horizonDays)` — dispatched, not container-
  resolved.
- `Internal/Listeners/ProjectForecastOnRecurringChange` /
  `OnDriftDismissed` / `OnScenarioChange` — re-project triggers.
- `Internal/Mapping/ForecastDtoMapper` — `forecast_runs` row →
  `ForecastDto`.
- `Internal/Mapping/ForecastWindow` — horizon, scenario and `asOf` as one
  value, so a mapped run, a flat-line fallback and a computing sentinel
  cannot be built from three different runs' worth of them.
- `Internal/Casts/ScenarioMutationPayloadCast` — Eloquent cast
  that JSON-encodes / decodes the typed payload classes.
- `Internal/Support/AmountStringParser::toMinor($input, $currency,
  $allowNegative, $requireNonZero)` — parses user-typed amount strings into
  minor units at the CURRENCY'S own scale, with locale-aware decimal
  separators. The currency is required: a yen has no minor unit, and the
  repo-wide two decimals stored ¥5,000 as ¥500,000.
- `Internal/Support/ScenarioHorizonBounds::assertReachable($payload)` —
  refuses a mutation dated where no horizon reaches, rather than saving one
  that is listed as active and changes nothing.
- `Internal/Support/BufferFloor::forKind($kind, $explicitMinor)` — the floor
  a projected balance is judged against, or null for none.

## Models + migrations

- `Models/ForecastScenario` — `(user_id, name, ...)`.
- `Models/ForecastScenarioMutation` — `(scenario_id, type,
  payload)` with the typed-payload cast.
- `Models/ForecastShortfallWindow` — per-detected shortfall.
- `Models/ForecastRun` — `(user_id, scenario_id, horizon_days,
  status, result_json, ...)`. One run holds every account, so there is no
  `account_id`, and the column is `status`. The state machine is its sole
  mutator.

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
