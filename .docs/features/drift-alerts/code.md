# `DriftAlerts` — code

The file-level map for the module.

## Directory layout

```
Modules/DriftAlerts/
├── Public/
│   ├── Actions/
│   │   ├── AcknowledgeDriftAlert.php
│   │   ├── SnoozeDriftAlert.php
│   │   └── DismissDriftAlertAsCancelled.php
│   ├── Dto/
│   │   ├── DriftAlertDto.php
│   │   └── CancellationImpactDto.php
│   ├── Events/
│   │   ├── DriftAlertOpened.php
│   │   ├── DriftAlertAcknowledged.php
│   │   ├── DriftAlertSnoozed.php
│   │   └── DriftAlertDismissedCancelled.php
│   ├── Services/
│   │   ├── DriftAlertQuery.php
│   │   └── CancellationImpactQuery.php
│   └── Http/Livewire/
│       ├── DashboardDriftBadge.php
│       ├── DriftThresholdEditor.php
│       └── SavingsInsightsCard.php
├── Internal/
│   ├── DriftEvaluator.php
│   ├── AmountMovement.php
│   ├── CadenceYearRate.php
│   ├── StateMachines/
│   │   ├── DriftAlertStateMachine.php
│   │   └── DriftAlertNotFoundException.php
│   ├── Jobs/
│   │   ├── DetectDriftAlertsJob.php
│   │   └── RevivedExpiredDriftSnoozesJob.php
│   ├── Listeners/
│   │   └── EvaluateDriftOnMetricsRefreshed.php
│   ├── Mapping/
│   │   └── DriftAlertDtoMapper.php
│   └── Http/Livewire/
│       ├── DriftPage.php
│       └── SubscriptionDriftWatchPage.php
├── Models/
│   ├── DriftAlert.php
│   └── DriftAlertTransition.php
├── Database/
│   ├── Migrations/
│   │   ├── 2026_05_19_010001_create_drift_alerts_table.php
│   │   ├── 2026_05_19_010002_create_drift_alert_transitions_table.php
│   │   └── 2026_06_07_010001_create_savings_insight_dismissals_table.php
│   ├── Factories/
│   │   ├── DriftAlertFactory.php
│   │   └── DriftAlertTransitionFactory.php
│   └── Seeders/
│       └── Demo/DemoDriftAlertsSeeder.php
├── Routes/
│   └── web.php
├── Resources/views/
├── Providers/
│   └── DriftAlertsServiceProvider.php
└── tests/
    ├── Unit/
    ├── Feature/
    └── fixtures/drift-corpus/   (one file per real-world shape)
```

## Public API

