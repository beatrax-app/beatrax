# `Recurring` — how to test

Practical recipes for exercising the `Recurring` module in
isolation.

## Unit tests

- **Location:** `Modules/Recurring/tests/Unit/` (when present)
- **What they test:** the `CadenceInferrer` classification
  against fixture occurrence sequences; the
  `ClusterKeyComposer` against canonical transactions; the
  state machine's allowed-transition matrix; the
  detectors against synthetic input rows.

## Feature tests

- **Location:** `Modules/Recurring/tests/Feature/`
- **What they test:**
  - The full detect-job lifecycle on a realistic month of
    transactions (assert series rows + events).
  - The seven Public actions end-to-end (transition + event +
    audit row).
  - The `RecurringPage`, `RecurringReviewPage`,
    `RecurringSeriesDetailPage` Livewire SFCs.
  - The `FixedPaymentsCard` dashboard tile.
  - The top-nav badge memoisation.
  - The cross-user 404 posture on every action + mount.
  - The cadence-flip path (an `approved` series whose
    inferred cadence shifted).
  - The `RecurringSeriesQuery::lastTwoOccurrences` shape
    consumed by `DriftAlerts`.

## Contract / arch invariants

- The repo-wide
  `noRecurringSeriesStateWritesOutsideMachine` — only
  `Internal\StateMachines\RecurringSeriesStateMachine` may
  write `recurring_series.state`.
- The repo-wide module-boundary invariant — forbids any
  external module from importing
  `Modules\Recurring\Internal\*` or
  `Modules\Recurring\Models\*`. External reads go through
  the Public `RecurringSeriesQuery`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Recurring/tests

# Just the detector + cadence math
vendor/bin/pest Modules/Recurring/tests --filter "Detector|Cadence"

# Just the state machine
vendor/bin/pest Modules/Recurring/tests --filter "StateMachine"

# Stop on first failure
vendor/bin/pest Modules/Recurring/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A real subscription not being detected as a series** —
  walk the detector path. The most common cause is the
  user's history did not yet contain enough occurrences for
  the cadence inferrer to settle on a stable cadence (the
  default window is two months; a quarterly subscription
  needs three months minimum to land as a candidate).
  Increase `users.recurring_detection_window` for the user
  and re-run.
- **A series's cadence flipping repeatedly between two
  values** — the `CadenceInferrer` is on the boundary. The
  series's variance tolerance can be widened via
  `EditRecurringSeriesVarianceTolerance` to stabilise.
- **`RecurringSeriesMetricsRefreshed` not firing on a new
  occurrence** — the new occurrence didn't get linked to
  the series. Check `recurring_series_occurrences` for a
  row pointing at the new `transactions.id`; if missing, the
  detector pass didn't run (confirm the import dispatched
  detection via `DispatchesRecurringDetection`).
- **A pending series the user keeps re-detecting after
  reject** — the rejected row's cluster key matches the
  re-detected one; the detector dedups, but if the
  cluster key changed (because the user added an alias
  that renamed the counterparty), a new pending row appears.
  Pattern: edit the existing rejected row instead (via the
  series detail page).
- **The badge shows a stale pending count after Approve** —
  the composer reads
  `RecurringSeriesQuery::pendingCountForUser` per render.
  A Livewire roundtrip that didn't re-render the nav uses
  the prior payload's count; a hard reload forces a fresh
  read.
