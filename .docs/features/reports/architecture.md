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

`Public/` holds exactly one class: `Http/Livewire/PinnedReportsRow`, the
dashboard's row of pinned-report mini-cards. `Shell`'s dashboard mounts it by
its registered alias `reports.pinned-reports-row` — the crossing
`pinnedCrossModuleLivewireMounts` pins — and no module outside `Reports`
imports a `Modules\Reports\` symbol at all. The module publishes a card, not
a query surface.

Everything else lives under `Internal/` and is reachable only from inside the
module:

- **Internal/Dto/** — `ReportDefinition` (the full user-composed recipe, the
  exact shape persisted as `saved_reports.definition` JSON), `ReportResultDto`
  (the aggregator's output contract: rows, total, currency, the two
  FX-exclusion SETS, optional comparison rows), `ReportResultRow`
  (one grouped total), `SavedReportIndexRow` (a `/reports/library` row
  with a pre-rendered summary line).
- **Internal/Actions/** — `SaveReport`, `UpdateReport`, `DeleteReport`,
  `TogglePin` — the write surface for saved/pinned reports.
- **Internal/Services/** — `PinnedReportsQuery` and `SavedReportsQuery`, the
  read models behind the dashboard mini-card row and the library index, and
  `ReportCsvExporter`.
- **Internal/Aggregation/**, **Internal/Http/**, **Internal/Support/** — the
  aggregation engine, the Livewire components, and small support helpers.

## Key services + events

- `ReportAggregator::run(User, ReportDefinition): ReportResultDto` — the
  module's single aggregation entry point. Every consumer calls
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
  `CounterpartySpendQuery` draws the same two-label distinction the
  category dimension does, from `reports::builder.no_counterparty` and
  `reports::builder.unavailable_counterparty`: a transaction whose
  `counterparty_id` is `NULL` has no counterparty, and one whose id
  `CounterpartyProfileQuery::identitiesForIds()` cannot resolve has one
  this device cannot name. Both were English literals until a phone set
  to Dutch read `No counterparty €12.34`.
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
  `ReportAggregatorMetricsTest` guards. **"The same report" includes
  its filters.** Pass 2 tested the amount bound against the split leg's
  `settled_amount_minor` while every other dimension tests the
  transaction's, so a €124.50 parent with a €24.50 leg moved the
  headline by €24.50 the moment the reader tapped a different "Group
  by" chip: €2,005.61 by category against €2,030.11 by account under
  `amount_min=50`, and the other way under `amount_max`. Both bounds
  and the direction predicate go to `t.`, never `ts.` — a filter
  selects transactions, and the legs of a selected transaction are
  attributed whole. The invariant is exercised WITH a filter set in
  both `CrossDimensionTotalConsistencyTest` and
  `ReportAggregatorMetricsTest`: under the default empty
  `SpendQueryFilters` the one predicate the four dimensions write
  differently is never reached.

  Its group labels are full breadcrumbs, resolved through `Ledger`'s
  `Public\Services\CategoryAncestry` — the same seam the dashboard's
  `TopCategoriesByPeriodQuery` renders from, rather than a second copy
  of the parent walk and its visibility predicate. A category id that
  walk cannot resolve — cross-tenant, deleted, or arrived over Sync
  ahead of its own row — is labelled
  `ledger::common.unavailable_category`, never "Uncategorized".
  Borrowing that label put two rows with the same name and different
  money in the report table, in the pinned donut legend and in the
  dashboard's spending-trend movers, with nothing to tell them apart:
  having no category and having one this device cannot see are two
  different facts, and only the first is one the reader can act on.
  `CategorySpendTrendQuery` makes the same distinction from the same
  two keys.
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
  currency has no available rate is excluded from the total and named
  in `ReportResultDto::excludedCurrencies` — never a silent 1:1
  fallback, mirroring `NetWorthSeriesQuery`'s own never-1:1 guard.

  **The exclusion metadata is two fields because it is two facts.** A
  transaction metric excludes CURRENCIES; `net_worth` excludes
  ACCOUNTS. One `accountsWithoutRate` counter carried both and the
  builder rendered it through `":count account not converted"`, so a
  reader with four ARS accounts, each holding an unconvertible expense,
  was told "1 account not converted" while ARS 2,300.00 of spend went
  missing. Both are now SETS — `excludedCurrencies: list<string>` and
  `excludedAccountIds: list<int>` — which also makes the compare-mode
  union correct: the previous window's counters used to be ADDED to the
  current window's, so a currency unconvertible in both periods counted
  as two. The transaction path renders through
  `core::money.not_converted`, which names the currency, the sentence
  the dashboard already uses; the balance path keeps
  `reports::builder.fx_excluded`, which counts accounts and is right
  there. The
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
  the total); the DTO-level `currency`/`totalMinor` come from the
  *actual* result rows, never a "first discovered currency" guess made
  before the filtered query has run. **They are picked by what each
  currency's subtotal is worth, not by the size of its raw number.**
  Minor units of different currencies are not comparable: ARS 2,300.00
  is 230,000 of them and EUR 1,049.94 is 104,994, so the headline read
  "TOTAL SPEND: ARS 2,300.00" over a table whose largest row was the
  euro one — and that ARS total had no rate behind it at all. Each
  subtotal is converted to the reader's base currency for RANKING only,
  changing no displayed figure, and a currency with no rate can never
  outrank one that has; ties go to the first currency discovered, which
  is ordered by code, so the same report always headlines the same
  currency.
- **The amount filter is one amount of money, not one number.**
  `ReportAggregator` parses `amountMin`/`amountMax` at the scale of the
  currency the reader typed them in (their base currency), then converts
  the bound into the currency each dimension query is scoped to. Parsed
  at a hard two decimals it became 2,000 minor units against a ¥1,000
  charge, so a JPY-only report filtered "amount ≥ 20" came back empty;
  applied unconverted to every discovered currency at once, "≥ 20" meant
  EUR 20, USD 20, ARS 20 and ¥2,000 simultaneously. Where no rate
  reaches a currency the bound cannot be stated in it at all, so
  `queryForCurrency` returns **null** rather than an empty row list —
  `null` is "this currency cannot be answered", `[]` is "nothing
  matched" — and `CurrencyModeApplier` adds it to `excludedCurrencies`
  in BOTH modes. A silent 1:1 threshold is the same forbidden guess as a
  silent 1:1 conversion. `OtherMovementQuery` re-sums each currency's
  bucket under its own bound for the same reason, and pays the extra
  statements only when a bound is actually set.
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
  The account set is the same one and asked the same way: both call
  `AccountKind::mirrorValues()` rather than keeping a list each, because a
  kind one of them counts alone is a step in this line with no cause behind
  it. Which kinds those are, and why `ics_card` is deliberately not among
  them, is in
  [which kinds hold money](../ledger/architecture.md#accountkind--which-kinds-hold-money).
  **Scope limitation:** the most-recent point in this series is
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
  report's "this month" always matches the dashboard's. `this_year`
  resolves through `Ledger\Public\Services\CalendarSpan::year()` — the
  whole calendar year, `startOfYear() -> startOfYear()+1year` — which is
  the same definition `/transactions` draws its own "This year" preset
  from, so a window labelled "2026" means 2026 on both surfaces. `ytd`
  keeps that start and stops at `now+1day`, which is the only thing that
  makes it a different preset. These two used to share the second formula
  on the premise that *a future date never carries transactions*; that
  premise is false in a codebase shipping `BookedFutureRowQuery`, and it
  cost a booked-ahead expense its place in a report headed "2026" (157
  rows on `/transactions` against 156 on `/reports`). `custom` parses
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

  What "the other window has no counterpart" means is the join's to say,
  and `ComparisonJoin::missingCounterpartMinor()` is the one place it is
  said: `0` for `Group` (a category nobody spent on genuinely spent
  zero) and `null` for `Sequence` (a bucket the previous window never
  reached is unknown). `compare()` reads an EMPTY previous window
  through that same rule, because it had been reading it a second way:
  every bucket rendered an em dash while the footer under them computed
  a full-value delta of +€8,308.65 off a previous total of zero — one
  screen, two contradictory claims about the same fact, and the em dash
  is documented as meaning "no counterpart, and must not read as 'was
  zero then'".

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
  also emits `type[]`, the metric's own type set, so the list a row opens
  carries exactly the rows the figure summed — a `transfer_out` or a `fee`
  cannot appear beside the expenses a `spend` figure counted, and neither
  can salary income.

  **It emits no direction of its own.** `amount_dir` was the first attempt at
  that narrowing and it survived `type[]` landing, which made the two
  parameters contradict each other: `ReportMetric::Spend->types()` is
  `[expense, refund]`, a refund is POSITIVE, and `amount_dir=out` is
  `amount_minor < 0` — so the list dropped every refund the figure had
  already subtracted. A €100 charger and a €30 refund read as €70 on the
  report and €100 in the list. It is not kept for the single-signed metrics
  either: `income`'s type set looks positive-only by convention rather than
  by constraint, and a reversed salary would go the same way. An explicit
  `amountDirection` on the definition IS carried through — that one is the
  reader's own filter, and the dimension queries apply it to the report
  figure too (`SpendFilterApplier`), so the two still agree.
  Uses the *singular* array param names
  `TransactionsList`'s `#[Url(as: 'account'/'category'/'counterparty')]`
  properties expect (`accounts`/`categories`/`counterparties` would
  silently no-op). `Period.endExclusive` is exclusive by contract but
  the `before` query param is inclusive — this is the one place that
  `subDay()` conversion happens. `time_bucket` carries no group filter
  param (a time-bucket row has no category/account/counterparty id).

  **The reader's own account/category/counterparty filters ride along.**
  Only the clicked row's group was ever emitted, so a report narrowed to
  one account and grouped by category opened a list carrying every
  account's rows: an `Office` row reading €100.00 opened a list summing
  €140.00, and neither number was labelled as the narrowed one. The three
  filter arrays are emitted first and the row's own group overwrites its
  own dimension's entry — the group key is already inside that filter, so
  intersecting them would be a no-op at best and, for the `uncategorized`
  bucket, an impossible AND. That bucket therefore clears the inherited
  `category[]` outright: "has no category" and "is one of these
  categories" cannot both hold. Pinned by
  `ADrilldownCarriesTheFiltersTheFigureWasNarrowedByTest`.

  **A `null` `groupKey` on the CATEGORY dimension is a filter, not the
  absence of one.** Emitting nothing for it opened the whole period: a
  row reading `Uncategorized €85.00` produced a list of 32 transactions,
  €2,459.11 out and €34.99 in. It now emits `uncategorized=1`, which
  `TransactionsList`/`SearchFilters`/`SearchQuery` honour as
  `category_id IS NULL` plus `SplitLegs::excludeParents()` — the same
  convention the dashboard's uncategorized count uses. The other two
  dimensions have no such bucket to name and still emit nothing.

  **`category[]=N` is split-aware.** It filtered
  `transactions.category_id` alone, so a split parent whose LEG the
  report had counted was invisible to the list it opened:
  `Personal care €110.00` opened one transaction of €21.05, and
  `Subscriptions/Cloud/Software €137.95` opened three totalling €113.45.
  `SearchQuery::applyCategoryFilter()` now matches the parent column OR
  any of the transaction's `transaction_splits.category_id` legs, which
  is what `CategorySpendQuery`'s two passes actually select on —
  splitting a transaction is precisely how part of it is attributed to a
  category. The account and counterparty drilldowns never had either
  problem: those columns are invariant across a split parent's legs, and
  every row of theirs has a key.

  A caveat that remains, and is not a defect: for a parent split across
  SEVERAL categories the list shows the parent's whole amount, because a
  transaction list lists transactions. The row's figure and the list's
  sum agree exactly whenever the parent's legs all sit in the clicked
  category, which is what
  `TheDrilldownListAddsUpToTheRowItWasOpenedFromTest` pins; the
  partially-split case is pinned on the weaker property that the parent
  is *present*, which is the thing that was broken.
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

  **It exports the rows the SCREEN renders**, which with comparison on
  is `comparisonRows` — the union of both windows' groups, carrying the
  delta and sorted by it — plus a `Delta` column. Iterating `->rows`
  instead silently dropped every group that had fallen to zero (a
  15-row screen exported as 14, with `Donations €0.00 −€75.00` gone)
  along with the whole column the reader had turned comparison on to
  get. A row whose counterpart is unknown writes an EMPTY delta cell,
  never `0.00` — the em dash the table prints there. In `'original'`
  mode the file spans several currencies and there is no single sum to
  check it against: each currency's rows sum to that currency's own
  subtotal, and the headline currency's rows sum to `totalMinor`. The
  group header comes from `Enums\ReportGroupHeading`, which the
  on-screen table reads too — the exporter guarded the `net_worth` case
  in a comment describing the exact failure while the screen it is
  documented to match still headed a column of months with "Category".

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

