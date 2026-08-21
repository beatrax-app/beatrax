# `Forecasting` — how to test

Practical recipes for exercising the `Forecasting` module in
isolation.

## Unit tests

- **Location:** `Modules/Forecasting/tests/Unit/` (when present)
- **What they test:** each pipeline collaborator in isolation —
  `BalanceAnchorResolver` against fixture accounts; `RangeProjector`
  - `CadenceJitter` against synthetic recurring series; `DailyFold`
  - `Percentile` against fixed contribution lists; `ScenarioApplier`
  against typed payload variants; `ShortfallDetector` against
  curve fixtures crossing buffer thresholds; the
  `ForecastRunStateMachine` transitions + the
  `InvalidForecastRunTransitionException`.

## Feature tests

- **Location:** `Modules/Forecasting/tests/Feature/`
- **What they test:**
  - The eleven Public actions end-to-end, including the launchpad
    atomic actions' transaction safety.
  - The cross-user 404 posture on every action + Livewire mount.
  - The scenario CRUD lifecycle + the matching events.
  - The chain-aware routing against multi-account fixtures.
  - The shortfall detector's window detection + `ForecastShortfallDetected`
    dispatch.
  - The opening-balance editor's
    `OpeningBalanceDivergenceWarning` path.
  - The six Livewire SFCs (`ForecastPage`, `AccountBufferEditor`,
    `ForecastHighlightsTile`, `ScenarioEditorSidebar`,
    `ModelWhatIfDropdown`, `OpeningBalanceEditor`).
  - The sidebar badge composer's count query + memoisation.
- **Setup:** every test uses `RefreshDatabase`. Tests that drive
  the queued projection use `Queue::fake()` or the in-memory
  worker for the listener-fan-out contracts.

## Contract / arch invariants

- `noScenarioMutationsJoinedToTransactionQueries` — the load-
  bearing one. Forbids any JOIN that couples
  `forecast_scenario_mutations` to `transactions`,
  `recurring_series`, or `chain_links`. The scenario substrate is
  walled off from the ledger.
- `noForecastRunStateWritesOutsideMachine` — only
  `ForecastRunStateMachine` may write `forecast_runs.state`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Forecasting/tests

# Just the projection pipeline
vendor/bin/pest Modules/Forecasting/tests/Unit --filter "Pipeline"

# Just the scenario CRUD
vendor/bin/pest Modules/Forecasting/tests/Feature --filter "Scenario"

# Stop on first failure
vendor/bin/pest Modules/Forecasting/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A scenario change does not re-project** — confirm
  `ProjectForecastOnScenarioChange` is subscribed (the provider's
  `registerListeners()` is the wiring point) and that the action
  raised the matching event. Tail `/dev/logs` for the listener's
  job-dispatch log line.
- **The shortfall band is missing on a chart the user expects** —
  read the underlying `forecast_runs.result_json`; the
  `shortfall_windows` array is empty when the curve stayed above
  the buffer. Check `accounts.forecast_buffer_minor` for the
  account; the default is zero.
- **The percentile bands look identical to the median** — the
  cadence jitter for every series in the contribution set was
  zero. `CadenceJitter` produces zero jitter only for a series
  with perfectly stable historical cadence; this is usually
  correct.
- **`OpeningBalanceDivergenceWarning` raised but the user wants
  the override anyway** — the warning is informational, not
  blocking; the write completes. The Livewire SFC re-renders the
  warning text; the user clicks confirm.
- **A launchpad action's two underlying writes diverge** —
  the launchpad wraps both in a DB transaction; a half-applied
  state should never land. Check `forecast_scenarios` for an
  orphan row without any matching `forecast_scenario_mutations`;
  if present, the transaction failed partway — surface as a
  Rule 1 bug and add a covering feature test.
- **The sidebar forecast badge stays at zero after a shortfall
  was detected** — the composer's memo cache (`&$cache` per
  user id) is request-scoped; a Livewire roundtrip in the same
  response uses the cached value. If the badge is stale across
  requests, the COUNT query in
  `ForecastHighlightsQuery::activeShortfallCountForUser` returned
  zero — confirm `forecast_shortfall_windows` has the expected
  rows and that the window's `window_end` is still in the future.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Forecasting` module.

## Behavioral contracts

- **Scenarios never touch the transaction substrate.** The
  `noScenarioMutationsJoinedToTransactionQueries` arch invariant
  blocks any JOIN that couples `forecast_scenario_mutations` to
  `transactions`, `recurring_series`, or `chain_links`. Scenarios
  are an in-memory transform applied by `ScenarioApplier`.
- **`ForecastRunStateMachine` is the SOLE sanctioned mutator of
  `forecast_runs.state`.** Allowed transitions: `pending →
  running → complete | failed`.
- **Every scenario CRUD action raises exactly one event.**
  `ScenarioCreated`, `ScenarioMutated`, or `ScenarioDeleted`
  fires once per successful action; the `ProjectForecastOnScenarioChange`
  listener fans out the projection.
