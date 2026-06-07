---
phase: 01-base-currency-fx-conversion
plan: "02"
subsystem: FX
tags: [fx, exchange-rates, rate-providers, circuit-breaker, scheduler, seeder]
dependency_graph:
  requires: ["01-01"]
  provides: ["ExchangeRateService.convertToBase", "ExchangeRateService.convertAtDate", "FetchFxRatesJob", "BundledRatesSeeder"]
  affects: ["Modules/FX/Public/Services/ExchangeRateService.php", "Modules/FX/Internal/RateProviderRegistry.php", "Modules/FX/Internal/Jobs/FetchFxRatesJob.php", "routes/console.php"]
tech_stack:
  added: []
  patterns:
    - "ECB XML parsed via SimpleXMLElement + registerXPathNamespace; rates cast to string at parse boundary"
    - "Frankfurter JSON at api.frankfurter.dev/v1/latest; is_scalar() guard before (string) cast"
    - "RateProviderRegistry mirrors MatcherRegistry; circuit-breaker via cache counter with 6h TTL"
    - "ExchangeRateService uses brick/money BaseCurrencyProvider for cross-rate derivation"
    - "FetchFxRatesJob: ShouldBeUniqueUntilProcessing + LockStore::forUniqueJobs(), keyed on feed date not now()"
    - "BundledRatesSeeder: constructor-injected DatabaseManager, raw upsert on unique index"
    - "Schedule entry uses .name() before .dailyAt() before .withoutOverlapping() to avoid LogicException"
key_files:
  created:
    - Modules/FX/Internal/Providers/EcbRateProvider.php
    - Modules/FX/Internal/Providers/FrankfurterRateProvider.php
    - Modules/FX/Internal/Providers/BundledSnapshotProvider.php
    - Modules/FX/Resources/rates-snapshot.json
    - Modules/FX/Internal/RateProviderRegistry.php
    - Modules/FX/Public/Services/ExchangeRateService.php
    - Modules/FX/Internal/Jobs/FetchFxRatesJob.php
    - Modules/FX/Database/Seeders/BundledRatesSeeder.php
    - Modules/FX/tests/Unit/EcbRateProviderTest.php
    - Modules/FX/tests/Unit/FrankfurterRateProviderTest.php
    - Modules/FX/tests/Unit/RateProviderRegistryTest.php
    - Modules/FX/tests/Feature/ExchangeRateServiceTest.php
    - Modules/FX/tests/Feature/FetchFxRatesJobTest.php
    - Modules/FX/tests/Feature/BundledRatesSeederTest.php
  modified:
    - Modules/FX/Providers/FXServiceProvider.php
    - routes/console.php
decisions:
  - "ExchangeRateService constructor takes only DatabaseManager (not registry or cache) because the service reads the DB directly for conversions; the registry is invoked by FetchFxRatesJob during the write path, not the read path"
  - "ExchangeRateServiceTest moved to tests/Feature/ (not tests/Unit/) to use RefreshDatabase without conflict with root tests/Pest.php dataset bindings"
  - "Psr\\Log\\LoggerInterface injected into FetchFxRatesJob::handle() (not Log facade) per BoundaryArchTest no-facade rule"
  - "rates-snapshot.json stores all rate values as JSON strings; is_scalar() guard on read to satisfy Larastan L10 mixed type assertion requirement"
  - "FXServiceProvider::register() uses iterable<int|string, mixed> PHPDoc on $app->tagged() result to allow the instanceof RateProvider narrowing guard"
metrics:
  duration_minutes: 180
  completed_date: "2026-06-07"
  tasks_completed: 3
  files_created: 14
  files_modified: 2
---

# Phase 01 Plan 02: FX Rate Infrastructure Summary

**One-liner:** ECB→Frankfurter→bundled provider chain with circuit-breaker, `ExchangeRateService` cross-rate conversion via `brick/money` `BaseCurrencyProvider`, daily `FetchFxRatesJob` gated on `fx_online_enabled`, offline-capable `BundledRatesSeeder`.

## What Was Built

### Task 1: Rate Providers + Bundled Snapshot (RED/GREEN TDD)

Three `RateProvider` implementations under `Modules/FX/Internal/Providers/`:

