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
  - The nine Public actions end-to-end, including the launchpad
    atomic action's transaction safety.
  - The cross-user 404 posture on every action + Livewire mount, and the
    soft reset the PAGE takes instead.
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
  - That the roll-ups count one movement once
    (`AReceiptIsNotASecondChargeTest`): both legs of a Google Play
    purchase land in the ledger, and the net-worth card and the
    all-accounts curve each subtract it a single time.
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
  `ForecastRunStateMachine` may write `forecast_runs.status`.

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
  the windows live in `forecast_shortfall_windows`, not in
  `forecast_runs.result_json`, and the chart reads only the rows whose
  `horizon_days` matches the horizon on screen. No row means the curve
  stayed above the buffer at that horizon. Check
  `accounts.forecast_min_buffer_minor` for the account; the default is
  zero.
- **The percentile bands look identical to the median** — the series
  took the envelope tier rather than the percentile one, so nothing was
  jittered. `CadenceJitter` smears only a contribution `RangeProjector`
  marked `dateIsUncertain`, which is the percentile tier alone; failing
  either bar of the two-part gate is usually correct.
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
  rows, that their `horizon_days` is the badge's own 30, and that
  `ends_at` is still in the future.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

- **Scenarios never touch the transaction substrate.** The
  `noScenarioMutationsJoinedToTransactionQueries` arch invariant
  blocks any JOIN that couples `forecast_scenario_mutations` to
  `transactions`, `recurring_series`, or `chain_links`. Scenarios
  are an in-memory transform applied by `ScenarioApplier`.
- **`ForecastRunStateMachine` is the SOLE sanctioned mutator of
  `forecast_runs.status`.** Allowed transitions: `pending →
  running → complete | failed`.
- **Every scenario CRUD action raises exactly one event.**
  `ScenarioCreated`, `ScenarioMutated`, or `ScenarioDeleted`
  fires once per successful action; the `ProjectForecastOnScenarioChange`
  listener fans out the projection.
- **The launchpad action is atomic.** `CreateScenarioFromTemplate`
  wraps a `CreateScenario` + `AddScenarioMutation` pair in a DB
  transaction; a half-applied launchpad scenario can never land.
- **A second click returns the first scenario, in any language.**
  The lookup is `existingScenarioIdForTemplate()` — mutation kind plus
  target series — never the translated name, which the reader may also
  have renamed.
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
  Livewire mount filters by `(id, user_id)`. `/forecast`'s own `?account=`
  and `?scenarioId=` are the exception, and deliberately so: a page
  answering "not yours" with a 404 and "nobody's" with a rendered page is an
  existence oracle over the id space, so both soft-reset to the reader's own
  view. See [url-parameters.md](url-parameters.md#one-answer-for-both-cases).
- **`forecast_scenarios.user_id` is non-nullable + cascade-on-
  delete.** A NULL `user_id` cannot land; deleting the user wipes
  the scenarios cleanly.
- **The projection is deterministic against the input set.**
  Same inputs (scenario, recurring set, anchor, horizon) produce
  the same `result_json`. There is no randomness anywhere in the
  pipeline: `CadenceJitter` spreads a fixed `100 / window` share over a
  fixed ±3-day window, with no seed and nothing to seed.
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
  - [`Chains`](../chains/how-to-test.md) —
    `CardStatementQuery::nextSettlementForUser` (the synthetic
    settlement contribution and the highlights tile) and
    `ChainLinkQuery::confirmedFundersForSeries` (the
    funder account chain routing sends a series to).
- **Depended on by**
  - [`Desktop`](../desktop/how-to-test.md) — subscribes to
    `ForecastShortfallDetected` for OS notifications.
  - The dashboard layout — renders
    `ForecastHighlightsTile`.
  - The app sidebar — reads
    `ForecastHighlightsQuery::activeShortfallCountForUser` via
    the nav badge composer.

## Configuration + feature flags

- `accounts.forecast_min_buffer_minor` — per-account buffer threshold
  the shortfall detector compares against.
- `accounts.opening_balance_minor` — per-account user-set opening
  balance override.
- The horizon options are the cases of `ForecastHorizon`
  (30 / 60 / 90 / 180 / 365) and the rail offers exactly those; no
  config knob today.
- `CadenceJitter`'s window is `WINDOW_DAYS = 3` and its share is
  `100 / window`; no seed, no user-visible knob.
- No per-user opt-out for the shortfall detector; the buffer is
  the chokepoint.
