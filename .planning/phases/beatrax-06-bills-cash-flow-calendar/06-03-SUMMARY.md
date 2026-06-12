---
phase: beatrax-06-bills-cash-flow-calendar
plan: "03"
subsystem: Calendar
tags: [calendar, livewire, blade, css, ui, phase-6]
dependency_graph:
  requires: ["06-02"]
  provides: ["06-03"]
  affects: [Calendar, Core, DevMode]
tech_stack:
  added: []
  patterns:
    - Livewire render() method injection (no constructor DI)
    - UserPreference::query()->updateOrCreate() persistence
    - "#[Url] clamped props with resolveDisplay() helper"
    - role=grid/row/gridcell ARIA semantics on calendar
    - "@layer components CSS block appended to app.css"
    - "x-core::bottom-sheet name=day-detail for phone"
    - Alpine x-show + x-transition for day panel slide
key_files:
  created:
    - Modules/Calendar/Resources/views/livewire/partials/day-panel.blade.php
    - Modules/Calendar/tests/Feature/CalendarMonthNavTest.php
    - Modules/Calendar/tests/Feature/CalendarAccountsPopoverTest.php
    - Modules/Calendar/tests/Feature/CalendarPaletteAndSidebarTest.php
  modified:
    - Modules/Calendar/Internal/Http/Livewire/CalendarPage.php
    - Modules/Calendar/Resources/views/livewire/calendar-page.blade.php
    - resources/css/app.css
decisions:
  - PHPStan level 10 requires narrowing mixed before int cast — used is_int() filter loops instead of array_map('intval', ...)
  - "preg_match returns int|false not bool — negation must use !== 1 not ! preg_match(...)"
  - x-core::bottom-sheet namespace (not bare x-bottom-sheet) — consistent with Goals/Pots module pattern
metrics:
  duration_minutes: 90
  completed_date: "2026-06-12"
  tasks_completed: 3
  tasks_total: 4
  files_created: 4
  files_modified: 3
---

# Phase 06 Plan 03: Calendar UI Summary

**One-liner:** Full `/calendar` page UI — 7-col role=grid with CalendarQuery wiring, month nav ceiling, Accounts popover with two independent per-account controls (entries / balance) persisted to user_preferences, desktop day panel + phone bottom sheet with CAL-03 series/counterparty drill links, calm-slate CSS layer, and sidebar + ⌘K palette entries verified.

---

## Tasks Completed

### Task 1: CalendarPage component — month nav, account prefs, day selection

Replaced the Plan 01 stub `render()` with the full implementation:

- `mount()` loads `calendar_entries_accounts` / `calendar_balance_accounts` from `user_preferences`
- `prevMonth()` / `nextMonth()` with 12-month forward ceiling (D-14, T-06-01)
- `selectDay(string $date)` validates Y-m-d within display month; dispatches `open-sheet` event
- `toggleEntriesAccount()` / `toggleBalanceAccount()` with ownership validation (T-06-02)
- `persistAccountPrefs()` uses `UserPreference::query()->updateOrCreate()`
- `render()` builds account roster, `atCeiling` flag, `isComputingAny`, `selectedDayDto`
- PHPStan level 10 clean

**Commit:** `e45fe4b`

**Tests:** CalendarMonthNavTest (6) + CalendarAccountsPopoverTest (6) = 12/12 passing

### Task 2: Calendar view + CSS layer

Built the full UI-SPEC §5–§8 contract:

- `calendar-page.blade.php`: page header, month summary strip (rose risk copy / computing spinner), toolbar (month nav + Accounts popover), 7-col `role="grid"` with `role="row"` / `role="gridcell"` semantics, day cells with balance corner / entry rows / `+N more` overflow / paid ✓ / missed ! / approximate ~ markers, empty state, desktop day panel include, phone `<x-core::bottom-sheet name="day-detail">`
- `partials/day-panel.blade.php`: SOD/EOD balance rows, entry rows with `↗ series` + `↗ counterparty` drill links (CAL-03), approximate note (D-15)
- `resources/css/app.css`: `@layer components` block with all 15 `.cal-*` classes from UI-SPEC §10; reduced-motion override; phone compact cell (44px, hide balance corner)

**Commit:** `88cf8c0`

**Tests:** CalendarPageRendersSeriesTest (3) + CalendarDrillThroughTest (2) + CalendarPageEmptyStateTest (2) = 7/7 passing

### Task 3: Sidebar + palette verification

Sidebar (`▦ Calendar` → `route('calendar.index')`) and palette (`calendar.index` entry) both present from Plan 01.

