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
│   └── Services/
│       ├── DriftAlertQuery.php
│       └── CancellationImpactQuery.php
├── Internal/
│   ├── DriftEvaluator.php
│   ├── StateMachines/
│   │   ├── DriftAlertStateMachine.php
│   │   └── InvalidStateTransitionException.php
│   ├── Jobs/
│   │   ├── DetectDriftAlertsJob.php
│   │   └── RevivedExpiredDriftSnoozesJob.php
│   ├── Listeners/
│   │   └── EvaluateDriftOnMetricsRefreshed.php
│   ├── Mapping/
│   │   └── DriftAlertDtoMapper.php
│   └── Http/Livewire/
│       ├── DriftPage.php
│       ├── DashboardDriftBadge.php
│       └── DriftThresholdEditor.php
├── Models/
│   ├── DriftAlert.php
│   └── DriftAlertTransition.php
├── Database/
│   ├── Migrations/
│   │   ├── 2026_05_19_010001_create_drift_alerts_table.php
│   │   └── 2026_05_19_010002_create_drift_alert_transitions_table.php
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
    └── fixtures/drift-corpus/   (24 representative fixtures)
```

## Public API

- **Actions/**
  - `AcknowledgeDriftAlert::__invoke($alertId, $user)`,
    `SnoozeDriftAlert::__invoke($alertId, $until, $user)`,
    `DismissDriftAlertAsCancelled::__invoke($alertId, $user)`.
- **DTOs/**
  - `DriftAlertDto` — flattened row carrying series metadata for
    rendering.
  - `CancellationImpactDto` — `(annualSavingsMinor, currency,
    perOccurrence, occurrencesPerYear)`.
- **Events/**
  - `DriftAlertOpened` (`alertId, userId, seriesId, deltaMinor`).
  - `DriftAlertAcknowledged`, `DriftAlertSnoozed`,
    `DriftAlertDismissedCancelled` — each carrying the alert id and
    user id.
- **Services/**
  - `DriftAlertQuery::openCountForUser($user)` — top-nav badge
    query (single COUNT against `(user_id, state)` index).
  - `DriftAlertQuery::listFor($user)` — drift page list ordered by
    `detected_at DESC`.
  - `DriftAlertQuery::forSeries($seriesId, $user)` — per-series
    drill-in.
  - `CancellationImpactQuery::computeFor($alertId, $user)` —
    projected annual savings (cadence-aware multiplication of the
    current per-occurrence amount).

## Internal services

- `Internal/DriftEvaluator` — the math. Reads through
  `RecurringSeriesQuery`, computes delta, applies effective
  threshold, INSERTs. Idempotent on the unique constraint.
- `Internal/StateMachines/DriftAlertStateMachine` — SOLE sanctioned
  mutator of `drift_alerts.state`. Validates the transition and
  records a `drift_alert_transitions` audit row.
- `Internal/StateMachines/InvalidStateTransitionException` — typed
  exception thrown for any disallowed transition.
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
- Registers three Livewire components under the `drift-alerts.*`
  namespace.
- Subscribes `EvaluateDriftOnMetricsRefreshed` to
  `Recurring::RecurringSeriesMetricsRefreshed`.
- Registers the top-nav badge composer via the ViewFactory
  contract (no `view()` helper). The composer carries a boot-scoped
  memo `&$cache` that collapses repeated renders within a single
  boot cycle to a single COUNT query.
