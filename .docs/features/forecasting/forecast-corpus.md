# The forecast fixture corpus

`Modules/Forecasting/tests/fixtures/forecast-corpus/` holds eleven
hand-built financial situations. Each is a plain PHP file returning an
array of synthetic accounts, synthetic approved recurring series, and
the projection the pipeline is expected to produce from them.

Together they are the module's worked-example set: a fixed, readable
description of what the projection maths is supposed to do in each of
the shapes a real user's finances can take. A change to the tier gate,
the jitter window, the quadrature formula or the shortfall state
machine should be visible as a change to these numbers — and if it is
not, the change was not understood.

## Why a corpus rather than more unit tests

The unit suites around
[the projection math](projection-math.md) each pin one class in
isolation: `PercentileTest` pins the interpolation, `DailyFoldTest`
pins the quadrature, `ShortfallDetectorTest` pins the window state
machine. Every one of them passes on a pipeline that is individually
correct and collectively wrong, because none of them ever composes the
stages.

The failure they cannot catch is the interaction. A series is
envelope-tier or percentile-tier depending on two thresholds; a
percentile-tier series is jittered and an envelope-tier one is not;
jitter changes the per-day half-width, which changes the quadrature,
which changes the band, which is what the shortfall detector's buffer
is compared against. Reasoning about that chain in the abstract is
where mistakes live. A fixture states the whole chain as a concrete
situation with a concrete answer, in a file a person can read.

The second reason is that the fixtures name the situations worth
worrying about at all. "A subscription in a foreign currency", "a
credit card that settles in bulk on the funder account", "a series
that was approved yesterday and has no history" — the list itself is
the design knowledge. It is harder to reconstruct than any individual
number in it.

## Shape

Every fixture returns an array with three required top-level keys, and
`FixtureCorpusTest` fails the build if any of them is missing or
malformed:

- **`accounts`** — a non-empty list of synthetic account rows. Each
  needs `id`, `user_id`, `name`, `kind` and `default_currency`;
  `kind` must be one of `bank`, `ics_card` or `paypal`. Optional
  `opening_balance_minor` and `forecast_min_buffer_minor` supply the
  anchor and the shortfall floor.
- **`series`** — a list of synthetic approved recurring-series rows.
  Each needs `id`, `user_id`, `name`, `cadence`, `direction`,
  `account_id`, `latest_amount_minor`, `latest_currency`,
  `variance_tolerance_percent`, `state` and `next_expected_date`. An
  optional `occurrences` list supplies the observed history the
  percentile tier reads.
- **`expected`** — an associative array with at least `projection` and
  `shortfalls`. Projection entries carry `horizon_days` (30, 60 or
  90), `account_id`, `date`, `low_minor`, `point_minor`, `high_minor`
  and `currency`; shortfall entries carry `account_id`, `starts_at`,
  `ends_at`, `lowest_balance_minor`, `currency` and
  `buffer_used_minor`.

Three fixtures add a fourth key: `ics-settlement-chain` declares
`chain_state`, `scenario-with-each-mutation-kind` declares
`expected.scenarios`, and `booked-future-row` declares `booked_rows` —
a list of `account_id`, `counterparty`, `direction`, `date`,
`settled_amount_minor` and `settled_currency`, seeded as real
`transactions` rows with no occurrence link behind them.

### The clock every fixture is read against

Every fixture is anchored to the same notional "today" of 1 May 2026,
so dates can be compared across files. `ForecastCorpus::TODAY` is the
single place that date is written down; the shape test and the
end-to-end contract test both read it from there rather than repeating
a literal.

That anchor carries a corpus-wide invariant, and it is the one thing
to hold on to when editing any fixture in this directory:

> **Every occurrence is dated strictly before the clock. A row dated
> on or after it is a booked row, not an occurrence.**

An occurrence is *observed history* — a charge the ledger has already
seen. A transaction dated after "today" is a *booked future row* — a
movement the bank has scheduled but not yet posted. No real ledger
observes a payment that has not happened, so no row can honestly be
both, and the pipeline treats the two completely differently:
`RangeProjector` turns a series' history into a banded estimate, while
`BookedRowProjector` turns a booked row into a certainty with no band
at all and drops the estimate it supersedes.

The distinction used to be silently violated. Nine fixtures listed
their `next_expected_date` a second time inside `occurrences`, which
seeded a transaction dated ahead of the clock; once
`BookedRowProjector` existed those rows were read as certainties and
the corpus's bands collapsed to `low = point = high`. Only
`salary-and-side-income` had a band wide enough to breach the test's
tolerance and fail — the rest passed while testing nothing they
claimed to. `FixtureCorpusTest` now asserts the invariant in both
directions, so the same drift cannot return unannounced.

