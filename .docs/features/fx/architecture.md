# `FX` — architecture

The `FX` module owns base-currency conversion: turning a `Money` value in
any currency into the user's reporting currency, backed by a
priority-ordered chain of rate providers with a cache-backed circuit
breaker, and stored on the `exchange_rates` table so historical figures
convert at the rate that was in effect on their date — meaning the
newest rate published on or before that date, not one keyed to it.

## Provider chain and circuit breaker

`RateProviderRegistry` holds every bound `RateProvider` sorted by
`priority()` descending and tries each in turn until one succeeds:

- `EcbRateProvider` (priority 200) — the ECB daily reference XML feed,
  tried first.
- `FrankfurterRateProvider` (priority 100) — a second live-rate fallback.
- `BundledSnapshotProvider` (priority 0) — the offline snapshot shipped
  with the app (`Modules/FX/Resources/rates-snapshot.json`), always
  available and never making a network request.

Each provider's failure count is persisted in the Laravel cache under
`fx.circuit.{key}.failures`. A provider with three or more cached
failures is skipped (circuit open) for six hours; the first failure in a
window anchors the six-hour TTL, and subsequent failures within that
window increment the counter without resetting the TTL — otherwise a
provider that fails more often than once per six hours would slide its
window forever and the circuit would never auto-heal once the outage
ends. A success resets the counter.

The key is provider-global while the jobs that write it are per user, so the
create is an atomic `add()` rather than a read followed by a write: two
readers' refresh jobs failing the same provider both saw no counter and both
wrote a 1, which lost one of the three failures and left the circuit open a
failure late. `add()` decides the create once; whichever caller it answers
`false` increments instead. The desktop's cache store is the file one and the
phone's is the database one, and `add()` is atomic in both — `flock` on one
side, an `insertOrIgnore` against the primary key on the other.

### The step from one to two is still raced on the desktop, deliberately

`add()` closes the create. The increments after it are not equally safe, and
that is an accepted cost rather than an oversight.

`CACHE_STORE` is `file` on the desktop and `database` on the phone (the
`.env.example` each build copies). `DatabaseStore::increment()` wraps its read
and write in a transaction with `lockForUpdate()`, so the phone is safe
throughout. `FileStore::increment()` is a plain `getPayload()` followed by
`put()` with no lock at all, so on the desktop two jobs failing the same
provider in the same instant can both read 1 and both write 2. The circuit then
opens one step late.

What that costs is one extra request to a provider that is already failing, and
it is self-correcting: the next failure lands on the higher count, and a success
clears the counter outright. Set against that, both ways of closing it are worse
or wider than the defect:

- **Take a lock around `recordFailure()`.** `LockStore::lockProvider()` is the
  repo's cross-process primitive, but `->get()` returning false would *drop* a
  failure — the very thing this rule exists to count — and `->block()` throws
  `LockTimeoutException`, which would escape the `catch (RateFetchException)`
  in the provider loop and abort the whole chain, turning a slow feed into a
  failed refresh.
- **Move the counter to the `database` store.** Correct, and it relocates FX
  circuit state for every install to make one step of one counter atomic on one
  platform. That is a change to where this module keeps its state, and it
  deserves to be decided as one rather than arriving as a side effect.

So the circuit is exactly-once on its first failure and best-effort on the two
after it. Anyone revisiting this should know the race is known and priced, not
missed. When every provider in the chain
fails or is circuit-open, `RateProviderRegistry::fetchCurrentRates()`
throws `AllProvidersFailed`. `FetchFxRatesJob` catches it, records the
attempt through `FxRefreshStatus` and rethrows so the retry profile still
runs; conversion itself is unaffected, because it reads the table rather
than the feed and the rows already there stay in use.

`BundledSnapshotProvider` and `FrankfurterRateProvider` both treat an
HTTP-200-but-empty rate set as a failure (throwing `RateFetchException`)
rather than a success — otherwise the registry would reset the circuit
and stop there instead of falling through to the next provider in the
chain.