Added `CalendarPaletteAndSidebarTest.php` to formally test:
- `AppSidebar` renders link to `/calendar` labeled "Calendar"
- `NavigationRegistry::all()` contains entry with id `'calendar.index'`

**Commit:** `244434b`

**Tests:** 2/2 passing

---

## Human Verification Pending

**Task 4 (checkpoint:human-verify) deferred per orchestrator instructions.**

The 8-point browser QA checklist below requires human visual verification at `http://localhost:8000/calendar`:

1. Visit `/calendar`. Confirm the month grid renders with recurring entries on plausible days and a day-end balance figure in each cell corner.
2. Open the Accounts popover. Toggle an account's "Count in balance" off — balance line changes but entries stay visible (D-02). Reload — choice persists.
3. Click a day with entries → desktop right-rail panel slides in. Confirm entry rows show name/account/amount, and `↗ series` / `↗ counterparty` links navigate correctly (CAL-03).
4. Navigate forward month-by-month — next button disables ~12 months out. Navigate back freely.
5. If a month has a projected below-zero day, confirm rose cell tint + summary strip callout ("Balance dips below €0 on …").
6. Resize to phone width — cells collapse to compact badges; tapping a day opens the bottom sheet with the same content.
7. Visit `/forecast` — confirm the horizon switch shows 180 and 365 options and renders a chart.
8. Open ⌘K, type "calendar" — confirm the Calendar entry appears and navigates.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan level 10 disallows string callbacks to array_map**
- **Found during:** Task 1 PHPStan pass
- **Issue:** `array_map('intval', $decoded)` — phpstan-strict-rules prohibits string callbacks
- **Fix:** Replaced with `is_int()` filter loops; `array_map(fn(mixed $v): int => (int) $v, ...)` also rejected (cannot cast mixed to int)
- **Files modified:** `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php`
- **Commit:** included in e45fe4b

**2. [Rule 1 - Bug] PHPStan level 10 disallows negating non-bool preg_match result**
- **Found during:** Task 1 PHPStan pass
- **Issue:** `! preg_match(...)` — preg_match returns `int|false`; strict rules require boolean in negated context
- **Fix:** Changed to `preg_match(...) !== 1`
- **Files modified:** `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php`
- **Commit:** included in e45fe4b

**3. [Rule 1 - Bug] Bottom-sheet component namespace was wrong**
- **Found during:** Task 2 test run
- **Issue:** `<x-bottom-sheet>` fails — must be `<x-core::bottom-sheet>` (module namespaced, consistent with Goals/Pots)
- **Fix:** Changed to `<x-core::bottom-sheet name="day-detail" ...>`
- **Files modified:** `Modules/Calendar/Resources/views/livewire/calendar-page.blade.php`
- **Commit:** included in 88cf8c0

**4. [Rule 3 - Pint] Pint reformatted CalendarPage.php + CalendarAccountsPopoverTest.php**
- **Found during:** Task 2 Pint check
- **Issue:** binary_operator_spaces, unary_operator_spaces, not_operator_with_successor_space violations
- **Fix:** `vendor/bin/pint Modules/Calendar` auto-fixed
- **Commit:** included in 88cf8c0

---

## Known Stubs

None. All data is live from CalendarQuery — no hardcoded values flow to UI.

---

## Threat Flags

No new threat surface introduced. All threat mitigations from the plan's `<threat_model>` are implemented:

| Threat ID | Status |
|-----------|--------|
| T-06-01 | Mitigated — month clamping + nextMonth() ceiling in CalendarPage |
| T-06-02 | Mitigated — fetchOwnedAccountIds() intersection before every toggle |
| T-06-04 | Mitigated — counterpartySlug comes only from user-scoped CalendarQuery output |
| T-06-05 | Mitigated — route under auth middleware from Plan 01 |

---

## Self-Check

### Created files exist
- `Modules/Calendar/Resources/views/livewire/partials/day-panel.blade.php` — FOUND
- `Modules/Calendar/tests/Feature/CalendarMonthNavTest.php` — FOUND
- `Modules/Calendar/tests/Feature/CalendarAccountsPopoverTest.php` — FOUND
- `Modules/Calendar/tests/Feature/CalendarPaletteAndSidebarTest.php` — FOUND

### Commits exist
- `e45fe4b` — Task 1: CalendarPage component
- `88cf8c0` — Task 2: Calendar view + CSS layer
- `244434b` — Task 3: Sidebar/palette test

### Test counts
- Task 1: 12/12 passing
- Task 2: 7/7 passing (+ pre-existing 6 balance/computing/past-day tests all green)
- Task 3: 2/2 passing
- Full Calendar suite: 41/41 passing

## Self-Check: PASSED
