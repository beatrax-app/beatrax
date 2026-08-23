# Projection math — from recurring series to a banded balance curve

This page follows a single number all the way through the projection
pipeline: from one approved recurring series in
[`Recurring`](../recurring/architecture.md), through the per-occurrence
contribution, the daily fold, and out the other side as the
`low_minor / point_minor / high_minor` triple the chart draws and the
shortfall detector reads.

[`Forecasting` — architecture](architecture.md) describes what each
class is for and how they are wired. This page is the arithmetic: the
exact formulas, the exact constants, the rounding rule, and the places
where the maths does something a reader would not predict from the
class names.

## The problem

A recurring series is not a promise. The electricity bill is "monthly,
about €140" — but the eight charges actually observed were €60, €105,
€220, €195, €180, €130, €85 and €140. A single-line forecast drawn
through the mean of those tells the user their balance on 28 June will
be one specific number. It will not be. The line is a lie with a very
convincing shape.

The obvious fix — average the amounts and draw that — is worse than
doing nothing, for two reasons:

- **It throws away the only information the user needs.** The mean of
  that series is about €139. The thing the user actually wants to know
  is whether a €220 January-style bill would push them under their
  buffer. The mean is silent about exactly that.
- **It is confidently wrong at both ends.** A mean line drawn 90 days
  out compounds three months of "about" into a single unqualified
  figure, and the user reads a rendered line as a fact.

So every projected amount travels as a triple — a pessimistic bound, a
central estimate, and an optimistic bound — and the chart draws the
region between them. The width of that region is the message.

## What the band actually is (and is not)

This is the single most common misreading of this pipeline, so it is
worth stating before the mechanism.

`Percentile` computes P10, P50 and P90. Those percentiles are computed
**once per high-variance series, across that series' own observed
historical amounts**. They become that series' `(low, point, high)`
triple, and every projected occurrence of that series carries the same
triple.

The band you see on the *balance curve* is not a percentile of
anything. `DailyFold` reduces each contribution's triple to a single
half-width, combines same-day half-widths in quadrature, and paints
that half-width symmetrically around the running balance. There is no
simulation, no sampling, and no distribution over balances. The curve
is one deterministic running total with an uncertainty envelope drawn
around it.

Two consequences follow directly, and both matter:

- **Asymmetry is discarded.** The electricity series' real triple is
  skewed — the pessimistic end is €70 below the centre while the
  optimistic end is €58 above it. The fold keeps only
  `|high - low| / 2` and paints it both ways, so the balance curve's
  band is always symmetric even when the underlying series' band was
  not.
- **The band is not a confidence interval.** Calling the edges "P10"
  and "P90" on the balance curve would be wrong. They are
  "centre ± combined half-width".

## Stage 1 — one series becomes banded contributions

`RangeProjector::project()` walks a series' `nextExpectedAt` forward by
its cadence until the horizon end, emitting one `ForecastContribution`
per occurrence that lands on or after `asOf`. An `Irregular` cadence
emits nothing at all, and neither does a series with no
`nextExpectedAt`.

### Every occurrence is anchor + k, never a chain of steps

Cadence steps are overflow-safe: a monthly step off 31 January lands on
28 February rather than spilling forward into March. That much is not
enough on its own. Take the *next* step from the date February just
clamped and you get 28 March, and every month after it inherits the
28th — a bill charged on the 31st quietly migrates to the 28th for the
rest of the projection, and the calendar, which never chained, goes on
showing the 31st. The clamp is not the fault. Forgetting the anchor is.

So the k-th occurrence is computed as `anchor + k periods` in one shot,
from an anchor that never moves:

```
anchor 2026-01-31, monthly, chained:  01-31 → 02-28 → 03-28 → 04-28 → …
anchor 2026-01-31, monthly, anchor+k: 01-31,  02-28,  03-31,  04-30, …
```

