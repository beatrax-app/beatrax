# `Calendar` — architecture

The `Calendar` module renders `/calendar`, a month-grid cash-flow surface:
each day shows the recurring-series entries expected on it plus a
start-of-day/end-of-day projected balance line, so the user can see upcoming
fixed payments and the funding position they land against. `CalendarQuery` is
the single backend brain — the Livewire page is a thin renderer over its
output.

## Module boundary

- **Public/Dto** — `CalendarDayDto` (one grid day: date, today/past flags,
  risk flag, SoD/EoD balances, computing sentinel, entries) and
  `CalendarEntryDto` (one recurring-series occurrence: amount, direction,
  account, counterparty, paid/missed/approximate flags). Read-only value
  objects; the module never writes.
- **Internal/Services/CalendarQuery** — the sole composition service.
  Registered as a stateless singleton (all state flows through `forMonth()`
  arguments).
- **Internal/Http/Livewire/CalendarPage** — the `/calendar` page: month
  navigation, the Accounts popover, day selection, and account-preference
  persistence to `user_preferences`.

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
row, or `created_at` as a fallback, minus a small slack (`MatchWindow::DAYS`)
so a payment expected slightly before its first observed occurrence is not
dropped along with genuine pre-inception phantoms. Without this floor, every
history month before a series existed would render a phantom "expected — not
found" entry.

Metadata resolution (counterparty, account name) is fully batched — the
service issues a bounded number of queries per render regardless of how many
series are approved, resolving counterparty identity primarily through
occurrence → transaction → counterparty links and falling back to a
`cluster_counterparty_key` ↔ counterparty-slug match for series with no
linked occurrence yet. That fallback only ever reaches a single-token
merchant, and reaches nothing at all for a user with at-rest encryption
enabled: the key is a 64-hex blind index by then, and no slug can equal
one.

## Balance aggregation

Each balance-included account's forecast is fetched exactly once (not
re-fetched per grid day) and summed per `(date, currency)` bucket, then each
currency bucket is FX-converted to the user's base reporting currency before
summing across accounts — minor units are never added across currencies.
Internal-transfer entries appear on the grid but net to zero in the combined
balance automatically, because each account's own forecast already includes
both legs of a self-transfer.

**Past days** never depend on a forecast run: the balance line for any day
before today is each account's starting balance plus the real cumulative sum
of `transactions.amount_minor` across the balance accounts (bucketed per
currency, FX-converted the same way), carried forward day-by-day from a base
sum computed once for everything before the visible grid. This mirrors the
forecast's own anchor semantics and means past-day balances stay known even
while a projection is still computing.

The starting balance comes from Ledger's `AccountStartingBalanceQuery`
([the baseline every balance starts from](../ledger/architecture.md#accountstartingbalancequery--the-baseline-every-balance-starts-from)),
and it lands in the bucket of the ACCOUNT's `default_currency`, not the
currency its transactions happen to carry. Both transaction reads join
`accounts` and apply the reader's `AT_OR_AFTER_BASELINE_SQL` lower bound: a
row posted before an account's `starting_balance_date` is history the
baseline already holds, and counting it here would draw the line twice as
high as the money on the account.

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
- `month`/`year` URL properties are clamped to a valid range and to a
  12-month forward forecast ceiling both when resolving the display month
  and on `nextMonth()`, so a tampered `?year=&month=` query string cannot
  render or navigate past the forecast horizon.
- Day selection validates the date string shape and calendar validity
  (`checkdate()`) before parsing, since a shape-only regex still admits
  impossible dates that would otherwise throw from the date parser on a
  trivially tampered `wire:click` payload.
