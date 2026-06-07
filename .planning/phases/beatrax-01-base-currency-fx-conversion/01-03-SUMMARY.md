---
phase: 01-base-currency-fx-conversion
plan: "03"
subsystem: Forecasting+FX
tags: [fx, net-worth, conversion, dto, cross-module]
dependency_graph:
  requires: ["01-02"]
  provides: ["NetWorthQuery.forUser+FX", "NetWorth+FXMetadata"]
  affects:
    - Modules/Forecasting/Public/Services/NetWorthQuery.php
    - Modules/Forecasting/Public/Dto/NetWorth.php
    - Modules/Forecasting/tests/Feature/NetWorthQueryFxTest.php
    - Modules/Forecasting/tests/Unit/NetWorthDtoFxMetaTest.php
    - Modules/Forecasting/tests/Feature/NetWorthQueryTest.php
tech_stack:
  added: []
  patterns:
    - "ExchangeRateService injected into NetWorthQuery via constructor (Public->Public cross-module, sanctioned)"
    - "No-rate signal: result->converted->currency() != baseCurrency detects passthrough-on-wrong-currency"
    - "Additive-nullable pattern: new NetWorth fields have defaults so all existing call sites compile unchanged (Pitfall 7)"
    - "function_exists() guard on nwAccount() helper to prevent fatal redeclaration in full suite runs"
key_files:
  created:
    - Modules/Forecasting/tests/Feature/NetWorthQueryFxTest.php
    - Modules/Forecasting/tests/Unit/NetWorthDtoFxMetaTest.php
  modified:
    - Modules/Forecasting/Public/Dto/NetWorth.php
    - Modules/Forecasting/Public/Services/NetWorthQuery.php
    - Modules/Forecasting/tests/Feature/NetWorthQueryTest.php
decisions:
  - "No-rate signal detected by comparing result->converted->currency() to baseCurrency (not via isPassthrough alone) — handles both empty-rows and pair-not-found passthrough paths from ExchangeRateService"
  - "nwAccount() global function guarded with function_exists() in both NetWorthQueryTest and NetWorthQueryFxTest to prevent fatal redeclaration when Pest collects both files"
  - "baseCurrency read directly from $user->base_currency (typed string on User model) without is_string() guard to satisfy Larastan L10 function.alreadyNarrowedType rule"
metrics:
  duration_minutes: 45
  completed_date: "2026-06-07"
  tasks_completed: 2
  files_created: 2
  files_modified: 3
---

# Phase 01 Plan 03: NetWorthQuery FX Conversion Summary

**One-liner:** `NetWorthQuery` now converts non-EUR accounts to the user's base currency via `ExchangeRateService.convertToBase()`, threads `ratesSource`/`ratesAsOf`/`hasStaleRates`/`accountsWithoutRate` metadata into the `NetWorth` DTO, and excludes only accounts with a genuinely missing rate (D-07).

## What Was Built

### Task 1: NetWorth DTO FX Metadata (TDD RED/GREEN)

Added four additive-nullable properties to `Modules/Forecasting/Public/Dto/NetWorth.php`:

- `?string $ratesSource = null` — source string from the latest non-passthrough conversion (e.g. `'ecb'`, `'bundled'`)
- `?CarbonImmutable $ratesAsOf = null` — rate_date of the latest conversion used
- `bool $hasStaleRates = false` — OR-aggregate of all `ConversionResult::isStale` flags
- `int $accountsWithoutRate = 0` — count of accounts excluded because no rate pair existed

All four fields use defaults so every existing `new NetWorth(...)` call site continues to compile without modification (additive-nullable pattern, RESEARCH Pitfall 7). Updated class docblock to reflect FX-aware semantics.

Commits: `81c75b7` (RED), `e90e418` (GREEN)

### Task 2: FX Conversion in NetWorthQuery + Feature Test (TDD RED/GREEN)

`Modules/Forecasting/Public/Services/NetWorthQuery.php`:

