# `Calendar` — architecture

The `Calendar` module renders `/calendar`, a month-grid cash-flow surface:
each day shows the payments expected on it — recurring-series occurrences and
the rows the ledger already holds dated ahead — plus a start-of-day/end-of-day
projected balance line, so the user can see upcoming fixed payments and the
funding position they land against. `CalendarQuery` is
the single backend brain — the Livewire page is a thin renderer over its
output.

## Module boundary

- **Public/Dto** — `CalendarDayDto` (one grid day: date, today/past flags,
  risk flag, SoD/EoD balances, computing sentinel, entries) and
  `CalendarEntryDto` (one expected payment: amount, direction, account,
  counterparty, paid/missed/approximate flags). An entry carries either a
  `seriesId` or a `transactionId` — a cadence predicted it, or the ledger has
  already booked it — and the panel drills through to whichever it has.
  Read-only value objects; the module never writes.
- **Internal/Services/CalendarQuery** — the sole composition service.
  Registered as a stateless singleton (all state flows through `forMonth()`
  arguments).
- **Internal/Http/Livewire/CalendarPage** — the `/calendar` page: month
  navigation, the Accounts popover, day selection, and account-preference
  persistence to `user_preferences`.

## The grid edge

A month is rendered as a Mon–Sun strip, so the grid runs from the Monday on or
before the 1st to the Sunday on or after the last day — up to six days of the
previous month and six of the next. Those lead-in and lead-out cells are
ordinary cells: they draw a balance corner off the forecast, they carry
`tabindex="0"` and a `wire:click`, and only their day number is dimmed.

`CalendarQuery::gridRange()` is the single answer to where that strip starts and
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

## Booked rows dated ahead

`SeriesEntryPlacer` answers what a cadence expects. `BookedEntryPlacer` answers
what the ledger has already booked: a row whose `posted_at` is still to come is
a known, dated payment, and leaving it off the grid left a day panel reading
"No payments on this day." above a balance line that stepped down €1,450.00.
Both placers feed one entry map, and the same supersession rule the
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
the grid's first day rather than the month's.

Booked entries are placed **only ahead of today**. A past day already draws its
balance from the transactions themselves and gives its entries a paid-or-missed
verdict, so a booked row behind today is a payment that pass has covered — and
the supersession is likewise skipped on those days, because the tolerance
window reaches a week back and would otherwise silently remove an entry the
reader is owed a verdict on. Those days are withheld from the pairing itself
rather than filtered afterwards: an entry that cannot be removed must not spend
the booked row that would otherwise have retired a day ahead.

## The empty state

`/calendar` shows its "no upcoming payments" card when the reader has **nothing
the calendar could ever draw** — not when the month on screen happens to be
quiet. The two are different questions, and keying the card on the visible grid
made the calendar tell a reader with a full ledger and an approved rent to
"connect an account or approve a recurring series" on every month the
projection did not reach, including every month in the past.
`CalendarQuery::hasProjectableEntries()` answers it as an existence check over
the two series states the projection walks **plus** any booked row dated ahead
within `CalendarQuery::HORIZON_MONTHS` — the same reach the page clamps its
month navigation to, so a reader is never told there is nothing on a horizon
they cannot open. Asking about approved series alone told a reader whose ledger
held a dated rent, and whose grid was about to draw it, that they had no
upcoming payments.

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
here would draw the line twice as high as the money on the account.

**Start-of-day chaining**: a day's start-of-day balance is the prior grid
day's end-of-day balance, chained forward — but only when that prior value was
itself known (not a computing sentinel). A day following a data-less day
reports SoD unknown ("—"), never a fabricated 0. Today's SoD falls back to
the forecast's own today-anchor sum, since yesterday (a past day) carries no
forecast point of its own.

## Past-day paid/missed matching

For a past grid day, each expected entry is marked paid when a
`recurring_series_occurrences` row for that series falls within a tolerance
window of the expected date, missed otherwise. The window is clamped per
cadence: sub-monthly cadences (daily, weekly) use half their interval so one
observed payment can never simultaneously mark multiple adjacent expected
entries paid (an unclamped ±7-day window over 7-day weekly spacing could mark
up to three occurrences paid from a single payment); monthly-and-longer
cadences keep the full 7-day cap, since their spacing comfortably exceeds
twice it.

## Account-preference resolution

Two independent per-account controls exist: which accounts' entries appear
on the grid, and which accounts are summed for the balance line. Both are
resolved with an explicit **null-vs-empty-array** distinction:

- `null` (never configured) resolves to a default — all accounts for entry
  visibility, the "spendable" kind set (checking/savings/cash/PayPal; ICS
  credit-card family excluded, since its liability already shows up via the
  bulk-iDEAL settlement leg into the funding account) for the balance line.
- An explicit array — including the empty array — is taken literally, so
  "every account deselected" is representable and distinguishable from
  "never configured."

`CalendarPage::mount()` materializes both defaults into explicit account-id
lists on first load (reading any persisted `user_preferences` row first), so
the popover checkboxes, the toggle actions, and `CalendarQuery` always agree
on the same explicit set. Every caller-supplied account-id array is
intersected against the user's own `accounts` rows before use, both on the
read side (`CalendarQuery`) and again before persisting a preference write —
a client-controlled Livewire array property is never trusted at either
boundary.

## Security

- Every DB query in `CalendarQuery` scopes on `user_id` — no query can read
  another user's series, occurrences, or transactions.
- `month`/`year` URL properties are clamped to **both** bounds — a
  `CalendarQuery::HORIZON_MONTHS` forward ceiling and a
  `CalendarQuery::HISTORY_MONTHS` backward floor — when resolving the display
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
