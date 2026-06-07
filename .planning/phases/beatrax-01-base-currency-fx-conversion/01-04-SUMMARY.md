---
phase: 01-base-currency-fx-conversion
plan: "04"
subsystem: Core/FX
tags: [fx, settings, base-currency, livewire, ui-spec]
dependency_graph:
  requires: ["01-01", "01-02"]
  provides:
    - "SettingsPage.baseCurrency (validated picker, batch-save)"
    - "SettingsPage.toggleFxOnline (instant-apply)"
    - "SettingsPage.refreshFxRates (dispatches FetchFxRatesJob via DispatchFxRatesRefresh)"
    - "DispatchFxRatesRefresh (FX Public action — boundary-compliant dispatch seam)"
  affects:
    - Modules/Core/Internal/Http/Livewire/SettingsPage.php
    - Modules/Core/Resources/views/livewire/settings-page.blade.php
    - Modules/Core/tests/TestCase.php
    - Modules/FX/Public/Actions/DispatchFxRatesRefresh.php
tech_stack:
  added: []
  patterns:
    - "SettingsPage instant-apply toggle via raw query-builder write (mirrors toggleAutoImport)"
    - "DispatchFxRatesRefresh public action as boundary-compliant FX job dispatch seam"
    - "Core TestCase seeds EUR/USD/GBP reference currencies with try-catch QueryException for Unit test isolation"
    - "nullable|string|size:3|exists:currencies,code validation on baseCurrency with custom messages"
key_files:
  created:
    - Modules/FX/Public/Actions/DispatchFxRatesRefresh.php
    - Modules/FX/tests/Feature/BaseCurrencySettingTest.php
    - Modules/FX/tests/Feature/FxOnlineToggleTest.php
  modified:
    - Modules/Core/Internal/Http/Livewire/SettingsPage.php
    - Modules/Core/Resources/views/livewire/settings-page.blade.php
    - Modules/Core/tests/TestCase.php
decisions:
  - "DispatchFxRatesRefresh Public action wraps FetchFxRatesJob dispatch — Core cannot import FX Internal jobs directly (BoundaryRule); a thin public dispatch action is the minimal boundary-compliant seam"
  - "Core TestCase seeds reference currencies with try-catch QueryException so Unit tests (no RefreshDatabase) do not crash on the exists:currencies,code validation side-effect"
  - "fxOnlineEnabled null-coalesce (??false) in mount() to guard against users created without explicit fx_online_enabled value even though the migration has default(false) — defensive against race between seeder and test factory"
metrics:
  duration_minutes: 60
  completed_date: "2026-06-07"
  tasks_completed: 2
  files_created: 3
  files_modified: 3
---

# Phase 01 Plan 04: Settings Currency-reporting Card Summary

**One-liner:** Settings page gains a base-currency picker (validated `exists:currencies,code`), an instant-apply online-fetch opt-in toggle, and a "Refresh now" button dispatching `FetchFxRatesJob` through a new `DispatchFxRatesRefresh` public action that respects the cross-module Internal boundary rule.

## What Was Built

### Task 1: SettingsPage — baseCurrency picker, fxOnline toggle, refresh action (RED/GREEN TDD)

Extended `Modules/Core/Internal/Http/Livewire/SettingsPage.php`:

- **New properties:**
  - `#[Validate('nullable|string|size:3|exists:currencies,code')] public string $baseCurrency = 'EUR'` — validates against seeded currencies table (T-04-01)
  - `public bool $fxOnlineEnabled = false` — opt-in, off by default (D-04 / T-04-03)
  - `public bool $fxRefreshing = false` — UI feedback flag for "Refresh now"
  - `public ?string $fxLastUpdated = null` — last exchange_rates row date for help text
- **`mount()`** — hydrates all four from the user row and the `exchange_rates` table's latest `rate_date`
- **`toggleFxOnline()`** — mirrors `toggleAutoImport` exactly; flips `fxOnlineEnabled`, then writes via raw query-builder scoped to `$currentUser->user()->id` (T-04-02 / V4)
- **`refreshFxRates()`** — sets `fxRefreshing = true` and calls `DispatchFxRatesRefresh($userId)` (D-06 / T-04-02)
- **`save()`** — adds `$user->base_currency = $this->baseCurrency` to the existing batch-save block
- **`render()`** — builds `$currencyOptions` array from `currencies` table sorted by code; passed to the view
- **`messages()`** — adds `baseCurrency.size`, `baseCurrency.exists`, `baseCurrency.string` → `'Please choose a currency.'`

Created `Modules/FX/Public/Actions/DispatchFxRatesRefresh.php` — thin `__invoke(int $userId): void` action injected via constructor; wraps `FetchFxRatesJob` dispatch. Core imports this Public action; no Internal boundary violation.

Updated `Modules/Core/tests/TestCase.php` to seed EUR/USD/GBP into the `currencies` table in `setUp()` (guarded by `try-catch QueryException` so Unit tests without `RefreshDatabase` skip silently).

**Tests:** 15 tests in `BaseCurrencySettingTest.php` + `FxOnlineToggleTest.php` — all green.

Commits: `6ddcf5a` (RED), `81408fa` (GREEN)

### Task 2: Settings Currency-reporting card markup (UI-SPEC §5.1)

Extended `Modules/Core/Resources/views/livewire/settings-page.blade.php`:

Inserted a new `<section class="space-y-6">` block between "Currency display" and "Period" inside the existing `<form wire:submit="save">`:

