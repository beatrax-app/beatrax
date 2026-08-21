# `Recurring` — the detection fixture corpus

The recurring detector is a pile of tuned numbers: a variance tolerance,
four cadence bands, a missed-interval multiplier, a minimum occurrence
count. Every one of them is a trade-off between missing a real
subscription and inventing a fake one, and none of them can be checked
by reasoning about the code — the only way to know that ±25% is right is
to run it against data where you already know the answer.

That is what the corpus is: a fixed set of hand-built transaction
histories under `Modules/Recurring/tests/fixtures/`, each with the
outcome it is supposed to produce written down beside it. It exists so
that a change to a tolerance cannot silently reclassify anything. Widen
the variance band and the utility-bills case starts producing a series;
narrow the monthly band and the drifting-price case fragments. The
corpus turns "this constant felt right" into a test that fails.

## Shape

Each fixture file returns two keys:

```php
return [
    'transactions' => [ /* list of rows */ ],
    'expected' => [ 'series_count' => 1, 'series' => [ /* ... */ ] ],
];
```

Every transaction row carries the canonical key set the detector reads:
`account_id`, `type`, `posted_at`, `booked_at`, `amount_minor`,
`currency`, `original_amount_minor`, `original_currency`,
`counterparty_normalized`, `counterparty_iban`.

Note the currency convention, which is the one genuinely confusing part.
In a fixture, `original_amount_minor` / `original_currency` is the
**native** pair and `amount_minor` / `currency` is the **settled** pair.
The seeding helpers in the detector tests swap them onto the
`transactions` schema, where `amount_minor` / `currency` is the native
pair and `settled_amount_minor` / `settled_currency` is the settled one.
Reading a fixture as if its `amount_minor` were the amount the detector
clusters on will mislead you on every mixed-currency case.

`Wave0FixtureCorpusTest` validates that structure — the file count, the
two top-level keys, and the key set on every row — so a malformed
fixture fails on its own rather than as a confusing detector assertion.

## The synthesised cases

### Positive cases: one series, correctly classified

**`stable-monthly-spotify`** — 18 identical €9.99 charges on the same
day of month. The baseline: gaps alternate 28/30/31 because months are
not equal, which is the first reason exact-interval matching would not
work. Expects one monthly expense series.

**`drifting-monthly-spotify`** — the same 18 months, but the price rises
from €9.99 to €11.49 partway through. A 15% step, inside the default
±25% band, so the median-based filter keeps every row and the series
stays whole with the post-rise amount as its latest. This is the case
that says a subscription is allowed to change price without becoming a
different subscription.

**`quarterly-insurance`** — 6 charges of €89.99 spaced exactly 90 days.
Lands mid-band for quarterly (80–100 days); monthly equivalent is the
amount divided by three.

**`weekly-streaming`** — 12 charges of €2.49 spaced exactly 7 days.
Lands in the weekly band (under 10 days) and pins the `× 52 / 12`
monthly-equivalent conversion at roughly €10.79.

**`yearly-domain`** — exactly 2 charges of €12.00, 365 days apart. This
is the minimum-occurrences floor at its most consequential: a yearly
subscription cannot be caught after one cycle at all, and only becomes
detectable at two — with a detection window wide enough to hold both.

**`missing-month-subscription`** — a €14.99 monthly newsletter with a
92-day hole where two consecutive months were skipped. That gap is
nearly three times the median, well past the 1.8× threshold, so it is
counted as a missed period rather than being taken as evidence of a
quarterly rhythm. One missed period in nine intervals is under the
"more than 2 in any 6" cap, so the series stays whole and monthly.

**`mixed-currency-netflix-usd`** — 12 monthly charges of $11.99 whose
settled EUR amount wanders between €10.79 and €11.39 as the rate moves.
Clustering happens on the native currency, so this is one USD series;
clustering on the settled amount would have produced FX noise instead of
a subscription.

**`monthly-salary`** — 12 monthly incomes of €3500 from one employer
IBAN. Above the €2000 income floor, clustered on the IBAN rather than
the description.

**`two-employer-salary`** — two employers paying €2200 each on the same
day, interleaved, with distinct IBANs. Expects two separate income
series. This is the case that says the counterparty identity, not the
display name, decides what is one series.

### Negative cases: the detector must produce nothing

These matter more than the positive ones. A detector that suggests too
much trains the user to dismiss the review queue without reading it.

**`irregular-gym-must-not-cluster`** — 5 charges of wildly different
amounts (€15.00, €32.00, €58.00, €21.00, €44.00) at gaps of 5, 40, 70
and 120 days. No pattern in either dimension, and the expense detector
rejects it in the first dimension it checks: the amounts scatter so far
around the €32.00 median that only one row survives the ±25% band,
which drops the cluster below the minimum of two before cadence
inference ever runs. The intervals would have failed too — their median
of 55 days sits in the gap between the monthly and quarterly bands — and
that second line of defence is the reason the cadence snaps on the
unfiltered median (see [series detection](series-detection.md)).

**`variable-amount-beyond-tolerance-bills`** — 6 monthly utility bills
alternating roughly €40 and €135. Perfectly regular in time, so the
cadence side would happily call this monthly; it is the variance filter
that stops it. The median of €87.50 sits in the empty middle, and the
±25% band around it contains none of the actual amounts, so the cluster
empties out and no series is produced.

This case draws the honest boundary of the feature. A variable utility
bill genuinely *is* a recurring commitment, and the detector does not
find it. The user can widen that series' tolerance to 50% by hand, which
the next sweep then honours — but the default refuses to guess, because
the alternative is a "series" whose amount means nothing.

## The real-data fixture

`tests/fixtures/real/anonymised-asn-ics-6mo.php` is a placeholder. It is
intended to hold a six-month anonymised export from a real ASN current
account and an ICS credit-card statement, and currently returns an empty
transaction list so the file lookup stays deterministic and the scaffold
runs. The work it is waiting on is the anonymisation itself: scrubbing
personal data out of a real export while keeping counterparty IBANs
stable as deterministic tokens, since randomising them would destroy the
exact clustering property the fixture would exist to test.

## What is and is not exercised today

Not every fixture is wired into a detector assertion. The detector
feature tests currently drive `stable-monthly-spotify`,
`drifting-monthly-spotify`, `variable-amount-beyond-tolerance-bills`,
`irregular-gym-must-not-cluster`, `mixed-currency-netflix-usd`,
`monthly-salary` and `two-employer-salary`. The remaining synthesised
fixtures — `quarterly-insurance`, `weekly-streaming`, `yearly-domain`
and `missing-month-subscription` — are validated structurally by
`Wave0FixtureCorpusTest` but their `expected` blocks are not yet
asserted against a detector run. Their `expected` values were computed
by hand from the bands, so they are a specification of intent rather
than a passing assertion.

Detector tests widen the user's detection window before seeding, because
the two-month default would clip almost every fixture down to its last
occurrence.

## Related pages

- [How a series is detected](series-detection.md) — the algorithm and
  every tolerance these fixtures pin.
- [Detection under encryption](detection-encryption-posture.md) — the
  encrypted-IBAN clustering cases, which are built inline rather than as
  corpus fixtures because they need live ciphertext.
- [How to test `Recurring`](how-to-test.md) — running the suites.
