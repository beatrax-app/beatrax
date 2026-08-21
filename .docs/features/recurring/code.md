# `Recurring` — code

The file-level map for the module.

## Directory layout

```
Modules/Recurring/
├── Public/
│   ├── Contracts/
│   │   ├── SeriesDetector.php
│   │   └── DispatchesRecurringDetection.php
│   ├── Actions/         (7 actions covering the state-machine
│   │                      transitions + the editable fields)
│   ├── Dto/
│   │   ├── RecurringSeriesDto.php
│   │   ├── RecurringOccurrenceDto.php
│   │   ├── RecurringSeriesAmountTrendDto.php
│   │   └── NextExpectedChargeDto.php
│   ├── Events/          (5 events)
│   └── Services/
│       ├── RecurringSeriesQuery.php
│       └── FixedPaymentsViewQuery.php
├── Internal/
│   ├── CadenceInferrer.php
│   ├── Detection/
│   │   └── ClusterKeyComposer.php
│   ├── Detectors/
│   │   ├── ExpenseSeriesDetector.php
│   │   └── IncomeSeriesDetector.php
│   ├── Jobs/
│   │   └── DetectRecurringSeriesJob.php
│   ├── Services/
│   │   └── BusRecurringDetectionDispatcher.php
│   ├── StateMachines/
│   │   └── RecurringSeriesStateMachine.php
│   └── Http/Livewire/
│       ├── RecurringPage.php
│       ├── RecurringReviewPage.php
│       ├── RecurringSeriesDetailPage.php
│       └── FixedPaymentsCard.php
├── Models/
│   ├── RecurringSeries.php
│   ├── RecurringSeriesOccurrence.php
│   └── RecurringSeriesTransition.php
├── Database/Migrations/   (8 migrations covering the schema +
│                            evolution)
├── Routes/
│   └── web.php
├── Resources/views/
├── Providers/
│   └── RecurringServiceProvider.php
└── tests/
```

## Public API

