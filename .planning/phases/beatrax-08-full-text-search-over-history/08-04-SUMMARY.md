---
phase: 08-full-text-search-over-history
plan: "04"
subsystem: Ledger / Search
tags: [search, filter, livewire, url-binding, fts5, css]
dependency_graph:
  requires: ["08-02", "08-03"]
  provides: ["08-05"]
  affects: []
tech_stack:
  added: []
  patterns:
    - "#[Url] property binding for cursor-safe URL state"
    - "Alpine.js popover panels (no flux:popover — unavailable in test env)"
    - "Pitfall-4: highlight/snippet excluded from accumulatedRows (snapshot safety)"
    - "T-08-09: {!! $sRow->highlightedCounterparty !!} for server-built FTS markup only"
    - "Method-DI for SearchQuery in render() — no constructor injection"
key_files:
  created:
    - Modules/Ledger/tests/Feature/TransactionsListSearchTest.php
    - Modules/Ledger/Resources/views/livewire/partials/search-toolbar.blade.php
    - Modules/Ledger/Resources/views/livewire/partials/search-filter-popovers.blade.php
    - Modules/Ledger/Resources/views/livewire/partials/search-no-results.blade.php
  modified:
    - Modules/Ledger/Internal/Http/Livewire/TransactionsList.php
    - Modules/Ledger/Resources/views/livewire/transactions-list.blade.php
    - resources/css/app.css
decisions:
  - "Replace flux:popover with Alpine.js plain HTML popovers — Flux anonymous component stubs do not include a popover directory, causing 'Unable to locate class or view for component flux::popover' in test environments. Alpine.js popover achieves the same UX without Flux dependency."
  - "Replace flux:radio.group / flux:radio with plain label+input[type=radio] for the same reason."
  - "filterAccounts / filterCategories typed as list<int> to satisfy PHPStan level 10 missingType.iterableValue rule."
  - "array_values(array_map(...)) on a list<...> simplified to array_map — PHPStan detects the array_values call has no effect on a list type."
metrics:
  duration: "~40 minutes active work (continued from previous session)"
  completed_date: "2026-06-13"
  tasks_completed: 2
  files_changed: 7
---

# Phase 08 Plan 04: Search and Filter UI for /transactions Summary

Added full URL-bound search/filter surface to `TransactionsList` Livewire component — 8 `#[Url]` props, `SearchQuery::search()` render branch, search toolbar, Alpine.js filter popovers, no-results state, FTS5 highlight/snippet rendering, and the complete `.srch-*` CSS namespace.

## Tasks Completed

| # | Name | Commit | Files |
|---|------|--------|-------|
| 1 | Search props, render() branch, toolbar, filter popovers, no-results partials, test GREEN | 3187ca6 | TransactionsList.php, transactions-list.blade.php, search-toolbar.blade.php, search-filter-popovers.blade.php, search-no-results.blade.php, TransactionsListSearchTest.php |
| 2 | `.srch-*` CSS namespace | 8724dd0 | resources/css/app.css |

## Verification

- `TransactionsListSearchTest`: 10/10 green
- `TransactionsListCurrencyToggleTest`: 7/7 green
- `TransactionsListInfiniteScrollTest`: 4/4 green
- `TaxBadgeSurfacesTest` (TransactionsList surface): 13/13 green
- Total: 34/34 assertions pass
- PHPStan level 10: no errors
- Pint: clean

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Replaced flux:popover with Alpine.js plain HTML popovers**
- **Found during:** Task 1 — all 10 search tests and 1 existing test failed with `Unable to locate a class or view for component [flux::popover]`
- **Issue:** `vendor/livewire/flux/stubs/resources/views/flux/` does not include a `popover` subdirectory. Flux's anonymous component path registration cannot resolve `flux::popover`, `flux::popover.trigger`, or `flux::popover.content` tags.
- **Fix:** Rewrote `search-filter-popovers.blade.php` to use Alpine.js `x-data="{ open: false }"` + `x-show` / `x-on:click.outside` driven plain `<div>` panels. Also replaced `flux:radio.group` / `flux:radio` in `search-toolbar.blade.php` (phone bottom sheet) with `@foreach` over `<label><input type="radio">` elements.
- **UX impact:** None — identical behaviour, same CSS classes, Alpine.js drives open/close transitions.
- **Files modified:** search-filter-popovers.blade.php, search-toolbar.blade.php

**2. [Rule 2 - PHPStan] Typed array PHPDocs on filterAccounts / filterCategories**
- **Found during:** PHPStan run post-Task 1
- **Issue:** `missingType.iterableValue` — `public array $filterAccounts = []` lacked value type.
- **Fix:** Added `@var list<int>` PHPDoc to both properties.

**3. [Rule 1 - PHPStan] Removed redundant array_values on list and redundant is_numeric + cast**
- **Found during:** PHPStan run post-Task 1 (after fixing #2 the `list<int>` type exposed two more errors)
- **Issue:** `array_values()` on an already-list type is a no-op (PHPStan `arrayValues.list`); `is_numeric($v)` on an `int` always evaluates true; `(int) $v` on an `int` is a useless cast.
- **Fix:** Removed `array_values(array_map(...))` wrapping in the search accumulation block; simplified `SearchFilters` accounts/categories construction from defensive `array_map` with mixed type to direct `array_filter` on the typed `list<int>`.

## Known Stubs

None — all search data flows through real `SearchQuery::search()` with a seeded FTS table in tests.

## Self-Check: PASSED

- [x] `Modules/Ledger/tests/Feature/TransactionsListSearchTest.php` exists
- [x] `Modules/Ledger/Resources/views/livewire/partials/search-toolbar.blade.php` exists
- [x] `Modules/Ledger/Resources/views/livewire/partials/search-filter-popovers.blade.php` exists
- [x] `Modules/Ledger/Resources/views/livewire/partials/search-no-results.blade.php` exists
- [x] Commit 3187ca6 exists
- [x] Commit 8724dd0 exists
