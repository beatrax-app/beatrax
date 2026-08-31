# `DriftAlerts` — architecture

The `DriftAlerts` module watches every approved recurring series and
raises a `drift_alerts` row when the latest occurrence's amount
deviates from the prior occurrence by more than the effective
threshold. It hosts the `/drift` page, the dashboard drift-count
badge, the sidebar drift badge, the per-series threshold override
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
page. The effective threshold is the most specific setting that
exists: per-series override, else user-global, else the 5% default.
There is no floor over the top of it. `DriftThresholdOptions::PERCENTS`
offers 1 and 2, and a floor would make both inert while the settings
screen went on reading back the number the reader chose. A lower
threshold reports more drift, not less, so nothing is silenced by it.

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
  - `DriftAlertQuery::openCountForUser($user)`,
    `openForUser($user, $cursorId, $limit)`,
    `historyForUser(...)`, `dismissedForUser(...)`,
    `groupedBySeriesForUser($user, $seriesLimit)`,
    `openAnnualizedImpactByCurrencyForUser($user)`,
    `openSeriesIdsForUser($user)`,
    `seriesStatesForUser($user, $seriesIds)`,
    `seriesThresholdsForUser($user, $seriesIds)`.
  - `CancellationImpactQuery::forSeries($seriesId, $user)` and
    `forSeriesIds($seriesIds, $user)` — keyed on the recurring series,
    not on an alert id.

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

## `DriftAlertQuery` read contract

Public read API over `drift_alerts`. Every method scopes by `user_id` and
returns Spatie-Data DTOs so the drift page, the dashboard tile, and
downstream module listeners read a single canonical shape. Cross-user
reads return an empty list (or zero on count aggregates); cross-user 404s
are surfaced at the Public Action layer.

Cursor pagination is keyed on `(detected_at, id)`. `drift_alerts.id` used to be
a SQLite autoincrementing surrogate that ascended with insertion; it is now
derived from `(recurring_series_id, latest_occurrence_id)` so that two devices
name the same alert, which means it sorts in hash order and cannot lead. The id
still breaks ties within one `detected_at` second — which the revival sweep and
the detector listener both produce, each writing a batch inside one scheduler
tick — and the cursor row's `detected_at` is read back scoped to the reader
rather than carried on the wire.

The open-tab projection (`openForUser`, `openCountForUser`,
`openAnnualizedImpactByCurrencyForUser`, `openSeriesIdsForUser`,
`groupedBySeriesForUser`) applies a
compound filter — `state='open' OR (state='snoozed' AND snoozed_until <=
now())` — so snoozed-but-expired rows surface immediately, before the
next hourly `RevivedExpiredDriftSnoozesJob` sweep writes the audit
transition. The two paths produce the same eventual set; the sweep is the
durable write, the query is the fresh read.

`openAnnualizedImpactByCurrencyForUser` returns one magnitude per
currency over open EXPENSE-direction alerts, in original-currency-minor
units. Two things it is deliberately not: it is not summed across
currencies, because `annualized_impact_minor` is denominated in the
series' own and euro cents do not add to dollar cents; and it is not a
signed SUM, because the tile says "potential annualized cost" and a
signed total let one series getting EUR 120/yr cheaper erase another
getting EUR 120/yr dearer. Only the rows whose impact makes the expense
larger are counted, and the total is their magnitude. It stays scoped to
expenses because folding an income raise's positive delta into the same
headline would conflate "subscriptions going up" with "salary going up"
under one up-arrow tile.

Cross-module reads of `recurring_series` (display name, state,
per-series threshold) are delegated to `RecurringSeriesQuery` so
`DriftAlerts` never issues a raw SELECT against another module's table.
`seriesThresholdsForUser` reads through `driftThresholdsForSeriesIds`,
which is batched — one editor read per rendered series would restore the
N+1 that method exists to kill.

## `CancellationImpactQuery`, `SavingsInsightsQuery`, `SubscriptionDriftWatchQuery`

