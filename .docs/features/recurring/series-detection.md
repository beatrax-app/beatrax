# `Recurring` — how a series is detected

A bank statement is a flat list of dated amounts. Nothing in it says
"this one is a subscription". The recurring detector's whole job is to
read that flat list and propose which rows are the same repeating
commitment seen several times — a Spotify charge, a quarterly insurance
premium, a salary — so the rest of the product can treat them as one
thing with a next occurrence rather than as unrelated history.

This page describes the detection algorithm end to end: what it groups,
what it filters, how it decides a cadence, the exact tolerances it uses,
and why it never acts on its own conclusions.

## Why the obvious approach does not work

The naive rule is "same counterparty, same amount, spaced 30 days
apart". Every part of that fails on real bank data.

**Same amount fails.** Prices change mid-window (a streaming service
raises its price by €1.50 and the same subscription now has two
amounts). Utility bills are recurring commitments whose amount is
genuinely different every month. FX-settled subscriptions move a few
percent every cycle because the rate moved, not because anything about
the subscription changed.

**Exact 30-day spacing fails.** Providers bill on a day-of-month, so
the gaps alternate 28/31/30. Weekends and bank holidays push a posting
date forward. Providers occasionally skip a month outright and take two
next time. A rule tight enough to reject noise rejects almost every
real subscription; a rule loose enough to accept them accepts anything
that happened twice.

**Same counterparty fails on its own.** Two payroll providers can share
a normalised description. A gym charging five random amounts at five
random intervals is one counterparty and no pattern at all.

So the detector does not test for exactness anywhere. It works in bands
and medians: cluster loosely, drop amounts that are clearly not the same
commitment, take the median gap, and only accept the cluster if that
median lands inside one of four named cadence bands. Anything that does
not is classified `irregular` and dropped, rather than being reported as
"every 46 days".

## The pipeline

Detection runs per user, as one sweep over both directions. For each
detector the shape is the same:

1. **Window.** Read transactions posted within the last
   `users.recurring_detection_window_months` months (see the tuned
   default below). `ExpenseSeriesDetector` reads types `expense` and
   `fee`; `IncomeSeriesDetector` reads type `income` only, and
   only rows at or above `users.recurring_income_min_amount_minor`.
   `refund` is deliberately absent: `TransactionType::Refund` carries
   `Direction::Income` and a positive amount, so inside an expense
   cluster it outranked the subscription as the newest row and the
   fixed-payments card rendered a **+**€10.99/month subscription.
2. **Group.** Bucket the rows by a counterparty identifier plus the
   **original** transaction currency. Expense groups on
   `counterparty_normalized`; income groups on the counterparty IBAN
   and falls back to `counterparty_normalized` when there is no IBAN.
3. **Filter.** Drop rows whose sign disagrees with the cluster's, then
   rows whose absolute amount falls outside a tolerance band around the
   cluster's median absolute amount. Both detectors run it —
   `ClusterAmountFilter` is shared.
4. **Infer cadence.** Feed the surviving posting dates to
   `CadenceInferrer`, which returns a cadence band, a median interval,
   a projected `next_expected_at`, a low-confidence flag and a
   missed-occurrence count.
5. **Accept or drop.** A cluster that resolves to `irregular` is
   dropped entirely — no row is written, nothing is suggested.
6. **Write.** Compose a cluster key, then either insert a new `pending`
   series or refresh the metrics on the series that already represents
   this cluster. Either way the contributing transactions are recorded
   as occurrence rows.

Grouping on the **original** currency rather than the settled one is
deliberate. A USD subscription settles to a slightly different EUR
amount every month as the rate moves; clustering on the settled amount
would make one stable subscription look like twelve different ones.

## The numbers

Everything the detector tolerates is a named constant. These are the
values it currently uses.

| Constant | Value | What it governs |
| --- | --- | --- |
| Detection window | 2 months (per-user, 2–60) | How far back the sweep reads |
| Minimum occurrences | 2 | Rows needed before a cluster is considered at all |
| Variance tolerance | ±25% (per-series; 10 / 25 / 50) | Expense amount band around the cluster median |
| Income minimum | €2000.00 (per-user) | Floor below which income rows are ignored |
| Weekly band | 0 < median < 10 days | |
| Monthly band | 10–45 days | |
| Quarterly band | 80–100 days | |
| Yearly band | 350–380 days | |
| Missed-interval multiplier | 1.8 × provisional median | A gap above this counts as a missed period |
| Missed-period cap | more than 2 in any 6 consecutive intervals | Above this the cluster is rejected as unstable |
| Low-confidence threshold | interval stddev > 5.0 days | Flags `next_expected_at` as uncertain |