**A chart draws one currency and, for a donut, one direction.**
`Internal\Support\ChartSeries` decides what a given `viz` can actually
draw out of the report's rows, and hands back what it left out so the
page can say it — the builder renders both omissions under the chart,
and `PinnedReportsRow` narrows the same way.

- **One currency.** Raw minor units of two currencies share no scale, so
  `'original'` mode plotted four of them on the reader's base-currency
  axis: "JP Wallet" drawn at 1,000 (it is ¥1,000 ≈ €6.29) and three ARS
  bars at 600 with no rate behind them, beside a real €1,049.94 bar. The
  chart keeps the rows in `ReportResultDto::currency` and names the rest
  through `reports::builder.chart.other_currencies`.
- **The axis says which currency.** Every partial now sets
  `beatraxCurrency`; without it `resources/js/app.js` stamps the
  reader's base currency on the axis of a chart drawn in another. This
  is the precedent `Forecasting`'s `aggregate-line-chart` set, pinned by
  `AYenChartIsDrawnInYenTest` and `ForecastChartSaysItsOwnCurrencyTest`.
- **One direction, for the donut.** A ring is built from sizes and a
  report total is signed, so `abs()` drew an `Income / Refunds` slice
  inside "where the money went" while the table beneath printed the same
  row as −€34.99 in red, and the slices summed to 2,459.11 under a
  headline of 2,389.13. The ring keeps the rows moving the way the total
  does and names what it left out through
  `reports::builder.chart.undrawn`, so ring + disclosure reconciles to
  the headline. The bar chart in base mode needs none of this: it has a
  zero line and draws a refund below it.