- **`DetectRecurringSeriesJob::handle` not resolving its
  detectors** — `iterable<SeriesDetector>` can't be
  auto-resolved by Container::call; the provider's
  `bindMethod()` is the resolution path. If detectors are
  missing at runtime, the `recurring.detector` tag is empty
  — confirm the detectors are bound + tagged in
  `register()`.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Recurring` module.

## Behavioral contracts

- **Detection never auto-approves a series.** Every detected
  series lands as `pending`; the only path to `approved` is
  the user's explicit `ApproveRecurringSeries` call. The
  "always suggest, never auto-apply" posture is the
  product's load-bearing UX guarantee.
- **`RecurringSeriesStateMachine` is the SOLE sanctioned
  mutator of `recurring_series.state`.** Any other write is
  forbidden by the
  `noRecurringSeriesStateWritesOutsideMachine` arch
  invariant. The audit row in
  `recurring_series_transitions` is appended on every
  transition.
- **Allowed transitions:**
  `pending → approved | rejected | snoozed`;
  `snoozed → pending | approved | rejected`;
  `approved ↔ cadence_changed`;
  `rejected → pending` (un-reject).
  Any other transition raises
  `InvalidStateTransitionException`.
- **Detection is idempotent on the cluster key.** A re-run
  against the same input upserts via
  `UNIQUE(user_id, cluster_counterparty_key)`; existing rows
  are touched but not duplicated.
- **A new occurrence on an `approved` series raises
  `RecurringSeriesMetricsRefreshed`.** `Forecasting` and
  `DriftAlerts` both subscribe; the projection re-runs and
  the drift evaluator re-checks the latest occurrence.
- **A cadence flip raises `RecurringSeriesCadenceFlipped`.**
  An `approved` series whose inferred cadence drifted to a
  new stable cadence (e.g. monthly → bi-monthly) flips to
  `cadence_changed`; `Forecasting` re-projects with the new
  cadence.
- **`RecurringSeriesDetected` fires once per first
  detection.** Re-detections on subsequent imports of the
  same series do NOT re-fire the detected event.
- **`Approve` / `Reject` / `UnReject` / `Snooze` each raise
  exactly one event.** The action layer is the single
  sanctioned event-emit site for each transition.
- **`SetDriftThresholdForSeries` updates
  `recurring_series.drift_threshold_percent`.** The per-
  series override consumed by `DriftAlerts`; clamped by the
  5% floor at evaluation time (not at write time — the user
  can set any value; the evaluator decides the effective
  threshold).
- **`RecurringSeriesQuery::lastTwoOccurrences` is the SOLE
  sanctioned external read of recurring occurrences.**
  `DriftAlerts::DriftEvaluator` and any other consumer never
  query the `recurring_series_occurrences` table directly.
- **`pending_count_for_user` query is a single COUNT
  against `(user_id, state='pending')`.** The top-nav badge
  read is hot; no JOIN, no aggregation.
- **An irregular-cadence series is detected but not
  eligible for drift.** The `CadenceInferrer` returns
  `irregular`; `DriftAlerts` filters those out.
- **Cross-user reads / writes return 404.** Every Public
  action + Livewire mount filters by `(id, user_id)`.

## Edge cases

- **A user with no recent transactions** — the detector
  returns an empty list; no rows written; no events fired.
- **A series whose cadence inference flips between runs** —
  the state machine's `approved → cadence_changed` (and
  vice versa) is the documented transition; the audit row
  carries the reason.
- **An import containing only one new occurrence for an
  `approved` series** — no new series row; one
  `RecurringSeriesMetricsRefreshed` event for the existing
  row.
- **A user rejecting a series the detector re-detects on
  the next sweep** — re-detection is suppressed for
  `rejected` rows; the cluster key is the dedup signal. A
  user changing their mind clicks UnReject.
- **A snooze whose `until` date is in the past** — the
  state machine handles via the documented
  `snoozed → pending` transition path; there's no
  background revival job today (the user re-visits the
  triage queue).
- **A series with one outlier occurrence** — the
  variance-tolerance field gates the outlier; a user can
  edit the tolerance via
  `EditRecurringSeriesVarianceTolerance`.
- **Two detectors emitting the same cluster key** — the
  cluster key is per-detector-aware (the key includes the
  series's `kind` so `expense` and `income` clusters with
  the same counterparty don't collide).

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`,
    `BelongsToUser`, `CurrentUser`.
  - [`Ledger`](../ledger/how-to-test.md) — reads
    `transactions` to compose occurrences; never writes.
- **Depended on by**
  - [`Forecasting`](../forecasting/how-to-test.md) — subscribes
    to four events (`Approved`, `Rejected`,
    `CadenceFlipped`, `MetricsRefreshed`); reads
    `RecurringSeriesQuery`.
  - [`DriftAlerts`](../drift-alerts/how-to-test.md) — subscribes
    to `MetricsRefreshed`; reads
    `RecurringSeriesQuery::lastTwoOccurrences`.
  - The shared layout — reads
    `RecurringSeriesQuery::pendingCountForUser` via the
    top-nav badge composer.
  - The dashboard layout — renders `FixedPaymentsCard`.

## Configuration + feature flags

- `users.recurring_detection_window` (per-user) — default
  two months; lowered from a higher original default in the
  `2026_05_24_233044` migration.
- `users.drift_alert_threshold_percent` (per-user global)
  — consumed by `DriftAlerts` as the user-global threshold.
- `recurring_series.drift_threshold_percent` (per-series
  override) — consumed by `DriftAlerts`.
- The cadence-classification thresholds (what counts as
  "weekly", "monthly") live in code in the
  `CadenceInferrer`; not user-tunable.
- The variance-tolerance field is per-series.