## Fetch job: concurrency, idempotency, and date-keying

`FetchFxRatesJob` is the single point where outbound rate HTTP actually
happens. Its concurrency contract mirrors `ProjectForecastJob`:
`ShouldBeUniqueUntilProcessing` keyed on `uniqueId() = "{userId}"`
collapses a concurrent scheduled-tick and on-demand refresh trigger pair
into one queued job per user, and `tries = 3` with backoff `[60, 300,
900]` absorbs a single transient provider failure without
final-failing the run.

Before fetching, the job re-checks the user's `fx_online_enabled` flag
directly against the database — this is a defense-in-depth privacy gate:
online fetch is opt-in and off by default, and re-checking here (rather
than trusting the caller) means no dispatch path — the scheduler, the
on-demand "Refresh now" action, or any future caller — can leak network
calls for a user who never enabled online fetch. The UI gate alone is
not a security boundary.

Rows are upserted on the unique index (`base_currency`,
`quote_currency`, `rate_date`, `source`) so re-running the job never
duplicates rows. The date keyed in `exchange_rates` is the date from the
provider's feed response, never `now()`: on weekends and ECB public
holidays the feed publishes the previous business day's date, so keying
on `now()` would produce a false "today" row. Rate values are validated
against a plausible range (0.00001–100000) before upsert; values outside
that range are logged and skipped rather than written.

### When the refresh comes back with nothing

`FxRefreshStatus` holds the last refresh attempt that produced no rows,
per user, so a screen waiting on one can say what happened instead of
timing out and guessing. Settings polls for a write to `exchange_rates`
and gives up after fifteen polls; the retry backoff runs to twenty
minutes, so without a record the reader watched a spinner die and was
told nothing about why.

Two things write it. `handle()` records `AllProvidersFailed` on the
attempt that raised it and rethrows, which is what puts the reason in
front of the reader inside the poll window rather than after the last
retry. `failed()` records the exhausted run, mapping anything that is not
`AllProvidersFailed` to `FxRefreshFailureReason::Unexpected` — Laravel
calls it as a bare `$command->failed($e)` with no container resolution,
so it resolves its collaborators from the container itself. A feed that
answered with rates the range guard threw all away writes no row either,
and records `NoUsableRates` rather than returning in silence.

The record is cleared by the next successful upsert, and by the gate that
returns early for a user who has turned online fetch off — with no
outbound fetch there is no failure to report. It lives in the cache
beside the provider circuit breaker rather than in a table: which rates a
device could reach is that device's own state and must not travel on the
op log.

## Conversion: passthrough, staleness, and cross-rates

`ExchangeRateService` is the single cross-module entry point for
currency conversion, exposing two methods:

- `convertToBase()` — for current-snapshot figures, uses the newest
  `rate_date` each pair has.
- `convertAtDate()` — for historical figures, uses the newest `rate_date`
  each pair has **on or before** the requested date.

`convertAtDate()` resolves that date per pair, not once for the whole
table: a pair last quoted a week before the date still answers, where a
single table-wide cut-off would have dropped it. An exact-date match was
what it used to do, and because ECB publishes on business days only,
every weekend date, every holiday and every date older than the first
stored row found no rows at all — the amount fell through unconverted
and the account vanished from that point of the series. The dashboard
card and `/reports?metric=net_worth` then disagreed about net worth by
four figures on the same install.

Where a pair has no row on or before the date, the lookup falls
**forward** to the oldest row that pair does have. A rate published after
a date is not the rate that was in effect on it, and the result says so —
`asOf` carries the row's real date, which is what a disclosure line
renders. It is chosen anyway because the alternative is the defect above:
silently dropping the whole account out of every early bucket, which
understates net worth without ever saying it did. A bundled snapshot
ships dated `2026-06-05`, so on a fresh install every figure older than
that takes this path.

The rows for one date are memoised for the life of the resolved service.
A net-worth series asks for the same bucket date once per account
currency line, and which row a date resolves to cannot change while a
single render is in flight.

