---
phase: 01-foundation-asn-csv-vertical-slice
plan: 01
subsystem: foundation
tags:
  - scaffolding
  - phpstan
  - pest
  - di-only
  - modules
dependency_graph:
  requires: []
  provides:
    - "buildable Laravel 13 + Livewire 4 + nwidart modules project"
    - "level-max PHPStan gate with custom BoundaryRule"
    - "Pest contract test framework + dataset"
    - "five module skeletons with Public/Internal split"
  affects:
    - "every Plan 01..N inherits the CI gate authored here"
tech_stack:
  added:
    - "PHP 8.5 (Herd)"
    - "laravel/framework v13.8.0"
    - "livewire/livewire v4.3.0"
    - "livewire/flux v2.14.1"
    - "nwidart/laravel-modules v13.0.0"
    - "brick/money 0.11.2"
    - "league/csv 9.28.0"
    - "spatie/laravel-data 4.23.0"
    - "laravel/fortify v1.37.0 (transitive — Plan 02 wires it)"
    - "pestphp/pest v4.7.0 + plugin-laravel v4.1.0 + plugin-arch v4.0.2"
    - "larastan/larastan v3.9.6"
    - "calebdw/larastan-livewire v2.5.0"
    - "canvural/larastan-strict-rules 3.0.3"
    - "phpstan/phpstan-strict-rules 2.0.11"
    - "laravel/pint v1.29.1"
  patterns:
    - "Module shape: Public/ (cross-module surface) + Internal/ (private)"
    - "BoundaryRule enforces Modules\\<X>\\Internal cannot be imported outside <X>"
    - "Constructor DI only — facades + helpers banned by canvural strict rules"
    - "Pest contract tests pin invariants that subsequent plans turn green"
key_files:
  created:
    - composer.json
    - composer.lock
    - package.json
    - pint.json
    - phpstan.neon
    - phpstan-fixtures.neon
    - phpunit.xml
    - .gitignore
    - .env.example
    - README.md
    - artisan
    - public/index.php
    - bootstrap/app.php
    - bootstrap/providers.php
    - config/app.php
    - config/database.php
    - config/session.php
    - config/modules.php
    - routes/web.php
    - routes/console.php
    - app/PhpStan/Rules/BoundaryRule.php
    - app/PhpStan/Rules/Fixtures/GoodBoundaryFixture.php
    - app/PhpStan/Rules/Fixtures/BadBoundaryFixture.php
    - tests/Pest.php
    - tests/TestCase.php
    - tests/Unit/PhpStanBoundaryRuleTest.php
    - tests/Contracts/NoExtImapTest.php
    - tests/Contracts/BoundaryArchTest.php
    - tests/Contracts/UserIdColumnArchTest.php
    - tests/Contracts/NoFloatMoneyArchTest.php
    - tests/Contracts/MoneyColumnsArchTest.php
    - tests/Contracts/IdempotencyContractTest.php
    - tests/Contracts/Stubs/AsnCsvAdapterStub.php
    - Modules/Core/module.json
    - Modules/Core/composer.json
    - Modules/Core/Providers/CoreServiceProvider.php
    - Modules/Core/tests/TestCase.php
    - Modules/Core/tests/Pest.php
    - Modules/Ledger/module.json
    - Modules/Ledger/composer.json
    - Modules/Ledger/Providers/LedgerServiceProvider.php
    - Modules/Ledger/tests/TestCase.php
    - Modules/Ledger/tests/Pest.php
    - Modules/Ingestion/module.json
    - Modules/Ingestion/composer.json
    - Modules/Ingestion/Providers/IngestionServiceProvider.php
    - Modules/Ingestion/tests/TestCase.php
    - Modules/Ingestion/tests/Pest.php
    - Modules/Import/module.json
    - Modules/Import/composer.json
    - Modules/Import/Providers/ImportServiceProvider.php
    - Modules/Import/tests/TestCase.php
    - Modules/Import/tests/Pest.php
    - Modules/Categorization/module.json
    - Modules/Categorization/composer.json
    - Modules/Categorization/Providers/CategorizationServiceProvider.php
    - Modules/Categorization/tests/TestCase.php
    - Modules/Categorization/tests/Pest.php
  modified:
    - .planning/phases/01-foundation-asn-csv-vertical-slice/01-VALIDATION.md
