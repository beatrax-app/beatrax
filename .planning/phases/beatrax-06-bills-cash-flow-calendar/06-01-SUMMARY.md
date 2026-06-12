---
phase: "06"
plan: "01"
subsystem: "Calendar + Forecasting"
tags: [calendar, forecasting, scaffold, tdd-red, recurring-series, daily-balance]
dependency_graph:
  requires:
    - "05-01"  # Pin/biometric lock (auth layer this plan builds on)
    - "04-01"  # PWA / app layout (sidebar, nav patterns)
  provides:
    - Calendar module scaffold (DTOs, route, migration, provider, stub component)
    - Forecasting 180/365-day horizon (D-14)
    - Wave 0 RED test suite for CAL-01/02/03 (17 tests)
  affects:
    - Modules/Forecasting (horizon extension + new feature tests)
    - Modules/Core (UserPreference fillable/casts, sidebar nav)
    - Modules/DevMode (palette entry)
    - bootstrap/providers.php (CalendarServiceProvider registered)
tech_stack:
  added: []
  patterns:
    - "CalendarEntryDto / CalendarDayDto extend Spatie Data"
    - "CalendarPage extends Livewire Component with #[Url] params"
    - "CalendarServiceProvider follows GoalsServiceProvider pattern"
    - "Migration uses Container DI for DatabaseManager (not DB facade)"
    - "RED test helpers use prefixed functions (cprs*, cpbl*, cpcs*, etc.) to avoid name collisions"
    - "app(DatabaseManager::class) called inside closures — not in ->with() dataset"
key_files:
  created:
    - Modules/Calendar/Public/Dto/CalendarEntryDto.php
    - Modules/Calendar/Public/Dto/CalendarDayDto.php
    - Modules/Calendar/Providers/CalendarServiceProvider.php
    - Modules/Calendar/Routes/web.php
    - Modules/Calendar/Database/Migrations/2026_06_12_000001_add_calendar_account_prefs_to_user_preferences.php
    - Modules/Calendar/Internal/Http/Livewire/CalendarPage.php
    - Modules/Calendar/Resources/views/livewire/calendar-page.blade.php
    - Modules/Calendar/tests/Pest.php
    - Modules/Calendar/tests/TestCase.php
    - Modules/Calendar/tests/Unit/CalendarQueryIrregularTest.php
    - Modules/Calendar/tests/Feature/CalendarPageRendersSeriesTest.php
    - Modules/Calendar/tests/Feature/CalendarPageBalanceLineTest.php
    - Modules/Calendar/tests/Feature/CalendarPagePastDayStateTest.php
    - Modules/Calendar/tests/Feature/CalendarPageComputingStateTest.php
    - Modules/Calendar/tests/Feature/CalendarDrillThroughTest.php
    - Modules/Calendar/tests/Feature/CalendarPageEmptyStateTest.php
    - Modules/Forecasting/tests/Feature/ForecastPageHorizonTest.php
  modified:
    - Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php
    - Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php
    - Modules/Forecasting/tests/Unit/ProjectForecastJobUniqueTest.php
    - Modules/Core/Models/UserPreference.php
    - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
    - Modules/DevMode/Providers/DevModeServiceProvider.php
    - bootstrap/providers.php
    - tests/Pest.php
decisions:
  - "D-14 implemented: HORIZON_DAYS extended from [30,60,90] to [30,60,90,180,365]; whitelist and dispatch loop both governed by the single constant"
  - "CalendarPage accepts #[Url] month/year/selectedDay/visibleAccountIds/balanceAccountIds — all treated as untrusted until Plan 02 validation layer"
  - "CalendarServiceProvider registers migrations/routes/views lazily (is_dir/is_file guards) so scaffold is safe with empty directories"
  - "Wave 0 RED tests use function-prefixed helpers (no class-per-test) matching existing Forecasting/Goals test conventions"
  - "->with([app(...)]) dataset pattern avoided — app() called inside closure bodies to prevent parse-time container boot errors"
metrics:
  duration: "~90 minutes (split across two context windows)"
  completed_date: "2026-06-12"
  tasks_completed: 3
  tasks_total: 3
  files_changed: 25
---

# Phase 06 Plan 01: Calendar Module Scaffold + Forecasting 180/365 Horizon Summary

Calendar module fully scaffolded with typed DTOs, Livewire stub page, migration, service provider, route, and all nav/palette registrations; Forecasting engine extended to 180/365-day horizon per D-14; Wave 0 RED test suite (17 tests across 7 files) established contracts for CAL-01/02/03 that will go GREEN in Plans 02/03.

## Tasks Completed

| Task | Name | Commit | Key Files |
|------|------|--------|-----------|
| 1 | Extend Forecasting horizon to 180/365 (D-14) | `ed5e3d4` | ProjectForecastJob.php, forecast-page.blade.php, ProjectForecastJobUniqueTest.php, ForecastPageHorizonTest.php |
| 2 | Calendar module scaffold | `ecef342` | CalendarEntryDto, CalendarDayDto, CalendarServiceProvider, CalendarPage, migration, route, sidebar nav, palette entry |
| 3 | Wave 0 RED test suite (CAL-01/02/03) | `584368b` | 7 test files, TestCase.php, tests/Pest.php |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed ->with([app(...)]) dataset pattern causing parse-time container errors**
- **Found during:** Task 3
- **Issue:** `->with([app(DatabaseManager::class)])` calls `app()` at test-file parse time before the Laravel container is booted, causing a DatasetMissing/RuntimeException in Pest.
- **Fix:** Removed `->with([...])` chains; added `$db = app(DatabaseManager::class);` as the first statement inside each affected test closure (CalendarPageBalanceLineTest, CalendarPagePastDayStateTest, CalendarPageComputingStateTest, CalendarDrillThroughTest).
- **Files modified:** 4 feature test files
- **Commit:** included in `584368b`

## Known Stubs

- `CalendarPage::render()` returns a static stub view with no CalendarQuery call — the heading renders but no series data, balance line, or day panel is wired. This is the intentional RED state for Plan 01; Plans 02/03 wire the query layer.
- `calendar-page.blade.php` renders only an h1 and subheading — no grid, no day cells, no entry rows. All 17 RED tests that assert series/balance content will fail until Plans 02/03.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: input-validation | Modules/Calendar/Internal/Http/Livewire/CalendarPage.php | #[Url] params month/year/visibleAccountIds/balanceAccountIds are user-controlled; no type/range validation in Plan 01 scaffold. Plan 02 must validate before use in CalendarQuery. |

## Self-Check: PASSED

All 3 task commits verified:
- `ed5e3d4` exists: FOUND
- `ecef342` exists: FOUND
- `584368b` exists: FOUND

Key files exist in worktree:
- Modules/Calendar/Public/Dto/CalendarEntryDto.php: FOUND
- Modules/Calendar/Public/Dto/CalendarDayDto.php: FOUND
- Modules/Calendar/Providers/CalendarServiceProvider.php: FOUND
- Modules/Calendar/Internal/Http/Livewire/CalendarPage.php: FOUND
- Modules/Calendar/tests/Feature/CalendarPageRendersSeriesTest.php: FOUND
- Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php (modified): FOUND