Both methods short-circuit to `ConversionResult::passthrough()` when the
figure's currency already equals the target currency — no DB query
fires, and the result carries no rate metadata so the Blade disclosure
affordance skips rendering entirely. This is the zero-cost path every
already-base-currency figure takes.

A conversion that found no usable rate returns `ConversionResult::noRate()`
instead, which is a **different** `ConversionOutcome` from a passthrough.
Both leave the amount in its original currency, and reporting the failure
as "already in the base currency" is how a caller ends up adding foreign
minor units into a base-currency total. `isPassthrough` is derived from
the outcome rather than passed in, so the flag and the outcome cannot
disagree.

There is no per-transaction rate path. `transactions.fx_rate_used` is a
display artifact — the detail screen's "Effective rate" line — and a
balance as of a date is a sum of many transactions carrying many
different stored rates, so there is nothing a caller could hand in. It
reads **settled currency per one native unit**, which is what the detail
screen draws it as (`€0.924 / USD`), and it is a magnitude: the value is
`Rate::between(settled, native)` over a pair whose two legs
`Ledger::TransactionAmount` has already given one shared sign, so a
negative rate is not a value this column can hold. The
`$knownRate` argument that claimed precedence over the dated row was
never passed by any caller, and it registered whatever it was given as an
uninverted direct pair on the caller's word.

Conversion uses Brick Money's `BaseCurrencyProvider` with EUR as the
base, so a cross-rate such as USD→GBP is derived exactly from two
EUR-based pairs rather than requiring a direct USD/GBP row. The as-of
date, source, and staleness of a multi-leg conversion reflect the
*oldest* rate leg actually involved, not the globally newest row in the
table — otherwise a stale non-EUR leg could be mis-reported as fresh
just because some other pair refreshed recently. The staleness threshold
is three calendar days, calibrated to the ECB weekend gap: Friday to
Monday is three days, so Monday morning still shows non-stale rates
fetched on Friday.

All DECIMAL reads from PDO are cast to `(string)` before being handed to
brick/money — rate values are never represented as float anywhere in
this module, since floating-point representation silently corrupts FX
conversion precision.

### Which row wins, and how it is found

Both reads answer the same shape: one row per currency pair, for the day
in effect. `fetchLatestRates()` takes each pair's newest row;
`fetchRatesForDate()` takes the newest on or before the day asked for and
falls forward to the oldest held when there is nothing before it. Each
resolves that date in one grouped pass over
`exchange_rates_latest_lookup` and joins it back, rather than re-running
the aggregate once per row of the table — which is what they used to do,
and which priced a read that returns twenty rows at the size of the whole
rate history ([reads bounded by the user](../../architecture/reads-bounded-by-the-user.md)).

The rows come back **ordered**, and the order decides two things rather
than one. `convertWithRows()` folds them into a `RateTable` last-write-
wins per pair and fills its metadata map the same way, so the order picks
both the rate the conversion uses and the source and as-of date the
figure reports about itself. The unique index is keyed by
(`base_currency`, `quote_currency`, `rate_date`, `source`), so one pair
on one day can hold a row per provider — the registry falls through to
the next provider when one fails, and two runs on one day can each leave
a row. Bundled-last is therefore not enough to settle it, and the second
key is the row's own `id`: among live providers the most recently written
row wins, which is the freshest thing the device knows. The snapshot
never wins that comparison however late it was written, because the
source class is compared first.

## Roll-ups that span currencies

`CrossCurrencyTotal` is the one collaborator every roll-up that adds
figures from more than one currency goes through. A Revolut import
carries a currency per row, so a single account — and therefore a single
counterparty, tax year, recurring series or drift watchlist — can hold
euro and dollar side by side. Adding those minor units is the arithmetic
`AccountBalance` deliberately has no `total()` to prevent, and every
surface that did it printed the sum under one symbol.

