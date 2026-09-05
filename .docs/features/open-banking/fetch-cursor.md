# The fetch cursor

Every incremental fetch asks the aggregator for a date window. Deciding where
that window opens is the whole of "did the reader lose money without being told",
so this page holds the three decisions that answer it: what the cursor **is**,
how far back of it every window **re-reads**, and what happens when the walk over
the bank's pages **does not reach the end**.

## The cursor is what was committed, not what was fetched

`open_banking_connections` carries two timestamps that used to be one:

| Column | Question it answers | Written by |
| --- | --- | --- |
| `last_successful_sync_at` | "How current is my data?" — the freshness signal the transparency panel shows | every attempt that fetched without failing |
| `fetched_through_at` | "Which of the bank's dates have been read into the ledger?" — the cursor the next window resumes from | only an attempt that **committed** the rows it fetched |

One column could not be both. "Sync now" runs `OpenBankingFetchService::preview()`,
which stages an import run for the reader to confirm and writes **no ledger row**.
Advancing a shared cursor there declared a page read that nothing had written:
three transactions came back, one landed (the only one still inside the next
window), and the connection reported `ok`. The two behind the cursor were never
offered by anybody again — silent, permanent loss under a green status.

The fix is structural rather than an ordering of two statements, because an
ordering fix dies the moment somebody adds an early return:

- `preview()` returns `OpenBankingFetchResult::previewed(...)`, whose
  `committedThrough` is **null by construction** — there is no argument to pass.
- `fetchAndConfirm()` returns `OpenBankingFetchResult::committed(..., $window->dateTo)`.
- `OpenBankingSyncRunner` writes `fetched_through_at` only from a non-null
  `committedThrough`. It never reads the window, and never asks the adapter what
  it fetched.

So the cursor advances **from what the write returned**. A confirm that refuses
(`ImportNotConfirmableException` — the fetched rows name an account the ledger
does not have) throws before a result exists, and the window stays open.

The reader's own "Sync now" therefore never moves the cursor; the daily
scheduled sync does. Re-fetching a window that has already been previewed costs
one fingerprint lookup per row and lands nothing twice.

## Why the overlap is 14 days

A window that opens exactly where the last one closed assumes the bank never
posts anything behind the dates it has already answered for. Banks do, routinely:

- **Weekend and holiday settlement.** A Friday-evening card payment books on the
  next business day. Around the Christmas–New Year cluster, or a Good
  Friday–Easter Monday chain, that gap runs to four or five days.
- **Delayed merchant capture.** Fuel stations, hotels, airlines and car hire
  authorise on the day and capture up to a week or ten days later.
- **Reversals and disputed charges.** A refund or an R-transaction reverses an
  entry the reader has already seen, with a value date pointing back at it.

The connector cannot control which of `booking_date` or `value_date` a given
ASPSP filters `date_from`/`date_to` on — the PSD2 standard leaves it to the
provider, and the two disagree exactly in the cases above. The only safe
assumption is that a row may surface behind a date the connector has already
read past.

**14 days** covers the longest ordinary holiday-settlement chain plus a full
merchant-capture window, with margin. The trade is asymmetric and the direction
is not close:

- A re-fetched row costs one indexed fingerprint lookup and is dropped as a
  duplicate. Fourteen days of an ordinary retail account is tens of rows.
- A missed row is money that never appears in the ledger, in a balance, in a
  budget or in a forecast, and no later window will ever ask for it.

It is not wider because the window is also what the page walk has to get through
in one run (below), and re-paging a month every morning trades a real cost for a
transaction that does not exist.

## The cursor is a date, not a clock reading

`fetched_through_at` holds the **end of the window whose rows were committed**
(`FetchWindow::dateTo`), not the wall-clock instant the sync ran at. The two
coincide on a machine that syncs daily and diverge on every machine that does
not: a desktop that was closed for three weeks has a fetch time three weeks old
and a *data* boundary that is exactly where its last committed window ended.

Expressing the overlap against the data boundary means the 14 days are 14 days of
the bank's own dates, which is what the backdating happens in. Against a fetch
time they would have been 14 days of the reader's uptime, which is not a unit
anything at the bank is measured in.

## Bounding the page walk

`EnableBankingSourceAdapter::fetch()` follows `continuation_key` until the
provider stops handing one back. Unbounded, a provider that always has one more
page spins the walk until something unrelated fails — measured at **193,797 rows
from 193,797 HTTP round trips in 10.0 seconds, and still going**.

The walk now stops on three bounds, checked in this order:

| Stop | Bound | What it catches |
| --- | --- | --- |
| `RepeatedCursor` | a `continuation_key` already served in this walk | the unambiguous no-progress case — caught on the second page rather than the hundredth |
| `PageCap` | 100 pages | many small pages |
| `RowCap` | 25,000 scanned rows | few enormous pages |

The numbers are sized against the widest window this connector ever asks for,
the 90-day initial lookback. 25,000 rows over 90 days is ~275 a day, an order of
magnitude past the heaviest personal account; 100 pages covers that at any page
size an aggregator realistically returns, and at a pathological page size of one
row it ends the walk in 100 round trips rather than never.

**Hitting a bound is an outcome, not a silent truncation.** The generator returns
a `FetchWalk` naming the `FetchStop`, and everything downstream reads the ending
from it rather than inferring it from the rows that arrived:

- `last_attempt_status` is recorded as `truncated`, its own
  `SyncAttemptStatus` case rather than a success or an error, and the
  transparency panel names it "stopped early" rather than "error".
- **Neither `last_successful_sync_at` nor `fetched_through_at` moves.** The rows
  that did arrive are kept — they are real — but the connection makes no claim
  to have read the window, so the next sync opens on the same dates.
- "Sync now" replaces the count-of-new-rows flash with a message saying the run
  stopped early and that nothing was recorded as synced.

A connection that truncates repeatedly therefore stays visibly stuck rather than
quietly advancing over pages it never asked for. That is the intended direction:
the alternative — advancing over a partial answer — is the defect this page
exists to prevent, and pages are not guaranteed to arrive in date order, so
there is no partial boundary that could be advanced to safely.

## See also

- [`architecture.md`](architecture.md) — the module map and the fetch/confirm split.
- [Consent window](consent-window.md) — the other boundary a fetch re-checks on pickup.