decisions:
  - "Replaced joeymckenzie/facadeless with phpstan/phpstan-strict-rules — facadeless is not on Packagist; canvural already ships NoFacadeRule and NoGlobalLaravelFunctionRule"
  - "Pinned brick/money ^0.11 instead of ^0.13 — 0.13 requires brick/math ~0.15+ which conflicts with ramsey/uuid 4.9.x bundled with Laravel 13"
  - "Pinned Pest ^4 instead of ^3 — pest-plugin-laravel v3 caps at Laravel 12; v4.1 is the first release supporting Laravel 13"
  - "Pinned livewire/flux ^2 instead of ^1 — the v1 line is closed upstream (current is v2.14)"
  - "Dropped laravel/tinker — current 2.11 release does not yet support Laravel 13"
  - "BoundaryRule detects the importer module via namespace first, falling back to filesystem path — so the dedicated fixture files under app/PhpStan/Rules/Fixtures/ exercise the rule without relocation"
  - "phpstan-fixtures.neon is a dedicated PHPStan config for the unit-test invocation against fixture files; the production phpstan.neon excludes those fixtures from the normal sweep"
metrics:
  duration: "~19 minutes wall-clock (single executor)"
  completed_date: "2026-05-12"
  tasks_completed: 3
  files_created: 51
  commits: 3
---

# Phase 1 Plan 01: Foundation Scaffold + DI-Only Enforcement Gate Summary

**One-liner:** Laravel 13 + Livewire 4 + nwidart-modules project pinned to PHP 8.5 with a custom PHPStan BoundaryRule enforcing cross-module Internal/Models bans, plus 7 cross-module Pest contract tests pinning the work Plans 02..05 must complete.

## What this plan delivered