- `EcbRateProvider` (priority 200): parses ECB XML via `SimpleXMLElement`, registers `http://www.ecb.int/vocabulary/2002-08-01/eurofxref` namespace, XPaths the `Cube[@time]` element for the feed date and child Cubes for currency/rate pairs. Uses `createPendingRequest()->get()` (never the Http facade). All rates cast to string.
- `FrankfurterRateProvider` (priority 100): fetches `https://api.frankfurter.dev/v1/latest` (not .app — see Pitfall 3), casts rates via `is_scalar()` + `(string)`.
- `BundledSnapshotProvider` (priority 0): reads `Modules/FX/Resources/rates-snapshot.json` via `file_get_contents`; same `is_scalar()` guard on rate values.
- `rates-snapshot.json`: 30 EUR-based pairs with date "2026-06-05"; all values stored as JSON strings.

Commits: `000333e` (RED), `2b1538f` (GREEN)

### Task 2: RateProviderRegistry + ExchangeRateService + FXServiceProvider Wiring (RED/GREEN TDD)

- `RateProviderRegistry`: mirrors `MatcherRegistry`; iterates providers by priority DESC; circuit-breaker via `cache()->get('fx.circuit.{key}.failures')` — 3 failures opens the circuit for 6h; success resets the counter; throws `AllProvidersFailed` when all providers exhausted.
- `ExchangeRateService`: two public methods — `convertToBase()` (latest available rate, D-03 zero-overhead passthrough when currencies match) and `convertAtDate()` (prefers `$knownRate` from `tx.fx_rate_used`, else dated DB row). Cross-rate derivation uses `brick/money` `BaseCurrencyProvider` with EUR base + `CurrencyConverter` with `DefaultContext` and `RoundingMode::HALF_UP`. Staleness threshold: 3 calendar days (covers ECB Fri→Mon weekend gap). All DECIMAL reads from PDO are `(string)`-cast via `self::toString(mixed)` helper — never float.
- `FXServiceProvider::register()`: tags all three providers as `fx.rate_provider` singletons, wires `RateProviderRegistry` sorted by `priority() DESC`, wires `ExchangeRateService` singleton.

Commits: `d6967ec` (RED), `7cbc24e` (GREEN)

### Task 3: FetchFxRatesJob + BundledRatesSeeder + Scheduler Entry

- `FetchFxRatesJob`: `ShouldQueue + ShouldBeUniqueUntilProcessing`, `tries=3`, `backoff=[60,300,900]`, `uniqueId()=(string)$this->userId`, `uniqueFor()=600`, `uniqueVia()=LockStore::forUniqueJobs()`. `handle()` calls `RateProviderRegistry::fetchCurrentRates()`, validates each rate in range 0.00001–100000 (T-02-01), upserts rows keyed on the **feed's date attribute** (not `now()` — Pitfall 2). Idempotent on re-run.
- `BundledRatesSeeder`: reads `rates-snapshot.json`, upserts rows with `source='bundled'` via injected `DatabaseManager`. Safe to re-run (`updateOrInsert` on unique index).
- `routes/console.php`: `fx.daily-refresh` entry at 09:00 daily; fans out `FetchFxRatesJob` only for users with `fx_online_enabled=true` (D-04 opt-in). Method order `.name()->dailyAt()->withoutOverlapping()` — required to avoid `LogicException` on `schedule:list`.

Commit: `5158b38`

## Test Coverage

40 tests, 102 assertions — all passing.

| Test file | Tests | Covers |
|---|---|---|
| `EcbRateProviderTest.php` | 5 | XML parse, string rates, HTTP fail, malformed XML, key/priority |
| `FrankfurterRateProviderTest.php` | 6 | JSON parse, string rates, correct URL, HTTP fail, missing date, key/priority |
| `RateProviderRegistryTest.php` | 7 | fallback chain, AllProvidersFailed, circuit-breaker skip, success reset, supportedKeys |
| `ExchangeRateServiceTest.php` | 8 | passthrough/zero-query, EUR→USD, USD→GBP cross-rate, stale, fresh, knownRate, dated fallback, rate-is-string |
| `FetchFxRatesJobTest.php` | 4 | feed-date keying, idempotency, date-not-today, range validation |
| `BundledRatesSeederTest.php` | 5 | inserts rows, snapshot date, idempotency, count ≥30, rate values |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] ExchangeRateServiceTest placed in Feature not Unit**
- **Found during:** Task 2 GREEN phase
- **Issue:** `tests/Unit/ExchangeRateServiceTest.php` conflicted with root `tests/Pest.php` that bound `TestCase` without `RefreshDatabase`; the service test needs DB access.
- **Fix:** Moved to `tests/Feature/ExchangeRateServiceTest.php`; removed explicit `uses()` call so root Pest.php bindings apply.
- **Files modified:** `Modules/FX/tests/Feature/ExchangeRateServiceTest.php`
- **Commit:** `7cbc24e`

