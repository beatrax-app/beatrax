# `FX` — architecture

The `FX` module owns base-currency conversion: turning a `Money` value in
any currency into the user's reporting currency, backed by a
priority-ordered chain of rate providers with a cache-backed circuit
breaker, and stored on the `exchange_rates` table so historical figures
convert at the rate that was actually in effect on their date.

## Provider chain and circuit breaker

`RateProviderRegistry` holds every bound `RateProvider` sorted by
`priority()` descending and tries each in turn until one succeeds:

- `EcbRateProvider` (priority 200) — the ECB daily reference XML feed,
  tried first.
- `FrankfurterRateProvider` (priority 100) — a second live-rate fallback.
- `BundledSnapshotProvider` (priority 0) — the offline snapshot shipped
  with the app (`Modules/FX/Resources/rates-snapshot.json`), always
  available and never making a network request.

Each provider's failure count is persisted in the Laravel cache under
`fx.circuit.{key}.failures`. A provider with three or more cached
failures is skipped (circuit open) for six hours; the first failure in a
window anchors the six-hour TTL, and subsequent failures within that
window increment the counter without resetting the TTL — otherwise a
provider that fails more often than once per six hours would slide its
window forever and the circuit would never auto-heal once the outage
ends. A success resets the counter. When every provider in the chain
fails or is circuit-open, `RateProviderRegistry::fetchCurrentRates()`
throws `AllProvidersFailed`; callers that need a safe result catch it and
fall back to the passthrough / original-currency path.

`BundledSnapshotProvider` and `FrankfurterRateProvider` both treat an
HTTP-200-but-empty rate set as a failure (throwing `RateFetchException`)
rather than a success — otherwise the registry would reset the circuit
and stop there instead of falling through to the next provider in the
chain.

## Fetch job: concurrency, idempotency, and date-keying

`FetchFxRatesJob` is the single point where outbound rate HTTP actually
happens. Its concurrency contract mirrors `ProjectForecastJob`:
`ShouldBeUniqueUntilProcessing` keyed on `uniqueId() = "{userId}"`
collapses a concurrent scheduled-tick and on-demand refresh trigger pair
into one queued job per user, and `tries = 3` with backoff `[60, 300,
900]` absorbs a single transient provider failure without
final-failing the run.

Before fetching, the job re-checks the user's `fx_online_enabled` flag
directly against the database — this is a defense-in-depth privacy gate:
online fetch is opt-in and off by default, and re-checking here (rather
than trusting the caller) means no dispatch path — the scheduler, the
on-demand "Refresh now" action, or any future caller — can leak network
calls for a user who never enabled online fetch. The UI gate alone is
not a security boundary.

Rows are upserted on the unique index (`base_currency`,
`quote_currency`, `rate_date`, `source`) so re-running the job never
duplicates rows. The date keyed in `exchange_rates` is the date from the
provider's feed response, never `now()`: on weekends and ECB public
holidays the feed publishes the previous business day's date, so keying
on `now()` would produce a false "today" row. Rate values are validated
against a plausible range (0.00001–100000) before upsert; values outside
that range are logged and skipped rather than written.

## Conversion: passthrough, staleness, and cross-rates

`ExchangeRateService` is the single cross-module entry point for
currency conversion, exposing two methods:

- `convertToBase()` — for current-snapshot figures, uses the latest
  available rate from `exchange_rates`, ordered by `rate_date` DESC.
- `convertAtDate()` — for historical figures, prefers the caller-supplied
  known rate (`transactions.fx_rate_used`) and falls back to the dated
  snapshot row when no known rate is supplied.

Both methods short-circuit to `ConversionResult::passthrough()` when the
figure's currency already equals the target currency — no DB query
fires, and the result carries no rate metadata so the Blade disclosure
affordance skips rendering entirely. This is the zero-cost path every
already-base-currency figure takes.

Conversion uses Brick Money's `BaseCurrencyProvider` with EUR as the
base, so a cross-rate such as USD→GBP is derived exactly from two
EUR-based pairs rather than requiring a direct USD/GBP row. The as-of
date, source, and staleness of a multi-leg conversion reflect the
*oldest* rate leg actually involved, not the globally newest row in the
table — otherwise a stale non-EUR leg could be mis-reported as fresh
just because some other pair refreshed recently. The staleness threshold
is three calendar days, calibrated to the ECB weekend gap: Friday to
Monday is three days, so Monday morning still shows non-stale rates
fetched on Friday.

All DECIMAL reads from PDO are cast to `(string)` before being handed to
brick/money — rate values are never represented as float anywhere in
this module, since floating-point representation silently corrupts FX
conversion precision.

## Wiring

`FXServiceProvider::register()` tags and registers the three rate
providers as singletons, binds `RateProviderRegistry` sorted by
`priority()` DESC, and binds `ExchangeRateService` as a singleton,
mirroring the `ReceiptsServiceProvider` tagged-singleton pattern. FX has
no Livewire pages of its own in v1 — settings live on the Core
`SettingsPage` — and no module-local `Routes/console.php`; the daily
refresh entry lives in the root `routes/console.php`.