**`CancellationImpactQuery`** projects savings if the user cancels a
recurring series, read via `RecurringSeriesQuery` (cross-module access is
exclusively through that Public surface so
`noRecurringSeriesWritesFromDriftAlerts` and broader boundary discipline stay
green). The monthly figure is `recurring_series.monthly_equivalent_minor`; the
**yearly** one is the series' own `latest_amount_minor` at its cadence's
per-year rate, not that monthly integer multiplied back up. The monthly
equivalent is rounded to a whole minor unit, so routing the year through it
advertised "save EUR 109.92/yr" for a EUR 109.90 annual plan and EUR 571.44 for
a EUR 571.48 one — on the same row as the alert's own correct annualisation.
An `irregular` series bills at no rate, so there the monthly equivalent x 12 is
all there is. The returned currency is the
series's original currency (`recurring_series.latest_currency`), not
necessarily EUR — a USD-billed series (Google Play, cross-currency ICS
settlements) returns USD savings; the renderer owns any EUR shadow it
surfaces alongside the original-currency primary line. Cancellation
savings are expressed as positive amounts (cancelling a recurring expense
reduces outflow) even though the underlying `RecurringSeriesDto` stores
`monthlyEquivalent` as a signed Money (negative for expenses, positive
for incomes) — this query takes the absolute amount so the "save €X/yr"
copy reads naturally for both the common expense case and the rare
income-series case. Returns `null` for a cross-user or missing series.

**`SavingsInsightsQuery`** builds the "You could save here" suggestions:
each approved recurring subscription pairs with the most actionable
support-resource link, chosen by priority — (1) `cheaper`: the corpus has
a cheaper-plan/student/retention page; (2) `cancel`: the price has
drifted up (an open drift alert exists) and the corpus has a
cancellation page; (3) `review`: an ongoing charge at or above the EUR
review floor with a cancellation page. One suggestion per subscription,
dismissible via a stable persisted key, ranked by monthly cost — purely
informational, Beatrax surfaces the official link and never acts on the
user's behalf. `forUser()` is cached per user so the dashboard card
doesn't re-run the resolution fan-out on every render; the cache is
invalidated on `dismiss()` and also expires within the TTL so new
subscriptions/drift surface without a manual refresh.

**`SubscriptionDriftWatchQuery`** builds the Subscription Drift Watch
overview: every approved recurring EXPENSE series with at least two
observed amounts *whose first and last clear the same `AmountMovement`
refusal the evaluator applies* — a zero baseline and a sign flip are dropped
rather than rendered as a percentage of nothing and a refund read as a price
rise — with its baseline → latest price, the cumulative drift, and the
amount-history sparkline, sorted by the largest euro increase first. It is the subscription-centric counterpart to the
alert-centric `/drift` page, reusing `RecurringOccurrenceQuery::amountTrendForSeries`
and the `DriftAlerts` open-alert rows rather than introducing a new
history store. It reads the full occurrence history (a 600-point ceiling
covers any realistic subscription lifetime — 50 years monthly / 11 years
weekly) rather than the 24-point default the series-detail chart uses,
since the drift watch reports the price change since the *first* charge,
not just the most recent 24.

## `DriftPage` — the unified alerts home