The gaps between the cadence bands are load-bearing. An interval of 60
days matches nothing, so a cluster with a two-monthly rhythm falls
through to `irregular` instead of being absorbed by whichever comparison
happened to come last. The bands are written as a `match (true)` table
for exactly that reason.

### Why the detection window default is two months

The window default was 18 months and is now 2. The minimum-occurrences
gate is 2, so two months of history is already the smallest amount of
data the engine can act on at all, and it is enough to catch monthly
subscriptions and two-monthly pairs. The longer default was a
cold-start hedge for a user with no history; for a user who is actively
importing it mostly dragged older, no-longer-current noise into every
sweep. Users who explicitly chose a different value keep it — only rows
still parked on the old default were rolled forward.

The setting is still per-user and can be raised back to 60 months from
the settings page, which is how a yearly subscription becomes
detectable: two yearly charges need a window wider than two years.

Both ends of that range and the fallback itself are written once, in
`Modules\Recurring\Public\Support\RecurringDetectionWindow`
(`MINIMUM_MONTHS`, `MAXIMUM_MONTHS`), and both detectors open their window
through `RecurringDetectionWindow::opensOn()` rather than computing it
themselves. The floor and the fallback are the same number because they are
the same fact — below two months a monthly series cannot show the two
occurrences the gate needs — so a settings screen that widened alone would
offer the reader a window that detects nothing, and a detector that fell back
differently from its sibling would put a series in the merged set the other
pass could not see the occurrences of.

### Why the variance filter uses the median, not the mean

The filter computes the median of the cluster's absolute amounts and
keeps rows within `median × (100 ± tolerance) / 100`. A mean would be
dragged by the very outlier the filter exists to remove — one €500
charge in a cluster of €10 ones moves the mean enough to widen the band
around itself. The median does not move.

If the median is zero or negative the filter is skipped and every row is
kept; there is no meaningful band around zero.

The filter runs on **both** detectors. Income ran without one, so a
€12,000 holiday allowance paid beside four €3,500 salaries became the
cluster's newest row and was written to both `latest_amount_minor` and
`monthly_equivalent_minor`. Income also honours a user-widened
`variance_tolerance_percent` the way expense does — the existing series
for the (user, counterparty, currency) is read before the cluster
qualifies, because that row carries the tolerance that decides it.

### The sign guard, which runs before the band

A row carries its magnitude and its direction in one signed integer, so
comparing on `abs()` alone makes a refund look like a charge of the same
size. `ClusterAmountFilter` therefore drops rows whose sign disagrees
with the cluster's dominant sign — the sign of the median of the
**signed** amounts — before any band is computed. The dominant sign is
read off the cluster rather than off a direction constant, so a ledger
that stored one merchant the other way round loses that series' amounts
rather than every series it has.

The tolerance is not a fixed constant at refresh time. Before filtering,
the detector looks for an existing series for this
(user, counterparty, currency) in a live state and reuses the tolerance
stored on that row. This is what makes the per-series tolerance editor
work: a user who widens a variable utility bill to 50% keeps that
cluster intact on the next sweep instead of watching it fragment back
under the 25% default.

### Missed periods, and why the cadence snaps on the unfiltered median

`CadenceInferrer` computes the intervals between consecutive postings
and takes a provisional median. Any interval longer than 1.8 × that
provisional median is treated as a *missed period*: it is excluded from
a second, refined median and counted. If more than two missed periods
fall inside any window of six consecutive intervals, the cluster is
declared `irregular` outright — too unstable to be a commitment.

The subtle part is which median decides the cadence. There are two, and
they are used for different things:

- The **provisional** median (before the missed-interval filter) decides
  which cadence band the cluster snaps into.
- The **refined** median (after it) is what `next_expected_at` is
  projected from.

That split exists to stop the missed-interval filter from rescuing
clusters it should not. Consider a gym charged at 5, 40, 70, 120 and 200
day gaps: no pattern at all. If the cadence snapped on the refined
median, the filter would discard the long gaps as "missed periods" and
the surviving short gaps would land inside the monthly band — and the
user would be shown a confident "monthly gym membership, next charge on
the 14th" that does not exist. Snapping on the provisional median lets
that cluster fail honestly as `irregular`.

