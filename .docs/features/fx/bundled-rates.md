# Bundled exchange rates

`Modules/FX/Resources/rates-snapshot.json` ships with the app: one ECB-shaped
set of EUR-based rates, dated, with no network access needed to read it. It is
what makes the reporting-currency setting work on an install that never goes
online.

## Why a migration loads it

`FetchFxRatesJob` is the only writer to `exchange_rates`, and its first act is
to return when `users.fx_online_enabled` is false — correctly, because that flag
is the consent gate for the app's only outbound traffic. `BundledSnapshotProvider`
sits in the same registry as the two network providers, so the refusal took the
offline provider down with the online ones.

The consequence was silent. `ExchangeRateService::convertWithRows()` degrades to
`ConversionResult::passthrough()` on an empty rate set, so a reader who chose USD
as their reporting currency saw every total keep its euro sign and its euro
value, while Settings said "Bundled rates are used. No data leaves this device."

`2026_08_23_000010_seed_bundled_exchange_rates` writes the snapshot into
`exchange_rates` at install and on upgrade. The rows carry `source = 'bundled'`,
which is part of the table's unique key, so:

- a live provider's row for the same day is a separate row and is never
  overwritten;
- re-running the migration rewrites only its own rows;
- the snapshot's own date is used, never `now()`, so
  `ExchangeRateService::STALE_DAYS_THRESHOLD` marks the figures stale and the
  reader is told the rates are old rather than shown a false "today".

Opting into online fetching still layers fresher rows on top; nothing about the
consent gate changes.