- **Sub-section A "Base reporting currency":** `<select wire:model="baseCurrency" id="baseCurrency">` listing `{{ $code }} — {{ $name }}` from sorted `$currencyOptions`; help copy from UI-SPEC §7.1; `@error('baseCurrency')` rose line
- **Sub-section B "Exchange rates":** `.switch` toggle (`wire:click="toggleFxOnline"`, `aria-pressed`, `aria-label`); context-sensitive help text (OFF/ON states + "Last updated: {date}" when `$fxLastUpdated` present)
- **Refresh-now row:** `wire:transition` guard on `$fxOnlineEnabled`; idle/refreshing status text; ghost "Refresh now" button (`wire:click="refreshFxRates"`, `wire:loading.attr="disabled"`, `disabled:opacity-50`)

No new theme tokens. Copy matches UI-SPEC §7.1. All existing SettingsPage tests plus new FX tests pass.

Commit: `6734b67`

## Test Coverage

| Test file | Tests | Covers |
|---|---|---|
| `BaseCurrencySettingTest.php` | 7 | mount hydration, EUR default on null, valid/invalid code, error message, V4 write scoping |
| `FxOnlineToggleTest.php` | 8 | default false, hydration, flip true/false, instant-persist, refreshFxRates dispatch, fxRefreshing flag, V4 isolation |

Total: 15 new tests — all passing. Existing 265 Core tests and 40 pre-existing FX tests remain green (320 total across Core + FX modules).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Cross-module Internal boundary violation (BoundaryRule)**
- **Found during:** Task 1 GREEN — Larastan L10 run
- **Issue:** `SettingsPage` (in `Modules\Core\Internal`) imported `FetchFxRatesJob` from `Modules\FX\Internal\Jobs\` directly; the custom `BoundaryRule` PHPStan rule forbids Core importing from FX Internal namespace
- **Fix:** Created `Modules/FX/Public/Actions/DispatchFxRatesRefresh.php` as a thin public dispatch action; `SettingsPage::refreshFxRates()` now injects `DispatchFxRatesRefresh` instead of `Dispatcher + FetchFxRatesJob`
- **Files modified:** `SettingsPage.php`, new `DispatchFxRatesRefresh.php`
- **Commit:** `81408fa`

**2. [Rule 1 - Bug] Useless bool cast — Larastan L10 strict**
- **Found during:** Task 1 Larastan run (after removing `(bool)` cast)
- **Issue:** `$user->fx_online_enabled` typed as `bool` by Eloquent casts PHPDoc; but runtime users created without `fx_online_enabled` have `null` in DB — assigning `null` to `public bool $fxOnlineEnabled` throws `TypeError`
- **Fix:** Changed to `$user->fx_online_enabled ?? false` which is Larastan-clean and runtime-safe
- **Files modified:** `SettingsPage.php`
- **Commit:** `81408fa`

**3. [Rule 2 - Missing critical functionality] Core TestCase needs reference currencies**
- **Found during:** Task 1 GREEN — running existing `SettingsPageTest` after adding `exists:currencies,code` validation
- **Issue:** Existing Core tests call `save()` without seeding `currencies`, causing `exists:currencies,code` to fail validation; Unit tests also broke because `currencies` table doesn't exist without `RefreshDatabase`
- **Fix:** Added `setUp()` to `Core TestCase.php` seeding EUR/USD/GBP; wrapped in `try-catch QueryException` so Unit tests skip the seeding gracefully
- **Files modified:** `Modules/Core/tests/TestCase.php`
- **Commit:** `81408fa`

**4. [Rule 1 - Bug] Pint fully_qualified_string in BaseCurrencySettingTest**
- **Found during:** Task 1 Pint check
- **Issue:** Test used `\DB::table(...)` (FQCN form); Pint's `fully_qualified_string` rule requires `use DB;` + unqualified call
- **Fix:** Pint auto-fixed to unqualified `DB::table(...)`
- **Files modified:** `Modules/FX/tests/Feature/BaseCurrencySettingTest.php`
- **Commit:** `81408fa`

## Known Stubs

None — all components are fully wired. The currency picker renders options from the seeded `currencies` table; the toggle persists instantly; the refresh dispatches a real job.

## Threat Flags

None — no new network endpoints, auth paths, or schema changes beyond what the plan's `<threat_model>` documents. All T-04 threat mitigations implemented:

- T-04-01: `exists:currencies,code` in `#[Validate]` — only seeded ISO-4217 codes accepted
- T-04-02: All writes scoped to `$currentUser->user()->id`; no request-supplied `user_id`
- T-04-03: `fx_online_enabled` defaults `false`; outbound fetch only via explicit toggle/refresh

## Self-Check: PASSED

Files verified:

- `Modules/Core/Internal/Http/Livewire/SettingsPage.php` — FOUND
- `Modules/Core/Resources/views/livewire/settings-page.blade.php` — FOUND
- `Modules/FX/Public/Actions/DispatchFxRatesRefresh.php` — FOUND
- `Modules/FX/tests/Feature/BaseCurrencySettingTest.php` — FOUND
- `Modules/FX/tests/Feature/FxOnlineToggleTest.php` — FOUND
- `Modules/Core/tests/TestCase.php` — FOUND

Commits verified:
- `6ddcf5a` test(01-04): RED tests for BaseCurrencySetting and FxOnlineToggle
- `81408fa` feat(01-04): SettingsPage FX properties, toggleFxOnline, refreshFxRates + DispatchFxRatesRefresh public action
- `6734b67` feat(01-04): Currency-reporting card markup (UI-SPEC §5.1)
