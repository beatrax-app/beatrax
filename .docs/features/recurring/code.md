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
│   │   └── RecurringSeriesAmountTrendDto.php
│   ├── Enums/
│   │   ├── RecurringSeriesState.php
│   │   └── SeriesCadence.php
│   ├── Events/          (7 events)
│   ├── Http/Livewire/
│   │   └── FixedPaymentsCard.php
│   └── Services/
│       ├── RecurringSeriesQuery.php
│       └── FixedPaymentsViewQuery.php
├── Internal/
│   ├── CadenceInferrer.php
│   ├── Detection/
│   │   └── ClusterKeyComposer.php
│   ├── Detectors/
│   │   ├── DetectedSeries.php
│   │   ├── ExpenseSeriesDetector.php
│   │   ├── IncomeSeriesDetector.php
│   │   ├── MerchantDisplayName.php
│   │   ├── OccurrenceWriter.php
│   │   └── SeriesRefresher.php
│   ├── Enums/
│   │   └── ReviewTab.php
│   ├── Jobs/
│   │   ├── DetectRecurringSeriesJob.php
│   │   └── EmitPaymentRemindersJob.php
│   ├── Mapping/
│   │   └── RecurringSeriesDtoMapper.php
│   ├── Queries/
│   │   ├── RecurringSeriesProjector.php
│   │   └── SeriesAccountResolver.php
│   ├── Services/
│   │   └── BusRecurringDetectionDispatcher.php
│   ├── StateMachines/
│   │   ├── RecurringSeriesStateMachine.php
│   │   └── SeriesRowVanishedException.php
│   ├── Support/
│   │   └── SeriesIds.php
│   └── Http/Livewire/
│       ├── RecurringPage.php
│       ├── RecurringReviewPage.php
│       └── RecurringSeriesDetailPage.php
├── Models/
│   ├── RecurringSeries.php
│   ├── RecurringSeriesOccurrence.php
│   └── RecurringSeriesTransition.php
├── Database/Migrations/   (10 migrations covering the schema +
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
  - `SeriesDetector::detectForUser(User $user): void`. Tag
    `recurring.detector`. It returns NOTHING — the detector
    reads its own window from
    `users.recurring_detection_window_months`, and writes the
    `recurring_series` rows and raises the events itself. The
    returning shape this page used to describe — a
    `detect(User, Period)` handing a list of DTOs back for the
    job to persist — was never in the code; the contract has
    had this signature since it landed. Detection cannot hand
    its results out and stay correct: two merchant keys can
    normalise onto one `cluster_key`, so a later cluster in the
    same sweep has to find the row the earlier one just wrote,
    and only the detector holds that in-sweep index.
  - `DispatchesRecurringDetection::dispatchForUser(int $userId):
    void` — takes the id, not the `User`. Idempotent at the
    contract layer: `ShouldBeUniqueUntilProcessing` inside the
    job collapses duplicate dispatches for the same user into
    one queued instance.
- **Actions/**
  - `ApproveRecurringSeries`, `RejectRecurringSeries`,
    `UnRejectRecurringSeries`, `SnoozeRecurringSeries`,
    `EditRecurringSeriesName`,
    `EditRecurringSeriesVarianceTolerance`,
    `SetDriftThresholdForSeries`.
- **DTOs/**
  - `RecurringSeriesDto` — `(seriesId, direction, detectedName,
    displayNameOverride, state, cadence, latestAmount,
    eurEquivalent, monthlyEquivalent, latestFundingChainLinkId,
    nextExpectedAt, nextExpectedConfidenceLow,
    varianceTolerancePercent, snoozedUntil, latestFxRateUsed)`.
    Note there is no `baselineAmount`: the amount the series
    carries is the LATEST one observed, and drift is judged
    against the previous occurrence rather than against a
    frozen baseline.
  - `RecurringOccurrenceDto` — per-occurrence row.
  - `RecurringSeriesAmountTrendDto` — chart data for the
    series detail.
  There is no `NextExpectedChargeDto`. The projected next
  occurrence is not a DTO of its own: it rides
  `recurring_series.next_expected_at` /
  `next_expected_confidence_low`, written by the detector and
  carried out on `RecurringSeriesDto`.
- **Enums/** — `RecurringSeriesState`, `SeriesCadence`.
- **Events/** — seven, not the five this page used to list, and
  they do not share one payload shape:
  - `RecurringSeriesApproved`, `RecurringSeriesRejected` —
    `(seriesId, userId)`.
  - `RecurringSeriesDetected` — `(seriesId, userId, direction,
    detectedName, cadence)`.
  - `RecurringSeriesCadenceFlipped` — `(seriesId, userId,
    oldCadence, newCadence)`.
  - `RecurringSeriesMetricsRefreshed` — `(userId,
    recurringSeriesId, direction, cadence, latestAmountMinor,
    latestCurrency)`. Note the id field is
    `recurringSeriesId` here and `seriesId` on the others.
  - `PaymentReminderDue` — `(userId, seriesId, dueDate,
    confidenceLow, expectedAmount, displayName)`.
  - `PaymentSettled` — `(userId, seriesId, dueDate)`.
- **Http/Livewire/FixedPaymentsCard** — the dashboard tile,
  Public because the dashboard layout renders it directly.
- **Services/**
  - `RecurringSeriesQuery::pendingForUser($user, $cursorId,
    $limit): array` and its `rejectedForUser` /
    `approvedForUser` siblings — the per-state, cursor-paged
    list queries. There is no single `list()` entry point
    taking a filter bag: each review-page state has its own
    method, plus `cadenceChangedForUser` and the unpaged
    `allApprovedForUser`.
  - `RecurringSeriesQuery::forSeries(int $seriesId, User
    $user): ?RecurringSeriesDto`.
  - `RecurringSeriesQuery::occurrencesForSeries(int $seriesId,
    User $user): array` — every occurrence row for the series,
    ordered `observed_at` DESC. This is the read
    `DriftAlerts::DriftEvaluator` consumes; it takes the first
    two entries itself, so the query has no "last two" of its
    own to name.
  - `RecurringSeriesQuery::pendingCountForUser($user): int`
    — pending-review count.
  - The rest of `RecurringSeriesQuery` is bulk reads the
    detail and review pages batch through:
    `driftThresholdForSeries`, `statesForSeriesIds`,
    `displayNamesForSeriesIds`, `forSeriesIds`,
    `seriesMembershipForTransactionIds`,
    `counterpartyIdForSeries`, `counterpartyIdsForSeriesIds`,
    `approvedSeriesForCounterparty`, `amountTrendForSeries`,
    `accountIdsForSeriesIds`.
  - `FixedPaymentsViewQuery::viewForUser(User $user): array` —
    dashboard card data; `topByMonthlyEquivalent()` and
    `monthlyEquivalentTotals()` alongside it.

## Internal services

- `Internal/CadenceInferrer::infer($occurrences): string` —
  classifies as `monthly` / `weekly` / `quarterly` /
  `yearly` / `irregular`. The `irregular` verdict makes a
  series ineligible for drift detection.
- `Internal/Detection/ClusterKeyComposer::compose($tx):
  string` — produces the cluster key
  (`{counterparty_normalized}|{amount_minor}|{cadence}` or
  similar) that groups occurrences into one series.
- `Internal/Detectors/ExpenseSeriesDetector::detectForUser($user)`
  — finds repeating expense clusters in the user's recent
  transactions, and writes them: per cluster it either calls
  `SeriesRefresher::refresh()` on the existing row or inserts a
  new `recurring_series` row and raises
  `RecurringSeriesDetected`. There is no `$window` argument —
  the window comes from
  `users.recurring_detection_window_months`.
- `Internal/Detectors/IncomeSeriesDetector::detectForUser($user,
  ?Session $session = null)` — same for income (salary,
  recurring refunds). The extra optional parameter widens the
  `SeriesDetector` contract without breaking conformance: this
  detector clusters on IBAN, so it needs the caller's session
  to reach the app-lock KEK, and the job skips it entirely
  rather than let it cluster on undecryptable ciphertext.
- `Internal/Detectors/SeriesRefresher::refresh($series,
  $counterpartyKey, $detected, $user, $direction, $healedName)`
  — the update half of detection: rewrites the series columns,
  writes the occurrences, raises
  `RecurringSeriesCadenceFlipped` when the cadence moved and
  `RecurringSeriesMetricsRefreshed` always.
- `Internal/Detectors/OccurrenceWriter::write($userId,
  $seriesId, $rows, $currency)` — the `insertOrIgnore` into
  `recurring_series_occurrences`, called by both the insert and
  the refresh arm.
- `Internal/Jobs/DetectRecurringSeriesJob::handle(DatabaseManager,
  Clock, iterable $detectors, RecurringSeriesStateMachine,
  ?Session, ?AppLockKeyService, ?EncryptionMigrationService,
  ?LoggerInterface)` — the sweep job. The last four are
  optional so the bare-handle test-call shape stays valid;
  null on all of them means "full capability", which is right
  for those always-plaintext fixtures. Receives detectors via
  iterable injection bound by `bindMethod()` in the provider
  (Container::call can't auto-resolve `iterable<SeriesDetector>`
  because `iterable` has no class to instantiate).
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
  state machine is the sole mutator. Casts:
  `latest_amount_minor`, `monthly_equivalent_minor` and
  `variance_tolerance_percent` to `integer`; `snoozed_until`,
  `created_at`, `updated_at` to `immutable_datetime`;
  `next_expected_at` to `immutable_date`;
  `next_expected_confidence_low` to `boolean`. There is no
  `baseline_amount` column and no `meta` column — the money on
  the row is the latest observed amount in minor units, and
  `Money` is composed at the DTO boundary, not cast on the
  model.
- `Models/RecurringSeriesOccurrence` — per-charge row linking
  the series to its `transactions.id` source.
- `Models/RecurringSeriesTransition` — append-only audit log
  of state changes.

Migrations (10 total):

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
- `2026_07_29_010002_constrain_recurring_series_cadence.php` —
  the DB-layer cadence constraint.
- `2026_08_19_000002_show_merchant_names_on_recurring_review.php`
  — backfills a detected name for rows still showing the
  clustering key.

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
