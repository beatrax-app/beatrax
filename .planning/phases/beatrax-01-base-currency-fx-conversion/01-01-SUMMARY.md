---
phase: beatrax-01-base-currency-fx-conversion
plan: "01"
subsystem: FX
tags: [fx, currency, exchange-rates, migrations, dto, contracts, tdd]
dependency_graph:
  requires: []
  provides:
    - Modules/FX module shell (FXServiceProvider, module.json)
    - RateProvider contract (key/priority/fetch interface)
    - RateFetchException + AllProvidersFailed exceptions
    - exchange_rates cache table (DECIMAL(18,8), dated unique index)
    - users.base_currency + users.fx_online_enabled columns
    - ConversionResult DTO with passthrough() constructor
    - FXUnit + FXFeature Pest test suites registered
  affects:
    - Modules/Core/Models/User.php (added base_currency, fx_online_enabled)
    - bootstrap/providers.php (FXServiceProvider registered)
    - tests/Pest.php (Modules/FX registered)
    - phpunit.xml (FXUnit, FXFeature suites added)
tech_stack:
  added: []
  patterns:
    - Tagged-singleton service provider pattern (mirrors ReceiptsServiceProvider)
    - Anonymous-class migration with DatabaseManager resolver (mirrors BudgetsMigration)
    - Alter-users migration pattern (mirrors Recurring drift-alert migration)
    - Spatie\LaravelData\Data DTO with named static constructor (mirrors NetWorth)
    - Per-module test bootstrap pair: TestCase.php + inert Pest.php
key_files:
  created:
    - Modules/FX/module.json
    - Modules/FX/Providers/FXServiceProvider.php
    - Modules/FX/Public/Contracts/RateProvider.php
    - Modules/FX/Public/Exceptions/RateFetchException.php
    - Modules/FX/Public/Exceptions/AllProvidersFailed.php
    - Modules/FX/Public/Dto/ConversionResult.php
    - Modules/FX/Database/Migrations/2026_06_08_000001_create_exchange_rates_table.php
    - Modules/FX/Database/Migrations/2026_06_08_000002_add_base_currency_to_users.php
    - Modules/FX/tests/TestCase.php
    - Modules/FX/tests/Pest.php
    - Modules/FX/tests/Unit/ConversionResultTest.php
    - Modules/FX/tests/Feature/.gitkeep
  modified:
    - Modules/Core/Models/User.php
    - bootstrap/providers.php
    - tests/Pest.php
    - phpunit.xml
    - composer.json
decisions:
  - FXServiceProvider.register() is minimal in plan 01 — RateProviderRegistry tagged-singleton wiring deferred to plan 02 when provider classes exist
  - CarbonImmutable used for ConversionResult.asOf (matches Forecasting DTO convention)
  - Migrations use anonymous-class + DatabaseManager resolver pattern (matches Budgets/Recurring migrations)
  - FX tests/Feature placeholder .gitkeep created to prevent phpunit.xml paratest error
metrics:
  completed: "2026-06-07"
  tasks_completed: 3
  files_created: 12
  files_modified: 5
---

# Phase 1 Plan 01: FX Module Scaffold Summary

FX module skeleton with RateProvider contract, ConversionResult DTO, dated exchange_rates cache table, per-user base_currency/fx_online_enabled columns, and Pest test infrastructure — all interface-first foundations for the rest of Phase 1.

## Tasks Completed

| Task | Name | Commit | Status |
|------|------|--------|--------|
| 1 | Scaffold FX module shell, contract, exceptions, and register it | 2c06be3 | Done |
| 2 | Migrations (exchange_rates table + user FX columns) and User model wiring | e60b38a | Done |
| 3 (RED) | ConversionResult tests + FX test bootstrap (failing) | 6d62d7c | Done |
| 3 (GREEN) | ConversionResult DTO + test suite registration (passing) | efe7833 | Done |
| 3 (CHORE) | FX Feature test directory placeholder | 1c08ff9 | Done |

## What Was Built

