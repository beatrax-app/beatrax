# `DriftAlerts` — architecture

The `DriftAlerts` module watches every approved recurring series and
raises a `drift_alerts` row when the latest occurrence's amount
deviates from the prior occurrence by more than the effective
threshold. It hosts the `/drift` page, the dashboard drift-count
badge, the top-nav drift badge, the per-series threshold override
editor, and the queued evaluation that fans out from the
`Recurring::RecurringSeriesMetricsRefreshed` event.

## What this module is for

Subscription drift is the quiet expense increase: Netflix raised the
plan by €2; the energy supplier's pre-pay went from €120/m to
€155/m; ICS rebilled a USD subscription at a worse FX rate. The user
never gets a separate email about it; the difference just shows up
buried in the next month's bill. This module is the watchdog: every
time `Recurring` refreshes the metrics for a series, the evaluator
re-checks the last two occurrences and decides whether the change is
large enough to surface.

The default threshold is 5%; the user can override globally via
`/settings`, or per-series via the per-series editor on the drift
page. The effective threshold for any one series is
`max(perSeriesOverride, userGlobal, 5%)` — the 5% hard floor exists
so a careless override cannot silence real drift.

What the module explicitly does NOT do:

- It never decides what "recurring" means. The
  `RecurringSeriesQuery` Public surface owned by
  [`Recurring`](../recurring/architecture.md) is the source of truth;
  the evaluator reads through that surface and never imports the
  recurring module's `Internal\` namespace.
- It never auto-acknowledges an alert. The user is the only signal
  that closes a row.
- It never writes the recurring series's state. The
  `cadence_changed` state is set by `Recurring`; the evaluator
  treats both `approved` and `cadence_changed` series uniformly
  (both have stable enough cadence to compare amounts) but does not
  write back.

## Module boundary

`Public/` exposes the action + read-side surface:

- **Actions/**
  - `AcknowledgeDriftAlert::__invoke($alertId, $user)` — moves the
    alert to `acknowledged`.
  - `SnoozeDriftAlert::__invoke($alertId, $until, $user)` — moves
    the alert to `snoozed` with the until-date stamped.
  - `DismissDriftAlertAsCancelled::__invoke($alertId, $user)` —
    moves the alert to `dismissed_cancelled`; the user is saying
    "I cancelled the underlying subscription".
- **DTOs/**
  - `DriftAlertDto` — flattened read-model row.
  - `CancellationImpactDto` — projected annual savings if the user
    cancels.
- **Events/**
  - `DriftAlertOpened` (`alertId, userId, seriesId, deltaMinor`) —
    raised by the evaluator on every new `open` row. Consumed by
    `Desktop::DispatchOsNotification`.
  - `DriftAlertAcknowledged`, `DriftAlertSnoozed`,
    `DriftAlertDismissedCancelled` — raised by the corresponding
    actions.
- **Services/**
  - `DriftAlertQuery::openCountForUser($user)`, `listFor($user)`,
    `forSeries($seriesId, $user)`.
  - `CancellationImpactQuery::computeFor($alertId, $user)`.

`Internal/` houses the implementation:

- **Internal/DriftEvaluator** — the math: read last two occurrences
  through `RecurringSeriesQuery`, compute signed delta in the
  series's currency, apply effective threshold, INSERT one row
  when crossed. Idempotent on `UNIQUE(recurring_series_id,
  latest_occurrence_id)`.
- **Internal/StateMachines/DriftAlertStateMachine** — the SOLE
  legal mutator of `drift_alerts.state`. Allowed transitions:
  `open → acknowledged | snoozed | dismissed_cancelled`,
  `snoozed → open | acknowledged | dismissed_cancelled`. Throws
  `InvalidStateTransitionException` on any other.
- **Internal/Listeners/EvaluateDriftOnMetricsRefreshed** — queues
  `DetectDriftAlertsJob` per `(user, series)` from the Recurring
  event.
- **Internal/Jobs/DetectDriftAlertsJob** — runs the evaluator for
  one `(user, series)` pair. `ShouldBeUniqueUntilProcessing` keyed
  on `(user, series)` collapses concurrent triggers.
- **Internal/Jobs/RevivedExpiredDriftSnoozesJob** — sweeps
  expired snoozes back to `open` so the user sees them again.
- **Internal/Mapping/DriftAlertDtoMapper** — DB row → DTO.
- **Internal/Http/Livewire/** — `DriftPage` (`/drift`),
  `DashboardDriftBadge`, `DriftThresholdEditor`.

## Key services + events

- `DriftEvaluator::evaluate($seriesId, $user)` — the math. Reads
  the last two occurrences through `RecurringSeriesQuery`, computes
  `delta_minor`, applies the effective threshold (per-series →
  user-global → 5% floor, max), inserts on threshold-crossing.
  Guards against divide-by-zero on a prior amount of zero (the
  `prior-zero.php` fixture covers it). Idempotent via the unique
  constraint.
- `DriftAlertStateMachine::transition($alert, $next, $reason)` —
  the single sanctioned mutator of `drift_alerts.state`. Records
  the transition in `drift_alert_transitions` as the audit trail.
- `DetectDriftAlertsJob::handle()` — fans out from the listener;
  calls the evaluator; dispatches `DriftAlertOpened` on a fresh
  insertion.
- `RecurringSeriesMetricsRefreshed` (raised by `Recurring`) — the
  external trigger. The listener subscribes via the provider's
  `registerListener()` private method.
- The top-nav badge composer (`registerTopNavBadgeComposer()`)
  injects `driftOpenCount` into `core::livewire.top-nav` via the
  ViewFactory contract (no `view()` helper).

## Data flow

The drift-detection trigger chain:

```
Recurring metrics refresh
  → RecurringSeriesMetricsRefreshed($seriesId, $userId)
  → EvaluateDriftOnMetricsRefreshed
       → dispatch DetectDriftAlertsJob($userId, $seriesId)
            (ShouldBeUniqueUntilProcessing on (userId, seriesId))

DetectDriftAlertsJob handle()
  → DriftEvaluator::evaluate($seriesId, $user)
       → RecurringSeriesQuery::lastTwoOccurrences($seriesId, $user)
       → compute delta_minor + ratio
       → effective threshold = max(perSeriesOverride,
                                   userGlobal,
                                   5%)
       → if |ratio| > threshold:
            INSERT INTO drift_alerts (...)
              ON UNIQUE-CONFLICT no-op
            → if a new row was inserted:
                 dispatch DriftAlertOpened
                   → Desktop::DispatchOsNotification (bundle-only)
```

The user-facing actions:

```
/drift
  → DriftPage Livewire SFC
       → DriftAlertQuery::listFor($user)
       → user clicks Acknowledge / Snooze / Dismiss cancelled
            → corresponding Public action
                 → DriftAlertStateMachine::transition
                 → record drift_alert_transitions row
                 → dispatch DriftAlert{Acknowledged|Snoozed|DismissedCancelled}

/drift threshold editor
  → DriftThresholdEditor SFC
       → write per-series override OR user-global threshold

scheduler tick
  → RevivedExpiredDriftSnoozesJob
       → for each (user, alert) where state=snoozed AND
                   snoozed_until <= now():
            DriftAlertStateMachine::transition($alert, 'open',
                                                'snooze-expired')
```
