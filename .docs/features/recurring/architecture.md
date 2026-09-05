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
  - `SeriesDetector::detectForUser(User $user): void` — the
    per-detector contract. Tag `recurring.detector`. The
    detector reads its own window from
    `users.recurring_detection_window_months` and upserts the series
    itself; it returns nothing to its caller.
  - `DispatchesRecurringDetection::dispatchForUser(int $userId)`
    — called by `Import::ConfirmImport` after every import
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
  - `RecurringSeriesQuery::pendingForUser($user, $cursorId,
    $limit)` and its `rejectedForUser` / `approvedForUser`
    siblings — the per-state, cursor-paged list queries. There
    is no single filtered list entry point; each review-page
    state has its own method, plus `cadenceChangedForUser` and
    the unpaged `allApprovedForUser`.
  - `RecurringOccurrenceQuery::occurrencesForSeries($seriesId,
    $user)` — every occurrence row that contributed to the
    series, ordered `observed_at` DESC. This is the read
    `DriftAlerts::DriftEvaluator` consumes; it takes the first
    two entries itself.
  - `RecurringSeriesQuery::pendingCountForUser($user)` —
    the pending-review count.
  - `FixedPaymentsViewQuery::viewForUser($user)` — the dashboard
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
- **Internal/Http/Livewire/** — three SFCs (`RecurringPage`,
  `RecurringReviewPage`, `RecurringSeriesDetailPage`). The
  fourth, `FixedPaymentsCard`, is Public: the dashboard layout
  renders it, so it is a cross-module surface rather than an
  internal one.

## Key services + events

- `DetectRecurringSeriesJob::handle()` — writes NO
  `recurring_series` row and raises NO detection event. It does
  four things: loads the user, expires every `snoozed` series
  whose `snoozed_until` has passed (through the state machine,
  so the transition is audited), probes whether the app-lock
  KEK is available for an encrypted user, and then loops the
  tag-discovered detectors calling `detectForUser()` on each.
  The KEK probe is why the loop is not uniform:
  `IncomeSeriesDetector` clusters on IBAN, so without a KEK it
  is skipped entirely for that sweep rather than called and
  left to cluster on undecryptable ciphertext; it picks up on
  the next in-app "Detect now".
- The persistence and the events belong to the DETECTOR, not
  the job. Each detector builds an in-memory index of the
  user's existing series keyed on the cluster counterparty key,
  then per cluster either calls `SeriesRefresher::refresh()` on
  the row it found or inserts a new one. That is an
  insert-or-update decided in PHP against the index, not a DB
  upsert: `(user_id, direction, cluster_counterparty_key,
  latest_currency)` carries a plain INDEX, and the UNIQUE
  constraint (`rec_series_uniq`) is on `cluster_key` instead.
  The index is the load-bearing part — two merchant keys can
  normalise onto one `cluster_key` (an ampersand and a space
  both become a hyphen), so a later cluster in the same sweep
  must find the row an earlier one just wrote instead of
  inserting over it.
- Which event fires follows that split.
  `RecurringSeriesDetected` fires only on the insert arm, once
  per NEW series. `RecurringSeriesMetricsRefreshed` fires on
  both arms, so once per touched series.
  `RecurringSeriesCadenceFlipped` fires from the refresh arm
  only when the inferred cadence moved, and only after a fresh
  re-read of the row confirms it is still `approved` — the
  `approved → cadence_changed` transition is illegal from any
  other state, and the row may have moved since the detector
  read it.
- `RecurringSeriesStateMachine::transition($series, $toState,
  $reason, $actor, $notes = null, $extraColumns = [])` — the
  sole sanctioned mutator; writes the
  `recurring_series_transitions` audit row. `$actor` is not
  optional: an audit row that cannot say whether the detector
  or the user moved the series is not an audit row.
- `BusRecurringDetectionDispatcher::dispatchForUser($userId)` —
  dispatches `DetectRecurringSeriesJob` per user.
- `RecurringOccurrenceQuery::occurrencesForSeries` — the occurrence
  read surface `DriftAlerts` consumes. It is not the only method
  `DriftAlerts` calls: `DriftEvaluator` also uses `forSeries` and
  `driftThresholdForSeries`, `DriftAlertQuery` uses
  `statesForSeriesIds` and `displayNamesForSeriesIds`, and
  `CancellationImpactQuery` uses `forSeries` and `forSeriesIds`.
- The seven Public events form the cross-module reactivity
  surface — the five series-lifecycle ones plus
  `PaymentReminderDue` and `PaymentSettled`. Every consumer
  (`Forecasting`, `DriftAlerts`, `Notifications`) subscribes
  via the Public event class, never reaches into this module's
  internals.

## `RecurringSeriesQuery` read contract

`RecurringSeriesQuery` is the Public read API over `recurring_series` for
callers that start from a series. Callers that start from a transaction —
Anomaly's duplicate detector, the calendar placer, the booked-row projector —
read `TransactionSeriesMembershipQuery` instead; it is the only other Public
reader of the table, and it returns ids rather than DTOs.
Every method scopes by `user_id` and returns Spatie-Data DTOs so the review
page, the fixed-payments view, the dashboard tile, and downstream module
listeners all read a single canonical shape. Cross-user reads return an
empty list or `null` rather than raising — cross-user 404s are the Public
Action layer's responsibility (mirrors the Chains precedent: query services
stay silent so caller policy stays caller-side).

Cursor pagination on `id` matches the chains-side review queue.
`approvedForUser` orders by `monthly_equivalent_minor DESC` then `id DESC`
so the dashboard tile and fixed-payments view consume a stable,
"largest first" projection; its cursor is a composite of the cursor row's
`(monthly_equivalent_minor, id)` so subsequent pages follow the primary
sort instead of an id-only window.

## `FixedPaymentsViewQuery` read contract

Public read API over approved `recurring_series` rows for the `/recurring`
page sections, the dashboard fixed-payments tile, and the net-flow header
summary. Three top-level methods:

- `viewForUser(User)` — full grouped payload (expenses + income +
  transfers) for the `/recurring` page; each section sorted DESC by
  absolute `monthly_equivalent_minor`.
- `topByMonthlyEquivalent(User, $limit = 6)` — dashboard tile payload.
- `monthlyEquivalentTotals(User)` — single SUM query partitioned by
  direction, used by the page header net-flow summary.

**Query budget:** `viewForUser` runs in at most three queries regardless of
N — one approved-series scan with the chain_link join, one fallback-chain
walk against `recurring_series_occurrences` joined to `chain_links`
(skipped when no series needs the fallback), and one batch merchant-memory
lookup (skipped when no expense rows surface). The n-plus-one-budget
feature test enforces ≤ 3 queries for N≥10.

**Transfers ship empty in v1:** neither the expense detector
(`type='expense'|'fee'`) nor the income detector
(`type='income'`) reads `transfer_out`/`transfer_in` transactions, so no
`recurring_series` row currently models a recurring transfer. The empty
list is reserved structure for future detector work; the `/recurring` page
renders the section as a collapsed `<details>` panel so the layout slot
stays visible.

**Chain-fallback semantics:** when a series' `latest_funding_chain_link_id`
is null or points at a `chain_links` row whose `state` is anything other
than `confirmed`/`candidate`, the query walks back through the series'
occurrences (ordered by `observed_at` DESC) and adopts the first
occurrence's confirmed/candidate chain. The walk runs as a single batch
query against the full "needs-fallback" set so the per-row query count
stays flat. `RecurringSeriesQuery` (above) deliberately skips this walk —
its consumers do not need the fallback.

Cross-user reads return empty containers; cross-user 404s are the concern
of mutating Public Actions, not read services.

## Detection algorithm internals

`CadenceInferrer` is a stateless cadence-class inferrer over a
sorted-ascending list of occurrence timestamps. It returns an
`InferredCadence`: the snap-band cadence (weekly / monthly / quarterly /
yearly / irregular), the median interval in days, a projected
next-expected-at timestamp, a low-confidence signal (flagged when the
interval stddev exceeds five days), and the missed-occurrence count. It
is a value object rather than a five-key array because the array shape
had to be restated in a docblock on each of the three callers that pass
it through, and only two of its keys are read past the detector.

Snap bands cover the four canonical billing cadences personal-finance
recurring subscriptions land on; intervals outside every band classify as
`irregular` so the detector skips the cluster instead of producing a
spurious "every 46 days" suggestion.

Missed-interval tolerance keeps a stable subscription unfragmented when one
provider gap (bank holiday, mid-month billing skew) opens a
larger-than-normal interval. Any interval above `1.8 × provisional_median`
is excluded from the refined median pass and counted as a missed period.
The final cadence still snaps on the *provisional* median (not the
refined one) so a noisy cluster with one genuinely-out-of-band outlier
(e.g. gym charges with no underlying recurring pattern) is classified
`irregular` rather than rescued into a band by the missed-interval filter
— the refined median only feeds the `next_expected_at` projection, since
the filtered series better approximates the underlying cadence once a
clearly-missed period is discounted.

`ExpenseSeriesDetector` reads transactions of type `expense` and `fee`
for the user inside the detection window (per-user
`recurring_detection_window_months`, two-month default, opened by
`Modules\Recurring\Public\Support\RecurringDetectionWindow`) and clusters rows
by `(counterparty_normalized, original_currency)` — original-currency
clustering keeps a USD subscription stable when the settled-EUR amount
drifts with FX rate noise. `IncomeSeriesDetector` mirrors this for
`income`-type transactions, opening its window through the same
`RecurringDetectionWindow` — the two passes merge into one series set, so a
window either of them computed for itself would give that set two different
spans — and clustering by IBAN first (falling back to
`counterparty_normalized`) with a minimum-amount floor
(`recurring_income_min_amount_minor`, defaulting to
`User::DEFAULT_RECURRING_INCOME_MIN_AMOUNT_MINOR`, €2000) so small refunds and
cashbacks never pollute the income-series surface.

Both detectors share the same per-cluster pipeline: run
`ClusterAmountFilter` (drop rows whose sign disagrees with the cluster's,
then rows outside ±25% of the cluster median absolute amount, tolerance
read off any existing series for the pair) → run `CadenceInferrer` →
skip `irregular` cadences →
either insert a new `pending` series or refresh metrics on an existing
one. Approved series whose cadence class flips through a pass transition
to `cadence_changed` via the state machine, dispatching
`RecurringSeriesCadenceFlipped`. Rejected series are never touched
(rejection covers the entire counterparty+currency pair across every
cadence variant); snoozed series are skipped entirely so the paused
amount is never silently refreshed underneath the user — the next sweep's
snooze-expiry pass flips `snoozed → pending` first. Occurrence rows are
inserted via raw INSERT-OR-IGNORE on the `(recurring_series_id,
transaction_id)` UNIQUE constraint so a second pass over the same fixture
is idempotent.

`ClusterKeyComposer` composes the per-cluster idempotency key written to
`recurring_series.cluster_key` — the load-bearing payload of the
`(user_id, direction, cluster_key, latest_currency)` UNIQUE constraint that
gates duplicate-series creation across sweep re-runs. Output shape: four
lowercase tokens separated by `::`, each normalised so punctuation
variance in the counterparty name (e.g. `"Acme, Inc."` vs `"Acme Inc"`)
collapses to the same key — otherwise re-imports of the same merchant
under slightly different spellings would silently spawn parallel series.
Each token is capped at 60 characters so the assembled key fits well
inside the default Laravel string column width.

## `IncomeSeriesDetector` and encrypted-field clustering (CRYPT-01)

`IncomeSeriesDetector` clusters income rows by counterparty IBAN first
(falling back to `counterparty_normalized` when the IBAN is empty) since
an IBAN identifies the upstream payer (employer, freelance client) more
stably than the free-form description — banks rewrite description text
over time but the SEPA IBAN is constant. Rejected income series stay
rejected permanently, mirroring `ExpenseSeriesDetector`'s per-pair
suppression; cadence-flip detection on approved rows also mirrors the
expense detector.

`counterparty_iban` is a `SensitiveFieldRegistry`-listed column: at rest
under an encrypted user it is random-nonce ciphertext that differs per
row even for the same logical IBAN, so clustering on the raw stored value
would scatter every income row into its own one-row, sub-threshold group
and silently detect nothing. The detector's optional `$session` parameter
is decrypted-through via `SensitiveColumnCodec::decryptValue()` *before*
the value participates in cluster-key derivation; the codec call is a
documented no-op pass-through for a plaintext (pre-encryption or
never-encrypted) value, so passing a session is always safe. `$session`
is deliberately optional and not part of the `SeriesDetector` contract
(PHP permits an implementing method to add extra default-valued
parameters without breaking interface conformance) — `DetectRecurringSeriesJob`
is the only caller that knows which dispatch origin it is running under
(in-request `dispatchSync` with the KEK present, vs. the KEK-less
scheduled daemon) and decides whether to pass a session at all; every
other caller resolving this class through the generic `SeriesDetector`
interface still compiles and runs unchanged.

**Both paths store a keyed value, under different domains.** The expense
cluster key is whatever `transactions.counterparty_normalized` holds,
which for an encrypted user is a blind index under
`DOMAIN_COUNTERPARTY_NORMALIZED`. The income path decrypts the IBAN to
compute its key and then runs it through
`CounterpartyKey::forIban()` — `DOMAIN_COUNTERPARTY_IBAN` — before
storing it, because the decrypted IBAN written verbatim put the salary
payer, the benefits agency and the pension provider back in the clear in
an unencrypted column.

The two domains are the reason
`TransactionSeriesMembershipQuery::seriesIdsForTransactionIds()` cannot
resolve an income series with a plain SQL join: the column it compares
against, `transactions.counterparty_normalized`, is keyed under the other
domain. It derives the IBAN key in PHP for the rows the join left
unresolved. See
[Which columns are encrypted at rest](../sync/sensitive-columns-at-rest.md).

## `DetectRecurringSeriesJob` concurrency contract

Runs the snooze-expiry pass first (flipping `snoozed` rows back to
`pending` once `snoozed_until` has elapsed) and then iterates every
container-tagged `recurring.detector` implementation against the user's
detection window. `ShouldBeUniqueUntilProcessing` keyed on
`uniqueId() = userId` collapses a same-day re-dispatch (scheduled tick or
the on-demand "Detect now" button) into a single queued pass; the lock
releases the moment a worker begins `handle()`. `tries = 3` +
`backoff = [60, 300, 900]` tolerates a transient queue/DB hiccup without
final-failing the sweep. Queue-uniqueness lock resolution is delegated to
the shared `LockStore::forUniqueJobs()` helper, which resolves the cache
store named by `config('cache.locks_store')`. The sweep runs read-mostly
against `transactions` and writes only to Recurring-owned tables — the
`crossModuleRawTableWrites` arch invariant pins any cross-module raw-table
write, and Recurring has none.

## Detection dispatch: two KEK postures (CRYPT-01)

`DetectRecurringSeriesJob` has exactly two dispatch origins with different
decrypt capability, because `IncomeSeriesDetector` clusters on the
encrypted `counterparty_iban` column (see above) and decrypting it needs
the user's KEK:

- **`RecurringPage::reDetect()`** (the "Detect now" button) and
  `BusRecurringDetectionDispatcher` (called from `ConfirmImport` /
  `FirstImportStep`) both dispatch via `dispatchSync()` — running fully
  in-process on the same request whose Session is unlocked, so the KEK is
  always available.
- **`routes/console.php`'s daily `recurring.detect` scheduler entry**
  dispatches through the real queue — the queue worker process has never
  unlocked a Session, so the KEK is never available there.

`dispatchSync()` keeps the KEK in-process (never serialized onto the
`jobs` table) but, as a consequence, bypasses the queue layer entirely: the
job's `ShouldBeUniqueUntilProcessing` lock is only enforced by
`PendingDispatch::shouldDispatch()`, which `dispatchSync()` never invokes.
A same-user double-dispatch (e.g. an import-confirm followed immediately
by a "Detect now" click) now runs detection twice in sequence instead of
collapsing into one queued pass — detection is idempotent/re-run-safe, so
this is a redundant-but-harmless cost, not a correctness regression.

`handle()` therefore probes KEK availability (`AppLockKeyService::release()`)
and whether the user has encryption enabled at all
(`EncryptionMigrationService::isEnabled()`); when the user IS encrypted
and the KEK is ABSENT, the iban-dependent `IncomeSeriesDetector` pass is
explicitly skipped (never invoked) and a warning is logged naming the
user — `ExpenseSeriesDetector` (which does not depend on
`counterparty_iban`) still runs unaffected. A non-encrypted user's sweep
is unaffected either way (the codec's decrypt call is a documented no-op
pass-through for plaintext). **Known limitation:** with no headless-KEK
mechanism available, an encrypted user's income-series
detection only actually clusters via the in-app "Detect now" button — the
daily background sweep skips the iban-dependent pass for that user and
logs why, rather than running it and reporting nothing.

The `noSynchronousDetectionInRequestLifecycle` arch invariant stays green
regardless of dispatch origin: it forbids any Livewire/Http class from
importing the detector contract interface directly — only the
`DetectRecurringSeriesJob` class itself is ever referenced from request
context.

## `EmitPaymentRemindersJob` reminder emission

Per-user scheduled reminder evaluation: emits `PaymentReminderDue` for
every approved recurring series whose `nextExpectedAt` falls inside
`[today, today + leadDays]` inclusive, skipping any candidate whose
before-fire settlement check finds it already paid
(`RecurringOccurrenceQuery::latestObservedAtForSeriesIds()` answers the
newest `observed_at` per series in one read, so that date tells whether the
due charge already landed and the detector simply hasn't re-swept
`next_expected_at` forward yet).

`$leadDays` is a constructor parameter, not read from the notification
store's preference query in here — this module reading that Public
service would violate the trigger-module import boundary invariant; the
scheduler entry that dispatches this job reads the device's lead-time
preference and passes it in, keeping this module wholly ignorant of
notification delivery concerns.

Dispatch happens outside any open transaction (a queued job body is not
wrapped in one) so the event always emits after any commit, satisfying
the notification store's ingestion contract for free; the per-candidate
loop deliberately never opens one either.

Concurrency contract mirrors `SafetyNetAnomalySweepJob` /
`PruneNotificationsJob`: `ShouldBeUniqueUntilProcessing` keyed
on `uniqueId() = (string) userId` collapses a same-window duplicate
dispatch (two independent schedulers, desktop + mobile) into a single
queued run per user; `tries = 3` + `backoff = [60, 300, 900]` tolerates a
transient hiccup. Even if both schedulers evaluate the same series, the
notification store's deterministic PK collapses the eventual result to
one inbox row regardless — correctness never depends on this lock.
Cross-user discipline: every query carries an explicit `where('user_id',
...)` / `$user` argument since the global scope does not fire in
queue/console context; the job holds a single `int $userId` and never
batches across users.

## `RecurringSeries` model notes

The `direction` enum (`expense` | `income`) lets a single detector code
path serve both sides. The `state` column is mutated exclusively by
`RecurringSeriesStateMachine`; the schema-level trigger pair plus the
`noOtherRecurringSeriesStateMutator` arch invariant enforce that contract.
`latest_funding_chain_link_id` is the optional pointer back into the
funding-chain ledger so the review surface can render "funded by PayPal ·
backed by ASN" hints alongside the series row — the link is nullable on
delete: a removed chain_link nulls the pointer here rather than removing
the series.

## `RecurringSeriesStateMachine` contract

The single legal mutator of `recurring_series.state` and the sole
inserter into `recurring_series_transitions`. Every other write path in
the module touches only non-state columns (detectors refresh latest
amount, monthly equivalent, next-expected-charge, funding-chain link,
`snoozed_until` — never `state`). The `noOtherRecurringSeriesStateMutator`
arch invariant enforces this at the static-analysis level; schema-level
BEFORE INSERT / BEFORE UPDATE triggers on `recurring_series` enforce it
at the database level.

`transition()` validates the requested target against
`ALLOWED_TRANSITIONS`, opens a DB transaction, sets
`PRAGMA busy_timeout = 5000`, takes a row lock on the series
(`lockForUpdate()`), writes the new state + `updated_at`, and inserts one
row in `recurring_series_transitions` carrying the full audit metadata.
Throws `InvalidStateTransitionException` for an illegal target,
`InvalidArgumentException` for an unknown actor, and
`SeriesRowVanishedException` when the series row is missing under the
lock. This SQLite contention guard means two concurrent sweep jobs that
briefly contend on the same series row serialise rather than fail.

Both exceptions are caught where a concurrent move is a normal outcome
rather than a defect, and only there:

- `DetectRecurringSeriesJob::expireSnoozes()` — `dispatchSync` bypasses
  the uniqueness lock, so two sweeps overlap on the request path and the
  loser reads a row that has already moved off `snoozed`. Uncaught, one
  refused row aborted the whole detection pass.
- `RecurringReviewPage` — a row can move under a second tab or a sweep
  between the render and the click. The action writes nothing, raises no
  toast, and the re-render shows the reader the row's real state instead
  of a 500.

Neither is caught anywhere a refusal would mean a programming error.

## Write-action security & idempotency contracts

Every Public write action in `Recurring` shares the same defensive shape:

- **Cross-user 404, never 403.** Every lookup carries an explicit
  `where('id', $seriesId)->where('user_id', $user->id)` predicate;
  a cross-user id raises `NotFoundHttpException` rather than leaking
  existence via a 403.
- **Idempotent no-ops.** Re-invoking with the target state/value
  already in place is a silent no-op (`ApproveRecurringSeries` on an
  already-`approved` row, `EditRecurringSeriesVarianceTolerance`
  when the new percent equals the current one, `SnoozeRecurringSeries`
  re-snoozing to the exact same timestamp).
- **State-machine writes vs metric-style writes.** State transitions
  (`approve`/`reject`/`unreject`/`snooze`) go exclusively through
  `RecurringSeriesStateMachine`, which writes the
  `recurring_series_transitions` audit row in the same DB
  transaction. Metric-style column writes (`EditRecurringSeriesName`,
  `EditRecurringSeriesVarianceTolerance`, `SetDriftThresholdForSeries`)
  never transition `state` and carry no audit row or event — the
  `noOtherRecurringSeriesStateMutator` arch invariant enforces this
  split statically.
- **Whitelisted enums over free-form input.** Variance tolerance and
  drift-threshold percentages are whitelisted to the UI's fixed
  dropdown options; any other value raises `InvalidArgumentException`
  so a tampered Livewire payload cannot smuggle an arbitrary percent
  onto the row.
- **`SetDriftThresholdForSeries` lives on the `Recurring` side of the
  module boundary** even though `DriftAlerts` owns the drift-alert
  domain, so the `noRecurringSeriesWritesFromDriftAlerts` invariant
  stays green — `DriftAlerts`'s threshold editor calls this action via
  method-parameter DI and never writes `recurring_series` directly.

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
sidebar badge → NavCountsService active-series count
```