- **The donut palette does not repeat.** Ten brand colours cycled with a
  modulo, so a fifteen-category ring drew slices 11–15 in the same greys
  as 1–5. `Internal\Support\DonutPalette` keeps the brand set for ten or
  fewer and past that splits the wheel into as many hues as there are
  slices, anchored on the brand's own opening hue so a ring of eleven
  does not read as a different chart from a ring of ten.

Chart series go through `Internal\Support\ChartAmount`, never a division
by a hardcoded hundred: ApexCharts needs a number in *major* units and the
divisor is not the same for every currency, so a JPY row (which has no minor
unit at all) was drawn at a hundredth of itself beside a table still printing
the true figure. The scale is taken from the currency itself; an unrecognised
code falls back to two decimals, which is what every other boundary in the
repo assumes. Both of those come from `Ledger` and neither is copied here:
`ChartAmount` divides through `Money::majorUnits()`, and a caller that needs
the scale or the decimal count as a number asks
`Ledger\Public\ValueObjects\CurrencyScale`, the one reader of either.
`tests/Contracts/OneSeamAnswersTheMinorUnitScaleArchTest.php` fails the build
on a second `log10` over a minor-unit scale, or a second fallback to
`Money::MINOR_UNITS_PER_MAJOR`, anywhere outside that seam — so a local copy
of the divisor is a red build, not a review note.

CSV export mirrors the same aggregator call
(`ReportCsvExporter::export()`), so the download and the on-screen
table/chart are structurally guaranteed to agree. The `/reports/export`
route builds a `ReportDefinition` from validated query params using the
same short param names `ReportBuilder`'s `#[Url]`-bound properties use
(`metric`/`dim`/`period`/`gran`/`ccy`/`viz`/`cmp`/`account`/`category`/
`counterparty`/`amount_min`/`amount_max`/`amount_dir`/`from`/`to`), so a
builder-driven "Export CSV" link can pass its current URL query string
straight through unchanged.
