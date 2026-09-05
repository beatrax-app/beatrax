# `Calendar` — architecture

The `Calendar` module renders `/calendar`, a month-grid cash-flow surface:
each day shows the payments that landed or are expected on it — the rows the
ledger holds and the recurring-series occurrences no row has yet answered —
plus a start-of-day/end-of-day balance line, so the user can see upcoming fixed
payments and the funding position they land against. `CalendarQuery` is
the single backend brain — the Livewire page is a thin renderer over its
output.

## Module boundary

`Calendar` has no `Public/` directory, and that is the shape of the module
rather than an omission: no production file outside it may reach in, and there
is nothing inside for anyone to reach for. It composes one screen out of seams
other modules already publish — the ledger's rows, the recurring cadences, the
forecast balances — and publishes nothing back. All of it is `Internal/`, and
the only files outside the module that name a `Modules\Calendar\Internal\`
symbol are two test files, both pinned in `pinnedCrossModuleInternalImports`.

- **Internal/Dto** — `CalendarDayDto` (one grid day: date, today/past flags,
  risk flag, SoD/EoD balances, computing sentinel, entries),
  `CalendarEntryDto` (one payment: amount, direction, account, counterparty,
  paid/missed/approximate flags), and `DayBalanceDto` (what one day's balance
  line knows, including the currencies it could not price). An entry carries a
  `seriesId`, a `transactionId`, or **both** — a cadence predicted it, the
  ledger booked it, or the ledger's row retired the cadence's estimate of the
  same payment — and the panel drills through to every id it has.
  Read-only value objects; the module writes no domain row of its own.
- **Internal/Services/CalendarQuery** — the sole composition service.
  Registered as a stateless singleton (all state flows through `forMonth()`
  arguments).
- **Internal/Http/Livewire/CalendarPage** — the `/calendar` page: month
  navigation, the Accounts popover, day selection, and account-preference
  persistence, written through `Core`'s `UserPreferenceWriter` rather than
  onto `user_preferences` directly.

## The grid edge

A month is rendered as a Mon–Sun strip, so the grid runs from the Monday on or
before the 1st to the Sunday on or after the last day — up to six days of the
previous month and six of the next. Those lead-in and lead-out cells are
ordinary cells: they draw a balance corner off the forecast, they carry
`tabindex="0"` and a `wire:click`, and only their day number is dimmed.

`CalendarGrid::range()` is the single answer to where that strip starts and
ends, and **every** map `forMonth()` builds is built over it: series entries,
booked entries, the balance map and the occurrence map alike. Building them over
the calendar month instead left a 3 September cell in the August grid stepping
the balance down €1,450.00 with nothing listed on it, announcing "0 entries" to
a screen reader, and rejecting its own click — `selectDay()` accepted only dates
inside the display month, so the cell was a dead target that still looked live.
`selectDay()` now accepts any day inside the rendered grid and nothing outside
it, so a cell's balance, its entries, its click and its `aria-label` all say the
same thing.

The same range reaches `DailyBalanceAggregator`, which used to widen whatever it
was handed to a Mon–Sun week of its own. Two independent answers to where the
grid begins is how the edge cells came to disagree with the entries drawn on
them; the caller owns the range now.

## Entry placement

Recurring series are placed onto grid dates by **index-stepping from the
anchor** (`nextExpectedAt`): the k-th occurrence is computed directly as
`anchor->addMonthsNoOverflow(k)` (or the weekly/quarterly/yearly equivalent)
for `k = …,-1,0,1,…`, rather than chaining `addMonth()` calls forward and
backward from a moving cursor. Chained stepping permanently loses an
end-of-month anchor after the first short month (Jan 31 → Feb 28 → Mar 28 →
…) and makes the walk non-invertible — the same bill would land on a
different day of the month depending on which month is being viewed. Index
stepping is symmetric in both directions and always preserves the day-31
anchor in months that have it.

Irregular-cadence series with a null `nextExpectedAt` are excluded entirely
(there is no well-defined placement date). A series' occurrences are also
floored at its **inception** — the earliest observed `recurring_series_occurrences`
row, or `created_at` as a fallback, minus a small slack
(`Modules\Recurring\Public\Support\MatchWindow::DAYS`, shared with the
projection's supersession window)
so a payment expected slightly before its first observed occurrence is not
dropped along with genuine pre-inception phantoms. Without this floor, every
history month before a series existed would render a phantom "expected — not
found" entry.

The backward walk is also **ceilinged at today**: a negative-index occurrence
that lands on a day still to come is dropped. Backward steps exist to fill in
history, and the anchor is the app's own answer to when the next charge falls,
so the forecast's forward walk
([range projection](../forecasting/architecture.md)) never emits a
contribution before it. Without the ceiling the two disagree on the same cell:
a rent series anchored on 28 September drew an expected −€1,450.00 on 28
August — five days ahead of today — under a day panel reading start of day
€9,208.08 and end of day €9,208.08.

Metadata resolution (counterparty, account name) is fully batched — the
service issues a bounded number of queries per render regardless of how many
series are approved, resolving counterparty identity primarily through
occurrence → transaction → counterparty links and falling back to a
`cluster_counterparty_key` ↔ counterparty-slug match for series with no
linked occurrence yet. That fallback only ever reaches a single-token
merchant, and reaches nothing at all for a user with at-rest encryption
enabled: the key is a 64-hex blind index by then, and no slug can equal
one.

## Booked rows and the cadences that predicted them

`SeriesEntryPlacer` answers what a cadence expects. `BookedEntryPlacer` answers
what the ledger holds, and leaving a held row off the grid left a day panel
reading "No payments on this day." above a balance line that stepped down
€1,450.00. Both placers feed one entry map over **the whole grid**, past cells
included, and the same supersession rule the
[projection](../forecasting/architecture.md#booked-future-dated-rows) applies
runs here: where a series occurrence and a booked row are the same payment, the
booked row is the one that happens and the estimate steps aside. Literally the
same rule — both placers call `OccurrenceSupersession::supersededDates()`,
which pairs booked dates to expected ones
[one-to-one](../forecasting/architecture.md#one-booked-row-retires-one-occurrence)
rather than clearing the whole window. A weekly series' next occurrence is
exactly `MatchWindow::DAYS` from the one the ledger has already booked, and
clearing the window took that week's entry off the grid entirely.

Both placers are handed the same grid range, and they have to reach exactly the
same cells or one placer's payment vanishes on a day the other's is drawn.
`BookedFutureRowQuery::between()` takes an **exclusive** lower bound, so the
booked side asks from the day *before* the first grid cell — that subtraction is
compensation for the exclusivity, not a wider reach, and it must be anchored on
the grid's first day rather than the month's. It applies the reader's
`AT_OR_AFTER_BASELINE_SQL` bound, the same one the past-day balance overlay
applies, so a row the line does not count is a row the panel does not list.

### Behind today, the ledger is the entry list

The placer once ran from *yesterday* forward, on the reasoning that a past day
draws its balance from the transactions themselves and gives its entries a
paid-or-missed verdict, so a booked row behind today was a payment that pass had
covered. It is not: that pass reaches series occurrences only. A plain imported
row belonging to no series was listed by **neither** placer while
`DailyBalanceAggregator` counted every row there is. An 18-row ASN import read
on an iPhone had three such days in one month — 23 August −€41.20, 25 August
+€3,200.00, 27 August −€85.00 — each stepping its own balance line by exactly
the transaction it said it did not have, while the September cells listed theirs
in full.

So the rule behind today is the plain one: **the day lists the rows the ledger
holds on it**, and a cadence's estimate survives a past day only where no booked
row paid it — which is what "missed" means. Withholding past days from the
pairing, as the placer used to, is what put a paid ✓ on the day a payment was
*due* while the day it actually moved on read empty: a rent expected on 1 June
and paid on 3 June drew its entry on the 1st, over a balance that did not move,
and nothing on the 3rd, where it did.

Nothing is lost to the reader when an estimate steps aside. The surviving entry
keeps the **series' name and its `seriesId`** alongside the row's own date,
amount and `transactionId`, so the panel offers both drill-throughs and the
recurring payment is still named the way the reader named it. What the ledger
supplies is what can contradict the balance line — the day, the figure, and
whether it happened at all — and on all three the ledger wins.

`CalendarQuery::buildDayEntries()` carries the other half: an entry with a
`transactionId` is settled by its own existence and never goes through the
occurrence match. Running it through anyway marked every plain imported row on a
past day *missed*, an amber `!` beside money that demonstrably moved.

Today sits on the same side as the past for placement and as the future for
verdicts. It is neither: it gets no paid-or-missed verdict, while its balance
line — which comes from the projection, whose anchor is the ledger's balance as
of today — already counts every row posted on it. Excluded from both passes,
today's cell stepped down €1,450.00 over a panel reading "No payments on this
day.", which is the exact reading this placer exists to prevent.

## The empty state

`/calendar` shows its "no upcoming payments" card when the reader has **nothing
the calendar could ever draw** — not when the month on screen happens to be
quiet. The two are different questions, and keying the card on the visible grid
made the calendar tell a reader with a full ledger and an approved rent to
"connect an account or approve a recurring series" on every month the
projection did not reach, including every month in the past.
`CalendarQuery::hasProjectableEntries()` answers it as an existence check over
the two series states the projection walks **plus** any booked row dated ahead
through `CalendarMonthWindow::lastDrawableDay()` — the last *cell* the grid
draws, not the last day of the ceiling month. Bounded at that month's own end
it went blind to the strip's lead-out days on 304 of 365 today-values, and drew
a booked charge under a banner reading "No upcoming payments". Asking about
approved series alone told a reader whose ledger held a dated rent, and whose
grid was about to draw it, that they had no upcoming payments.

## Forward reach

The calendar draws no cell the projection behind it cannot fill.
`CalendarMonthWindow::PROJECTION` names the horizon the balance line is
supplied from — `ForecastHorizon::OneYear`, the same value
`DailyBalanceAggregator` asks `ForecastQuery` for — and
`CalendarMonthWindow::ceilingMonth()` walks forward from today's month only
while the **whole** Mon–Sun strip of the next month still lands inside it. The
ceiling is therefore derived, and usually eleven months rather than twelve.

Counted as a fixed twelve months off the first of the month and then extended
to a week boundary, the grid ran past the last forecast point on **364 of 365**
today-values — worst case 37 cells. A cell with no forecast bucket falls to
`isComputing: true`, renders `—` and sets `isComputingAny`, so the aria-live
strip read "Projection updating…" with no run in flight to finish it, which is
precisely the claim `ForecastQuery` refuses to make for a device that has never
computed one. `APairOfWindowsThatMustAgreeHasOneDefinitionArchTest` sweeps a
year of today-values over both directions: the grid may not overrun the
projection, and the ceiling may not stop short of a month the projection wholly
covers.

The strip's own boundaries — and its column headings, and the date field's
picker — come from `Modules\Core\Public\Support\WeekStart`, so a change to
the day a week opens on moves all three or none.

## Balance aggregation

Each balance-included account's forecast is fetched exactly once (not
re-fetched per grid day) and summed per `(date, currency)` bucket, then each
currency bucket is FX-converted to the user's base reporting currency before
summing across accounts — minor units are never added across currencies. The
conversion goes through `CrossCurrencyTotal`, so a currency the rate table
cannot reach is left out of the day's figure rather than added at one to one,
and every currency the month touches — forecast points, today's anchor and the
past-day overlay alike — is priced once for the whole render. The service
behind a conversion reads the entire `exchange_rates` table on every call, and
a 365-day horizon asked it for the same pair once per day.
Internal-transfer entries appear on the grid but net to zero in the combined
balance automatically, because each account's own forecast already includes
both legs of a self-transfer.

**Past days** never depend on a forecast run: the balance line for any day
before today is each account's starting balance plus the real cumulative sum
of `transactions.settled_amount_minor` across the balance accounts (bucketed
per account currency, FX-converted the same way), carried forward day-by-day
from a base sum computed once for everything before the visible grid. Past-day
balances therefore stay known even while a projection is still computing.
Today is the join: it comes from the projection, whose opening figure is
Ledger's balance as of today over the same rows
([balance anchor resolution](../forecasting/architecture.md#balance-anchor-resolution)),
so yesterday and today differ by exactly the rows posted today. While the
anchor was a statement closing balance months old the line stepped on today
instead — €3,020 on 22 August dropping to €2,085 on the 23rd.

The starting balance and the row sums open **different** buckets, and the
difference is deliberate. The baseline comes from Ledger's
`AccountStartingBalanceQuery`
([the baseline every balance starts from](../ledger/architecture.md#accountstartingbalancequery--the-baseline-every-balance-starts-from))
and opens the line of the ACCOUNT's `default_currency`, because that is what
the account is denominated in. Each row instead opens the line of its own
`settled_currency` and adds `settled_amount_minor`, the figure the ACCOUNT was
debited, on exactly the footing `AccountBalanceQuery` uses
([caveats shared by all four methods](../ledger/architecture.md#accountbalancequery--caveats-shared-by-all-four-methods)).
Grouping the rows on the account's currency instead dropped a Revolut
account's dollar rows into its euro line, which is the sum an account balance
must never take. Summing the native `amount_minor` re-derived each foreign row
from today's rate rather than the bank's rate on the day, so a euro account
holding USD rows drew yesterday €1.46 away from the same account's ledger
balance and the line stepped at today on a curve that is continuous by
construction.

Both transaction reads join `accounts` and apply the reader's
`AT_OR_AFTER_BASELINE_SQL` lower bound: a row posted before an account's
`starting_balance_date` is history the baseline already holds, and counting it
here would draw the line twice as high as the money on the account. The bound
reaches the baseline's own currency only, exactly as `AccountBalanceQuery`
does: a dollar row on a euro-denominated account is not inside a euro
baseline, so it counts wherever it is posted. Bounding it too dropped it
outright, and the line read EUR3,509.85 for an account the accounts page
reported as EUR3,509.85 and -USD221.00.

**Start-of-day chaining**: a day's start-of-day balance is the prior grid
day's end-of-day balance, chained forward — but only when that prior value was
itself known (not a computing sentinel, and not a figure a missing rate
censored). A day following a data-less day reports SoD unknown ("—"), never a
fabricated 0. Today's SoD falls back to the forecast's own today-anchor sum,
since yesterday (a past day) carries no forecast point of its own.

The chain is **seeded**, not started empty. The grid's first cell has no prior
grid day, and it reported "Start of day —" for a figure the aggregator was
holding: `cumulativeBalanceBefore()` computes the balance as of the instant
before `gridStart` in order to seed the past-day overlay's running total, and
that value *is* the first day's opening balance. `buildBalanceMap()` returns it
as `gridStartOpening` and `CalendarQuery` seeds `$prevEod` with it. The null
that remains is the honest one: `gridStartOpening` is null whenever the actuals
overlay does not reach `gridStart` — a grid that begins after today is
projection all the way down, and a projection carries points for its own days,
not an opening balance for the day before the first one. It is also withheld
when the opening balance could not be fully priced, on the same terms as any
other censored figure.

**A currency with no rate is named, never rounded away.** `CrossCurrencyTotal`
returns the codes it could not price alongside the converted figure, and
`DayBalanceDto` carries both per grid day. The page states those codes once
above the grid — the same code is missing on every day it appears on, so
forty-two copies of the sentence would not be forty-two facts — and the day
panel repeats them under the balance rows it qualifies.

A day still prints the part it *could* price: a reader holding euros and pesos
keeps the euro line rather than losing the whole forecast to one account, which
is what every other money surface here does. `hasFigure` is the line between
the two — false only when **every** bucket the day holds was unpriced, so
`minor` is no balance rather than a small one, and the cell reads `—` like a
computing day instead of the fabricated `€0` that used to fill all forty-two
corners of an ARS-only grid. The same flag gates the chain: a day with no
priced bucket leaves the next day's start-of-day unknown.

The risk tint reads `DayBalanceDto::isNegative` rather than the converted
figure: a balance overdrawn only in an unpriced currency converts to exactly
nothing, so an account €0 by arithmetic and overdrawn in fact never tinted
rose. That flag is true when the converted part is negative **or** when any
bucket the rate table could not reach is itself negative — being overdrawn in
pesos is an overdraft whatever the pesos are worth.

## Past-day paid/missed matching

For a past grid day, each entry that is still only an *expectation* — no
`transactionId` on it, because no booked row retired it — is marked paid when a
`recurring_series_occurrences` row for that series falls within a tolerance
window of the expected date, missed otherwise. The window is clamped per
cadence: sub-monthly cadences (daily, weekly) use half their interval so one
observed payment can never simultaneously mark multiple adjacent expected
entries paid (an unclamped ±7-day window over 7-day weekly spacing could mark
up to three occurrences paid from a single payment); monthly-and-longer
cadences keep the full 7-day cap, since their spacing comfortably exceeds
twice it.

The occurrence map reaches occurrences whose transaction sits outside the grid,
or outside the account baseline, or was never linked to the series — which is
the remaining work for this pass now that
[the ledger's own rows are on every past cell](#behind-today-the-ledger-is-the-entry-list).

## Account-preference resolution

Two independent per-account controls exist: which accounts' entries appear
on the grid, and which accounts are summed for the balance line. Both are
resolved with an explicit **null-vs-empty-array** distinction:

- `null` (never configured) resolves to a default — all accounts for entry
  visibility, and `AccountKind::spendableValues()` for the balance line.
- An explicit array — including the empty array — is taken literally, so
  "every account deselected" is representable and distinguishable from
  "never configured."

The spendable kinds are `bank`, `cash` and `paypal`. Three kinds are outside
them, and `AccountKind` — not this module — says which and why, because the
balance line, `Forecasting::NetWorthQuery` and `Reports::NetWorthSeriesQuery`
each kept a list of their own until one of them drifted:

| Kind | Why the balance does not sum it |
|---|---|
| `ics_card` | The card's liability reaches the balance as the bulk-iDEAL settlement leg into the funding account |
| `google_play` | Google Play publishes receipts and no statement, and `GooglePlayReceiptMatcher` writes only charges — the account is never credited, so its balance is a running spend tally rather than a holding, and each charge on it was paid by a funding account the balance already sums |
| `paypal_funding` | Nothing writes an account of this kind at all. It is the account-side vestige of `ChainLinkKind::PaypalFunding`, which links two transactions that both already exist |

`ics_card` is the one exclusion the net-worth pair does **not** share, and that
is correct rather than drift: net worth asks what the reader holds *and owes*,
so a debt belongs in it, while a forward balance line that summed the card would
subtract the settlement once when the charge posted and again when the bank paid
it. The other two are mirrors of a movement counted elsewhere, and no total may
count a mirror — see
[which kinds hold money](../ledger/architecture.md#accountkind--which-kinds-hold-money).

`paypal_funding` was in this list, and only this list, from the first commit of
`CalendarQuery`: the list was seeded from a design note that named
"provider-code account kinds" and mixed the chain-link kinds `ics` and
`ics_bulk_settle` in among real ones. On a bank-funds-wallet fixture the balance
line read €2,000.00 against a net worth of €1,445.92 — the €554.08 of funding
handed back to a reader whose bank had already paid it out.

`CalendarPage::mount()` materializes both defaults into explicit account-id
lists on first load (reading any persisted `user_preferences` row first), so
the popover checkboxes, the toggle actions, and `CalendarQuery` always agree
on the same explicit set. Every caller-supplied account-id array is
intersected against the user's own `accounts` rows before use, both on the
read side (`CalendarQuery`) and again before persisting a preference write —
a client-controlled Livewire array property is never trusted at either
boundary.

### Entries the balance line does not reach

The two halves of a day panel come from those two different sets, and a reader
reads a start figure, a list of payments and an end figure as arithmetic. So
every day names the accounts its own entries sit on that its balance figures do
not sum — `CalendarDayDto::$uncountedAccounts`, rendered under the end-of-day
line through `calendar::messages.balance.not_counted`, and rolled up once above
the month grid by `CalendarPage::uncountedAcross()`. It is the same disclosure
`unconvertedCurrencies` makes for a currency with no rate, and it is placed the
same way: per day in the panel, once above the grid, because an account outside
the balance set is outside it on every cell it appears on.

A day whose balance is unknown — computing, or with no balance source at all —
names nothing. It has stated no figure, so there is no arithmetic to disown, and
naming every account there would be the loudest possible answer to a panel
already reading "—".

## Security

- Every DB query in `CalendarQuery` scopes on `user_id` — no query can read
  another user's series, occurrences, or transactions.
- `month`/`year` URL properties are clamped to **both** bounds — a
  `CalendarMonthWindow::ceilingMonth()` forward ceiling and a
  `CalendarMonthWindow::HISTORY_MONTHS` backward floor — when resolving the display
  month and again on `nextMonth()`/`prevMonth()`, so a tampered
  `?year=&month=` query string cannot render or navigate outside them. The
  backward direction had no floor at all: `prevMonth()` stepped freely and the
  display resolver then rejected the year it produced (a bare `>= 2000`) and
  fell back to *today's* year, so paging back far enough teleported the reader
  to the current year instead of stopping. The floor now clamps to itself, and
  the toolbar's "previous month" control reports it the way `atCeiling` does
  for the forward one.
- Day selection validates the date string shape and calendar validity
  (`checkdate()`) before parsing, since a shape-only regex still admits
  impossible dates that would otherwise throw from the date parser on a
  trivially tampered `wire:click` payload. It then bounds the date to the
  rendered grid — wider than the display month, and no wider.
