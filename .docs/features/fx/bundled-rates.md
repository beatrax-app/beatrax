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
  `ExchangeRateService::STALE_DAYS_THRESHOLD` marks the figures stale and the
  reader is told the rates are old rather than shown a false "today".

Opting into online fetching still layers fresher rows on top; nothing about the
consent gate changes.
