---
phase: "06"
plan: "02"
subsystem: Calendar
tags: [calendar, query, tdd, livewire, forecast, recurring, counterparty]
dependency_graph:
  requires:
    - "06-01"  # Calendar scaffold, routes, DTOs, CalendarPage stub
  provides:
    - CalendarQuery.forMonth()  # list<CalendarDayDto> assembly service
    - CalendarPage render()     # fully wired Livewire page with real data
  affects:
    - Modules/Calendar/Internal/Services/CalendarQuery.php
    - Modules/Calendar/Internal/Http/Livewire/CalendarPage.php
    - Modules/Calendar/Public/Dto/CalendarEntryDto.php
    - Modules/Calendar/Resources/views/livewire/calendar-page.blade.php
tech_stack:
  added:
    - "CalendarQuery: final readonly service, injecting DatabaseManager + Clock + RecurringSeriesQuery + ForecastQuery + ExchangeRateService + CounterpartyProfileQuery"
    - "CarbonImmutable::parse(sprintf()) instead of CarbonImmutable::create() — avoids CarbonImmutable|null return type (PHPStan level 10)"
    - "CurrentUser contract injection in Livewire render() — replaces auth() global helper"
  patterns:
    - "TDD: 3 RED commits (unit+feature tests) then 1 GREEN commit (full implementation)"
    - "null=all-visible vs []=none-visible semantics for resolveVisibleAccountIds (D-02)"
    - "cluster_counterparty_key fallback for counterparty resolution (series with no occurrences)"
key_files:
  created:
    - Modules/Calendar/Internal/Services/CalendarQuery.php
    - Modules/Calendar/tests/Unit/CalendarQueryAccountPrefsTest.php
    - Modules/Calendar/tests/Unit/CalendarQueryPastDayMatchTest.php
  modified:
    - Modules/Calendar/Internal/Http/Livewire/CalendarPage.php
    - Modules/Calendar/Public/Dto/CalendarEntryDto.php
    - Modules/Calendar/Resources/views/livewire/calendar-page.blade.php
    - Modules/Calendar/Providers/CalendarServiceProvider.php
    - Modules/Calendar/tests/Feature/CalendarPageEmptyStateTest.php
    - Modules/Calendar/tests/Feature/CalendarPageRendersSeriesTest.php
    - Modules/Calendar/tests/Feature/CalendarPageBalanceLineTest.php
    - Modules/Calendar/tests/Feature/CalendarPageComputingStateTest.php
    - Modules/Calendar/tests/Feature/CalendarPagePastDayStateTest.php
    - Modules/Calendar/tests/Feature/CalendarDrillThroughTest.php
    - Modules/Calendar/tests/Unit/CalendarQueryIrregularTest.php
decisions:
  - "resolveVisibleAccountIds returns ?array: null=all-visible (D-02 default when caller passes []), []=none-visible (explicit filter that matched nothing)"
  - "CalendarEntryDto.accountId widened to ?int: series with no linked occurrences have no account yet; must not be excluded from the calendar"
  - "Counterparty fallback via cluster_counterparty_key: when counterpartyIdForSeries returns null (no occurrences), look up via recurring_series.cluster_counterparty_key → counterparty slug"
  - "CarbonImmutable::parse(sprintf('%04d-%02d-01', year, month)) replaces ::create() — ::create() returns CarbonImmutable|null, unsafe at PHPStan level 10"
  - "CalendarPage uses CurrentUser contract via render() method injection — auth() global helper not allowed in module code (larastan-strict-rules)"
metrics:
  duration: "~3h (continuation across context boundary)"
  completed: "2026-06-12"
  tasks_completed: 3
  files_changed: 14
---

# Phase 6 Plan 02: CalendarQuery — Balance Query Assembly Summary

**One-liner:** CalendarQuery (final readonly) assembles list<CalendarDayDto> from approved recurring series, ForecastQuery balance points, and ±7-day occurrence matching, wired into CalendarPage via Livewire method injection.