A buildable repository where every CI gate (`vendor/bin/pest`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`) executes and reports a known baseline:

- **Larastan level max — clean.** All 4 rule packages (Larastan, calebdw-livewire, canvural-strict-rules, phpstan-strict-rules) loaded; the custom `App\PhpStan\Rules\BoundaryRule` is registered and proven by `tests/Unit/PhpStanBoundaryRuleTest.php`. The empty module skeletons pass at level max.
- **Pint — clean.** Default Laravel preset.
- **Pest — 11 passed, 5 failed (RED by design).** The 5 failing rows are the contract tests Plans 02..05 will turn green.

## Installed versions (from composer.lock)

| Package | Version |
|---------|---------|
| laravel/framework | v13.8.0 |
| livewire/livewire | v4.3.0 |
| livewire/flux | v2.14.1 |
| nwidart/laravel-modules | v13.0.0 |
| brick/money | 0.11.2 |
| league/csv | 9.28.0 |
| spatie/laravel-data | 4.23.0 |
| pestphp/pest | v4.7.0 |
| pestphp/pest-plugin-laravel | v4.1.0 |
| pestphp/pest-plugin-arch | v4.0.2 |
| larastan/larastan | v3.9.6 |
| calebdw/larastan-livewire | v2.5.0 |
| canvural/larastan-strict-rules | 3.0.3 |
| phpstan/phpstan-strict-rules | 2.0.11 |
| laravel/pint | v1.29.1 |

## Custom BoundaryRule

**Location:** `app/PhpStan/Rules/BoundaryRule.php`.

**Behaviour:** Walks `PhpParser\Node\UseItem` (the v5 successor to `UseUse`). For each `use Modules\<Target>\<Tail>` statement inside a file whose declared namespace begins `Modules\<Importer>\…`, the rule fires if `<Target> ≠ <Importer>` AND the imported tail starts with one of:

- `Internal\`
- `Database\`
- `Providers\`
- `Http\Livewire\`

The error message is `Cross-module Internal/Models import forbidden: <Importer> cannot use <FQN>` carrying the identifier `diederik.boundary`. Cross-module imports of `Modules\<Target>\Public\…` and `Modules\<Target>\Models\…` are allowed per CLAUDE.md.

**Test outcomes:**

- `tests/Unit/PhpStanBoundaryRuleTest.php` — 3 of 3 passing
  - `it emits a BoundaryRule error on the bad fixture` ✓
  - `it emits zero errors on the good fixture` ✓
  - `it passes against empty module skeletons at level max` ✓

## Contract Test Colour Matrix

| Test | Requirement | Status at end of Plan 01 | Closes at |
|------|-------------|---------------------------|-----------|
| `tests/Contracts/NoExtImapTest.php` | PLT-05 | GREEN | Plan 01 |
| `tests/Contracts/BoundaryArchTest.php` | D-02, D-03 | GREEN | Plan 01 |
| `tests/Contracts/UserIdColumnArchTest.php` | FND-03 | RED (no domain tables yet) | Plan 03 |
| `tests/Contracts/NoFloatMoneyArchTest.php` | FND-04 | RED (no migrations yet) | Plan 03 |
| `tests/Contracts/MoneyColumnsArchTest.php` | MC-01 | RED (no `transactions` table yet) | Plan 03 |
| `tests/Contracts/IdempotencyContractTest.php` | ING-06 | RED (`RunsImports` contract not bound) | Plan 05 |
| `tests/Unit/PhpStanBoundaryRuleTest.php` | D-03 fixture proof | GREEN | Plan 01 |

## Per-module test scaffolds

Each of `Modules/{Core,Ledger,Ingestion,Import,Categorization}/tests/` has its own `TestCase.php` (extends `Tests\TestCase`) and `Pest.php` (binds `RefreshDatabase` to `Feature`). Sub-second feedback via `vendor/bin/pest Modules/<M>/tests` is available.

## Deviations from Plan

### Auto-fixed dependency conflicts

The plan author wrote the dependency block in early 2026 against package versions that have since shifted. The following adjustments were necessary to make `composer install` succeed against the current Packagist state on PHP 8.5:

**1. [Rule 3 — Blocker] Substituted `phpstan/phpstan-strict-rules` for `joeymckenzie/facadeless`**

- **Found during:** Task 2 (composer install)
- **Issue:** `joeymckenzie/facadeless` does not exist on Packagist — `composer search` returns nothing matching that name. The plan listed it as the project's facade-ban enforcer.
- **Fix:** Removed `joeymckenzie/facadeless` from `composer.json`; added `phpstan/phpstan-strict-rules ^2.0`. The facade-ban surface is fully covered by canvural's `NoFacadeRule` and `NoGlobalLaravelFunctionRule` (both included in `canvural/larastan-strict-rules`'s default rule set) plus the architectural assertion in `tests/Contracts/BoundaryArchTest.php` that `Illuminate\Support\Facades` is `not->toBeUsedIn('Modules')`.
- **Files modified:** `composer.json`, `phpstan.neon`
- **Commit:** Task 2 (`80ea98c`)

**2. [Rule 3 — Blocker] Pinned `brick/money` to `^0.11` instead of `^0.13`**

- **Found during:** Task 1 (composer install)
- **Issue:** `brick/money` 0.12 + 0.13 require `brick/math` `~0.15`/`~0.16`/`~0.17`. Laravel 13's transitive dependency `ramsey/uuid` 4.9.2 requires `brick/math` `~0.8.16 || …  || ^0.14`. The two requirements have empty intersection.
- **Fix:** Pinned `brick/money` to `^0.11` (last version that accepts `brick/math ~0.14`). The plan's choice of 0.13 will become reachable once Laravel's ramsey/uuid pin updates to support brick/math 0.15+.
- **Files modified:** `composer.json`
- **Commit:** Task 1 (`92dc3f2`)

**3. [Rule 3 — Blocker] Bumped Pest to `^4.0`**

- **Found during:** Task 1 (composer install)
- **Issue:** `pestphp/pest-plugin-laravel` v3 caps at `laravel/framework ^11|^12` — explicitly conflicts with the project's `^13.0` pin. v4.0 was Laravel 11+12 only; v4.1.0 was the first release with Laravel 13 support.
- **Fix:** Pinned all three Pest packages (`pest`, `pest-plugin-laravel`, `pest-plugin-arch`) to `^4.0`. Pest 4 is API-compatible with the test syntax the plan specified (`it()`, datasets, `arch()` plugin); no test rewrites required.
- **Files modified:** `composer.json`
- **Commit:** Task 1 (`92dc3f2`)

**4. [Rule 3 — Blocker] Bumped livewire/flux to `^2.0`**

- **Found during:** Task 1 (composer install)
- **Issue:** `livewire/flux ^1.0` resolves to v1.x which is no longer the current line — v2 has been the active release line for some time and is required for Livewire 4 compat.
- **Fix:** Pinned to `^2.0` (current stable v2.14.1).
- **Files modified:** `composer.json`
- **Commit:** Task 1 (`92dc3f2`)

**5. [Rule 3 — Blocker] Dropped `laravel/tinker` from require**

- **Found during:** Task 1 (composer install)
- **Issue:** `laravel/tinker` 2.11.1 (latest) supports up to Laravel 12 only; the package does not yet have a Laravel-13-compatible release.
- **Fix:** Removed from `composer.json`. Tinker is a developer-convenience REPL; not required for Plan 01's gates. Will be added back when an L13-compatible release ships.
- **Files modified:** `composer.json`
- **Commit:** Task 1 (`92dc3f2`)

### Auto-resolved technical issues

**6. [Rule 3 — Blocker] BoundaryRule namespace-first detection**

- **Found during:** Task 2 (TDD RED → GREEN)
- **Issue:** Plan instructed deriving the importer module from the filesystem path. The fixture files (`app/PhpStan/Rules/Fixtures/BadBoundaryFixture.php`) live OUTSIDE `Modules/`, so a path-only regex returns NULL and the rule never fires for them. Without firing, the test cannot prove the rule works.
- **Fix:** The rule now derives the importer module first from `Scope::getNamespace()` (which returns `Modules\Categorization\Internal\Examples` for the fixture file), falling back to the filesystem path. Production code lives at `Modules/<X>/…` with matching namespace, so the original semantics are preserved.
- **Files modified:** `app/PhpStan/Rules/BoundaryRule.php`
- **Commit:** Task 2 (`80ea98c`)

**7. [Rule 3 — Blocker] Dedicated phpstan-fixtures.neon config**

- **Found during:** Task 2 (TDD RED → GREEN)
- **Issue:** The fixtures import classes (`MoneyMinorCast`, `RecordsTransactions`, `Transaction`) that don't exist until later plans. Including them in the main `phpstan.neon` paths would produce `class.notFound` errors, breaking the "empty modules pass at level max" gate. Plan instructed `excludePaths: app/PhpStan/Rules/Fixtures/*` — but with that exclude, running `vendor/bin/phpstan analyse <fixture>` in isolation refuses the file.
- **Fix:** Authored a separate `phpstan-fixtures.neon` config that includes only `app/PhpStan/Rules/Fixtures/` and ignores the expected `class.notFound` errors. The unit test invokes PHPStan with `--configuration=phpstan-fixtures.neon`. The production sweep continues to use `phpstan.neon` with the fixtures excluded.
- **Files modified:** `phpstan-fixtures.neon`, `tests/Unit/PhpStanBoundaryRuleTest.php`
- **Commit:** Task 2 (`80ea98c`)

## Self-Check: PASSED

Created files exist on disk:

- `composer.json` ✓
- `composer.lock` ✓
- `phpstan.neon` ✓
- `phpstan-fixtures.neon` ✓
- `phpunit.xml` ✓
- `app/PhpStan/Rules/BoundaryRule.php` ✓
- `app/PhpStan/Rules/Fixtures/{Good,Bad}BoundaryFixture.php` ✓
- `tests/Pest.php`, `tests/TestCase.php` ✓
- `tests/Unit/PhpStanBoundaryRuleTest.php` ✓
- `tests/Contracts/{NoExtImap,Boundary,UserIdColumn,NoFloatMoney,MoneyColumns,Idempotency}*Test.php` (6 files) ✓
- `tests/Contracts/Stubs/AsnCsvAdapterStub.php` ✓
- `Modules/{Core,Ledger,Ingestion,Import,Categorization}/Providers/<Name>ServiceProvider.php` (5 files) ✓
- `Modules/{Core,Ledger,Ingestion,Import,Categorization}/tests/{TestCase,Pest}.php` (10 files) ✓

Commits exist in `git log`:

- `92dc3f2` chore(01-01): scaffold Laravel 13 + Livewire 4 + nwidart modules ✓
- `80ea98c` feat(01-01): add Larastan level max + custom BoundaryRule with fixture test ✓
- `789abb6` test(01-01): author cross-module Pest contract tests + per-module scaffolds ✓

End-of-plan invariants:

- `composer install` succeeded against PHP 8.5 ✓
- `php artisan --version` outputs `Laravel Framework 13.8.0` ✓
- `vendor/bin/pint --test` exits 0 ✓
- `vendor/bin/phpstan analyse` exits 0 at level max ✓
- `vendor/bin/pest` runs to completion: 11 passed, 5 failed (RED by design) ✓
- `bootstrap/providers.php` references all 5 module providers ✓
- `wave_0_complete: true` set in `01-VALIDATION.md` frontmatter ✓

## Open Questions Surfaced

None. The four PHPStan rule packages combine without conflicting rule IDs (A10 resolved). The fixture-based test proves the BoundaryRule fires on the bad import and not on the good one.

Two assumptions in the plan turned out to be unresolved upstream:

- **brick/money 0.13 vs Laravel 13 ramsey/uuid:** The plan's `^0.13` pin is incompatible with the current `laravel/framework v13.8.0` lock. The conflict will resolve naturally when ramsey/uuid widens its `brick/math` constraint. Plan 03 (money columns + casts) can revisit the pin.
- **laravel/tinker for Laravel 13:** Not yet released. Re-add the dependency once available; Plan 01 does not consume tinker.

Both are non-blocking and tracked in this document.