- **Actions/**
  - `AcknowledgeDriftAlert::__invoke($alertId, $user)`,
    `SnoozeDriftAlert::__invoke($alertId, $until, $user)`,
    `DismissDriftAlertAsCancelled::__invoke($alertId, $user)`.
- **DTOs/**
  - `DriftAlertDto` — flattened row carrying series metadata for
    rendering.
  - `CancellationImpactDto` — `(recurringSeriesId, monthlySavings,
    annualSavings, currency)`.
- **Events/**
  - `DriftAlertOpened` (`alertId, userId, seriesId, deltaMinor`).
  - `DriftAlertAcknowledged`, `DriftAlertSnoozed`,
    `DriftAlertDismissedCancelled` — each carrying the alert id and
    user id.
- **Services/**
  - `DriftAlertQuery::openCountForUser($user)` — open-alert count
    (single COUNT against the `(user_id, state)` index). The sidebar
    badge applies the same predicate from `NavCountsService`.
  - `DriftAlertQuery::openForUser($user, $cursorId, $limit)` — the
    flat open list, keyset-paged on `(detected_at, id)` rather than
    offset-paged.
    `historyForUser(...)` and `dismissedForUser(...)` are the same
    shape over the acknowledged and dismissed states, and are what the
    History and Dismissed tabs render.
  - `DriftAlertQuery::groupedBySeriesForUser($user, $seriesLimit)` —
    the per-series grouping the Open tab actually renders, bounded to
    `$seriesLimit` series. The Open tab does NOT call `openForUser`:
    it once called it on every render and discarded the result.
  - `CancellationImpactQuery::forSeries($seriesId, $user)` —
    projected savings: the monthly equivalent, and the year at the
    series' own price times its cadence rate. Keyed on the recurring
    series, not on an alert id;
    `forSeriesIds($seriesIds, $user)` is the batched form the open tab
    calls once instead of looping.

## Internal services

- `Internal/DriftEvaluator` — the math. Reads through
  `RecurringSeriesQuery`, computes delta, applies effective
  threshold, INSERTs under the id derived from
  `(recurring_series_id, latest_occurrence_id)`, and emits the
  `EntityMutated` create. Idempotent on the unique constraint.
- `Internal/AmountMovement` — whether two amounts describe a movement at
  all; refuses a zero prior, a currency change and a sign flip. The
  evaluator and `SubscriptionDriftWatchQuery` share it, so the alert and
  the watchlist cannot disagree about what counts as drift.
- `Internal/CadenceYearRate` — the one spelling of how many times a year
  each cadence bills, read by the evaluator's annualisation and by
  `CancellationImpactQuery`.
- `Internal/StateMachines/DriftAlertStateMachine` — SOLE sanctioned
  mutator of `drift_alerts.state`. Validates the transition, records a
  `drift_alert_transitions` audit row, and emits the `EntityMutated`
  edit afterwards, so every acknowledge, snooze, dismissal and revival
  reaches the peer through one dispatch.
- `Internal/StateMachines/DriftAlertNotFoundException` — raised
  inside the state machine's transaction when the `lockForUpdate`
  lookup comes back null: the row vanished after the caller handed
  over the model. It is the only exception this module declares.
  `InvalidStateTransitionException`, thrown for any disallowed
  transition, is `Modules\Core\Public\StateMachine`'s — the
  guarded-state-machine base raises one sentinel for every module that
  builds on it, so a caller catching it does not need to know which
  machine rejected the move.
- `Internal/Jobs/DetectDriftAlertsJob` — queued evaluation per
  `(user, series)`. `ShouldBeUniqueUntilProcessing` on
  `uniqueId() = "{userId}-{seriesId}"`.
- `Internal/Jobs/RevivedExpiredDriftSnoozesJob` — scheduled sweep
  that revives expired snoozes.
- `Internal/Listeners/EvaluateDriftOnMetricsRefreshed::handle($event)`
  — translates the Recurring event into a job dispatch.
- `Internal/Mapping/DriftAlertDtoMapper` — pure mapper from a raw
  DB row to `DriftAlertDto`.
- `Internal/Http/Livewire/DriftPage` — the `/drift` page.
- `Public/Http/Livewire/DashboardDriftBadge` — the open-count
  badge on the dashboard.
- `Public/Http/Livewire/DriftThresholdEditor` — the per-series +
  user-global threshold editor.

## Models + migrations

- `Models/DriftAlert` — maps to `drift_alerts`. Uses
  `BelongsToUser`. Casts: `detected_at`, `snoozed_until` as
  `immutable_datetime`. The `state` column is enforced by paired
  BEFORE INSERT / BEFORE UPDATE OF `state` triggers; the state
  machine is the only sanctioned writer.
- `Models/DriftAlertTransition` — maps to `drift_alert_transitions`.
  The append-only audit log for state changes; each row carries
  `(alert_id, from_state, to_state, reason, recorded_at)`.

Migrations:

- `2026_05_19_010001_create_drift_alerts_table.php` — the alerts
  table. Money columns BIGINT signed minor-units; UNIQUE
  `(recurring_series_id, latest_occurrence_id)` is the evaluator's
  idempotency seam. Three read indexes for the badge / page /
  drill-in hot paths.
- `2026_05_19_010002_create_drift_alert_transitions_table.php` —
  the audit log.
- `2026_06_07_010001_create_savings_insight_dismissals_table.php` —
  the per-user set of dismissed savings insights, keyed on the stable
  insight key `SavingsInsightsQuery` composes so a recomputed insight
  set filters against it by key rather than by row id.

## Provider wiring

`DriftAlertsServiceProvider::register()`:

- Singletons `DriftAlertStateMachine`, `DriftEvaluator`,
  `DriftAlertQuery`, `CancellationImpactQuery`,
  `AcknowledgeDriftAlert`, `SnoozeDriftAlert`,
  `DismissDriftAlertAsCancelled`.
- Queued jobs and Livewire components are intentionally NOT bound
  as singletons (jobs are serialised; components are
  per-request).

`DriftAlertsServiceProvider::boot()`:

- Loads migrations, routes, views (all file-/dir-existence guarded).
- Registers this module's Livewire components under the
  `drift-alerts.*` namespace.
- Subscribes `EvaluateDriftOnMetricsRefreshed` to
  `Recurring::RecurringSeriesMetricsRefreshed`.
- Registers no view composer. The sidebar's drift badge is one of the
  counts `Core`'s `NavCountsService` computes, so the number has a
  single source.