### Task 1: FX Module Shell

- `Modules/FX/module.json` — name "FX", alias "fx", priority 8, FXServiceProvider
- `Modules/FX/Providers/FXServiceProvider` — minimal bootable provider with conditional `loadMigrationsFrom` + `loadViewsFrom` in `boot()`; `register()` intentionally minimal for plan 01 (registry wiring comes in plan 02)
- `Modules/FX/Public/Contracts/RateProvider` — interface with `key(): string`, `priority(): int`, `fetch(): array{date: string, rates: array<string, string>}` and `@throws RateFetchException` docblock; mirrors SenderMatcher pattern
- `Modules/FX/Public/Exceptions/RateFetchException` + `AllProvidersFailed` — both extend RuntimeException; used in the provider fallback chain
- `bootstrap/providers.php` — FXServiceProvider registered after BudgetsServiceProvider (priority 8 order)

### Task 2: Migrations + User Model

- `exchange_rates` table: `id`, `char(base_currency,3)`, `char(quote_currency,3)`, `date(rate_date)`, `decimal(rate,18,8)`, `string(source,20)`, `timestamps()`
  - UNIQUE `(base_currency, quote_currency, rate_date, source)` — idempotent upsert key (D-10)
  - INDEX `(base_currency, quote_currency, rate_date)` — latest-rate lookup
  - INDEX `(quote_currency, rate_date)` — inverse cross-rate lookup
  - Rate is DECIMAL(18,8) matching `transactions.fx_rate_used` precision (T-01-01 / Pitfall 1)
- `users` additions: `base_currency CHAR(3) NULLABLE`, `fx_online_enabled BOOLEAN DEFAULT false`
- `User.php` wired: `$fillable`, `$attributes` (`base_currency => 'EUR'`), `casts()`, `@property` docblock

### Task 3: ConversionResult DTO + Test Infrastructure (TDD)

- RED: `ConversionResultTest.php` written with 5 failing tests (class absent)
- GREEN: `ConversionResult extends Data` with typed constructor + `passthrough(Money): self` named constructor
- Test suites registered in `tests/Pest.php` (foreach map) and `phpunit.xml` (FXUnit + FXFeature)
- `composer.json` autoload-dev: `Modules\FX\Tests\` namespace added
- 5/5 tests green via `vendor/bin/pest --testsuite=FXUnit`

## Deviations from Plan

### Auto-fixed Issues

None — plan executed exactly as written, with one additional action:

**[Rule 2 - Missing Critical Functionality] FX tests/Feature directory placeholder**
- **Found during:** Task 3 GREEN verification (full suite run)
- **Issue:** `phpunit.xml` FXFeature testsuite referenced `./Modules/FX/tests/Feature` which did not exist; `paratest` (used by `pest --parallel`) errors on missing paths
- **Fix:** Created `Modules/FX/tests/Feature/.gitkeep` placeholder so the registered path exists
- **Files modified:** `Modules/FX/tests/Feature/.gitkeep`
- **Commit:** 1c08ff9

## TDD Gate Compliance

| Gate | Commit | Status |
|------|--------|--------|
| RED — `test(01-01)` commit with failing tests | 6d62d7c | PASS |
| GREEN — `feat(01-01)` commit with passing tests | efe7833 | PASS |
| REFACTOR — No structural changes needed | n/a | N/A |

## Known Stubs

None — all plan artifacts are fully implemented and functional.

## Threat Flags

No new network endpoints, auth paths, file access patterns, or schema changes outside the plan's threat model were introduced.

## Self-Check

All files exist and commits are verified:
- `git log --oneline -5` shows all 5 plan commits from 2c06be3 to 1c08ff9
- `vendor/bin/pest --testsuite=FXUnit,FXFeature` — 5 passed (20 assertions)
- `vendor/bin/pint --test Modules/FX` — 10 files, 0 issues
- `phpstan analyse Modules/FX/Public/Dto/ConversionResult.php` — 0 errors

## Self-Check: PASSED