The reverse case is the reason the refined median exists at all: a real
monthly subscription that skipped two months should still be read as
monthly rather than three-monthly, so the skipped gaps are discounted
before the median is reported.

### Projecting the next occurrence

`next_expected_at` is one step of the **snapped cadence** past the last
posting — one month for `SeriesCadence::Monthly`, seven days for
`SeriesCadence::Weekly`, three months for `SeriesCadence::Quarterly`, a
year for `SeriesCadence::Yearly`. It is not the median number of days
added to that posting: a bill seen on 15 January and 15 February has a
31-day median, and adding 31 days projects 18 March, then 14 April, then
a little further off every period. Stepping the band is also what
delivers "one month out, not three" for the subscription that skipped —
the step is taken from the last posting the series actually has, so a
skipped period is behind it, not inside it. An `irregular` cluster is
projected nowhere at all — it is the one band with no calendar step, and
it is excluded before the step is taken rather than falling through to a
day-median guess.

The day of the month is derived from the whole window, not off the
stepped date and not off one posting. February clamps a bill charged on
the 31st to the 28th, and no later step recovers the 31st from a clamped
date — every month after it would sit on the 28th.

Reading the billing day off the window's **oldest** posting fixed the
clamp but made the answer depend on which month happened to be oldest: a
February + March window projected 28 April for a bill on the 31st, and
`next_expected_at` moved by up to three days as the window rolled.
`CadenceInferrer::billingDay()` instead treats a posting that lands on
its own month's last day as *clamped evidence* — the real day is at
least that, never less — and:

- takes the most frequent day among the **unclamped** postings, keeping
  the earliest posting's day on a tie, because a cluster whose days
  never agree has no billing day to find and moving it would be noise;
- falls back to the largest clamped day when every posting is a
  month-end one, which is the 31st-of-the-month case.

`SeriesEntryPlacer` then steps whole periods from `next_expected_at`
rather than chaining single steps, for the same reason — and it hands
`SeriesCadence::occurrenceAt()` the series' own `billing_day`, which is
persisted alongside `next_expected_at` for exactly this. Stepping from
the anchor alone is not enough, because the anchor is itself clamped
whenever it lands in a short month: a 31st bill whose next date fell in
February projected the 28th for every month after it on the calendar and
the forecast curve, while the reminder — which re-infers from the
postings each sweep — kept naming the 31st. The two surfaces named
different days for the same charge.

`confidence_low` is set when the interval standard deviation exceeds 5
days. It does not change the cadence; it marks `next_expected_at` as a
soft estimate, which the UI renders dimmed and which downstream
consumers can weigh.

### The cluster key

`ClusterKeyComposer` builds the string stored in
`recurring_series.cluster_key` from four parts — direction,
counterparty, original currency, cadence band — each lowercased with
every run of non-alphanumeric characters collapsed to `-`, trimmed, and
capped at 60 characters, joined with `::`.

The normalisation is what makes re-imports safe: `"Acme, Inc."` and
`"Acme Inc"` collapse to the same token, so importing the same merchant
twice under slightly different spellings does not spawn two parallel
series. The key is the payload of the
`(user_id, direction, cluster_key, latest_currency)` UNIQUE constraint,
which is what makes re-running the whole sweep idempotent.

