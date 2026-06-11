---
phase: 04-responsive-installable-pwa-seed-008
plan: 08
subsystem: Ledger/TransactionsList
tags: [infinite-scroll, phone, livewire, accumulation, sentinel]
requirements: [PWA-01]

depends_on:
  requires: []
  provides:
    - Accumulating phone card-list on /transactions (rows append, not replace)
    - Re-bindable wire:intersect sentinel keyed per cursor
  affects:
    - Modules/Ledger/Internal/Http/Livewire/TransactionsList.php
    - Modules/Ledger/Resources/views/livewire/transactions-list.blade.php

tech_stack:
  added: []
  patterns:
    - Livewire public array property for serialised scalar row state (Livewire-dehydratable)
    - $appendedCursorIds guard set prevents double-append on re-render
    - wire:key="sentinel-{$nextCursorId}" re-binds IntersectionObserver per cursor
    - Alpine intersect .margin modifier for rootMargin early-trigger buffer

key_files:
  created:
    - Modules/Ledger/tests/Feature/TransactionsListInfiniteScrollTest.php
  modified:
    - Modules/Ledger/Internal/Http/Livewire/TransactionsList.php
    - Modules/Ledger/Resources/views/livewire/transactions-list.blade.php

decisions:
  - Expose $hasMore/$nextCursorId/$nextCursorPostedAt as public component properties so the blade sentinel and Livewire test harness can read the next-page cursor directly without parsing view data
  - Store accumulated rows as list<array{...}> (scalar array) not list<TransactionRowDto> so Livewire can dehydrate the component state across wire requests
  - Use $appendedCursorIds guard set (key = cursorId, sentinel 0 = first page) to prevent double-append on re-renders that do not advance the cursor
  - wire:intersect.margin.0px.0px.200px.0px syntax uses Alpine intersect modifier API (not attribute-value rootMargin)
  - array_values() wrapping of array_map() result ensures list<...> type for PHPStan level 10 compliance

metrics:
  duration: 17m
  completed: 2026-06-11
  tasks_completed: 2
  files_modified: 3
  tests_added: 4
---

# Phase 4 Plan 8: Infinite-Scroll Accumulation for Phone Ledger Summary

Phone-width transaction list now accumulates rows across `loadMore()` calls (append-not-replace) with a re-bindable `wire:intersect` sentinel keyed per cursor, closing UAT Gap 1.

## Tasks Completed

| Task | Type | Commit | Description |
|------|------|--------|-------------|
| RED: Failing accumulation tests | TDD | 1f46bce | `TransactionsListInfiniteScrollTest` — 4 tests proving 50→100→130 accumulation, no duplicate ids, reset on toggleFullHistory |
| Task 1 GREEN: `$accumulatedRows` state | feat | 1a85d67 | Add accumulating phone-row state, cursor-guard, public $hasMore/$nextCursorId properties |
| Task 2: Blade + sentinel | feat | 09d7ed7 | Phone card-list iterates `$accumulatedRows`; sentinel re-keyed on `$nextCursorId` |

## What Was Built

### TransactionsList.php Changes

- `public array $accumulatedRows = []` — serialised phone-row data accumulated across `loadMore()` calls. Each element is a scalar array (`id`, `bookedAt`, `counterpartyName`, `counterpartySlug`, `categoryId`, `amountMinor`, `amountCurrency`, `secondaryMinor`, `secondaryCurrency`) so Livewire can dehydrate the state.
- `public bool $hasMore`, `$nextCursorId`, `$nextCursorPostedAt` — exposed from the most-recently rendered page so the sentinel blade and test harness can read cursor state as component properties.
- `protected array $appendedCursorIds` — guard set preventing double-append when Livewire re-renders without a cursor advance (key `0` = first page, key `$cursorId` = subsequent pages).
- `render()` resets `$accumulatedRows` when `cursorId === null` (first page or window-switch) and appends on new cursors. `toggleFullHistory()` clears all accumulated state.
- `rowToArray(TransactionRowDto): array` — private serialiser that converts Money objects to minor+currency pairs.

### transactions-list.blade.php Changes

