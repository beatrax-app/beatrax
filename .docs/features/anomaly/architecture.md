# `Anomaly` — architecture

The `Anomaly` module flags unusual charges — a large-vs-typical amount, a
large first-time merchant, or a duplicate-looking charge — as distinct
from `drift-alerts`' recurring-series drift. One row in `anomaly_alerts`
models one detected unusual charge for a single transaction
(`UNIQUE(transaction_id)`), aggregating every tripped reason rather than
minting a row per reason.

## Detection orchestration

`AnomalyEvaluator::evaluate(int $transactionId, User $user)` is the single
entry point shared by the reactive job, the full-history backfill, and the
scheduled safety-net sweep. Its skeleton (idempotent insert, `QueryException`
no-op, event dispatch, cross-module read discipline) is cloned from
`DriftEvaluator`; the math lives in three injected detectors
(`LargeVsTypicalDetector` / `FirstTimeMerchantDetector` /
`DuplicateChargeDetector`). Steps:

1. Load the transaction row (a sanctioned cross-module read of
   `transactions`; the evaluator never writes it).
2. Load the user's anomaly settings (sensitivity + minimum floor).
3. Run the three detectors, aggregating a canonically-ordered `reasons[]`
   of tripped keys (`large` / `first_time` / `duplicate`) — one alert per
   transaction, multi-reason.
4. Drop any reason a matching `anomaly_suppression_rules` row suppresses,
   checked BEFORE insert; if no reason survives, return without inserting.
5. Insert exactly one `anomaly_alerts` row carrying `reasons[]`, the
   large-vs-typical baseline trio, `sensitivity_percent_used`, and
   direction, under a DERIVED id (below); a `UNIQUE(transaction_id)` or
   primary-key collision is caught at the `QueryException` boundary and
   treated as a silent no-op.
6. Dispatch `AnomalyAlertOpened` and `EntityMutated` on a successful insert.

Every raw query carries an explicit `where('user_id', ...)` — the
`BelongsToUser` global scope does not fire under queue/console, where this
evaluator runs.

The `large` reason can be injected two ways: directly by
`LargeVsTypicalDetector`, or synthetically by `FirstTimeMerchantDetector`
when a brand-new merchant's charge is large vs the user's *overall* spend
(the first-time-and-large coupling is deliberate — a new payee with a
small/typical charge is noise, not signal). When the synthetic `large` is
excluded from suppression-rule matching (see below), the per-merchant
`large` band cannot mute a first-time merchant's own signal.

## Detectors

- **`LargeVsTypicalDetector`** — judges a charge against the user's own
  per-counterparty history (robust median + k×MAD z-score) over a rolling
  12-month window, falling back to the per-category p95 when the merchant
  has thin history (fewer than 5 prior samples). When both the
  counterparty and category are thin, the detector returns null (no fire)
  rather than guessing. Every amount is compared in settled minor units so
  a multi-currency merchant's history stays comparable.
- **`FirstTimeMerchantDetector`** — fires only when the user has no prior
  transaction for the counterparty AND the charge is large vs the user's
  overall same-direction settled-currency distribution. The overall-history
  minimum sample size (3) is deliberately lower than the per-merchant thin
  cutoff (5).
