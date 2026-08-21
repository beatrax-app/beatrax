# Anomaly detector maths

## The problem

"Is this charge unusual?" has to be answered from the user's own history,
and that history is tiny. A merchant the user pays monthly has twelve
samples in a year; a merchant they pay quarterly has four. The obvious
answer — flag anything more than a few standard deviations above the mean
— fails badly at that sample size: one legitimately large prior charge
pulls the mean up *and* inflates σ, so the very next anomaly sits comfortably
inside the band and never fires. The outlier teaches the detector to ignore
outliers.

So the large-vs-typical path uses **robust** statistics — a median and a
median absolute deviation, neither of which a single extreme sample can
move — and abstains outright rather than guessing when even those cannot be
computed from the data present.

All arithmetic lives in `Modules\Anomaly\Internal\Support\RobustStatistics`,
a pure static class with no I/O. The three detectors in
`Modules\Anomaly\Internal\Detectors` supply the samples.

## Sampling

Every sample is drawn from `transactions` under the same five constraints:

- `user_id` matches (explicitly — these detectors run under the queue and
  the console, where the `BelongsToUser` global scope does not fire),
- `type` is in the direction's transaction-type set (an expense is never
  compared against income),
- `settled_currency` matches the charge under test exactly,
- `posted_at >= now - WINDOW_MONTHS`,
- `id !=` the charge under test, so a charge is never part of its own
  baseline.

Amounts are always `settled_amount_minor` — the settled figure, not the
original — so a merchant that bills in several currencies keeps one
comparable history per currency instead of one incoherent mixed history.
Comparison is on absolute magnitude throughout, so the ledger's signed
convention (expenses negative) can never flip a result.

`WINDOW_MONTHS = 12`. Twelve months is the top of the intended 6–12 month
range: it covers a full seasonal cycle — an annual subscription renewal, a
salary step — without letting amounts from two years ago define "typical".
The window start is computed with `subMonthsNoOverflow()`, so running on the
31st does not skip a month.

## Large vs typical, per merchant

`LargeVsTypicalDetector::fires()` first tries the per-counterparty sample.
It needs `RobustStatistics::THIN_HISTORY_CUTOFF = 5` prior charges for that
merchant; below that the per-merchant baseline is noise and the detector
falls through to the category path below.

With 5 or more samples the trip test is a robust z-score:

```
robustSigma = MAD_CONSISTENCY × MAD(sample)
denominator = max(robustSigma, floor)
z           = (|x| − median(sample)) / denominator
fires when    z > k
```

`MAD_CONSISTENCY = 1.4826` is the standard consistency constant: for
normally distributed data, `1.4826 × MAD` converges on σ, which is what makes
a robust z-score comparable in scale to an ordinary one. That is the only
reason it is there — it does not make the estimator more or less robust, it
just puts `k` back on the familiar "how many sigma" scale.

