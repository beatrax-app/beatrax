# `Forecasting` — specs

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
  - [`Core`](../core/specs.md) — `User`, `Clock`,
    `BelongsToUser`, `CurrentUser`.
  - [`Recurring`](../recurring/specs.md) —
    `RecurringSeriesQuery` reads + four event subscriptions.
  - [`DriftAlerts`](../drift-alerts/specs.md) —
    `DriftAlertDismissedCancelled` event + `CancellationImpactQuery`.
  - [`Ledger`](../ledger/specs.md) — account reads (no writes).
  - [`Chains`](../chains/specs.md) — `CardStatementQuery::nextSettlement`,
    `forecastTiles`; chain routing inputs.
- **Depended on by**
  - [`Desktop`](../desktop/specs.md) — subscribes to
    `ForecastShortfallDetected` for OS notifications.
  - The dashboard layout — renders
    `ForecastHighlightsTile`.
  - The shared layout — reads
    `ForecastHighlightsQuery::activeShortfallCountForUser` via
    the top-nav badge composer.

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