The collaborator takes buckets keyed by currency, converts each at its
own rate, and returns a `ConvertedTotal` carrying the figure, the
currency it is denominated in, the rates that brought the other
currencies into it, and the codes it could not reach. A currency with no
rate is **left out and named**, never added at one to one — the rule
`NetWorthQuery` already applies to a balance line it has no rate for.
`ConvertedTotal::isPartial()` is what a renderer gates the "not
converted" line on, so a reader can tell a partial total from a whole
one.

`ratesTo()` fetches one rate per currency for the whole render rather
than one per bucket: `convertToBase()` reads the entire `exchange_rates`
table on every call, and a twelve-month sparkline across a few hundred
counterparties would otherwise ask for the same pair thousands of times.
The rate is probed with a zero amount, because the rate a zero converts
at is the rate any amount converts at. Callers holding many buckets call
`ratesTo()` once and then `withRates()` per bucket group; callers with a
single group call `of()`, which does both.

### A converted figure carries the rate that made it

`ratesTo()` answers a `RateSet`, not a map of decimals. It used to
answer `array<string, string>` — code to rate — and that array is where
every surface's disclosure went. The `ConversionResult` behind each
entry already held the source, the as-of date and the staleness;
`ratesTo()` read `->rate` off it and dropped the rest, one call before
the surface that would have to render them. Thirty-one renderings
across twenty-two templates drew the "not converted" half and **none**
drew the rate, because none of them had it. Seven converting surfaces
drew neither.

The set carries a `RateUsed` per source currency — `from`, `to`, the
exact decimal `rate`, `source`, `asOf`, `isStale` — and `withRates()`
narrows it with `only()` to the buckets the figure was actually built
from, so six cards sharing one batched lookup do not each disclose the
other five's pairs.

`ConvertedTotal::disclosure()` projects that into a
`ConversionDisclosure`: the rates plus the codes no rate reached, with
the **oldest** leg answering for the whole — the same rule a multi-leg
conversion follows, for the same reason. `RateSet` is required by the
`ConvertedTotal` constructor rather than defaulted, so "I converted, and
I will not say at what" is not a state the type can hold.
`NetWorthSeriesPoint` takes it the same way, for the same reason.

The oldest leg answers for ONE figure's own legs and no further. A series of
figures is a series of conversions, not one conversion with more legs: folding
the oldest rate in a sixty-bucket net-worth series onto its headline quotes a
rate that cannot rebuild the figure printed above it, which is the same defect
as quoting a display-rounded one. Every figure discloses the set it was built
from, and a figure built from nothing — a passthrough — discloses nothing.
`RateSet::withConversion()` is the one place a `ConversionResult` becomes a
leg, and it is what drops a passthrough: every roll-up was unpacking that
check by hand.

