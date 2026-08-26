# Drift detection — the threshold ladder and the deterministic evaluator

A subscription price rise never announces itself. The bank line just
gets bigger, and the user finds out months later. Detecting it is
tempting to treat as an anomaly problem — build a statistical model of
"normal" for the series and flag outliers — but that fails on exactly
the data this module has: a recurring series carries a handful of
occurrences, not a distribution, and a single €2 rise on a €9.99 plan
is a permanent step change rather than an outlier. So the evaluator is
deterministic: it compares the last two occurrences of one series
against one number, and that number is chosen by a fixed ladder.

`Modules\DriftAlerts\Internal\DriftEvaluator` is the whole decision.
Everything else in the module reacts to the row it writes.

## What counts as drift

`evaluateForSeries($seriesId, $user)` walks four gates in order, and
returns silently at the first one that fails:

1. **The series is eligible.** It resolves through
   `Recurring`'s `RecurringSeriesQuery::forSeries()` and must be in
   state `approved` or `cadence_changed`. A `pending` series has not
   been confirmed as recurring yet and a `rejected` one was explicitly
   dismissed; alerting on either would be alerting on a guess. A
   `cadence_changed` series still qualifies — its interval flipped, but
   the amount movement is separately actionable.
2. **There are at least two occurrences.** With one occurrence there is
   nothing to compare against, so a first-ever charge never drifts.
3. **The comparison is arithmetically meaningful.** `occurrences[0]` is
   the latest and `occurrences[1]` the prior. A prior of `0` minor units
   (a refunded or waived period) would divide by zero, and an
   `irregular` cadence yields a year multiplier of `0`, so both return
   no alert rather than a fabricated one.
4. **The move clears the effective threshold.** The ratio is
   `abs(delta) * 100 / abs(prior)` — a percentage of the prior amount —
   and the test is `ratio <= threshold` returns nothing. The comparison
   is **strictly greater than**: a move of exactly the threshold percent
   does not fire. A 5.0% change against a 5% threshold is silent; 5.1%
   opens an alert.

The amounts compared are `RecurringOccurrenceDto::$observedAmount` —
the amount in the series's **original** billing currency. The settled
EUR shadow is never read. A USD subscription billed at a steady $11.99
produces zero alerts however far the EUR settlement wanders on FX rate
movement, because the price the user is being charged did not change.

Sign is preserved, not normalised. `delta = latest - prior` on signed
minor units, so an expense that grew reads as a more-negative delta and
an income raise reads as a positive one. The magnitude is taken only
for the ratio test, which is why the ladder is symmetric: a drop past
the threshold opens an alert just as a rise does.

## The threshold ladder

`effectiveThresholdPercent()` is a **precedence chain, not a maximum**.
It returns the first of these that is set, along with a `source` label
that is written onto the alert row:

| Order | Source | Where it comes from | `threshold_source` |
|---|---|---|---|
| 1 | Per-series override | `recurring_series.drift_threshold_percent`, read through `RecurringSeriesQuery::driftThresholdForSeries()`. Any non-null value wins, including one *lower* than the user's global. | `series_override` |
| 2 | User global | `users.drift_alert_threshold_percent`, but only when it is `> 0`. | `global` |
| 3 | Hard default | `DriftEvaluator::DEFAULT_THRESHOLD_PERCENT`, which is `5`. | `default` |

The per-series step exists because volatility is a property of the
series, not of the user. A monthly electricity pre-payment in the
Netherlands swings ±30% with the seasons; against a 5% threshold it
opens an alert nearly every month, and a user who is drowning in noise
stops reading the page at all. Raising the *global* threshold to silence
that one series would also silence a real €2 rise on every subscription
the user has. The override is the escape valve that keeps the global
number honest.

The `default` label is deliberately distinct from `global`. A user who
has never touched the setting and a user who explicitly chose 5% both
end up at 5, but the audit trail can tell them apart, and the renderer
can say "your default" versus "your setting".