- Phone `md:hidden` section: `@foreach ($accumulatedRows as $row)` replaces `@foreach ($page->rows as $row)`. Money reconstructed at render time via `$rowMoney()` helper (`Money::ofMinor($row['amountMinor'], $row['amountCurrency'])`).
- Sentinel: `wire:key="sentinel-{{ $nextCursorId }}"` ensures Livewire 4 mounts a fresh node after each morph so the IntersectionObserver re-binds for the next page.
- Directive: `wire:intersect.margin.0px.0px.200px.0px="loadMore(...)"` — no `.once` modifier; 200px bottom margin for early trigger before reaching viewport bottom.
- Desktop `hidden md:block` section: **unchanged** — still iterates `$page->rows` with the explicit "Load more" button.

### Test Coverage

`TransactionsListInfiniteScrollTest.php` (4 tests, group phase-4):
1. Accumulates 50 → 100 → 130 rows across two sequential `loadMore()` calls
2. No duplicate ids in accumulated set after all pages loaded
3. `toggleFullHistory()` resets accumulated rows to a single page
4. Fresh mount starts with exactly 50 accumulated rows

## Verification Results

```
vendor/bin/pest Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php TransactionsListInfiniteScrollTest.php
Tests: 11 passed (26 assertions)

vendor/bin/pest Modules/Ledger — Tests: 1 skipped, 173 passed (623 assertions)
vendor/bin/pint Modules/Ledger --test — PASSED
vendor/bin/phpstan analyse Modules/Ledger — No errors (level 10)
```

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Seed dates were outside 90-day window**
- **Found during:** Task 1 GREEN (tests returned 0 accumulated rows)
- **Issue:** Test seeded transactions from 2025-01-01 but CarbonImmutable::setTestNow is 2026-06-15; `recent(daysBack: 90)` filters to posted_at >= 2026-03-17, so none of the 2025 rows appeared.
- **Fix:** Changed seed start date to 2026-04-01 (within the 90-day window).
- **Files modified:** `TransactionsListInfiniteScrollTest.php`
- **Commit:** 1a85d67

**2. [Rule 2 - Missing functionality] `$component->get('page')` returns null in Livewire tests**
- **Found during:** Task 1 GREEN (test couldn't read cursor from view data)
- **Issue:** `page` is view data passed to `$views->make()`, not a Livewire component property — `$component->get('page')` returns null.
- **Fix:** Added `$hasMore`, `$nextCursorId`, `$nextCursorPostedAt` as public component properties populated from the page DTO in `render()`. Tests read cursor state via `$component->get('nextCursorId')`.
- **Files modified:** `TransactionsList.php`, `TransactionsListInfiniteScrollTest.php`
- **Commit:** 1a85d67

**3. [Rule 1 - Bug] PHPStan array type mismatch on `$accumulatedRows`**
- **Found during:** Task 2 static analysis
- **Issue:** `array_map()` returns `array<int|string, ...>` which PHPStan rejects as `list<...>`.
- **Fix:** Wrapped `array_map()` call with `array_values()` to produce a sequential list.
- **Files modified:** `TransactionsList.php`
- **Commit:** 09d7ed7

### Worktree Test Infrastructure Note

This plan ran inside a git worktree where the shared `vendor/` directory has `$baseDir` pointing to the main repo root. To run Livewire feature tests against the worktree's implementation (not the main repo's unmodified files), the execution:
1. Created a `vendor` symlink in the worktree pointing to the main repo vendor
2. Ran `composer dump-autoload` from the worktree to update the shared autoload map's `$baseDir` to the worktree path
3. Placed a copy of the test file at the main repo's corresponding path (since Pest resolves `rootPath` from the vendor binary's `__DIR__`)
4. After testing, restored the main repo to its original state

This is a worktree-execution infrastructure concern, not a code deviation.

## Known Stubs

None.

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes introduced.

## Self-Check: PASSED

All files present:
- Modules/Ledger/Internal/Http/Livewire/TransactionsList.php — FOUND
- Modules/Ledger/Resources/views/livewire/transactions-list.blade.php — FOUND
- Modules/Ledger/tests/Feature/TransactionsListInfiniteScrollTest.php — FOUND
- .planning/phases/beatrax-04-responsive-installable-pwa-seed-008/04-08-SUMMARY.md — FOUND

All commits present:
- 1f46bce test(04-08): add failing infinite-scroll accumulation tests (RED) — FOUND
- 1a85d67 feat(04-08): add accumulatedRows phone-scroll state to TransactionsList (GREEN) — FOUND
- 09d7ed7 feat(04-08): wire phone card-list to accumulated rows and fix sentinel re-binding — FOUND