- **Contracts/**
  - `SeriesDetector::detect(User $user, Period $window):
    list<DetectedSeriesDto>`. Tag `recurring.detector`.
  - `DispatchesRecurringDetection::dispatch(User $user):
    void`.
- **Actions/**
  - `ApproveRecurringSeries`, `RejectRecurringSeries`,
    `UnRejectRecurringSeries`, `SnoozeRecurringSeries`,
    `EditRecurringSeriesName`,
    `EditRecurringSeriesVarianceTolerance`,
    `SetDriftThresholdForSeries`.
- **DTOs/**
  - `RecurringSeriesDto` — `(id, name, cadence, baselineAmount,
    state, ...)`.
  - `RecurringOccurrenceDto` — per-occurrence row.
  - `RecurringSeriesAmountTrendDto` — chart data for the
    series detail.
  - `NextExpectedChargeDto` — projected next occurrence.
- **Events/**
  - `RecurringSeriesDetected`, `RecurringSeriesApproved`,
    `RecurringSeriesRejected`, `RecurringSeriesCadenceFlipped`,
    `RecurringSeriesMetricsRefreshed` — each carrying the
    `(seriesId, userId)` pair.
- **Services/**
  - `RecurringSeriesQuery::list($user, $filters):
    list<RecurringSeriesDto>`.
  - `RecurringSeriesQuery::forId($id, $user):
    ?RecurringSeriesDto`.
  - `RecurringSeriesQuery::lastTwoOccurrences($id, $user):
    list<RecurringOccurrenceDto>` — the read consumed by
    `DriftAlerts::DriftEvaluator`.
  - `RecurringSeriesQuery::pendingCountForUser($user): int`
    — pending-review count.
  - `FixedPaymentsViewQuery::for(User $user)` — dashboard
    card data.

## Internal services

- `Internal/CadenceInferrer::infer($occurrences): string` —
  classifies as `monthly` / `weekly` / `quarterly` /
  `yearly` / `irregular`. The `irregular` verdict makes a
  series ineligible for drift detection.
- `Internal/Detection/ClusterKeyComposer::compose($tx):
  string` — produces the cluster key
  (`{counterparty_normalized}|{amount_minor}|{cadence}` or
  similar) that groups occurrences into one series.
- `Internal/Detectors/ExpenseSeriesDetector::detect($user,
  $window)` — finds repeating expense clusters in the
  user's recent transactions.
- `Internal/Detectors/IncomeSeriesDetector::detect($user,
  $window)` — same for income (salary, recurring refunds).
- `Internal/Jobs/DetectRecurringSeriesJob::handle(DatabaseManager,
  Clock, iterable $detectors, RecurringSeriesStateMachine)`
  — the sweep job. Receives detectors via iterable injection
  bound by `bindMethod()` in the provider (Container::call
  can't auto-resolve `iterable<SeriesDetector>` because
  `iterable` has no class to instantiate).
- `Internal/Services/BusRecurringDetectionDispatcher` —
  concrete `DispatchesRecurringDetection` impl.
- `Internal/StateMachines/RecurringSeriesStateMachine::transition($series,
  $next, $reason)` — SOLE sanctioned mutator of
  `recurring_series.state`. Writes the audit row.
- `Internal/Http/Livewire/RecurringPage` — `/recurring`
  main list.
- `Internal/Http/Livewire/RecurringReviewPage` —
  `/recurring/review` triage queue (pending rows).
- `Internal/Http/Livewire/RecurringSeriesDetailPage` —
  `/recurring/{seriesId}`.
- `Public/Http/Livewire/FixedPaymentsCard` — dashboard
  tile.

## Models + migrations

- `Models/RecurringSeries` — maps to `recurring_series`.
  Uses `BelongsToUser`. State enforced by paired triggers;
  state machine is the sole mutator. Casts: `cadence` enum
  string; `baseline_amount` as `Money`; `meta` as `array`.
- `Models/RecurringSeriesOccurrence` — per-charge row linking
  the series to its `transactions.id` source.
- `Models/RecurringSeriesTransition` — append-only audit log
  of state changes.

Migrations (8 total):

- `2026_05_18_010001_create_recurring_series_table.php`.
- `2026_05_18_010002_create_recurring_series_occurrences_table.php`.
- `2026_05_18_010003_create_recurring_series_transitions_table.php`.
- `2026_05_18_010004_add_recurring_settings_to_users.php` —
  per-user detection window default.
- `2026_05_19_010001_add_cluster_counterparty_key_to_recurring_series.php`
  — the cluster-key column added when detection evolved.
- `2026_05_19_010002_add_drift_threshold_percent_to_recurring_series.php`
  — per-series drift override; consumed by `DriftAlerts`.
- `2026_05_19_010003_add_drift_alert_threshold_percent_to_users.php`
  — per-user global drift threshold.
- `2026_05_24_233044_lower_recurring_detection_window_default_to_two_months.php`
  — the documented default-window evolution.

## Provider wiring

`RecurringServiceProvider::register()`:

- Singletons every internal (state machine, detectors,
  cluster composer, cadence inferrer, dispatch dispatcher,
  every Public Action + query, the sweep job).
- Tags `ExpenseSeriesDetector` + `IncomeSeriesDetector`
  under `recurring.detector`.
- `bindMethod()` for `DetectRecurringSeriesJob::handle` —
  resolves the `iterable<SeriesDetector>` parameter from the
  tag. Covers both the sync queue driver (tests) and the
  database queue worker (production).
- Binds `DispatchesRecurringDetection` →
  `BusRecurringDetectionDispatcher`.

`RecurringServiceProvider::boot()`:

- Loads migrations, routes, views (file-/dir-existence
  guarded).
- Registers four Livewire components under the `recurring.*`
  namespace.
- Registers no view composer. The sidebar's Recurring badge is one of
  the counts `Core`'s `NavCountsService` computes, so the number has a
  single source.