Both `threshold_percent_used` and `threshold_source` are stamped onto
the `drift_alerts` row at open time. Changing either setting afterwards
never rewrites an existing alert — the row records the rule that was in
force when it fired.

## Annualising the impact

`annualized_impact_minor` is `delta * cadenceMultiplierForYear(cadence)`,
a plain integer multiplication on minor units:

| Cadence | Multiplier |
|---|---|
| `weekly` | 52 |
| `monthly` | 12 |
| `quarterly` | 4 |
| `yearly` | 1 |
| `irregular` | 0 |

52 is a calendar-year approximation chosen for integer consistency with
the monthly-equivalent multiplier used elsewhere in `Recurring`, not for
calendar accuracy.

`irregular` maps to `0`, which is also the gate that stops the alert in
step 3 above. A series with no discernible interval has no meaningful
yearly impact, and a zero is a value callers can short-circuit on rather
than a number the module made up.

## Writing the alert

`openAlert()` inserts one `drift_alerts` row and dispatches
`DriftAlertOpened`. The currency stored is the series's own
(`$series->latestAmount->currency()`), so a USD-billed subscription
produces a USD-denominated alert.

The insert is wrapped in a `catch (QueryException)` that does nothing.
That is the module's idempotency seam: `drift_alerts` carries
`UNIQUE(recurring_series_id, latest_occurrence_id)`, so re-running the
evaluator for a `(series, occurrence)` pair that already produced an
alert collides and is swallowed. Crucially the event dispatch sits
*inside* the `try`, after the insert, so a collision also suppresses the
notification — a re-detect sweep cannot re-notify the user about an
alert they have already seen. This is what makes `DetectDriftAlertsJob`
safe to retry.

Each drift *event* gets its own row. A series that steps €9.99 → €10.99
→ €11.99 produces two alerts, not one running total, because the audit
trail should record each rise. The `/drift` page collapses the visual
noise by grouping on series
(`DriftAlertQuery::groupedBySeriesForUser()`), which is a presentation
decision rather than a detection one.

## Reading drift back

Two read surfaces apply their own scoping on top of the stored rows.

**`DriftAlertQuery::openAnnualizedImpactByCurrencyForUser()`** SUMs
`annualized_impact_minor` across open alerts filtered to
`direction = 'expense'`. Income alerts are excluded on purpose: the
dashboard headline is "what your subscriptions are costing you extra
per year", and folding a salary raise's positive delta into it would net
a real cost increase away against unrelated good news under a single
up-arrow tile. It returns one total per currency rather than one
number: `annualized_impact_minor` is denominated in the series' own
currency, so a single sum across them would add euro cents to dollar
cents.

**`SubscriptionDriftWatchQuery`** answers a different question —
"how much has this subscription risen since I first paid it" — and so
reads the **entire** occurrence history rather than the 24-point window
the series-detail chart uses. `FULL_HISTORY_POINTS = 600` is the ceiling
it passes to `RecurringSeriesQuery::amountTrendForSeries()`: 600 monthly
occurrences is 50 years and 600 weekly ones is 11, which covers any
subscription lifetime that can exist in real data while still bounding
the query. Truncating to 24 would silently redefine "baseline" as
"the price two years ago" for any long-lived series.

That query is also expense-only, and compares **absolute** amounts:
expense occurrences are stored negative, so taking the magnitude makes a
more-expensive charge read as a positive, upward drift in the row and in
the sparkline, which is the direction a reader expects.

## See also

- [`architecture.md`](architecture.md) — the module map, the job and
  event wiring, and the write-action security contracts.
- [`snooze-lifecycle.md`](snooze-lifecycle.md) — what happens to an
  alert after it opens.
- [`how-to-test.md`](how-to-test.md) — the fixture corpus under
  `Modules/DriftAlerts/tests/fixtures/drift-corpus/`, which encodes one
  scenario per gate described above.
- [`../recurring/architecture.md`](../recurring/architecture.md) — the
  owner of series state, cadence, and the per-series override column.
