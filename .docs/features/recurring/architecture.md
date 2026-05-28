# `Recurring` — architecture

The `Recurring` module detects repeating-cadence transactions
(monthly subscriptions, weekly salaries, quarterly invoices) and
maintains the per-series state machine that drives every downstream
surface: the fixed-payments dashboard card, the
`/recurring/review` triage queue, the `/recurring` page, and the
events `Forecasting` + `DriftAlerts` subscribe to. The module's
posture is "always suggest, never auto-apply" — a candidate series
is always surfaced for the user to approve.

## What this module is for

A recurring series is the load-bearing concept for the dashboard's
"what do I actually owe each month" surface. Without it, every
month's Netflix charge looks like a fresh expense; with it, the
dashboard can group, project, and watch for drift. The
[categorization architecture topic](../../architecture/categorization.md)
covers the cross-cutting design context; this page describes the
module's surface.

The detection pipeline runs as `DetectRecurringSeriesJob` — fired
every time an import lands, configurable via the per-user
detection window (default two months of history). The job runs the
two detectors (`ExpenseSeriesDetector`, `IncomeSeriesDetector`)
against the user's recent transactions; new series land as
`pending` for the user to approve, edit, or reject. Metrics are
recomputed on every approve / cadence flip / reject / new
occurrence and surface as `RecurringSeriesMetricsRefreshed` —
`Forecasting` and `DriftAlerts` both subscribe.

What the module explicitly does NOT do:

- It never auto-applies a detection. Every detected series lands
  as `pending`; the user is the only signal that flips it to
  `approved`.
- It never categorises a transaction. The recurring series links
  to its category through the canonical
  `transactions.category_id` column; that column is
  `Ledger::UpdatesTransactionCategory`'s sole-write domain.
- It never decides drift. `DriftAlerts` consumes the
  `RecurringSeriesMetricsRefreshed` event and runs its own math.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `SeriesDetector::detect(User $user, Period $window):
    list<DetectedSeriesDto>` — the per-detector contract. Tag
    `recurring.detector`.
  - `DispatchesRecurringDetection::dispatch(User $user)` —
    called by `Import::ConfirmImport` after every import
    commits; bound to `BusRecurringDetectionDispatcher`.
- **Actions/** (every Public Action's `__invoke($seriesId,
  $user)` shape, except where noted):
  - `ApproveRecurringSeries` — `pending → approved`.
  - `RejectRecurringSeries` — `pending → rejected`.
  - `UnRejectRecurringSeries` — `rejected → pending` (user
    changed their mind).
  - `SnoozeRecurringSeries` — `pending → snoozed` (until a
    typed date).
  - `EditRecurringSeriesName($seriesId, $name, $user)`.
  - `EditRecurringSeriesVarianceTolerance($seriesId,
    $tolerance, $user)`.
  - `SetDriftThresholdForSeries($seriesId, $pct, $user)` —
    per-series drift override; consumed by `DriftAlerts`.
- **DTOs/**
  - `RecurringSeriesDto`, `RecurringOccurrenceDto`,
    `RecurringSeriesAmountTrendDto`,
    `NextExpectedChargeDto`.
- **Events/**
  - `RecurringSeriesDetected` — `(seriesId, userId)`.
  - `RecurringSeriesApproved` — `(seriesId, userId)`.
  - `RecurringSeriesRejected` — `(seriesId, userId)`.
  - `RecurringSeriesCadenceFlipped` — `(seriesId, userId)`
    — cadence drifted to a different stable cadence.
  - `RecurringSeriesMetricsRefreshed` — `(seriesId, userId)`.
- **Services/**
  - `RecurringSeriesQuery::list($user, $filters)` —
    main list query.
  - `RecurringSeriesQuery::lastTwoOccurrences($seriesId,
    $user)` — the read consumed by `DriftAlerts::DriftEvaluator`.
  - `RecurringSeriesQuery::pendingCountForUser($user)` —
    the top-nav badge.
  - `FixedPaymentsViewQuery::for($user)` — the dashboard
    fixed-payments card data.

`Internal/` houses the implementation:

- **Internal/CadenceInferrer** — infers cadence from a
  sequence of occurrences (monthly / weekly / quarterly /
  yearly / irregular).
- **Internal/Detection/ClusterKeyComposer** — composes the
  cluster key that groups same-merchant-same-amount-same-cadence
  rows into one series.
- **Internal/Detectors/** —
  `ExpenseSeriesDetector`, `IncomeSeriesDetector`. Each
  emits `DetectedSeriesDto` instances.
- **Internal/StateMachines/RecurringSeriesStateMachine** —
  SOLE sanctioned mutator of `recurring_series.state`.
  Allowed transitions: `pending → approved | rejected |
  snoozed`; `snoozed → pending | approved | rejected`;
  `approved ↔ cadence_changed`; `rejected → pending`
  (un-reject).
- **Internal/Jobs/DetectRecurringSeriesJob** — the queued
  sweep that runs the tagged detectors against the user's
  recent transactions.
- **Internal/Services/BusRecurringDetectionDispatcher** —
  concrete `DispatchesRecurringDetection`.
- **Internal/Http/Livewire/** — four SFCs.

## Key services + events

- `DetectRecurringSeriesJob::handle()` — collects the
  tag-discovered detectors via `iterable $detectors`; runs
  each against the user's recent transactions; for each
  detected cluster, upserts a `recurring_series` row keyed
  on `(user_id, cluster_counterparty_key)`; raises
  `RecurringSeriesDetected` per new row;
  `RecurringSeriesMetricsRefreshed` per touched row.
- `RecurringSeriesStateMachine::transition($series, $next,
  $reason)` — the sole sanctioned mutator; writes
  `recurring_series_transitions` audit row.
- `BusRecurringDetectionDispatcher::dispatch($user)` —
  dispatches `DetectRecurringSeriesJob` per user.
- `RecurringSeriesQuery::lastTwoOccurrences` — the read
  surface `DriftAlerts` consumes.
- The five Public events form the cross-module reactivity
  surface; every consumer (`Forecasting`, `DriftAlerts`)
  subscribes via the Public event class, never reaches into
  this module's internals.

## Data flow

The detection trigger chain:

```
Import::ConfirmImport (post-commit)
  → DispatchesRecurringDetection::dispatch($user)
       → dispatch DetectRecurringSeriesJob($user->id)

DetectRecurringSeriesJob::handle
  → for each tagged detector (Expense / Income):
       → detector->detect($user, $window)
       → for each DetectedSeriesDto:
            → ClusterKeyComposer::compose
            → upsert recurring_series row (UNIQUE per cluster_key)
            → CadenceInferrer::infer for the series's occurrences
            → if NEW row: RecurringSeriesStateMachine handles
                          the initial pending state
                          → dispatch RecurringSeriesDetected
            → if cadence drifted: state machine transitions
                                  approved ↔ cadence_changed
                                  → dispatch RecurringSeriesCadenceFlipped
            → dispatch RecurringSeriesMetricsRefreshed
                 → Forecasting + DriftAlerts subscribe
```

The user-facing actions:

```
/recurring/review
  → RecurringReviewPage shows pending rows
  → user clicks Approve / Reject / Snooze
       → corresponding Public action
            → RecurringSeriesStateMachine::transition
            → write recurring_series_transitions audit row
            → dispatch RecurringSeriesApproved / Rejected
                 → Forecasting re-projects;
                   DriftAlerts re-evaluates

/recurring → main list page
/recurring/{seriesId} → series detail
dashboard → FixedPaymentsCard
top-nav badge → RecurringSeriesQuery::pendingCountForUser
```