It is deliberately NOT what the row's primary key is derived from. `cluster_key`
moves — see the cadence note below — and a derived id has to come from columns
that never do, so `DerivedSeriesId` folds
`(user_id, direction, cluster_counterparty_key, latest_currency)` instead. See
[the sync architecture](../sync/architecture.md#capture-for-the-last-five-detector-driven-tables).

Because the cadence band is *part* of the key, a series whose cadence
changes no longer matches its own old key. That is handled deliberately
rather than accidentally: both detectors also look the series up by the
persisted `cluster_counterparty_key`, so a cadence flip resolves onto
the existing row instead of inserting a second one. The fallback keys on
the counterparty identifier rather than the display name, so two payroll
providers that share a normalised name but differ by IBAN stay separate.

### Monthly equivalent

Every series carries a `monthly_equivalent_minor` so mixed cadences can
be summed into one "what do I spend a month" figure. The conversions are
weekly `× 52 / 12`, monthly unchanged, quarterly `÷ 3`, yearly `÷ 12`;
`irregular` has none.

`52 / 12` is computed rather than written as `4.33`. The rounded literal
drifted about 0.07% on every weekly row — a weekly €10 projected to
€43.30 a month instead of €43.33 — and that error compounded across the
dashboard totals.

## The detector never applies its own conclusions

Every newly detected series is written in state `pending`. It is a
suggestion on the review queue and nothing else: it does not appear in
the forecast, it is not compared for drift, and it does not appear on
the fixed-payments card until the user approves it. There is no
confidence score above which the detector promotes a series itself, by
design.

The states are `pending`, `approved`, `rejected`, `snoozed` and
`cadence_changed`, and the legal moves are:

- `pending → approved | rejected | snoozed`
- `approved → cadence_changed | rejected`
- `cadence_changed → approved | rejected`
- `snoozed → pending | approved | rejected`
- `rejected → pending`

There is no any-to-any escape hatch and no same-state re-entry;
idempotent no-ops live in the public actions. Every transition goes
through `RecurringSeriesStateMachine`, which is the only sanctioned
writer of the `state` column — a SQLite trigger pair and a boundary arch
invariant both enforce that — and each one appends a row to
`recurring_series_transitions` recording the from-state, to-state,
reason and actor (`user` or `detector`).

What a later sweep may and may not do to an existing row follows from
that:

- **Rejected rows are never touched.** Rejection covers the whole
  (counterparty, currency) pair across *every* cadence band, so a
  rejected monthly series does not come back as a quarterly suggestion.
  Only the user un-rejecting it puts it back in play.
- **Snoozed rows are skipped entirely.** Refreshing one would change the
  amount the user paused on, underneath them. The sweep's first pass
  expires elapsed snoozes back to `pending`, and only then detects.
- **Approved rows are refreshed**, and if the cadence band moved the
  state machine flips them `approved → cadence_changed` and raises
  `RecurringSeriesCadenceFlipped`. The row keeps working; it is just
  flagged for re-confirmation rather than silently reinterpreted.
- **Occurrence rows are written with INSERT-OR-IGNORE** against the
  `(recurring_series_id, transaction_id)` UNIQUE constraint, so a second
  pass over the same data adds nothing.

`detected_name` is refreshed by the detector, but a user-supplied
`display_name_override` always wins at the read site and is never
written by a sweep.

`MerchantDisplayName::forStoredKey()` resolves the clustering key through the
user's own `merchants.name` and then the decrypted
`transactions.counterparty_name`, and answers null when neither knows a name —
at which point the detector **defers the series to the next sweep** rather than
writing an unreadable value into a column the review screen renders. It answers
null for two shapes: a keyed digest, and the `_no_counterparty` sentinel, which
is legible but names no merchant and used to print itself at the user.

Two caveats. A series detected *before* encryption keeps whatever
`detected_name` it already had — the sweep converts `cluster_counterparty_key`
and deliberately not the display column — so `healed()`'s
`$storedName === $normalized` test can no longer match on those rows, and a
pre-encryption clustering key stays on screen until the user renames it. See
[Which columns are encrypted at rest](../sync/sensitive-columns-at-rest.md).

## Who consumes the result

- [Forecasting](../forecasting/architecture.md) reads every approved (and
  `cadence_changed`) series unpaged and projects its contributions
  across the horizon. This is why `cadence_changed` counts as approved
  for reads: from the user's point of view the commitment is still real,
  only its reclassification is awaiting confirmation, and excluding it
  would silently drop a payment out of the forecast.
- [Drift alerts](../drift-alerts/architecture.md) compare each new
  occurrence's amount against the series baseline, using the per-series
  `drift_threshold_percent` override when set and the per-user
  `drift_alert_threshold_percent` otherwise. `irregular` series are
  never detected in the first place, so they never reach this
  comparison.
- The `/recurring` fixed-payments view and the dashboard card read the
  same two states, sorted by absolute monthly equivalent. They filtered
  on `approved` alone, so a series the detector had just flipped to
  `cadence_changed` vanished from the page, the card and the monthly
  totals while Forecasting and the sidebar badge went on counting it.
  Every read of the projectable set goes through
  `RecurringSeriesState::projectableValues()` rather than naming the
  states, which is what keeps the two sides from drifting apart again.

## Related pages

- [Detection and the encryption posture](detection-encryption-posture.md)
  — why income detection must run in-process while the key is
  available, and what breaks if the job is queued.
- [The detection fixture corpus](detection-corpus.md) — the fixed set of
  cases that pin every tolerance on this page.
- [`Recurring` architecture](architecture.md) — the module's boundary,
  public surface and read services.