- **Launchpad actions are atomic.**
  `CreateCancellationScenarioForAlert`,
  `CreateCancellationScenarioForSeries`, and
  `CreateAmountChangeScenarioForSeries` each wrap a
  `CreateScenario` + `AddScenarioMutation` pair in a DB
  transaction; a half-applied launchpad scenario can never land.
- **Scenario names are unique per user.** UNIQUE
  `(user_id, name)`; the rename action has a deterministic conflict
  surface.
- **The shortfall detector writes one row per detected window AND
  dispatches `ForecastShortfallDetected` once per window.** The
  user sees the shortfall band on the chart; the OS notification
  fires through the `Desktop` dispatcher (bundle-only).
- **Per-account buffer is the comparison threshold.**
  `accounts.forecast_buffer_minor` is what the detector compares
  against; a buffer of zero means "any negative balance is a
  shortfall".
- **`SetAccountOpeningBalance` raises
  `OpeningBalanceDivergenceWarning` when the manual override
  diverges from the statement-derived anchor by more than the
  documented threshold.** The warning does not block the write;
  the user is informed and can confirm.
- **Re-projection triggers cover every upstream that affects the
  projection.** `Recurring`'s Approved / CadenceFlipped /
  Rejected / MetricsRefreshed; `DriftAlerts`'
  DismissedCancelled; the three scenario lifecycle events.
- **Cross-user reads / writes return 404.** Every action +
  Livewire mount filters by `(id, user_id)`.
- **`forecast_scenarios.user_id` is non-nullable + cascade-on-
  delete.** A NULL `user_id` cannot land; deleting the user wipes
  the scenarios cleanly.
- **The projection is deterministic against the input set.**
  Same inputs (scenario, recurring set, anchor, horizon) produce
  the same `result_json`. `CadenceJitter` uses a deterministic
  per-series seed so the "noise" is reproducible.
- **The percentile bands are computed from the modelled cadence
  jitter, not from random sampling.** Each series's confidence
  surfaces as P10/P50/P90; the bands reflect modelling
  uncertainty rather than a Monte-Carlo run.

## Edge cases

- **An account with no recurring series + zero opening balance** —
  the curve is flat at zero; no shortfall windows; the
  `ForecastDto` carries `shortfallWindows = []`.
- **A scenario with zero mutations** — `ScenarioApplier::apply`
  returns the routed contributions unchanged; the result equals
  the baseline.
- **A scenario that cancels every recurring series** — the curve
  trends to (or stays at) the anchor; the user can compare the
  "do nothing" baseline against the "cancel everything" scenario
  side by side.
- **A chain-routed contribution whose funder account is not
  the user's** (theoretical edge — the contributions come from
  the user's own series) — the router falls back to the source
  account; the chain hint is logged.
- **A `ProjectForecastJob` retry after a worker crash** — the
  state machine carries `running → failed` via the job's
  `failed()` hook; the next dispatch starts fresh from
  `pending`.
- **A re-projection triggered while a prior run is still
  running** — the new job dispatches; the new run replaces the
  prior `result_json` once complete. No uniqueness lock today on
  the projection job; the projection is fast enough that
  concurrent runs settle on the latest input.
- **The user overrides the opening balance lower than the latest
  statement-derived anchor** — `OpeningBalanceDivergenceWarning`
  surfaces; the user can confirm intentionally (e.g. accounting
  for a pending charge not yet on a statement).

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`,
    `BelongsToUser`, `CurrentUser`.
  - [`Recurring`](../recurring/how-to-test.md) —
    `RecurringSeriesQuery` reads + four event subscriptions.
  - [`DriftAlerts`](../drift-alerts/how-to-test.md) —
    `DriftAlertDismissedCancelled` event + `CancellationImpactQuery`.
  - [`Ledger`](../ledger/how-to-test.md) — account reads (no writes).
  - [`Chains`](../chains/how-to-test.md) — `CardStatementQuery::nextSettlement`,
    `forecastTiles`; chain routing inputs.
- **Depended on by**
  - [`Desktop`](../desktop/how-to-test.md) — subscribes to
    `ForecastShortfallDetected` for OS notifications.
  - The dashboard layout — renders
    `ForecastHighlightsTile`.
  - The app sidebar — reads
    `ForecastHighlightsQuery::activeShortfallCountForUser` via
    the nav badge composer.

## Configuration + feature flags

- `accounts.forecast_buffer_minor` — per-account buffer threshold
  the shortfall detector compares against.
- `accounts.opening_balance_minor` — per-account user-set opening
  balance override.
- The 30 / 60 / 90 horizon options are fixed in the
  `ForecastPage` SFC; no config knob today.
- `CadenceJitter`'s seed scheme is deterministic per series; no
  user-visible knob.
- No per-user opt-out for the shortfall detector; the buffer is
  the chokepoint.