**There are three states, not two.** Rates, or no rates because nothing was
converted — and `ConversionDisclosure::unrecorded()`, for a figure rebuilt from
a stored result whose format kept no rates. The second renders as silence, and
silence on this component means "converted nothing"; an old record supports
that claim no more than it supports the opposite, so the third state says only
what is true. `Forecasting`'s stored projection runs are the case that needs
it ([the stored output
shape](../forecasting/architecture.md#projection-orchestration-and-output-shape)).

One Blade component renders it everywhere:
`x-core::fx-disclosure`, in Core beside the copy it reads
(`core::fx.*`, which is where the provider labels and the three stale
sentences live). It takes the disclosure and an `id` — null renders
nothing, which is the passthrough path — and draws the rates line, a
native `[popover]` holding a line per pair, and the `core::money.not_converted`
clause. A surface that names what it left out without naming what it
converted at fails `AConvertedFigureNamesTheRateThatMadeItArchTest`.

The rate itself is printed at the column's own eight places
(`Rate::exact()`), not at the three significant digits `Rate::forDisplay()`
keeps for a rate read at a glance: a reader who opened the panel came to
check the rate, and 0.00629 does not reproduce a figure priced at
0.00628536.

The age a reader sees is Carbon's own relative phrase rather than a
sentence of ours: it is translated in all twenty-six languages with no
plural rule per language, and "3 days ago" against "3 months ago" is the
whole reason to show it. A bundled snapshot is what a fresh install
converts at for as long as online fetching stays off, which is the
default, so a ninety-nine-day-old rate is the ordinary case and not an
edge one.

`convert()` is the same rule for one amount rather than a bucket map:
a `Money` in, the amount in the target currency or `null` out. It is
what a surface that *ranks* across currencies uses — the costliest
subscription, the biggest drift, the lowest projected balance — where
the figure is a comparison key rather than a total. `null` drops the
candidate out of the race, which is the ranking equivalent of leaving a
bucket out of a sum: a JPY minor unit is not a euro cent, and on raw
minor units the cheaper subscription won.

### A bound met in more than one currency

`CrossCurrencyBound` is the same rule for a *threshold* rather than a
figure. The min/max pair a reader types is one amount of money, and a
query that tests rows in several currencies has to restate it in each of
them: the service answers the pair as it reads in the target currency,
or `null` where no rate reaches that currency — the bound's version of
leaving a bucket out of a sum, and never a silent one-to-one. A bound
that had a value and lost it in conversion makes the whole filter
unanswerable there, because honouring the surviving half alone would
widen the window the reader asked for.

Both callers of it are two ends of one screen. `ReportAggregator` scopes
each dimension query to one settled currency and restates the bound into
it; `SearchQuery` restates the same bound across the currencies the
ledger settles in so the transaction list a report row drills into
carries the rows that row counted. They read the conversion from here
rather than each doing its own, because a figure and the list beneath it
priced two ways is the same disagreement as two surfaces adding the same
buckets two ways — it just moves rather than resolves.

### What deliberately does not go through it

`NetWorthQuery` converts per balance line and stays on
`ExchangeRateService` on purpose. It needs the whole `ConversionResult`
per line, because the rate, its source, its as-of date and its staleness
are rendered beside each line and not only for the total. It also has to
keep a rate-less line **visible** in the breakdown, listed at its native
amount with a null base equivalent, which is the opposite of leaving a
bucket out; and its `balancesWithoutRate` counts *lines*, where
`ConvertedTotal::unconverted` counts distinct currency codes. Routing it
through the seam would quietly change both. Its cost is one rate read
per non-base line, bounded by the account count.

It does fold those per-line results into a `RateSet` of its own, so the
card's total discloses through the same component as everything else and
`NetWorth::$ratesSource` / `$ratesAsOf` / `$hasStaleRates` are derived
from it. They used to be folded newest-first, which meant one freshly
refreshed pair dated the whole card and named its source while a stale
leg was reported under that fresh date. The disclosure it hands the
component carries **no** unconverted codes: the card counts the lines it
left out, which is a different number from the distinct currencies, and
both sentences would otherwise be on screen saying it differently.
`AccountBalanceLine::disclosure()` is the per-line half, one rate each.

`CurrencyModeApplier`'s `'original'` mode converts nothing at all — it
is the mode that exists to leave every figure in the currency it was
settled in — so only its `'base'` branch meets the seam.

A roll-up converted in TWO hops discloses only the hop it made itself. The
all-accounts forecast aggregate and the calendar balance line both add curves
already denominated in their accounts' own currencies, each of which converted
its own contributions at a rate the run stored; the disclosure beside those
figures names the account-to-reader hop and not the contribution-to-account
one. A `RateSet` holds legs into ONE target by construction, so naming both
would need a disclosure spanning two targets, which this seam has no type for.
A converted figure whose legs all share a target — which is every other
surface — is unaffected.

`FXServiceProvider::register()` tags and registers the three rate
providers as singletons, binds `RateProviderRegistry` sorted by
`priority()` DESC, and binds `ExchangeRateService` as a singleton,
mirroring the `ReceiptsServiceProvider` tagged-singleton pattern. FX has
no Livewire pages of its own in v1 — settings live on the Core
`SettingsPage` — and no module-local `Routes/console.php`; the daily
refresh entry lives in the root `routes/console.php`.