- Added `ExchangeRateService $fx` as third constructor parameter (Public→Public cross-module injection, sanctioned by boundary rules).
- Per-account loop now calls `$this->fx->convertToBase(Money::ofMinor($anchor->openingBalanceMinor, $anchor->currency), $baseCurrency)`.
- No-rate detection: when `$result->converted->currency() !== $baseCurrency`, the account is excluded — `$hasExcluded = true`, `$accountsWithoutRate++` (D-07 fallback). This covers both the "no rows at all" and "pair not in rows" passthrough paths from `ExchangeRateService`.
- Each `AccountBalanceLine` keeps its own `currency` (D-02) — the original is written to the line before conversion.
- FX metadata tracked from non-passthrough conversions: `$latestSource`, `$latestAsOf` (max), `$hasStaleRates` (OR).
- `NetWorth.currency` set to `$user->base_currency` (not hardcoded `'EUR'`).

`Modules/Forecasting/tests/Feature/NetWorthQueryFxTest.php` (7 tests, 19 assertions):
- Non-EUR account included in totalMinor after conversion
- Original currency preserved on AccountBalanceLine (D-02)
- `hasExcludedAccounts=false` + `accountsWithoutRate=0` when all accounts have rates (FX-03)
- `hasExcludedAccounts=true` + `accountsWithoutRate=1` when no rate for the pair (D-07)
- `ratesSource` + `ratesAsOf` populated from conversion used
- `NetWorth.currency` equals `$user->base_currency`
- EUR-only passthrough regression (no rate rows needed, no metadata)

Commits: `a04568e` (RED), `2574b30` (GREEN)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Larastan L10 flagged redundant is_string() on $user->base_currency**
- **Found during:** Larastan quality gate after Task 2 GREEN
- **Issue:** `User::$base_currency` is typed `string` in the model cast; wrapping it in `is_string()` triggers `function.alreadyNarrowedType` at level 10.
- **Fix:** Replaced `is_string($user->base_currency) ? $user->base_currency : 'EUR'` with direct `$user->base_currency`.
- **Files modified:** `Modules/Forecasting/Public/Services/NetWorthQuery.php`
- **Commit:** `2574b30`

**2. [Rule 3 - Blocking] nwAccount() global function redeclaration fatal in full suite**
- **Found during:** Running FxTest + original NetWorthQueryTest together
- **Issue:** `NetWorthQueryFxTest.php` is alphabetically before `NetWorthQueryTest.php`; when Pest collects both, the FX test file's `nwAccount()` guard fires first, then `NetWorthQueryTest.php`'s bare function declaration causes a fatal `Cannot redeclare`.
- **Fix:** Wrapped the declaration in `NetWorthQueryTest.php` with the same `if (! function_exists('nwAccount'))` guard.
- **Files modified:** `Modules/Forecasting/tests/Feature/NetWorthQueryTest.php`
- **Commit:** `2574b30`

## Known Stubs

None — all components are fully wired. `ExchangeRateService.convertToBase()` is implemented (plan 02); `NetWorthQuery` uses it to produce a complete `NetWorth` with real metadata.

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes. All T-03 mitigations were implemented:

- T-03-01: No-rate path excludes the account and increments `accountsWithoutRate`; `forUser()` never throws.
- T-03-02: Conversion goes through `ExchangeRateService` (BigRational/Money::ofMinor); `NetWorthQuery` sums only integer minor units from `result->converted->toMinor()`.
- T-03-03: Only `Modules\FX\Public\Services\ExchangeRateService` is injected; BoundaryArchTest green (54/54).

## Self-Check: PASSED

Files verified:

- `Modules/Forecasting/Public/Dto/NetWorth.php` — FOUND
- `Modules/Forecasting/Public/Services/NetWorthQuery.php` — FOUND
- `Modules/Forecasting/tests/Feature/NetWorthQueryFxTest.php` — FOUND
- `Modules/Forecasting/tests/Unit/NetWorthDtoFxMetaTest.php` — FOUND

Commits verified (all present in `git log`):
- `81c75b7` test(01-03): RED — NetWorth DTO FX metadata properties
- `e90e418` feat(01-03): extend NetWorth DTO with nullable FX metadata fields
- `a04568e` test(01-03): RED — NetWorthQueryFxTest for FX conversion in net-worth
- `2574b30` feat(01-03): inject ExchangeRateService into NetWorthQuery; convert non-EUR accounts to base
