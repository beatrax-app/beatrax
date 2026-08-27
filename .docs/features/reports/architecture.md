# `Reports` — architecture

The `Reports` module is the `/reports` live single-page report builder and
the `/reports/library` saved-report index. It composes spend/income/net
transaction totals and a sampled net-worth series across four dimensions
(category, counterparty, account, time bucket), applies base/original
currency-mode conversion, supports period-over-period comparison, and
exports the current composition as CSV.

## What this module is for

A user picks a metric (spend/income/net/net_worth), a dimension to group
by, a period, and a set of filters; the module answers with grouped
totals the builder renders as a table or chart (bar/line/donut), and the
same composition can be saved, pinned to the dashboard, or exported as
CSV. Every consumer — the builder's table, its four chart partials, the
dashboard's pinned-reports mini-cards, and the CSV exporter — reads
through the exact same `ReportAggregator::run()` call with the exact same
`ReportDefinition`, so none of them can ever disagree about totals.

It explicitly does NOT store historical net worth in a table; every
net-worth point is computed fresh by resampling account balances at each
bucket's sample date. It does not persist ad-hoc query results; a "saved
report" persists only the *definition* (the recipe), never the computed
rows.

## Module boundary

`Public/` exposes the cross-module surface:

- **Dto/** — `ReportDefinition` (the full user-composed recipe, the exact
  shape persisted as `saved_reports.definition` JSON), `ReportResultDto`
  (the aggregator's output contract: rows, total, currency,
  FX-exclusion metadata, optional comparison rows), `ReportResultRow`
  (one grouped total), `SavedReportIndexRow` (a `/reports/library` row
  with a pre-rendered summary line).
- **Actions/** — `SaveReport`, `UpdateReport`, `DeleteReport`,
  `TogglePin` — the write surface for saved/pinned reports.
- **Services/** — `PinnedReportsQuery`, `SavedReportsQuery` — read models
  for the dashboard mini-card row and the library index.

`Internal/` houses the aggregation engine, the CSV exporter, the Livewire
components, and small support helpers — none of it is reachable from
another module.

## Key services + events

- `ReportAggregator::run(User, ReportDefinition): ReportResultDto` — the
  single Public-facing aggregation entry point. Every consumer calls
  this and never talks to a dimension query, `CurrencyModeApplier`, or
  `PeriodComparison` collaborator directly. Dispatch splits `net_worth`
  (a time series, dimension ignored) from the three transaction metrics
  first, then matches on `$definition->dimension` to pick the grouped
  query. `net` is never composed from separately-run spend/income
  totals — every dimension query implements `'net'` natively as
  `SUM(settled_amount_minor)` over the metric's own type set.
  `Aggregation\ReportMetric` owns that set: a `refund` is a reversal of an
  expense, so it is counted by `spend` (which it reduces) and by `net`
  (which it raises), and never by `income` — counting it in both would
  make `income - spend` and `net` disagree about it, and leaving it out
  of all three let a refunded purchase over-report `spend` by the whole
  refund. `transfer_in`/`transfer_out` stay silent in every metric: they
  are the two halves of one internal move.
- **Cross-user isolation, structural not query-time:** `accounts`/
  `categories`/`counterparties` filter ids from a persisted
  `ReportDefinition` are passed straight into each dimension query's own
  `whereIn(...)` predicate, which sits *alongside* that query's existing
  `where('user_id', $user->id)` guard — a foreign id can therefore never
  widen a result to another user's rows, it can only narrow (or zero)
  the calling user's own already-scoped result. No separate
  pre-validation query is needed.
- **Dimension queries** (`Internal/Aggregation/*SpendQuery.php`) —
  `AccountSpendQuery`/`CounterpartySpendQuery`/`TimeBucketSpendQuery`
  aggregate the `transactions` parent rows directly with no split-leg
  join (`account_id`/`counterparty_id` are invariant across a split
  parent's legs — `transaction_splits` carries only `category_id`).
  `CategorySpendQuery` is the one place a split-leg-aware `legs ∪
  unsplit-parents` join is combined with the type-based spend/income/net
  definition: pass 1 rolls up unsplit parents *and* "broken split"
  parents (legs that don't sum to the parent's own
  `settled_amount_minor`) via the parent's own `category_id`; pass 2
  attributes only internally-consistent split legs via
  `transaction_splits.category_id`. Uncategorized rows
  (`category_id IS NULL`) are included as their own "Uncategorized"
  group so the category dimension's grand total never disagrees with
  the other (unfiltered-by-category) dimensions' totals for the same
  report — the `cross_dimension_total_consistency` invariant
  `ReportAggregatorMetricsTest` guards. Its group labels are full
  breadcrumbs, resolved through `Ledger`'s
  `Public\Services\CategoryAncestry` — the same seam the dashboard's
  `TopCategoriesByPeriodQuery` renders from, rather than a second copy
  of the parent walk and its visibility predicate.
- `CurrencyModeApplier::apply()` — applies a report's `currencyMode`
  (`'base'` | `'original'`) to a dimension query and assembles the
  `ReportResultDto`. Neither this class nor its caller ever hardcodes a
  currency list: every call first discovers the distinct
  `settled_currency` values actually present for the
  user+period+metric+filters, then re-runs the caller-supplied
  dimension query once per discovered currency. Discovery applies the
  category filter to `transactions.category_id` **or** to any of the
  transaction's `transaction_splits.category_id` legs, matching what
  `CategorySpendQuery`'s two passes actually select on: a category living
  only on legs used to discover no currency at all, so the dimension query
  was never called and a report filtered to it came back empty even though
  that query on its own answered with the leg's total. Splitting a
  transaction is precisely how part of it is attributed to a category.
  `'base'` mode converts
  each currency's rows via `CrossCurrencyTotal` and merges same-group
  rows across currencies into one base-currency total; a row whose
  currency has no available rate is excluded from the total and counted
  (`hasExcludedAccounts`/`accountsWithoutRate`) — never a silent 1:1
  fallback, mirroring `NetWorthSeriesQuery`'s own never-1:1 guard. The
  rate for every discovered currency, fees included, is fetched once
  per report: each dimension query returns rows already scoped to the
  one currency it was asked for, so converting per row read the whole
  `exchange_rates` table once per row for a rate that could not have
  changed between them.

  **The total is converted from the currency's own subtotal, never summed
  from separately-rounded rows.** Rounding each grouped row's conversion on
  its own drifts by up to half a minor unit per group, so one report
  totalled EUR 8942.01 by category and EUR 8942.04 by
  counterparty/account/time_bucket — the drift is O(number of groups),
  which is exactly what changing the dimension changes. Each currency's raw
  subtotal is converted once, and the difference between that and the sum of
  its converted rows is handed back to those rows largest-magnitude-first
  (ties by position, so the same report always lands the same cents on the
  same rows). The rows therefore still add up to the footer total, and the
  `cross_dimension_total_consistency` invariant now holds *after* conversion
  rather than only while every row was already in the base currency.

  `'original'` mode never converts: a group present in
  more than one currency yields one row *per* currency (never merged —
  summing raw minor units across different currencies would corrupt
  the total); the DTO-level `currency`/`totalMinor` are picked as the
  currency with the largest absolute total among the *actual* result
  rows, never a "first discovered currency" guess made before the
  filtered query has run.
- `OtherMovementQuery::totalsByCurrency()` — the money the chosen metric is
  not defined over, per settled currency: `fee` and `adjustment` always,
  plus `refund` for the one metric (`income`) that does not already count
  it. The set is derived by subtracting the metric's own types rather than
  listed, so nothing can be reported twice — once inside the total and
  again beside it. `ReportResultDto::otherMovementsByCurrency` is keyed by
  currency because `'original'` mode converts nothing: a fee bucket outside
  the headline currency has no other line to appear on, and reporting only
  the headline currency's bucket made it disappear from the one disclosure
  that exists so a total which omits money does not read as everything that
  left the account.
- `NetWorthSeriesQuery::forUser()` — net worth as an on-demand *sampled*
  series; no table stores historical net worth. It honours the **account**
  filter and nothing else: a balance is not a set of transactions, so a
  category/counterparty/amount predicate has nothing to select on. The
  builder does not offer those three controls for this metric, and says so
  when a URL still carries one — it used to offer all four, apply none, and
  report the whole portfolio as though the filter had been honoured. The
  exclusion metadata is a **set of account ids** unioned across the series:
  adding each point's count up told a reader with five accounts that 4108
  of them were not converted. Every point repeats
  Forecasting's `NetWorthQuery::forUser()` exclude+count algorithm once
  per `TimeBucketGenerator` sample date instead of once for "today".
  **Scope limitation (C7-R8):** the most-recent point in this series is
  NOT guaranteed to equal the dashboard net-worth card's "today" figure.
  Both now read `AccountBalanceQuery` as of a date, so the anchor is no
  longer the difference — the remaining one is cleared status. The series
  samples `clearedBalanceAsOf()`, which counts only cleared and reconciled
  rows, because a historical point should not move as old manual entries
  are confirmed; the card reads `currentBalanceAsOf()`, which counts every
  row, because an uncleared entry is still money the reader has. An
  account with pending rows therefore reads higher on the card than on the
  series' last point, by exactly the uncleared amount.

  The card previously sourced its balance from Forecasting's
  `BalanceAnchorResolver`. That resolver answers where a *projection*
  starts, not what an account holds: it returned a statement closing
  balance with no delta for the months since, and zero for a card with no
  statement at all. Measured on the desktop, the card read EUR 1,238.04
  against a true position of EUR 1,457.53 (EUR 5,065.53 is that reader's
  all-time sum, which counts money not yet received). The never-silent-1:1-fallback
  guarantee is unchanged. Each bucket samples at its last actual day
  (`endExclusive->subDay()`), e.g. a "Jul 2025" bucket samples the
  end-of-month balance on 2025-07-31.
- `TimeBucketGenerator::generate()` — splits a `Period` into an ordered
  list of half-open sub-`Period` buckets. Documented point cap:
  `MAX_BUCKET_POINTS = 60` (~5 years of monthly points) — a monthly
  request whose uncapped bucket count would exceed the cap auto-widens
  to quarterly stepping (never truncates the range); the weekly branch
  auto-widens to monthly first, then further to quarterly on top of
  that, so an arbitrarily long custom range never exceeds the cap
  regardless of starting granularity. The last bucket's `endExclusive`
  is always clamped to the overall range's `endExclusive`.
- `PeriodPresetResolver::resolve()` — resolves a period-preset string
  into a concrete `Period`. `this_month`/`last_N_months` delegate to
  `PeriodQuery` (the user's `period_start_day`-anchored stepping) so a
  report's "this month" always matches the dashboard's. `ytd`/
  `this_year` both resolve to `startOfYear() -> now+1day` (a future
  date never carries transactions, so the two windows coincide — kept
  as two preset keys purely for the picker's copy). `custom` parses
  `customFrom`/`customTo` *strictly* against `Y-m-d` (never Carbon's
  lenient natural-language parser, so a malformed replayed
  `saved_reports.definition` blob throws rather than silently
  resolving to an unintended date) and converts the user's inclusive
  end date to the half-open contract by adding one day; an inverted
  range (`customTo < customFrom`) is rejected outright. Every rejection is
  an `Internal\Exceptions\InvalidReportPeriod` carrying an
  `Enums\PeriodProblem` (`incomplete` | `malformed` | `inverted`), and
  **every surface that resolves a period catches it**: the builder renders
  the matching validation message with the rest of the composition
  untouched, `/reports/export` answers 422 with the same message *before*
  the stream opens (an exception thrown from inside the download callback
  has already sent 200 and the CSV headers, so the reader would get a
  truncated file instead of the reason), and `PinnedReportsRow` renders an
  empty card rather than taking the whole dashboard down. Picking the end
  date before the start date is an ordinary mid-edit state in a two-date
  picker; it used to be an HTML error page with the composition lost.
- `PeriodComparison::compare()` — period-over-period comparison. The
  previous period is a plain equal-length span-shift ending at the
  current period's `start` — never `PeriodQuery::previous()` (that
  method means "the calendar month before" only for `this_month` and
  would disagree with a `last_6_months`/`ytd`/`custom` report's own
  window). Every month-anchored preset steps back the same number of
  calendar months (never a raw day-count shift, which "borrows" days
  across a shorter/longer adjacent month); only a true arbitrary-length
  custom range uses a plain day-count shift. How rows are matched is an
  `Enums\ComparisonJoin`, which the aggregator picks from the definition:

  - `Group` — for the category, counterparty and account dimensions.
    Comparison rows are the union of current+previous group keys (a group
    that dropped to zero still surfaces as a mover), keyed by
    `(group, currency)` — never group alone, since `'original'` currency
    mode intentionally returns one row per currency for a multi-currency
    group. Sorted by `abs(deltaMinor)` descending.
  - `Sequence` — for the `time_bucket` dimension and the `net_worth`
    metric. Matched by **position within the window**, counted per currency
    (`'original'` mode emits the whole bucket run once per currency, and
    the two windows need not have discovered the same set of them). A
    bucket's group key is a DATE, which two disjoint windows can never
    share: joining on it gave every current row `previous = 0` and appended
    every previous row as a fabricated current row at 0, so the trend
    report — the one composition where "vs. previous period" is the whole
    point — produced double the rows, half of them invented, with every
    delta equal to the raw value. `net_worth` was worse: it claimed the
    reader's net worth had been zero. A current bucket the previous window
    does not reach carries `previousAmountMinor = null`, which the table
    renders as an em dash rather than as "was zero then", and the result is
    never re-sorted by delta: for a series, the row order IS the series.

  `compare()` also returns the previous period's **own** `totalMinor` and
  `currency`, which reach the page as
  `ReportResultDto::previousTotalMinor`/`previousCurrency`. The headline
  "vs. previous period" delta used to be re-derived in Blade by summing
  `previousAmountMinor` over the displayed rows: in `'original'` mode that
  is one row per currency, so USD cents went straight into a EUR sum, and
  for `net_worth` it added a balance up once per bucket — the exact
  miscomputation `buildNetWorthResult()` documents itself as avoiding for
  the current total. The delta renders only when both windows headline the
  same currency; otherwise the page prints an em dash, because a EUR total
  minus a USD total is not a figure.

  Currency-mode-agnostic by design: the
  caller's `$queryForPeriod` closure already carries whichever
  `CurrencyModeApplier` call produced the current period's rows.
- `DrilldownUrlBuilder::build()` — the tested `Period` -> URL conversion
  for chart drill-down. **The `Period` it is handed is the window the
  clicked ROW covers, not the report's.** `ReportBuilder::render()` maps a
  time-bucket row's `groupKey` (its window's start date) and a net-worth
  point's `groupKey` (its sample date, the last day of the same window)
  back through `TimeBucketGenerator` to that bucket's own `Period`; handed
  the whole range, three monthly rows all linked to one identical list. It
  also emits the metric's own direction — `spend` -> `amount_dir=out`,
  `income` -> `in`, `net` undirected — so a `spend` row cannot point at a
  list carrying salary income. An explicit `amountDirection` on the
  definition is the reader's and is left alone. `TransactionsList` has no
  `type` filter parameter, so the drill-down narrows by direction rather
  than by transaction type: a `transfer_out` or a `fee` can still appear
  beside the expenses a `spend` figure counted. Uses the *singular* array param names
  `TransactionsList`'s `#[Url(as: 'account'/'category'/'counterparty')]`
  properties expect (`accounts`/`categories`/`counterparties` would
  silently no-op). `Period.endExclusive` is exclusive by contract but
  the `before` query param is inclusive — this is the one place that
  `subDay()` conversion happens. `time_bucket` carries no group filter
  param (a time-bucket row has no category/account/counterparty id); a
  `null` `groupKey` (the "No category"/"No counterparty"/"No account"
  bucket) omits the dimension filter entirely rather than filtering on
  a synthetic id.
- `ReportCsvExporter::export()` — streams a report's aggregated rows as
  CSV via `ReportAggregator::run()`, so the download can never disagree
  with the on-screen table/chart. `EscapeFormula` runs on every
  free-text column — and *only* that column, since escaping the three
  generated ones turned a negative amount into the text `'-75.00`, which no
  spreadsheet will sum. The "Amount" column is written **signed** through
  `MoneyInput::toDecimalString($minor)` — the same seam `TaxCsvExporter`
  uses, and pure integer arithmetic throughout (CLAUDE.md forbids float
  division on money). Signed, because a `net` row is negative when more
  left than arrived and the file carries nothing else to recover the sign
  from: `abs()` made the export unsummable and put it at odds with the
  table it is documented to match.

## Write actions: security & concurrency contracts

- **Cross-user safety (IDOR):** every write action (`SaveReport`,
  `UpdateReport`, `DeleteReport`, `TogglePin`) and `ReportBuilder::mount()`
  perform an *explicit* `user_id` guard via
  `withoutGlobalScope(UserScope::class)->where('user_id', $user->id)`,
  never relying on the ambient session-bound global scope. A foreign or
  missing id throws `NotFoundHttpException` (404, never 403) so the
  existence of another user's report is never leaked through the error
  path; `ReportBuilder::mount()` falls through to its own default empty
  composition instead of a 404 (a 404 there would confirm the id's
  existence to an attacker probing `?report=`).
- **TOCTOU guard (`TogglePin`):** the 3-pin cap check runs *inside* the
  same DB transaction as the write, never as a pre-transaction round
  trip. Two concurrent `toggle()` calls that both read the pinned count
  before either commits could otherwise both pass a pre-transaction
  check and together pin a 4th report; reading the count inside the
  transaction means the second, blocked call re-reads the now-updated
  count once it acquires the write lock and correctly rejects — SQLite's
  single-writer serialization protects the invariant.
- **Dense pin-order invariant:** `PinOrderCompactor::compact()` (shared
  by `TogglePin`'s unpin path and `DeleteReport`) re-numbers the
  remaining pinned rows to a dense `1..N` `pin_order` sequence inside
  the same transaction as the mutation that triggered it, so deleting
  or unpinning a report never leaves a gap. Each row whose `pin_order`
  actually changes gets its own `SavedReportMutated` 'edit' event so
  every device's Sync op-log stays in step.
- **LWW per-field convergence (`UpdateReport`):** only genuinely-changed
  fields are written and emitted; a no-op update short-circuits before
  ever opening a transaction or dispatching an event. The dirty-check
  on `definition` compares a *normalized* copy of both sides — the
  list-valued filter fields (`accounts`/`categories`/`counterparties`)
  are sorted before the strict comparison, so a re-open that
  re-serializes the same filter *set* in a different array order is
  correctly treated as unchanged.
- **Save-vs-update dedup (`ReportBuilder::save()`):** a builder opened
  from a saved report (`loadedReportId !== null`) updates that same row
  rather than forking a new one; a fresh save's id is stashed into
  `loadedReportId` so a subsequent save on the same page load also
  updates in place instead of creating a duplicate.
- **A stored definition is coerced, never trusted
  (`Internal\Support\ReportDefinitionFactory::fromStored()`):**
  `saved_reports.definition` is a synced LWW column, so a row written by a
  peer on a different build is a realistic source of a word this build does
  not know. `ReportDefinition::from()` *throws* on one — a missing field, an
  unknown `granularity`, a `customFrom` that is not a date — so a single
  unreadable row 500'd `/reports`, and the dashboard with it when the report
  was pinned. Every read of a stored definition (`ReportBuilder::mount()`,
  `PinnedReportsQuery`) now goes through the factory, which coerces each
  field through the same `ReportVocabulary` the URL rail uses and drops a
  `customFrom`/`customTo` that is not a `Y-m-d` date, so the builder asks
  for the range again — a question the reader can answer — instead of
  replaying a window nobody chose. `ReportDefinition::from()` is unchanged
  and still strict; it is simply no longer the read path.
- **Independent cap re-enforcement (`PinnedReportsQuery`):** `TogglePin`
  already enforces the 3-pin cap in the write layer; the dashboard
  read query's own `LIMIT 3` is a second, independent enforcement point
  so a stray fourth pinned row (a data anomaly, a future write-path
  bug) can never render a 4th mini card.

## Data flow

Builder round trip (no page reload):

```
ReportBuilder (Livewire, every control is a #[Url]-bound property)
  -> currentDefinition() assembles a ReportDefinition from properties
  -> ReportAggregator::run($user, $definition)
       -> PeriodPresetResolver::resolve() -> concrete Period
       -> net_worth? NetWorthSeriesQuery::forUser() -> points -> rows
       -> else: dimensionRows() picks one *SpendQuery by dimension
            -> CurrencyModeApplier::apply() discovers currencies,
               re-runs the dimension query per currency, applies
               base/original mode
       -> compare=true? PeriodComparison::compare() joins the previous
          period's ReportResultDto by (group, currency)
  -> ReportResultDto (rows, total, currency, FX-exclusion metadata)
  -> table/chart partials render; DrilldownUrlBuilder maps each row to
     a /transactions filter URL
```

Chart series go through `Internal\Support\ChartAmount`, never a division
by a hardcoded hundred: ApexCharts needs a number in *major* units and the
divisor is not the same for every currency, so a JPY row (which has no minor
unit at all) was drawn at a hundredth of itself beside a table still printing
the true figure. The scale is taken from the currency itself; an unrecognised
code falls back to two decimals, which is what every other boundary in the
repo assumes. The right long-term home for that scale is `Ledger`'s `Money`
or `Currency`, both of which today hardcode `MINOR_UNITS_PER_MAJOR = 100`
everywhere except `Money::formatWholeUnits()`.

CSV export mirrors the same aggregator call
(`ReportCsvExporter::export()`), so the download and the on-screen
table/chart are structurally guaranteed to agree. The `/reports/export`
route builds a `ReportDefinition` from validated query params using the
same short param names `ReportBuilder`'s `#[Url]`-bound properties use
(`metric`/`dim`/`period`/`gran`/`ccy`/`viz`/`cmp`/`account`/`category`/
`counterparty`/`amount_min`/`amount_max`/`amount_dir`/`from`/`to`), so a
builder-driven "Export CSV" link can pass its current URL query string
straight through unchanged.