## What the corpus is checked against

Two tests read these files, and they check different things.

`FixtureCorpusTest` asserts the **shape**: that there are exactly
eleven files, that every required key is present, that enumerated
values are in range, that `low_minor <= point_minor <= high_minor`
holds on every projection entry, and that the clock invariant above
holds on every occurrence and every booked row.

`tests/Contracts/ForecastingProjectionContractTest.php` asserts the
**numbers**. It seeds each fixture into a real database, freezes the
clock to `ForecastCorpus::TODAY`, dispatches a real
`ProjectForecastJob` at 30, 60 and 90 days, and compares every
`expected.projection` entry against what the pipeline actually
emitted. Every triple in this directory is now derived from the
arithmetic in [projection math](projection-math.md) rather than
approximated, so the comparison runs at a tolerance of 5 minor units —
wide enough only for `DailyFold`'s integer FX rounding. It covers
every fixture except `ics-settlement-chain`, whose `chain_state` block
the harness does not seed; that fixture's numbers remain documentation
of intent.

A band that quietly collapsed to its own point estimate used to sit
unnoticed inside a thousands-wide allowance. At 5 it cannot.

## The eleven fixtures

### `stable-monthly-subscription`

One bank account (€1,500 opening) and one €11.99/month Netflix series
with a 5% variance tolerance and six stable observed charges.

The envelope-tier happy path, and the fixture that pins the tier gate
from the wrong side: six occurrences clears the occurrence bar, but a
5% tolerance is nowhere near the 40% bar, so the series stays on the
envelope tier and is never jittered. A gate accidentally rewritten as
`OR` instead of `AND` shows up here first. The expected band is a
tight ±€0.60 around the single in-window occurrence.

### `variable-utility`

One bank account and one Electricity series at €140/month with a 45%
tolerance and eight observed charges spanning €60 to €220.

The only percentile-tier fixture. It clears both bars, so the declared
tolerance is discarded, the band comes from the observed distribution,
and the contribution is jittered across a seven-day window. It is the
fixture that demonstrates the point of the whole tier: an envelope
drawn from the stated tolerance would be a smooth ±45%, while the real
history is lumpy and skewed toward the winter end.

### `zero-occurrence-edge-case`

One bank account and a just-approved series with
`latest_amount_minor` set and no observed occurrences at all.

The division-by-nothing guard. `Percentile` raises on an empty list by
design, so the value of this fixture is proving the pipeline never
asks it: the series carries a 10% tolerance, the 40% bar fails first,
and the occurrence lookup is never even performed. A future change
that reordered the gate to check occurrence count first would turn
this fixture from calm into a thrown exception.

### `drifting-subscription-midwindow`

One bank account and a Spotify series whose `latest_amount_minor` of
-1149 reflects a roughly 15% drift up from a €9.99 baseline, with the
tolerance still recorded as 5%.

Pins that the projection follows the *latest* amount rather than any
historical or nominal one, and that a drifted amount does not silently
widen the band — the tolerance is a separate field and stays at 5%.
The drift alert this situation would raise belongs to
[`DriftAlerts`](../drift-alerts/architecture.md) and is deliberately
not asserted here.

### `fx-only-usd-subscription`

One EUR bank account and a Netflix US series priced at $11.99 with a
stored FX rate of 0.9050.

The currency-boundary fixture. The contribution stays in USD all the
way through projection and routing; only `DailyFold` converts, using
the rate captured with the series. The account chart is EUR
throughout, the band reflects EUR-converted amounts, and the
per-series legend still shows the native price. It is also the fixture
that would catch a regression dropping `latest_fx_rate_used`, since a
cross-currency contribution with a null rate raises rather than
folding at face value.

### `salary-and-side-income`

One bank account with two income series — €3,500/month salary and
€450/month side income.

Sign coverage. Almost every other fixture is expense-dominated, so
this is the one that would notice an income series folded as an
outflow: 25 May lands on €5,450 with a band of ±€175, and a sign
dropped anywhere between `RangeProjector` and `DailyFold` sends the
curve down instead of up by thousands.

Be precise about the half of the sign rule it does *not* reach. The
envelope formula is asymmetric by construction — for income the larger
magnitude belongs in `highMinor`, for an expense it belongs in
`lowMinor` — but `DailyFold` keeps only `|high - low| / 2`, so a
transposition of the two leaves the balance curve numerically
identical. `RangeProjectorTest` pins that ordering directly on the
contribution, which is the only place it is still visible. What this
fixture guarantees is that there is a band here at all: while its
occurrences were mis-dated the band collapsed to `low = point = high`,
and a collapsed band has nothing left to get wrong.

