# Rebasing a statement fixture onto today

The shipped statement fixtures under `tests/fixtures/` carry absolute dates. The
gold ASN CSV covers February to April 2026 and every other file in that
directory sits in the same quarter. That is deliberate — those bytes are
themselves test data — but it means importing one by hand into a build you are
testing lands 229 rows that almost nothing in the product will show you.

Every time-relative surface is keyed to the clock, and each of them is right to
show nothing when the rows are months outside its window:

| Surface | Window |
|---|---|
| `ExpenseSeriesDetector`, `IncomeSeriesDetector` | two months, or `users.recurring_detection_window_months` |
| The transactions list, before "Show full history" | ninety days |
| `CounterpartyIndexQuery` | eleven months of sparkline, one year of activity |
| `CalendarMonthWindow` | ten years back (`HISTORY_MONTHS`), one year forward (`PROJECTION`) |
| The dashboard's period roll-ups | the reader's own period |

`fixture:rebase` writes a date-shifted **copy** so those windows have something
to read. It never edits the file you point it at.

```
php artisan fixture:rebase asn-sample-1.csv
php artisan fixture:rebase tests/fixtures/asn-mt940-sample-1.sta --out=/tmp/statement.sta
php artisan fixture:rebase asn-camt053-sample-1.xml --months=6
php artisan fixture:rebase asn-sample-1.csv --anchor=2026-12-01
```

A bare filename resolves under `tests/fixtures/`. Without `--out` the copy lands
in `storage/app/rebased/` under the same basename, and the command prints the
absolute path — upload that.

The command re-reads its own output through the adapter that will read it on
import and compares both the row count and where the read stopped. A shift that
produced a file the parser cannot line up fails here, not on the device.

## Why whole months

The shift is a whole number of calendar months, never a number of days.

A monthly series is recognised by its day-of-month. Three charges on the 24th of
three consecutive months are a series; the same three charges walked forward by
119 days are three unrelated charges on the 22nd, the 21st and the 20th. Shifting
by whole months is the only shift that leaves the shape intact.

Months are added with `addMonthsNoOverflow`, so a 31 March lands on 30 April
rather than rolling forward to 1 May. Overflow moves a row into the *next*
month and collapses two statement months into one, which is the same failure
`DemoTransactionsSeeder` documents for its own window.

The default lands the newest row **in the anchor's calendar month**, not on or
before the anchor date. Stepping back a month instead would drop the older of
the two in-window months for any series falling early in the month, and the
detector's window is only two months wide to begin with. The price is that the
last few rows can be dated a few weeks ahead of today — no window the product
reads is bounded at the top, so nothing hides because of it. Use `--anchor` or
`--months` when you want something else.

## CSV

Driven by `CsvPresetRegistry`, so every layout the app can import is covered by
one implementation: the ASN positional export and the N26, Revolut and ING
header-based ones.

A cell is rewritten when it is *entirely* a date in that preset's own date
format, checked by a round-trip rather than a permissive parse. That is what
keeps a reference number, a running balance and a date written inside a free-text
description out of the rewrite — and what leaves the deliberately unparseable
`geen-datum` sentinel in `asn-partial-failure.csv` exactly where it is.

The consequence worth knowing: the ASN export carries three date columns per
row — `Datum`, `Verwerkingsdatum` and `Valutadatum` — and only two of them are
named on the preset. All three are shifted, because all three are dates, and
each is shifted independently so the rows where a value date sits four days
before its posted date still do.

Line terminators, quoting and every non-date byte survive unchanged.

## camt.053

`Dt`, `DtTm`, `CreDtTm`, `FrDtTm` and `ToDtTm` bodies are shifted; the shift is
derived from the entry dates alone, because the header creation stamp and the
closing balance both sit after the last entry and anchoring on either lands the
entries a month short.

A creation stamp carries a UTC offset. A whole-month shift can cross a
summer-time boundary, so an offset that matches the export's own zone at the
original instant is recomputed for the new one — otherwise a `+02:00` left on a
November date moves the recorded instant by an hour once the adapter normalises
it. Fractional seconds are preserved byte for byte; the parser truncates
nanoseconds and re-rendering the timestamp would silently drop three digits.

## MT940

The balance tags and the `:61:` statement lines are shifted.

Both dates on a `:61:` line are read through
`Modules\Ingestion\Public\Banking\SwiftDate`, the same seam
`Mt940Tag61Parser` reads them through — not a second copy of the rule that
happens to agree. A rebased fixture is only ever a parser input, so a rule the
rebaser owns privately is a rule that can date the file differently from the
import it was written for. It did: read privately, a `:61:991231` line was
dated 2099-12-31 by the rebaser and 1999-12-31 by the parser, and every
two-digit year from 77 upward was a century out.

A `:61:` entry date carries a month and a day but no year — the reader infers
it from the value date. The rebaser rebuilds the full entry date, shifts value
and entry dates together, and re-emits the yearless form, so a shift that
crosses a New Year does not move the entry date twelve months away from the
value date it belongs to.

## Fixtures that must not be rebased

Rebasing writes a copy, so nothing here breaks by running the command. These are
the cases where a rebased copy is the wrong file to hand to a test, or where two
files have to move together.

| Fixture | Why |
|---|---|
| `unrecognised-headers.csv` | Its whole purpose is to match no preset. The command refuses it, which is the correct answer. |
| `asn-partial-failure.csv` | The `geen-datum` row is the case under test. A copy keeps it, but the file is only useful for the failure path. |
| `asn-cross-format/february.csv` + `february.camt053.xml` | One February described twice. They must receive the *same* shift or the cross-format fingerprints stop matching. Rebase both, and pass `--months` if you want certainty rather than relying on both deriving the same anchor. |
| `asn-paypal-funding-2026-04-05.csv` | Pairs against a PayPal activity export in a different dialect. The PayPal side has no rebaser, so shifting only the ASN side moves the legs outside the pairing window. |
| Any file a test names by path | The suite asserts on specific rows, IBANs, amounts and the file's own `sha256`. Rebasing in place breaks duplicate detection and every fingerprint assertion. Point the command at `--out` and leave the checked-in bytes alone. |