## What Was Built

CalendarQuery.forMonth() is the central assembly service for the `/calendar` page. It:

1. **Entry placement** — Fetches all approved recurring series for the user, resolves each series' expected date for the given month, and maps them to day cells. Irregular-cadence series with null `nextExpectedAt` are excluded (Pitfall 4).

2. **Account filtering** — `resolveVisibleAccountIds()` implements the D-02/D-03 defaults: passing `[]` means "all visible" (null returned), passing a non-empty list intersects against user-owned accounts and silently drops foreign IDs (T-06-02 security gate).

3. **Balance aggregation** — `buildBalanceMap()` reads ForecastQuery for each balance-included account and sums `point_minor` per date into `eodBalanceMinor`. When no forecast run exists or its status is not `complete`, `isComputing = true` and the view renders "—".

4. **Past-day reconciliation** — `buildOccurrenceMap()` loads occurrences for the month, and `hasMatchingOccurrence()` applies a ±7-day window (MATCH_WINDOW_DAYS = 7) to mark entries `isPaid` or `isMissed` for past days.

5. **Counterparty resolution** — Primary path: occurrences → transactions → `counterparty_id` → `CounterpartyProfileQuery`. Fallback (D-16): when no occurrences exist yet, reads `recurring_series.cluster_counterparty_key` and resolves via `CounterpartyProfileQuery::bySlug()`.

6. **Blade view** — calendar-page.blade.php renders the 7-column Mon-Sun grid, entry names linked to `/recurring/series/{id}`, balance line in each cell, paid/missed badges, counterparty links, and the day panel when a day is selected.

## TDD Gate Compliance

| Gate | Commit | Description |
|------|--------|-------------|
| RED (task 1) | ee6c5cd | Irregular + account-prefs unit tests + page smoke tests |
| RED (task 2) | 18bdf58 | Balance line + computing state feature tests |
| RED (task 3) | d55f489 | Past-day reconciliation + drill-through feature tests |
| GREEN (all)  | d65e67e | Full CalendarQuery implementation + wired CalendarPage |

Note: All three GREEN gates are consolidated into a single implementation commit because the CalendarQuery implements all three contracts atomically — splitting would have left partially-functional tests in between.

## Test Results

```
Tests: 27 passed (42 assertions)
Duration: ~1.1s
```

| File | Tests |
|------|-------|
| CalendarQueryIrregularTest | 4 |
| CalendarQueryAccountPrefsTest | 4 |
| CalendarQueryPastDayMatchTest | 6 |
| CalendarPageEmptyStateTest | 2 |
| CalendarPageRendersSeriesTest | 3 |
| CalendarPageBalanceLineTest | 2 |
| CalendarPageComputingStateTest | 2 |
| CalendarPagePastDayStateTest | 2 |
| CalendarDrillThroughTest | 2 |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] CarbonImmutable::create() returns CarbonImmutable|null**
- **Found during:** PHPStan level 10 pass
- **Issue:** `CarbonImmutable::create($year, $month, 1)` is typed `CarbonImmutable|null` — PHPStan reported non-object method calls on the return value
- **Fix:** Replaced with `CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month))` which returns a guaranteed non-null CarbonImmutable
- **Files modified:** `Modules/Calendar/Internal/Services/CalendarQuery.php` (lines 120, 716)
- **Commit:** d65e67e (included in GREEN)

**2. [Rule 1 - Bug] auth() global helper not allowed in module Livewire components**
- **Found during:** PHPStan level 10 pass (larastan-strict-rules.noGlobalLaravelFunction)
- **Issue:** `CalendarPage::render()` used `auth()->user()` — flagged by both `noGlobalLaravelFunction` and `staticMethod.dynamicCall`
- **Fix:** Injected `CurrentUser` contract via render() method parameter, replacing `auth()->user()` with `$currentUser->user()`
- **Files modified:** `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php`
- **Commit:** d65e67e (included in GREEN)

