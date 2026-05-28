# `Recurring` — specs

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
  - [`Core`](../core/specs.md) — `User`, `Clock`,
    `BelongsToUser`, `CurrentUser`.
  - [`Ledger`](../ledger/specs.md) — reads
    `transactions` to compose occurrences; never writes.
- **Depended on by**
  - [`Forecasting`](../forecasting/specs.md) — subscribes
    to four events (`Approved`, `Rejected`,
    `CadenceFlipped`, `MetricsRefreshed`); reads
    `RecurringSeriesQuery`.
  - [`DriftAlerts`](../drift-alerts/specs.md) — subscribes
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