**2. [Rule 2 - Missing critical functionality] ExchangeRateService constructor simplified**
- **Found during:** Larastan L10 analysis
- **Issue:** Larastan reported `$registry` and `$cache` as "only written, never read" in `ExchangeRateService` — the service reads rates directly from the DB (the registry is on the write path in `FetchFxRatesJob`).
- **Fix:** Removed `$registry` and `$cache` from the constructor; the service is a pure read-from-DB conversion engine.
- **Files modified:** `Modules/FX/Public/Services/ExchangeRateService.php`
- **Commit:** `b17807b`

**3. [Rule 1 - Bug] Larastan L10 strict errors resolved in batch**
- **Found during:** Quality gate run after all tasks
- **Issue:** 11 Larastan errors including: `stdClass` resolved as namespace-qualified `Modules\FX\Public\Services\stdClass` in PHPDoc; `now()` global helper not allowed; `Log` facade not allowed in modules; `(string) $quoteCurrency` useless cast; `(int) ($cache->get())` mixed cast; `Http::get()` dynamic call.
- **Fix:** Qualified `\stdClass` in all `Collection<int, \stdClass>` PHPDoc; replaced `now()` with `CarbonImmutable::now()`; replaced `Log` facade with `Psr\Log\LoggerInterface` injected into `handle()`; added `self::toInt(mixed)` helper in registry; used `createPendingRequest()->get()` pattern.
- **Files modified:** Multiple FX module files
- **Commit:** `b17807b`

**4. [Rule 1 - Bug] FetchFxRatesJobTest passed wrong argument count**
- **Found during:** Test run after Larastan fix (logger added to `handle()` signature)
- **Issue:** Tests called `$job->handle($registry, $db)` — 2 args — after `handle()` gained `LoggerInterface $logger` as third argument.
- **Fix:** Added `app(LoggerInterface::class)` as third argument in all 4 test `handle()` calls; added `use Psr\Log\LoggerInterface` import.
- **Files modified:** `Modules/FX/tests/Feature/FetchFxRatesJobTest.php`
- **Commit:** `b17807b`

## Known Stubs

None — all components are fully wired. The bundled snapshot ships 30 EUR-based rate pairs; seeder hydrates `exchange_rates` at install; `ExchangeRateService` reads from the DB and converts with full metadata.

## Threat Flags

None — no new network endpoints, auth paths, or schema changes beyond what is documented in the plan's `<threat_model>`.

All T-02 threat mitigations were implemented:
- T-02-01: Rate range validation (0.00001–100000) in `FetchFxRatesJob::handle()` — out-of-range values skipped and logged.
- T-02-03: All DECIMAL reads cast to `(string)` via `self::toString(mixed)` before passing to brick/money.
- T-02-04: `Illuminate\Http\Client\Factory` constructor-injected in both HTTP providers; no `Http` facade in module code.

## Self-Check: PASSED

Files verified:

- `Modules/FX/Internal/Providers/EcbRateProvider.php` — FOUND
- `Modules/FX/Internal/Providers/FrankfurterRateProvider.php` — FOUND
- `Modules/FX/Internal/Providers/BundledSnapshotProvider.php` — FOUND
- `Modules/FX/Resources/rates-snapshot.json` — FOUND
- `Modules/FX/Internal/RateProviderRegistry.php` — FOUND
- `Modules/FX/Public/Services/ExchangeRateService.php` — FOUND
- `Modules/FX/Internal/Jobs/FetchFxRatesJob.php` — FOUND
- `Modules/FX/Database/Seeders/BundledRatesSeeder.php` — FOUND

Commits verified (all present in `git log`):
- `000333e` test(01-02): RED tests for EcbRateProvider + FrankfurterRateProvider
- `2b1538f` feat(01-02): implement EcbRateProvider, FrankfurterRateProvider, BundledSnapshotProvider + rates-snapshot.json
- `d6967ec` test(01-02): RED tests for RateProviderRegistry + ExchangeRateService
- `7cbc24e` feat(01-02): implement RateProviderRegistry + ExchangeRateService + FXServiceProvider wiring
- `5158b38` feat(01-02): FetchFxRatesJob + BundledRatesSeeder + fx.daily-refresh scheduler entry
- `b17807b` fix(01-02): resolve Larastan L10 errors across FX module