- **`DuplicateChargeDetector`** — fires when an earlier sibling charge
  exists for the same counterparty, exact settled amount/currency,
  direction, within a 7-day backward window. The window is
  backward-only (with an `id <` tie-break for same-day siblings) so a
  genuine double-charge produces exactly one alert, on the later charge of
  the pair, regardless of which evaluation path (reactive import,
  backfill, safety-net sweep) processes the rows first. When both sides of
  a pair are members of an approved recurring series (resolved through
  Recurring's Public `seriesMembershipForTransactionIds`), the detector
  does not fire — a legit cadence landing twice is not a duplicate.

All three detectors share a minimum-amount floor that gates evaluation
entirely, and read `transactions` directly without ever writing it.

## Robust statistics

`RobustStatistics` is the pure, stateless arithmetic behind
`LargeVsTypicalDetector`: a median + k×MAD robust z-score, chosen over
mean+σ because per-merchant samples are small and outlier-laden (a single
legitimate large prior would inflate σ and mask the next anomaly). The
percentile-boundary test (`isAtOrAbovePercentile`) is deliberately
tie-inclusive (`>=`): for small samples, linear interpolation collapses
p95 toward the sample maximum, so a strict `>` would let a charge that
exactly repeats the largest-ever charge slip past undetected — exactly the
repeat-of-the-extreme case the feature exists to catch.

## Suppression

Suppression is checked before insert: a reason is dropped when a matching
`anomaly_suppression_rules` row exists (same counterparty, detector,
direction, and the charge's settled amount within `[band_low, band_high]`
in the same currency). `DismissAnomalyAlertAsExpected` computes the amount
band server-side as ±15% of the alert's `latest_amount_minor`
(`round(0.85x)` / `round(1.15x)`); for a duplicate-only or first-time-only
alert (which carries no per-merchant `latest_amount_minor`), the band
falls back to the alert transaction's own settled amount, so suppression
still works for those detectors. `RemoveAnomalySuppressionRule` exposes two
distinct paths: a settings "Remove" that deletes one rule by id (the
originating alert stays dismissed), and an "Undo" that deletes every rule
tied to a `source_anomaly_alert_id` and re-opens the alert via the state
machine's `dismissed -> open` edge — the only place that edge fires.

## State machine

`AnomalyAlertStateMachine` diverges from the `drift-alerts` map in exactly
one place: `dismissed -> open` is a legal undo edge (a user who dismissed
an anomaly "as expected" can re-open it via the Undo path above).
`acknowledged` stays terminal, and there is no "any state -> any state"
escape hatch — idempotent no-ops live in the Public Actions, not the state
machine.

## Jobs

All four jobs route through the shared `AnomalyEvaluator::evaluate()` path
— zero duplicated detection logic:

- **`DetectAnomaliesJob`** — queued (never inline) by the synchronous
  `EvaluateAnomaliesOnTransactionImport` listener after Import's
  `TransactionImported` fires. Uniqueness key is `"{userId}:{transactionId}"`
  so a reactive-import + safety-net-sweep + backfill trigger trio collapses
  into a single queued run.
- **`BackfillAnomaliesJob`** — dispatched once on first activation (the
  settings toggle), gated by `users.anomaly_backfilled_at`: the timestamp
  is claimed with a conditional `whereNull(...)->update(...)` before the
  walk, an atomic mutex so two racing dispatches cannot both walk full
  history. A worker crash mid-walk leaves the row stamped, so a retry
  no-ops rather than resuming — the hourly safety-net sweep is the durable
  backstop for anything a crashed backfill missed. History is enumerated
  via `->lazyById()` so a multi-year history never loads into memory at
  once. Backfilled alerts land in the normal Open queue with no special
  muting.
- **`SafetyNetAnomalySweepJob`** — hourly, per-user fan-out, re-evaluates
  only transactions with no existing `anomaly_alerts` row within a recency
  window.
- **`ReviveExpiredAnomalySnoozesJob`** — flips `snoozed` rows back to
  `open` once `snoozed_until` passes.

## The id is derived, not minted

`anomaly_alerts.id` is not an autoincrement. It is
`Core\Public\Support\DerivedRowId::for('anomaly_alerts', [user_id,
transaction_id])` — the sha256 of the columns `anomaly_alerts_uniq` already
names, folded into a positive 63-bit integer (63 and not 64 because SQLite's
`INTEGER` and PHP's `int` are both signed).

The reason is sync. The detector runs on every paired device, so both of them
open an alert for the same charge; an autoincrement gave each its own id, the
UNIQUE dropped whichever create arrived second, and the losing device's later
acknowledge named a row its peer had never held. Neither half of the tuple ever
moves — an alert is opened against one charge and stays against it, and the
transaction's id is device-stable because transactions sync pk-preserved — so
both devices compute the same number and the duplicate create collides
harmlessly.

`AUTOINCREMENT` was removed with it. Explicit ids are legal alongside it, but it
records the highest id ever used in `sqlite_sequence`, and one derived id near
2^63 would leave any later insert that omitted the column allocating past the
ceiling.

Capture rides on the same two writers: `AnomalyEvaluator` emits the create, and
`AnomalyAlertStateMachine` — the sole legal mutator of `state` — emits the edit
for every acknowledge, snooze, dismissal and revival.

## Read surfaces

A derived id does not ascend with insertion, so `AnomalyAlertQuery` cannot order
or page on it. The list orders `detected_at DESC` with the id breaking ties
only, backed by the existing `(user_id, state, detected_at)` index, and its
cursor carries both halves — `DriftPage` keeps a separate cursor for this tab
because drift ids are still autoincrement and page on the id alone.

`AnomalyAlertQuery` resolves merchant display names via Counterparties'
Public `identitiesForIds` (the table carries no `counterparty_id` column
directly — it is resolved through a permitted `transaction_id ->
counterparty_id` ledger read). The per-detector open-count breakdown is
computed in PHP over the revival-aware open set rather than a SQL
`GROUP BY`, since `reasons` is a JSON list and SQLite has no first-class
JSON-array aggregation here.
