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
scheduled safety-net sweep. Its skeleton (idempotent insert, classified
write-failure boundary, event dispatch, cross-module read discipline)
mirrors `DriftEvaluator`'s; the math lives in three injected detectors
(`LargeVsTypicalDetector` / `FirstTimeMerchantDetector` /
`DuplicateChargeDetector`). Steps:

1. Load the transaction row (a sanctioned cross-module read of
   `transactions`; the evaluator never writes it).
2. Return immediately unless
   [`TransactionType::isExternalMovementOf()`](../ledger/architecture.md#transactiontype--direction-is-not-the-question-anomaly-asks)
   holds — see [what this module does not judge](#what-this-module-does-not-judge)
   below.
3. Load the user's anomaly settings (sensitivity + minimum floor).
4. Run the three detectors, aggregating a canonically-ordered `reasons[]`
   of tripped `AnomalyDetector` cases — one alert per transaction,
   multi-reason.
5. Drop any reason a matching `anomaly_suppression_rules` row suppresses,
   checked BEFORE insert; if no reason survives, return without inserting.
6. Insert exactly one `anomaly_alerts` row carrying `reasons[]`, the charge's
   own settled amount and currency, the large-vs-typical baseline when one was
   computed, `sensitivity_percent_used`, and direction, under a DERIVED id
   (below). `latest_amount_minor` and `currency` are stamped whichever detector
   fired — the charge is a fact every alert knows — while
   `baseline_amount_minor` stays null when nothing compared against one. Read
   surfaces branch on that null rather than hydrating it to zero: a
   duplicate-only alert used to render `baseline EUR 0.00 -> actual: EUR 0.00`
   over a real charge, under an arrow that could only point up. Only a `UNIQUE(transaction_id)`
   collision is a no-op, and it is confirmed by re-reading the row rather
   than by the SQLSTATE alone: SQLite reports a `RAISE(ABORT)` trigger under
   the same 23000 class a unique violation uses, so a code-only check
   swallowed every schema failure as "already evaluated". Anything else
   propagates.
7. Dispatch `AnomalyAlertOpened` and `EntityMutated` after the insert
   returns — outside the guard, so a throwing listener cannot cost the
   capture event that carries the row to the paired device.

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

## What this module does not judge

Every question this module asks is behavioural — *was this unusual of you* —
and moving your own money between your own accounts is not something you did
to anyone. `AnomalyEvaluator` refuses a row whose type is not an
[external movement](../ledger/architecture.md#transactiontype--direction-is-not-the-question-anomaly-asks):
`transfer_out`, `transfer_in` and `adjustment` are never scored. `fee` is,
deliberately — a surprise bank charge is the reader's money leaving to
somebody else, which is exactly the thing worth surfacing — and so is
`refund`, on the income side.

The gate lives in the evaluator and not in the three detectors because it
decides eligibility for the row, once, rather than the maths of one reason;
every job path reaches it, because every one of them goes through `evaluate()`.

The same distinction scopes the baselines. Before it, a `transfer_out` was in
`Direction::Expense`, so every internal move sat in the sample the real charges
were compared against, and a card settlement was both flagged as a `large`
unusual charge and reported as a `first_time` merchant — the reader's own card
issuer, for money the twenty-three charges on that statement had already been
judged on individually. Six of the twenty-nine open alerts on the desktop
database were the two legs of three such settlements.
`2026_08_30_000001_drop_anomaly_alerts_raised_on_internal_moves` deletes the
ones already written; it does not touch the reader's suppression rules, only
nulls the alert each one names, because
[a rule is identified by its own tuple and not by its provenance](#suppression).

One question is deliberately left unscoped: `FirstTimeMerchantDetector`'s
prior-contact half counts *every* row for the counterparty, transfers
included. "Have I dealt with this party before" is about the relationship, not
about the kind of money — and the narrower reading would raise `first_time` on
a merchant the reader has already transferred money to, which is an alert too
many rather than an alert missed.

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
  direction, within a 7-day backward window. The window is backward-only on
  the DATE, with the `id <` tie-break reserved for a sibling sharing the
  anchor's date, so a genuine double-charge produces exactly one alert, on
  the later-dated charge of the pair, regardless of which evaluation path
  (reactive import, backfill, safety-net sweep) processes the rows first or
  what order they were inserted in. Among qualifying siblings the nearest
  one wins. When both sides of a pair are members of an approved recurring
  series (resolved through Recurring's Public
  `TransactionSeriesMembershipQuery`), the detector does not fire — a legit
  cadence landing twice is not a duplicate.

All three detectors share a minimum-amount floor that gates evaluation
entirely, and read `transactions` directly without ever writing it. The floor
is an amount in the reader's own currency, so `AnomalyEvaluator` restates it in
the charge's settled currency once before handing it to any of them — see
[the shared floor](detector-maths.md#the-shared-floor).

## Robust statistics

`RobustStatistics` is the pure, stateless arithmetic behind
`LargeVsTypicalDetector`: a median + k×MAD robust z-score, chosen over
mean+σ because per-merchant samples are small and outlier-laden (a single
legitimate large prior would inflate σ and mask the next anomaly). The
percentile-boundary test (`exceedsPercentile`) is deliberately
tie-inclusive (`>=`): for small samples, linear interpolation collapses
p95 toward the sample maximum, so a strict `>` would let a charge that
exactly repeats the largest-ever charge slip past undetected — exactly the
repeat-of-the-extreme case the feature exists to catch.

## The detector vocabulary

`Modules\Anomaly\Public\Enums\AnomalyDetector` is the one spelling of
`large` / `first_time` / `duplicate`, and its **declaration order is the
canonical `reasons[]` order** — paired devices must reach byte-identical
JSON for one charge, so a case inserted in the middle re-orders every alert
written after it.

The vocabulary is enforced in the schema the way `state` is, by trigger:
`anomaly_suppression_rules.detector` must be one of the enum's values, and
`anomaly_alerts.reasons` must be a non-empty JSON array every element of
which is. Before that, a drifted detector key was inert rather than loud —
the evaluator's `whereIn('detector', ...)` simply never matched it, so the
mute silently stopped working while the settings screen rendered the raw
`anomaly::settings.detectors.<key>` lang key at the reader. The read layer
now hands blades typed cases, so a template compares enum identities and
has nothing to render for a key the enum does not have.

## Suppression

Suppression is checked before insert: a reason is dropped when a matching
`anomaly_suppression_rules` row exists (same counterparty, detector,
direction, and the charge's settled amount within `[band_low, band_high]`
in the same currency). `Internal\Support\SuppressionRuleKey` is that
tuple, and `SuppressionRuleKeyResolver` derives it from an alert once, for
both the write and the undo — the band is ±15% of the alert's
`latest_amount_minor` (`round(0.85x)` / `round(1.15x)`), falling back to the
alert transaction's own settled amount for a duplicate-only or
first-time-only alert (which carries no per-merchant `latest_amount_minor`),
so suppression still works for those detectors.

`source_anomaly_alert_id` is provenance, not identity. Two alerts in one
band dedupe onto a single rule, and that rule keeps naming the FIRST of
them; deleting by that column meant undoing the second dismissal reported
success and left the merchant muted, while undoing the first pulled the rule
the second was relying on. The undo matches on the mute's own shape (plus
the source column, for a rule whose band can no longer be recomputed).

`RemoveAnomalySuppressionRule` exposes two distinct paths: a settings
"Remove" that deletes one rule by id (the originating alert stays
dismissed), and an "Undo" that deletes every rule the alert's band names and
re-opens the alert via the state machine's `dismissed -> open` edge — the
only place that edge fires.

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

`noOtherAnomalyAlertStateMutator` keeps it sole: no file under
`Modules/Anomaly/` outside the state machine may write `anomaly_alerts.state`
**or `snoozed_until`**, which is on the list because the Open tab decides
whether an alert is open by reading it. `actioned_at` and `dismissed_as` ride
the dismissal transition and are deliberately not on it. Migrations are
excluded, since a migration declares the column whose later mutation the rule
restricts.

## Read surfaces

A derived id does not ascend with insertion, so `AnomalyAlertQuery` cannot order
or page on it. The list orders `detected_at DESC` with the id breaking ties
only, backed by the existing `(user_id, state, detected_at)` index, and its
cursor carries both halves. `DriftAlertQuery` has the same shape, so
`DriftPage` reads both streams the same way.

`AnomalyAlertQuery` resolves merchant display names via Counterparties'
Public `identitiesForIds` (the table carries no `counterparty_id` column
directly — it is resolved through a permitted `transaction_id ->
counterparty_id` ledger read). The per-detector open-count breakdown is
computed in PHP over the revival-aware open set rather than a SQL
`GROUP BY`, since `reasons` is a JSON list and SQLite has no first-class
JSON-array aggregation here; it is keyed on `AnomalyDetector` values because
an array key cannot be an enum, and the dashboard badge walks
`AnomalyDetector::cases()` to read it back.