**3. [Rule 2 - Missing Critical] Counterparty fallback for series with no occurrences**
- **Found during:** CalendarDrillThroughTest counterparty link test (test linked counterparty via cluster_counterparty_key with no occurrences)
- **Issue:** `counterpartyIdForSeries()` traverses occurrences → transactions → counterparty_id. When a series has no occurrences yet (just approved, no imports processed), counterparty is null and no link renders
- **Fix:** Added `resolveCounterpartySlugByClusterKey()` fallback — reads `recurring_series.cluster_counterparty_key` directly when occurrence-based lookup returns null, then resolves via `CounterpartyProfileQuery::bySlug()`
- **Files modified:** `Modules/Calendar/Internal/Services/CalendarQuery.php`
- **Commit:** d65e67e (included in GREEN)

**4. [Rule 2 - Missing Critical] CalendarEntryDto.accountId widened to ?int**
- **Found during:** Unit test setup — series with no linked occurrences have no account
- **Issue:** `accountId: int` was required but series without occurrences cannot provide one — would exclude newly-approved series from the calendar entirely
- **Fix:** Changed `accountId` from `int` to `?int`; updated account filter logic to handle null accountId against effectiveVisible
- **Files modified:** `Modules/Calendar/Public/Dto/CalendarEntryDto.php`
- **Commit:** d65e67e (included in GREEN)

**5. [Rule 1 - Bug] resolveVisibleAccountIds null/empty semantics**
- **Found during:** CalendarQueryAccountPrefsTest "drops a foreign account id" assertion
- **Issue:** When caller passes a foreign-only list that intersects to [], the original `resolveVisibleAccountIds` returned `[]` which matched the "all visible" early-exit condition (`=== []`), showing unlinked series when nothing should be visible
- **Fix:** Changed return type to `?array`: `null` = all visible (caller passed []), `[]` = nothing visible (explicit filter that yielded empty intersection); updated `buildEntryMap` effectiveVisible check accordingly
- **Files modified:** `Modules/Calendar/Internal/Services/CalendarQuery.php`
- **Commit:** d65e67e (included in GREEN)

### Worktree Infrastructure Notes

**Pest rootPath binding issue** (non-plan, infrastructure):
The Pest binary's `rootPath` resolves to the MAIN REPO (not the worktree), causing TestCase bindings from `tests/Pest.php` `->in()` declarations to not apply to Calendar test files (path mismatch). Fixed by adding explicit `uses(TestCase::class, RefreshDatabase::class)` in each Calendar test file instead of relying on global `->in()` binding.

**Autoloader shim** (non-plan, infrastructure):
The worktree's `vendor/autoload.php` was updated to prepend the worktree's `Modules\Calendar\` namespace before the main repo's autoloader, so new classes in `Internal/Services/` resolve from the worktree during testing.

## Known Stubs

None. All balance, entry, and counterparty data is wired to real queries. The `isComputing: true` state for missing forecast runs is intentional design (shows "—"), not a stub.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: account-isolation | CalendarQuery.php | resolveVisibleAccountIds intersects caller-supplied IDs against user-owned accounts via DB query — foreign IDs are silently dropped (T-06-02 satisfied) |

No new unanticipated threat surface introduced.

## Self-Check: PASSED

- [x] `Modules/Calendar/Internal/Services/CalendarQuery.php` — exists
- [x] `Modules/Calendar/tests/Unit/CalendarQueryAccountPrefsTest.php` — exists
- [x] `Modules/Calendar/tests/Unit/CalendarQueryPastDayMatchTest.php` — exists
- [x] Commit ee6c5cd — RED task 1
- [x] Commit 18bdf58 — RED task 2
- [x] Commit d55f489 — RED task 3
- [x] Commit d65e67e — GREEN (all tasks)
- [x] 27/27 tests pass
- [x] PHPStan level 10: no errors
- [x] Pint: no formatting issues
