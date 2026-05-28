# `Forecasting` — how to test

Practical recipes for exercising the `Forecasting` module in
isolation.

## Unit tests

- **Location:** `Modules/Forecasting/tests/Unit/` (when present)
- **What they test:** each pipeline collaborator in isolation —
  `BalanceAnchorResolver` against fixture accounts; `RangeProjector`
  + `CadenceJitter` against synthetic recurring series; `DailyFold`
  + `Percentile` against fixed contribution lists; `ScenarioApplier`
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
  - The top-nav badge composer's count query + memoisation.
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
- **The top-nav forecast badge stays at zero after a shortfall
  was detected** — the composer's memo cache (`&$cache` per
  user id) is request-scoped; a Livewire roundtrip in the same
  response uses the cached value. If the badge is stale across
  requests, the COUNT query in
  `ForecastHighlightsQuery::activeShortfallCountForUser` returned
  zero — confirm `forecast_shortfall_windows` has the expected
  rows and that the window's `window_end` is still in the future.