`median(sample)` is the baseline the alert reports back to the user as
`baseline_amount_minor` (stored negated, matching the ledger's expense sign).

### The MAD floor

A merchant that charges exactly the same amount every month has `MAD = 0`,
and therefore `robustSigma = 0` — every deviation from the median divides by
zero and every charge one cent off is infinitely anomalous. The denominator
is floored, at the larger of two values:

- `RobustStatistics::MAD_FLOOR_MINOR = 50` — a flat 50 minor units (€0.50),
  the hard minimum enforced inside `robustZ()` itself, regardless of what
  the caller passes;
- `LargeVsTypicalDetector::MAD_FLOOR_MEDIAN_FRACTION = 0.01` of the sample's
  median magnitude, computed by the detector in `madFloorFor()` and passed in
  as the caller's floor.

The value-scaled half is what keeps the flat floor from being useless at the
top of the range. On a merchant whose typical charge is €5, a €0.50 floor is
a tenth of the bill and a real deviation clears it easily. On a merchant
whose typical charge is €2,000, that same €0.50 floor means a €2,003 charge
scores z = 6 and fires — noise, not signal. Scaling to 1% of the median puts
that merchant's floor at €20 instead. Cheap merchants get the flat floor,
expensive ones get the value-scaled one, and the `max()` picks whichever
applies.

### The sensitivity knob

The user has one control, a sensitivity percentage, and
`kForSensitivity()` maps it onto the trip multiplier:

```
k = clamp(K_BASE − K_SLOPE × (sensitivity − K_PIVOT), K_MIN, K_MAX)
```

with `K_BASE = 3.0`, `K_SLOPE = 0.04`, `K_PIVOT = 50.0`, `K_MIN = 1.5`,
`K_MAX = 4.0`. At the 50% default this yields exactly `k = 3.0`. The slope is
negative in effect: **higher sensitivity means lower k means more alerts.**
The clamp is reached at both ends of the slider — sensitivity 0 would compute
k = 5.0 and is held at 4.0; sensitivity 100 would compute k = 1.0 and is held
at 1.5 — so neither extreme can turn the feature off entirely or turn it into
a firehose.

The k actually used is recorded on the alert as `sensitivity_percent_used`,
so an alert stays explainable after the user moves the slider.

## Large vs typical, per category (the fallback)

When the merchant sample is thinner than `THIN_HISTORY_CUTOFF`,
`categoryTrip()` re-samples on `category_id` instead, under the same five
constraints, and applies the same cutoff of 5. If the charge has no
category, or the category sample is also thin, the detector returns null —
**no fire.** Thin history is not weak evidence of anomaly, it is absence of
evidence, and the module would rather miss a charge than open an alert it
cannot explain.

The category test is a percentile rather than a z-score, because a category
mixes many merchants and its distribution is not remotely unimodal: the
median of "Groceries" says nothing useful. A charge fires when it is at or
above `CATEGORY_PERCENTILE = 95.0` of the category sample.
`baseline_amount_minor` on the alert is that p95 value.

### Why the percentile test is tie-inclusive

`RobustStatistics::exceedsPercentile()` compares with `>=`, not `>`, and this
is deliberate. `percentile()` interpolates linearly between ranks: for a
sample of size n it takes `rank = (p/100) × (n − 1)` and blends the two
neighbouring sorted values. At p95 with a small n, that rank sits on or
essentially on the last index — for n = 5, `rank = 3.8`; for n = 6,
`rank = 4.75` — so **p95 collapses onto the sample maximum**. Under a strict
`>`, a charge that exactly repeats the largest charge the user has ever made
in that category would be below threshold and pass silently. Repeat-of-the-
extreme is precisely the case the feature exists to catch, so the boundary
includes the tie.

`percentile()` degenerates safely: an empty sample returns 0.0, a
single-element sample returns that element.

## First-time merchant

`FirstTimeMerchantDetector::fires()` is an AND of two conditions, both of
which must hold:

1. the user has **zero** prior `transactions` rows for this counterparty
   (excluding the charge itself), and
2. the charge is at or above p95 of the user's *overall* same-direction,
   same-settled-currency distribution over the same 12-month window.

The second condition is what makes the detector useful. A new payee for a
small or typical amount is noise — people acquire merchants constantly. A
new payee for an amount at the top of everything the user spends is the
fraud-adjacent case worth surfacing.

The minimum sample here is `OVERALL_HISTORY_MIN = 3`, deliberately lower than
the per-merchant `THIN_HISTORY_CUTOFF = 5`. The two numbers measure different
things: five is the point at which *one merchant's* charges start to describe
a stable band, while the overall pool is the user's entire spend and a handful
of points already bounds it. Below 3 the detector abstains and returns false.

Because a brand-new merchant has no per-merchant baseline for
`LargeVsTypicalDetector` to judge, a first-time-and-large charge injects the
`large` reason synthetically — the overall-spend comparison *is* the large
evidence. `AnomalyEvaluator` tracks whether `large` came from the merchant
baseline or from this synthetic path, and excludes the synthetic one from
suppression-rule matching, so a per-merchant `large` band cannot mute a
first-time merchant's own signal. See
[architecture.md](architecture.md) for the suppression model.

## Duplicate charge

`DuplicateChargeDetector::fires()` looks for an earlier sibling with the same
counterparty, the exact same `settled_amount_minor` and `settled_currency`,
and the same direction, inside `[anchor − DUPLICATE_WINDOW_DAYS, anchor]`
where the anchor is the charge's own `posted_at`.

`DUPLICATE_WINDOW_DAYS = 7` covers the real pattern — a double-tap at the
terminal, a retried payment, a merchant that captures twice — over a horizon
long enough to survive weekend settlement delays. It does not extend to catch
monthly repeats, which are not duplicates at all.

Two properties make the window safe to leave that wide:

- **It looks backward only**, with an `id <` tie-break for same-day
  siblings. A genuine double-charge therefore fires exactly once, on the
  later charge of the pair, no matter which evaluation path (reactive
  import, backfill, safety-net sweep) reaches the rows first, and no matter
  what order they were inserted in. A symmetric window would open two alerts
  for one incident, and an order-dependent one would open a different number
  depending on the import.
- **Both-on-a-series is excluded.** A weekly or fortnightly subscription
  falls inside seven days, so the detector resolves series membership for
  the candidate and the sibling through Recurring's
  `RecurringSeriesQuery::seriesMembershipForTransactionIds()` and does not
  fire when *both* are approved members. The condition is deliberately AND,
  not OR: a one-off duplicate of a subscription charge — the subscription
  billed twice — has only one member on the series, and still fires.

## The shared floor

All three detectors take `minFloorMinor` and return early below it, before
any query runs. Statistically a €1.20 coffee can be a five-sigma event
against a merchant whose history is all €0.30; it is not worth an alert.
The floor is a per-user setting, checked first, so the detectors do no work
at all on small charges.

## Where the numbers live

| Constant | Value | Class |
|---|---|---|
| `WINDOW_MONTHS` | 12 | `RobustStatistics` |
| `THIN_HISTORY_CUTOFF` | 5 | `RobustStatistics` |
| `MAD_CONSISTENCY` | 1.4826 | `RobustStatistics` |
| `CATEGORY_PERCENTILE` | 95.0 | `RobustStatistics` |
| `MAD_FLOOR_MINOR` | 50 | `RobustStatistics` |
| `K_BASE` / `K_SLOPE` / `K_PIVOT` | 3.0 / 0.04 / 50.0 | `RobustStatistics` |
| `K_MIN` / `K_MAX` | 1.5 / 4.0 | `RobustStatistics` |
| `MAD_FLOOR_MEDIAN_FRACTION` | 0.01 | `LargeVsTypicalDetector` |
| `OVERALL_HISTORY_MIN` | 3 | `FirstTimeMerchantDetector` |
| `DUPLICATE_WINDOW_DAYS` | 7 | `DuplicateChargeDetector` |

## Related

- [architecture.md](architecture.md) — the module map: orchestration,
  suppression, state machine, jobs, the derived alert id.
- [../drift-alerts/architecture.md](../drift-alerts/architecture.md) —
  recurring-series drift, the sibling feature this one is deliberately
  distinct from.