The two agree until the first short month and never agree again. The
same holds a quarter and a year out: a quarterly series anchored on 31
December reaches 31 December next year, and a yearly one anchored on 29
February 2024 returns to 29 February in 2028.

Multiplying is also the only form that reads backwards. `SeriesEntryPlacer`
walks negative `k` to fill in the part of a calendar month that already
happened, and a chain of no-overflow steps cannot be run in reverse.

`SeriesCadence::occurrenceAt()` holds that arithmetic for the whole app
— the enum owns the cadence, so it owns the step. It matches without a
`default` arm, so a cadence added later fails loudly instead of
silently taking a step nobody gave it. `CadenceWalk` is the forecasting
side: it yields the occurrence dates falling between `asOf` and the
horizon end, and both projection tiers plus `ScenarioApplier`'s
`add_recurring` mutation take their dates from it rather than each
walking their own.

The recurring module sets that anchor under the same rule from the
other end — see [Projecting the next
occurrence](../recurring/series-detection.md#projecting-the-next-occurrence)
for how `next_expected_at` is chosen, and why the billing day is read
off the series' first posting rather than off the stepped date.

Which formula produces the triple depends on a two-part gate:

```
percentile tier  ⟺  varianceTolerancePercent >= 40
                    AND count(observed occurrences) >= 6
```

Both bars must be cleared. Fail either one and the series takes the
envelope tier. The occurrence rows are only read from the database
when the first bar is cleared, so a calm series costs no extra query.

### Envelope tier

The default. It knows nothing about history — it stretches the latest
known amount by the series' own declared tolerance:

```
magnitude = |latestAmount|
lowMag    = round(magnitude × (100 - tol) / 100)
highMag   = round(magnitude × (100 + tol) / 100)
```

Then the sign is re-applied, and this is the part that trips people
up. For an **expense** the point is negative, and a *wider* outflow is
a *more negative* number, so the larger magnitude has to land in
`lowMinor`:

```
expense (point < 0):   lowMinor = -highMag,  highMinor = -lowMag
income  (point >= 0):  lowMinor =  lowMag,   highMinor =  highMag
```

Both branches guarantee `lowMinor <= pointMinor <= highMinor` when the
three are compared as raw signed integers. Everything downstream —
the fold's half-width, the chart, the confidence legend — relies on
that ordering holding without a per-direction special case.

Worked example, Netflix at €11.99/month with a 5% tolerance:
`magnitude = 1199`, `lowMag = round(1139.05) = 1139`,
`highMag = round(1258.95) = 1259`, and because it is an expense the
emitted triple is `(-1259, -1199, -1139)`.

### Percentile tier

For a series that has cleared both bars, the declared tolerance is
discarded and the triple is read straight out of history. The observed
amounts are collected, `p10`/`p50`/`p90` are computed over them, and
the resulting three numbers are sorted numerically so that the same
`low <= point <= high` invariant holds for income and expense alike.
Sorting rather than assigning by name is deliberate: for an
all-negative expense series, P10 is the *most negative* value, so it
must become `lowMinor` — the opposite of what the names suggest.

The triple is constant across every occurrence in the horizon. It
represents the full empirical distribution of the series, not
occurrence-by-occurrence variance, so June's charge and July's charge
carry identical bands.

### The interpolation rule, exactly

`Percentile` implements the R-7 method — linear interpolation between
closest ranks, the same definition used by `numpy.percentile`,
`scipy`, Excel's `PERCENTILE.INC` and R's `quantile(type = 7)`.

```
n     = count(values)
sorted = values sorted ascending, SORT_NUMERIC (signed)
index = (n - 1) × p / 100
k     = floor(index)
d     = index - k
result = round(sorted[k] + d × (sorted[k+1] - sorted[k]))
```

Behaviour at the edges:

- `n == 0` raises `InvalidArgumentException`. There is no meaningful
  percentile of nothing, and returning `0` would inject a phantom
  zero-amount contribution into a real balance.
- `n == 1` returns that single value unchanged, without sorting.
- The `k + 1 >= n` fall-through returns `sorted[k]` exactly. For the
  three exposed percentiles this branch is unreachable: with `p <= 90`
  and `n >= 2`, `index = 0.9(n-1) < n-1`, so `k <= n-2` always. Only
  `p = 100` could reach it, and nothing asks for `p100`.
- Sorting is numeric and signed, so an all-negative series produces
  all-negative percentiles. The interpolation is a linear combination
  of two observations, so it can never cross zero when every
  observation is on one side of it.

Worked example, the six-value distribution locked in `PercentileTest`
— `[6000, 8500, 13000, 18000, 19500, 22000]`, so `n = 6` and
`n - 1 = 5`:

| percentile | index | k | d | interpolation | result |
| --- | --- | --- | --- | --- | --- |
| P10 | 0.5 | 0 | 0.5 | `6000 + 0.5 × (8500 - 6000)` | 7250 |
| P50 | 2.5 | 2 | 0.5 | `13000 + 0.5 × (18000 - 13000)` | 15500 |
| P90 | 4.5 | 4 | 0.5 | `19500 + 0.5 × (22000 - 19500)` | 20750 |

R-7 was chosen over nearest-rank because nearest-rank collapses onto a
single observation at small `n`, and the tier's own trigger threshold
is `n = 6`. At that size nearest-rank would report the same raw
observation for two adjacent percentiles and the band would visibly
snap between buckets. R-7 keeps a continuous band at six samples.

### Cadence jitter

A percentile-tier series is uncertain in its *amount* — and the same
series is usually uncertain in its *date* too. Real charges land on
the next business day, lag behind a bulk settlement, or drift when the
funding source retries. `CadenceJitter` models that by replacing each
contribution with `2 × jitterDays + 1` replicas on consecutive days,
centred on the original date. `RangeProjector` passes
`jitterDays = 3`, so the window is 7 days wide, spanning `D-3` to
`D+3`.

Each replica is the original scaled by an equal share:

```
window = jitterDays × 2 + 1          = 7
weight = 100 / window                = 14.2857…
replica.field = round(original.field × weight / 100)
```

`weight` is a float, not a rounded integer — a detail worth pinning,
because rounding it to 14 first would lose 2% of every jittered
series' magnitude. For a -1000 minor contribution each replica is
`round(-142.857) = -143`, and the seven together sum to -1001: one
minor unit more outflow than the original, which is the whole of the
rounding error.

Only the percentile tier is jittered. Envelope-tier series have
predictable charge dates — that predictability is why they qualified
for the cheap tier — and smearing them would invent uncertainty they
do not carry.

**The non-obvious part.** Jitter scales `low`, `point` and `high` by
the same factor, so each replica's half-width is also divided by
seven. Since the fold *replaces* rather than accumulates the running
spread (see below), a jittered occurrence leaves a band roughly seven
times narrower than the same occurrence un-jittered. Concretely, for a
contribution of `(-1100, -1000, -900)`:

- un-jittered: one day carries a half-width of 100, and every later
  day inherits it;
- jittered: seven days each carry a half-width of 14, and every later
  day inherits 14.

Jitter therefore widens the projection *in time* — the balance steps
down over a week instead of dropping in one day, which is the honest
rendering of date uncertainty — while narrowing the band's *height*.
It is worth being precise about which of the two is meant when
reasoning about a chart.

**Boundary leakage.** `RangeProjector` only emits occurrences at or
after `asOf`, and the fold only walks `asOf` through
`asOf + horizonDays`. Jitter adds offsets outside both ends. An
occurrence landing exactly on `asOf` produces three replicas dated
before `asOf`, and the fold never visits them — roughly 3/7 of that
occurrence's magnitude is silently dropped. The same happens in
mirror image at the horizon end. This only affects percentile-tier
series with an occurrence within three days of either boundary.

## Stage 2 — contributions become a daily balance curve

Between stages, `ChainAwareForecastRouter` may rewrite a
contribution's `accountId` onto a funder account and synthesise the
next card settlement, and — when a scenario is active —
`ScenarioApplier` transforms the list in memory (see
[Scenario isolation](scenario-isolation.md)). Neither changes the
shape of a contribution's triple, so the arithmetic below is unchanged
by them.

`ProjectionPipeline` then buckets the routed contributions by
`accountId` and hands each account's list to `DailyFold` together with
that account's anchor balance from `BalanceAnchorResolver`.

### Currency conversion happens here and only here

A contribution carries its native currency and the FX rate captured
when the series' latest amount was observed. The fold converts on the
way in:

```
converted = round(minor × fxRateUsed)      when currency != accountCurrency
converted = minor                          when they match
```

A cross-currency contribution with a null `fxRateUsed` raises
`InvalidArgumentException` rather than being folded at face value.
That is the important part: silently treating 599 USD-minor as 599
EUR-minor would leak a wrong number into a real balance and no test
would notice. Failing the whole run is the cheaper outcome.

No other stage converts. The router explicitly does not, which is why
its funder-collapse aggregation assumes everything sharing an
`(account, date)` tuple is already in the funder's currency.

### Per-day aggregation and quadrature

Contributions are bucketed by `Y-m-d`. Within a bucket:

- `point_minor` is the plain signed **sum** of the converted points.
  Money adds linearly; there is nothing clever here.
- `spread_sq` accumulates `halfWidth²`, where
  `halfWidth = |convertedHigh - convertedLow| / 2`.

The day's spread is then `round(sqrt(spread_sq))` — quadrature, not a
sum. Two independent ±10 contributions on one day give
`round(√(10² + 10²)) = round(14.142) = 14`, not 20.

Quadrature is the statistically correct combination **for independent
variables**. Every approved recurring series is treated as
independent. Where that assumption is wrong — two streaming
subscriptions on the same billing cycle, driven by the same card being
declined — the combined spread is under-estimated. The percentile tier
sidesteps the assumption for the series that need it most, by reading
each series' own observed distribution rather than inferring one.

### The running walk

```
running       = anchor opening balance
currentSpread = 0
for each day from asOf through asOf + horizonDays inclusive:
    if the day has a bucket:
        running       += bucket.point_minor
        currentSpread  = round(sqrt(bucket.spread_sq))
    emit { low: running - currentSpread,
           point: running,
           high: running + currentSpread }
```

Three things in that loop are load-bearing and none of them are
obvious:

- **The range is inclusive at both ends**, so a 30-day horizon emits
  **31** points, not 30.
- **`currentSpread` is assigned, not accumulated.** A day with
  contributions replaces the spread with that day's own quadrature.
  The band therefore does not grow monotonically with time. This is
  deliberate: the band represents uncertainty in the current period's
  amount, not uncertainty compounded across the horizon.
- **A day without contributions carries the previous spread forward
  unchanged.** Without that, the chart band would collapse to zero
  width on every quiet day and the rendered region would be a comb of
  spikes rather than a continuous ribbon.

Contributions dated outside `[asOf, asOf + horizonDays]` sit unread in
their bucket and are never emitted — the mechanism behind the jitter
boundary leakage described above.

## Stage 3 — the buffer produces shortfall windows

`ShortfallDetector` walks the emitted points with a two-state machine
and writes a `forecast_shortfall_windows` row per contiguous dip.

**It compares `point_minor`, not `low_minor`.** The shortfall is
driven by the central estimate. A band whose lower edge dips well
below the buffer while its centre stays above produces **no** window
and **no** `ForecastShortfallDetected` event. Whether that is the
right call is a product decision, but it is the current one, and
reading the chart as though the shaded region drives the alert is
wrong.

The buffer is `accounts.forecast_min_buffer_minor` when set, otherwise
`0` — a null buffer means "warn me when I go overdrawn", not "never
warn me". The comparison is strict `point < buffer`, so a balance
resting exactly on the buffer is not a shortfall.

Window boundaries:

- A window opens on the first day below the buffer, and
  `lowest_balance_minor` tracks the minimum seen while it stays open.
- A window closes on the day **before** recovery. The day the balance
  climbs back to or above the buffer is not itself in shortfall, so
  `ends_at` is the previous day.
- A window still open at the end of the horizon closes on the last
  observed day. It is emitted, not discarded — a dip that has not
  recovered by day 90 is the most important one on the chart.

Persistence is delete-then-insert inside a single transaction, scoped
to `(user_id, account_id, scenario_id)`. Every projection run fully
replaces that account's shortfall picture, so a dip that has since
been forecast away leaves no stale row behind. `buffer_used_minor` is
captured per row at detection time: editing the buffer later triggers
a re-projection that writes new rows rather than rewriting the
historical narrative of the old ones.

## Constants and shapes in one place

| Value | Where | Notes |
| --- | --- | --- |
| `[30, 60, 90, 180, 365]` | `ProjectForecastJob::HORIZON_DAYS` | The horizons a run may be dispatched for; the constructor rejects anything else |
| `30` | `ForecastHighlightsQuery::HORIZON_DAYS` | The dashboard tile and sidebar badge read the 30-day run only |
| `40` | `RangeProjector::HIGH_VARIANCE_THRESHOLD_PERCENT` | Minimum declared tolerance to consider the percentile tier |
| `6` | `RangeProjector::MIN_OCCURRENCES_FOR_PERCENTILE` | Minimum observed occurrences to actually use it |
| `3` | `RangeProjector::JITTER_WINDOW_DAYS` | Half-width in days; the replica window is 7 days |
| `0.95` / `1.05` | `ScenarioApplier::ONE_OFF_ENVELOPE_*_MULTIPLIER` | The fixed ±5% band for scenario-added amounts, which have no tolerance field |
| `horizonDays + 1` | `DailyFold` | Points emitted per account per run |

## Integer arithmetic and rounding

Every amount in this pipeline is an integer count of minor units. No
stage holds a monetary value in a float. Floats appear in exactly
three places, all of them intermediate:

- the R-7 interpolation index and fraction,
- the jitter weight,
- `spread_sq` and its square root.

Each is collapsed back with `(int) round(...)` before it touches a
contribution or a point. PHP's `round()` is half-away-from-zero, so
`0.5` becomes `1` and `-0.5` becomes `-1` — not banker's rounding.
That matters for the sign symmetry of an expense band: an expense and
an income of equal magnitude round outward by the same amount rather
than one of them rounding toward zero.

Rounding error is bounded and accepted rather than corrected. The
jitter's seven replicas do not necessarily re-sum to the original
amount (-1000 becomes -1001 across the window). No compensating
adjustment is applied; a single minor unit spread across a seven-day
window is below the resolution of anything the user reads off a chart,
and a correction pass would introduce an ordering dependency between
replicas that a reader would have to keep in their head.

## Related pages

- [`Forecasting` — architecture](architecture.md) — what each class is
  and how the pipeline is wired together.
- [Scenario isolation](scenario-isolation.md) — how what-if mutations
  transform this pipeline without touching the ledger.
- [Forecast fixture corpus](forecast-corpus.md) — the hand-built
  scenarios that pin the numbers above.
- [`Recurring`](../recurring/architecture.md) — where series, their
  cadences, their variance tolerances and their observed occurrences
  come from.
- [`Ledger`](../ledger/architecture.md) — accounts, currencies, the
  transaction substrate the anchor balance is derived from, and the
  `Money` value object that owns minor-unit arithmetic elsewhere.