### `buffer-crossing`

One bank account with a €600 opening balance, a €500 buffer, and three
monthly expense series totalling €200/month.

The only fixture that produces a shortfall, and the reason it exists
is that the shortfall semantics are entirely about boundaries. The
balance drops below the €500 buffer on 7 May when the largest expense
lands, and there is no income series to lift it back — it goes on
falling, so exactly one window is expected and it never closes on a
recovery. That is the third of `ShortfallDetector`'s boundary rules:
a window still open at the end of the horizon closes on the last
observed day rather than being discarded, because a dip that has not
recovered by the end of the chart is the most important one on it. The
declared window is the 30-day one — `starts_at` 7 May, `ends_at` 31
May, `lowest_balance_minor` €400 — and the 60- and 90-day runs extend
the same window further down. `buffer_used_minor` is captured as 50000
in the row, which is what makes a later buffer edit unable to rewrite
history.

### `ics-settlement-chain`

Two accounts — a bank account and an ICS card — plus a `chain_state`
block declaring a €225 settlement due on 29 May, drawn from account 2
and funded by account 1.

The chain-routing fixture. The expectation encodes the behaviour that
surprises people: the card account's own view is unchanged by the
settlement, while the *funder* account's projected balance dips by the
settlement amount on the due date. It also exercises the scoped
de-duplication — a contribution chain-routed onto the funder that
collides with the synthesised settlement is dropped, while an
unrelated series landing on the same day is not.

### `multi-account-baseline`

Three accounts (bank, ICS card, PayPal) and six series spread across
all three, mixing income and expense and tolerances from 3% to 10%.

The read-path fixture. Its job is to prove that per-account bucketing
holds when accounts and series are interleaved: each account's fold
sees only its own contributions, each carries its own anchor and its
own currency, and the PayPal account's tiny €50 opening balance does
not borrow headroom from the €2,000 bank account. It also covers the
ICS card's zero-anchor fallback, since account 2 has neither a
statement nor a user-entered opening balance.

### `booked-future-row`

One bank account (€3,000 opening) and one €800/month rent series with
a 10% tolerance, plus a `booked_rows` entry: a real transaction on
account 1 dated 5 May for €805, which is the day the series' next
occurrence was expected.

The booked-row fixture, and the only one whose expected band is
deliberately zero-width. A row the bank has already scheduled is not
an estimate of a charge, it is the charge, so `BookedRowProjector`
emits it with `low = point = high` and drops the series contribution
it supersedes. Both halves of that are pinned here, and the €5
difference between the booked amount and the series' latest is what
makes them legible: 5 May folds to exactly €2,195, where the estimate
alone would give €2,200 ± €80 and counting both would give €1,315.

Two details of the situation are the point of it. The booked row
carries **no occurrence link** — the detection sweep has not reached a
row imported since it last ran, which is precisely the state a
future-dated row is in — so `RecurringSeriesQuery` has to resolve it
back to its series on cluster identity alone. And supersession is
one-to-one: the booked row retires the 5 May estimate and nothing
else, so June's unbooked occurrence survives as a normal banded
estimate at €1,395 ± €80. On a monthly series the next occurrence is a
month clear of the booked date and could never have collided; on a
weekly one the pairing rule is what stops a single booked row from
retiring two estimates.

### `scenario-with-each-mutation-kind`

The `multi-account-baseline` situation, plus one saved scenario
containing five mutations — one of every kind: `cancel_series` on
Adobe, `add_one_off` for a €200 car repair, `add_recurring` for a €15
gym membership, `change_series_amount` on the PayPal donation, and
`shift_series_date` moving rent to the 10th with `scope: next`.

The reference input for
[scenario isolation](scenario-isolation.md). It is the only fixture
carrying an `expected.scenarios` block, and having all five kinds in
one scenario is deliberate: it is the case where the applier's
ordering semantics matter, since each mutation rebuilds the whole
contribution list from the output of the previous one.

## Related pages

- [Projection math](projection-math.md) — the arithmetic these
  fixtures are worked examples of.
- [Scenario isolation](scenario-isolation.md) — the boundary the
  five-mutation reference fixture exercises.
- [`Forecasting` — architecture](architecture.md) — the module's
  wiring and read surfaces.
- [How to test `Forecasting`](how-to-test.md) — running the suites
  that consume these files.