`/drift` hosts a top-level TYPE switch ("Subscription drift" | "Unusual
charges") that selects which alert stream is shown; under it the same
three lifecycle tabs (Open / History / Dismissed) apply to whichever
type is active. Every list on the page — the grouped Open tab, the flat
History and Dismissed lists, and the anomaly stream — is one growing window:
`pageSize` starts at 26, `loadMore()` adds 26, and each read asks for one row
(or one series) past it so the extra row IS the evidence of more. Reading
exactly a page and offering the control whenever it came back full put "Load
more" under an exactly-full list, where pressing it changed nothing. The drift stream reads `DriftAlertQuery`; the anomaly
stream reads the `Anomaly` module's Public `AnomalyAlertQuery` — a
sanctioned Public crossing, since the page is owned by `DriftAlerts` but
composes `Anomaly`'s read surface exactly as it already composes
`Recurring`'s series query. Per-row drift actions: Acknowledge / Snooze
(1w / 1m / 3m popover) / "I cancelled this". Per-row anomaly actions:
Acknowledge / Snooze / Mark as expected (creates a suppression rule and
emits an "Undo" toast) / Dismiss. Every action dispatches a toast on
success.

`modelCancelInForecast()` invokes the `Forecasting` Public Action that
atomically creates a new scenario pre-seeded with a `cancel_series`
mutation for the alert's underlying series, then redirects to
`/forecast?scenarioId={new}`. The `drift_alerts` row itself is not
modified — modelling is non-destructive; the user can still dismiss or
acknowledge later.

Both the drift and anomaly snooze handlers independently re-validate the
snooze target server-side (bounding it to `(now, now+6mo]`) even though
the popover only ever emits one of three server-computed targets — this
is defence in depth against a tampered Livewire payload, on top of the
Public Action's own server-side bound.

## Write-action security & idempotency contracts

Every Public write action in `DriftAlerts` shares the same defensive
shape: cross-user 404 (never 403) via an explicit `where('id',
$alertId)->where('user_id', $user->id)` predicate; idempotent no-op when
the target state (or, for snooze, the exact target timestamp) already
matches; and every state write goes through `DriftAlertStateMachine`,
which stamps `actioned_at` (or `snoozed_until`) via the same
`$extraColumns` mechanism so the state flip and the companion timestamp
land inside one row-locked transaction and audit row.

`DismissDriftAlertAsCancelled` never writes `recurring_series` — enforced
by the `noRecurringSeriesWritesFromDriftAlerts` arch test — and uses the
distinct transition reason `user_dismissed_cancelled` (vs.
`AcknowledgeDriftAlert`'s `user_action`) so the audit trail can separate
"reviewed and accepted" from "I cancelled this series". It dispatches
`DriftAlertDismissedCancelled` carrying `recurringSeriesId` so downstream
listeners can exclude the series from their own projections without
re-reading the row. `SnoozeDriftAlert` bounds the target through
`SnoozeUntil::from()` before it writes, and compares snooze idempotency
through the same `toDateTimeString()` round-trip the stored value took,
since that form drops the sub-second precision and the source offset an
ISO-8601 caller may carry.

## `DriftThresholdEditor` per-series threshold popover

Mounts inline on both the `/drift` grouped-by-series headers and
`/recurring/series/{id}` — the same component drives both surfaces so the
popover chrome stays consistent. The save path delegates to the
Recurring-side `SetDriftThresholdForSeries` Public Action; `DriftAlerts`
itself never writes to `recurring_series`, keeping the
`noRecurringSeriesWritesFromDriftAlerts` invariant green without an
exemption list. `currentValue === null` means "use the user-global
default" — the popover's "Use global default" option saves `null` back.

`/drift` mounts one of these per alert group, so the value arrives as a
prop: `DriftPage::render()` reads the whole column for the grouped series
in one `DriftAlertQuery::seriesThresholdsForUser()` call and hands each
editor its own entry. Because `null` is a real answer there, a bare
`currentValue` cannot say "nothing was loaded"; `currentValueLoaded`
carries that, and a surface that mounts the editor alone —
`/recurring/series/{id}` — passes neither, and the component reads its
own row.

## Job concurrency contracts

**`DetectDriftAlertsJob`** — per-(user, series) drift evaluation,
dispatched by `EvaluateDriftOnMetricsRefreshed` after each `Recurring`
sweep refreshes a series's metric columns. `ShouldBeUniqueUntilProcessing`
keyed on `uniqueId() = "{userId}:{seriesId}"` collapses any concurrent
(scheduled-tick + on-demand-redetect) trigger pair into a single queued
job per (user, series); the lock releases the moment a worker begins
`handle()`. `handle()` hands off to `DriftEvaluator`, which owns all of
the math, persistence, and `DriftAlertOpened` dispatch.

**`EmitSavingsPromptsJob`** — per-user push of the existing savings
insights, deliberately the smallest of the proactive triggers since it
computes nothing: reads `SavingsInsightsQuery::forUser($user)` (the same
read `SavingsInsightsCard` uses) and dispatches one `SavingsPromptDue`
event per returned insight. `forUser()` already excludes any insight the
user has dismissed, so this job must not re-filter — a second filter here
would let the notification surface and the card silently drift apart on
what is "live". Clones `SafetyNetAnomalySweepJob`'s per-user job shape and
`CounterpartyGarbageCollectorJob`'s `ShouldBeUniqueUntilProcessing` triad.
The job holds one `int $userId` and never batches across users (the
global `UserScope` does not fire in queue/console context, and
`SavingsInsightsQuery` is already user-scoped by its own discipline). No
transaction wraps the dispatch loop — each `SavingsPromptDue` dispatch is
independent of any DB write here.

**`RevivedExpiredDriftSnoozesJob`** — hourly scheduled sweep that flips
`drift_alerts` rows from `snoozed` to `open` once `snoozed_until` has
elapsed. This is the durable-write companion to `DriftAlertQuery`'s
query-time conditional (which widens its state filter so counts/sums
stay honest between sweeps) — the audit row is written exclusively by
this sweep; the query-time conditional is read-only. The sweep is global
(no `user_id` scope; alerts may belong to any user, and the user id is
preserved on the audit row via the state machine's read of the alert's
owning user). Each transition is idempotent at the state-machine level,
so retrying a partially-completed sweep on transient failure is safe. If
a user acknowledges, dismisses, or re-snoozes a candidate row between the
SELECT scan and the state-machine call, the state-machine transaction
sees the new state under its row lock and raises
`InvalidStateTransitionException`; the sweep catches that per-row and
skips silently so a single mid-sweep user action cannot fail the whole job.

## `DriftAlertStateMachine` contract

The single legal mutator of `drift_alerts.state` and the sole inserter
into `drift_alert_transitions`. Other module code may UPDATE non-state
columns: the snooze action sets `snoozed_until` alongside the state flip
via `$extraColumns`, and moving an existing snooze to a new date writes
`snoozed_until` on its own, since the state does not change and the
machine has no `snoozed -> snoozed` edge.

The evaluator never UPDATEs an alert at all — it only inserts. A revived
alert therefore keeps the amounts captured when it opened, which is the
point: `thresholdPercentUsed` and the baseline/latest pair are an audit
of one movement. If the price moved again during the snooze, the new
occurrence carries a new `latest_occurrence_id` and opens its own row.
The sole-mutator contract is enforced at three layers: static analysis
(the `noOtherDriftAlertStateMutator` arch test rejects any non-allowed
file writing to `drift_alerts.state`), runtime (`ALLOWED_TRANSITIONS`
throws `InvalidStateTransitionException` on illegal targets), and
database (SQLite triggers ABORT on out-of-enum state values even when an
arbitrary code path bypasses this class). `acknowledged` and
`dismissed_cancelled` are terminal states (empty target arrays in
`ALLOWED_TRANSITIONS`).

`transition()` mirrors `RecurringSeriesStateMachine`: opens a
transaction, sets `PRAGMA busy_timeout = 5000`, takes a row lock
(`lockForUpdate()`), validates against `ALLOWED_TRANSITIONS`, writes the
new state + `updated_at`, and inserts exactly one
`drift_alert_transitions` row carrying the full audit metadata. Two
concurrent detectors that briefly contend on the same alert row serialise
rather than fail. `toIntOrNull()` silently degrades a corrupted/zero/
negative `user_id` to `null` on the audit-row FK so the transition
contract ("write exactly one `drift_alert_transitions` row per legal
state flip") stays resilient against a corrupted source row — callers
that need to detect the corruption can inspect the resulting
`drift_alert_transitions.user_id IS NULL` row; a test locks this
swallow-to-null semantic so a future refactor cannot quietly change it
into a throw. `InvalidStateTransitionException` is caught separately from
a generic `RuntimeException` so the queued evaluator job and the
drift-page actions can distinguish a rejected transition (programming or
race condition) from a row that vanished mid-flight (a transient cascade
delete from a missing `recurring_series` row).

`DriftAlertDtoMapper::hydrate()` fails loud with an identifying message
when `detected_at` is missing/non-string on a row (the schema marks it
non-null, but a corrupted row could still surface here), rather than
letting Carbon raise a bare `InvalidFormatException` out of an unscoped
`parse('')`.

## Key services + events

- `DriftEvaluator::evaluateForSeries($seriesId, $user)` — the math. Reads
  the newest three occurrences through
  `RecurringOccurrenceQuery::latestOccurrencesForSeries` — two for the
  movement, a third for the interval the prior amount was billed over —
  computes
  `delta_minor`, applies the effective threshold (per-series override →
  user-global → 5% default, first one set), inserts on
  threshold-crossing. `AmountMovement` decides whether there is a
  movement to measure at all: it refuses a zero prior (the
  `prior-zero.php` fixture), a currency change mid-series
  (`mixed-currency-within-series.php`) and a sign flip such as a refund
  against a charge (`sign-flip-refund.php`). The annualised impact is
  each side at the rate it was billed at, so a monthly-to-yearly
  restructure reports the change in yearly cost rather than one period's
  delta at the new multiplier (`cadence-restructure.php`). Idempotent
  via the unique constraint; any other write failure is re-raised.
- `DriftAlertStateMachine::transition($alert, $next, $reason)` —
  the single sanctioned mutator of `drift_alerts.state`. Records
  the transition in `drift_alert_transitions` as the audit trail.
- `DetectDriftAlertsJob::handle()` — fans out from the listener;
  calls the evaluator; dispatches `DriftAlertOpened` on a fresh
  insertion.
- `RecurringSeriesMetricsRefreshed` (raised by `Recurring`) — the
  external trigger. The listener subscribes via the provider's
  `registerListener()` private method.
- The sidebar's drift badge is not wired here: `NavCountsService`
  computes it alongside the rail's other counts, applying the same
  revival-aware open-state predicate this module's query uses.

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
       → RecurringOccurrenceQuery::occurrencesForSeries($seriesId, $user)
       → compute delta_minor + ratio
       → effective threshold = perSeriesOverride
                               ?? userGlobal
                               ?? 5%
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
       → DriftAlertQuery::openForUser / historyForUser /
         dismissedForUser / groupedBySeriesForUser
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
