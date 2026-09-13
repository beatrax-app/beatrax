# Bundled exchange rates

`Modules/FX/Resources/rates-snapshot.json` ships with the app: one ECB-shaped
set of EUR-based rates, dated, with no network access needed to read it. It is
what makes the reporting-currency setting work on an install that never goes
online.

## Why a migration loads it

`FetchFxRatesJob`'s first act is to return when `users.fx_online_enabled` is
false — correctly, because that flag is the consent gate for the app's only
outbound traffic. It was the one writer to `exchange_rates`, and
`BundledSnapshotProvider` sits in the same registry as the two network
providers, so the refusal took the offline provider down with the online ones
and an install that never went online held no rates at all.

The consequence was silent. `ExchangeRateService` returns
`ConversionResult::noRate()` on an empty rate set — the amount comes back in the
currency it arrived in — so a reader who chose USD as their reporting currency
saw every total keep its euro sign and its euro value, while Settings told them
their rates were covered. `core::settings.exchange_rates.online_off` still says
so — "The rates already on this device stay in use, with the bundled snapshot
as the fallback. No data leaves this device." — and the migration below is what
makes the fallback half of that sentence true.

`2026_08_23_000010_seed_bundled_exchange_rates` writes the snapshot into
`exchange_rates` through `Internal/Services/SeedBundledExchangeRates`, at
install and on upgrade; it is the table's second writer. The rows carry
`source = 'bundled'`, which is part of the table's unique key, so:

- a live provider's row for the same day is a separate row and is never
  overwritten;
- re-running the migration rewrites only its own rows;
- the snapshot's own date is used, never `now()`, so
  `RateFreshness::STALE_DAYS_THRESHOLD` marks the figures stale and the
  reader is told the rates are old rather than shown a false "today".

Opting into online fetching still layers fresher rows on top; nothing about the
consent gate changes.

## How old the file may be when a build is cut

The staleness mark tells a reader the figures are old. It does not tell them
by how much, and until `RefuseToShipStaleBundledRates` there was nothing in the
tree that read this file's own date at all: it shipped dated `2026-06-05` for a
hundred days, thirty-three times past `RateFreshness::STALE_DAYS_THRESHOLD`,
and every cross-currency roll-up on a stock install was priced at June rates.

`BundledSnapshot::SHIP_WITHIN_DAYS = 90` is the bound, and the listener throws
out of `native:build`, `native:package` and `mobile:package-android` when the
snapshot is past it, unreadable, or carries a date that is not a `Y-m-d` day.
`native:run` is deliberately **not** in that list — a developer iterating
locally sees the same staleness mark a reader does, and blocking the loop on a
network fetch would buy nothing.

Ninety days is the ECB's own reading of how far back a rate is still recent:
`eurofxref-hist-90d.xml` is the short-history feed it publishes beside the
daily one. The cost of the bound was measured against the feed rather than
assumed — comparing the shipped file to the day it was replaced:

| Age of the snapshot | Median pair has moved | Worst pair has moved |
|--------------------:|----------------------:|---------------------:|
| 30 days | 0.54% | 4.86% (KRW) |
| 60 days | 1.46% | 8.81% (KRW) |
| 90 days | 1.24% | 11.44% (KRW) |
| 100 days, as shipped | 5.29% | 31.35% (TRY) |

So the bound trades a median error of roughly a percent for a release that is
never blocked by a gap shorter than a quarter — longer than any gap between
tags this repo has actually had.

`scripts/refresh_bundled_rates.php` is the other half, and the refusal names
it: it reads the ECB daily feed, writes this file, and prints what changed.

### A currency the feed stopped quoting

The refresh script never shrinks the currency set on its own. Bulgaria's euro
entry took `BGN` out of the ECB's daily feed, so a straight copy would have
dropped it — and with it a currency `currencies` is seeded with, both pickers
offer, and `currency-names.json` names in twenty-six languages. The script
carries such a code forward at the figure it already held and names it on
stdout, because removing one is a product decision rather than a consequence
of running a script. `BGN` is the one code in this file the daily feed does
not price.

## The snapshot decides which currencies can be chosen

The thirty codes it quotes, plus the euro it quotes them against, are exactly
the rows `currencies` is seeded with and exactly the currencies the two pickers
offer. A reporting currency the snapshot cannot price is one every roll-up
would have to leave out, so the set is derived rather than maintained beside
it: replacing this file means re-running `scripts/generate_currency_names.php`,
and [`ACurrencyIsNamedInEveryLocaleTest`](../ledger/currency-names.md) fails
while the two disagree.
