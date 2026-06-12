---
phase: "07"
plan: "06"
subsystem: "Tax / Ledger / Counterparties / CashBook"
tags: ["tax", "badge", "livewire", "trait", "blade", "cross-surface"]
dependency_graph:
  requires:
    - "07-05: TaxPage cockpit (CSS classes .tax-badge, .tax-badge--untagged, sidebar)"
    - "07-01: TaxTransactionTag model + migration"
    - "07-02: TagTransaction / UntagTransaction actions"
    - "07-04: TaxTagQuery::forTransactionIds (batch-load query)"
  provides:
    - "HandlesTaxTagging trait (Public namespace) for any Livewire surface"
    - "tax-badge Blade component (x-tax::tax-badge)"
    - "tax-tag-popover @include partial (desktop + bottom-sheet)"
    - "tax-tag-popover-body shared form partial"
    - "Tax badge + picker on: TransactionsList, TransactionDetail, CounterpartyProfile, CashBookPage"
    - "TaxBadgeSurfacesTest: 17 assertions across all four surfaces"
  affects:
    - "Modules/Ledger"
    - "Modules/Counterparties"
    - "Modules/CashBook"
    - "Modules/Tax"
tech_stack:
  added: []
  patterns:
    - "HandlesTaxTagging trait in Public namespace (not Internal) for cross-module use"
    - "@include() instead of <x-component> for Livewire view-scope inheritance"
    - "taxTagStateFor: single whereIn query for all row ids on page (Pitfall-1)"
    - "$wire.taxPickerTxId Alpine watch (not @js()) for reactive popover open/close"
    - "Livewire method-parameter DI (no constructor injection on components)"
key_files:
  created:
    - "Modules/Tax/Resources/views/components/tax-badge.blade.php"
    - "Modules/Tax/Resources/views/components/tax-tag-popover.blade.php"
    - "Modules/Tax/Resources/views/components/tax-tag-popover-body.blade.php"
    - "Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php"
    - "Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php"
  modified:
    - "Modules/Ledger/Internal/Http/Livewire/TransactionsList.php"
    - "Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php"
    - "Modules/Ledger/Resources/views/livewire/transactions-list.blade.php"
    - "Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php"
    - "Modules/Counterparties/Internal/Http/Livewire/CounterpartyProfile.php"
    - "Modules/Counterparties/Resources/views/livewire/counterparty-profile.blade.php"
    - "Modules/Counterparties/Resources/views/livewire/profile-tabs/_recent-activity.blade.php"
    - "Modules/Counterparties/Resources/views/livewire/profile-tabs/{merchant,bank,government,personal,unknown}.blade.php"
    - "Modules/CashBook/Internal/Http/Livewire/CashBookPage.php"
    - "Modules/CashBook/Resources/views/livewire/cash-book-page.blade.php"
    - "Modules/Tax/Public/Services/TaxTagQuery.php (untaggedIdsForCounterparty added)"
    - "tests/.pest/snapshots/Snapshot/SidebarTest/*.snap (updated for Tax nav item)"
decisions:
  - "HandlesTaxTagging placed in Public namespace (not Internal) to comply with TaxBoundaryTest arch rule"
  - "Popover rendered via @include() not <x-tax::tax-badge> to inherit parent Livewire view scope for property access"
  - "Alpine $wire.taxPickerTxId watch (not @js() snapshot) for reactive open/close — @js() only captures initial value"
  - "taxTagStateFor issues ONE whereIn query per render via TaxTagQuery::forTransactionIds (Pitfall-1 guard)"
  - "batchSuggestionDismissed flag prevents batch suggestion re-surfacing after apply (Pitfall-7 guard)"
metrics:
  duration: "~180 min (across two sessions)"
  completed: "2026-06-12"
  tasks_completed: 2
  files_created: 5
  files_modified: 18
---

# Phase 07 Plan 06: Tax Badge UI + Surface Integration Summary

Shared tax badge UI, category picker popover/bottom sheet, HandlesTaxTagging Livewire trait, integrated on TransactionsList / TransactionDetail / CounterpartyProfile / CashBookPage with 17 feature-test assertions covering all five D-spec criteria.

## What Was Built

### Task 1: Shared components + trait

**`x-tax::tax-badge`** (`Modules/Tax/Resources/views/components/tax-badge.blade.php`) — anonymous Blade component with two states:
- Tagged: emerald pill button with `data-testid="tax-badge-tagged-{id}"`, dispatches `tax-edit-tag`
- Untagged: ghost "Tag" button with `data-testid="tax-badge-untagged-{id}"`, dispatches `tax-tag`
- `$showAlways` prop: `false` for desktop hover-reveal (`opacity-0 group-hover:opacity-100`), `true` for phone always-visible

**`tax-tag-popover`** (`Modules/Tax/Resources/views/components/tax-tag-popover.blade.php`) — rendered via `@include()` (not `<x-tax::>`) to inherit Livewire view scope. Alpine `$wire.taxPickerTxId` watch drives open/close:
- Desktop: fixed-center popover, click-outside + Escape dismiss
- Phone: bottom-sheet with handle bar, backdrop scrim, `safe-area-inset-bottom` padding
- Batch-tag banner shown when `batchSuggestion.untaggedCount >= 2` and `!batchSuggestionDismissed`

**`tax-tag-popover-body`** (`Modules/Tax/Resources/views/components/tax-tag-popover-body.blade.php`) — shared form:
- Note textarea
- Category listbox (No category first, then active cats)
- Inline new-category quick-add (expandable row)
- Year-assignment row (booked year vs seasonal tax year)
- Save / Remove tag footer

**`HandlesTaxTagging`** (`Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php`) — reusable Livewire trait:
- Public Livewire properties: `$taxPickerTxId`, `$pickerNote`, `$pickerCategoryId`, `$pickerYearOverride`, `$pickerCategories`, `$pickerInlineNewName`, `$pickerIsNewCatOpen`, `$batchSuggestion`, `$batchSuggestionDismissed`
- `#[On('tax-tag')]` listener: immediate tag + open picker + batch suggestion (Pitfall-1: ONE query)
- `#[On('tax-edit-tag')]` listener: pre-fill picker from existing tag
- `saveTaxCategory()`, `addInlineCategory()`, `untag()`, `applyBatchTag()`, `dismissBatch()`, `closePicker()`
- `taxTagStateFor(array $ids, TaxTagQuery, CurrentUser): array<int, array{taxTagged, taxCategoryShortName}>` — ONE whereIn query
- `resolveCurrentTaxYear(Clock): int` — D-22 seasonal default (month ≤ 4 → year-1)

### Task 2: Surface integration + tests

All four surfaces apply `use HandlesTaxTagging` and pass `TaxTagQuery $taxTagQuery` to `render()`:

| Surface | PHP file | View change |
|---------|----------|-------------|
| TransactionsList | + `use HandlesTaxTagging`, `taxTagStateFor()`, `taxState` to view | @include popover (inside root div), Tax column header, `<x-tax::tax-badge>` per row |
| TransactionDetail | + `use HandlesTaxTagging`, `taxTagStateFor([$id])`, `txTaxRow` to view | @include popover (inside root div), tax-tag-section with badge |
| CounterpartyProfile | + `use HandlesTaxTagging`, `taxTagStateFor($recentIds)`, `taxState` to view | @include popover (inside root div), taxState passed to per-type partials |
| CashBookPage | + `use HandlesTaxTagging`, `taxTagStateFor($entryIds)`, `taxState` to view | @include popover (inside root div), badge on phone + desktop entry lists |

**TaxBadgeSurfacesTest** — 17 assertions:
- Per surface: (a) untagged ghost button renders, (b) `tax-tag` event writes DB row, (c) tagged badge renders, (d) cross-user isolation
- Batch suggestion: ≥2 untagged siblings → `batchSuggestion` populated; `applyBatchTag` writes all; `batchSuggestionDismissed=true` (Pitfall-7)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `@include` popover placed outside root Livewire div (MultipleRootElementsDetectedException)**
- **Found during:** Task 2 test run — all CashBookPage and TransactionsList tests threw `MultipleRootElementsDetectedException`
- **Issue:** In `cash-book-page.blade.php` and `transactions-list.blade.php`, the `@include('tax::components.tax-tag-popover')` was rendered before the root `<div>` — Livewire only allows one root element
- **Fix:** Moved `@include` to be the first child inside the root `<div>` in both files
- **Files modified:** `cash-book-page.blade.php`, `transactions-list.blade.php`
- **Commit:** `5e57edc`

**2. [Rule 2 - Missing] HandlesTaxTagging must be in Public namespace**
- **Found during:** PHPStan cross-module boundary check
- **Issue:** The trait was initially in `Modules\Tax\Internal\Http\Livewire\Concerns\`. Consumer components in Ledger/Counterparties/CashBook import `Modules\Tax\Internal\*` which violates `TaxBoundaryTest` arch rule
- **Fix:** Moved trait to `Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging`. Deleted the Internal file. Updated all four consumer imports via sed
- **Files modified:** all four PHP components, trait file moved
- **Commit:** `5e57edc`

**3. [Rule 1 - Bug] SidebarTest snapshot stale**
- **Found during:** Full test suite run (1 failure)
- **Issue:** The sidebar snapshot was captured before Plan 05 added the "Tax" nav link. The snapshot test was failing from Plan 05 onwards (pre-existing out-of-scope failure now in this test run)
- **Fix:** Ran `php artisan test tests/Snapshot/SidebarTest.php --update-snapshots` to update snapshot to current sidebar HTML
- **Files modified:** `tests/.pest/snapshots/Snapshot/SidebarTest/it_matches_the_rendered_sidebar_HTML_for_a_developer__snapshot_lock_.snap`
- **Commit:** `5e57edc`

**4. [Rule 1 - Bug] `->assertDispatched('toast')` incorrect for `tax-tag` listener**
- **Found during:** Task 2 test run — 1 assertion failure in TaxBadgeSurfacesTest
- **Issue:** The test asserted a `toast` event fires on `tax-tag` dispatch, but `tagTransaction()` does not dispatch `toast` (only `saveTaxCategory`, `untag`, `applyBatchTag` do)
- **Fix:** Removed incorrect `->assertDispatched('toast')` assertion; added comment explaining toast timing
- **Files modified:** `TaxBadgeSurfacesTest.php`
- **Commit:** `5e57edc`

**5. [Rule 1 - Bug] PHPStan `Cannot cast mixed to int` in CounterpartyProfile + CashBookPage**
- **Found during:** PHPStan run after Task 1
- **Issue:** `array_map(static fn (object $row): int => (int) $row->id, ...)` — stdClass properties typed as `mixed`
- **Fix:** Changed to `is_numeric($row->id) ? (int) $row->id : 0` pattern
- **Files modified:** `CounterpartyProfile.php`, `CashBookPage.php`
- **Commit:** `5e57edc`

**6. [Rule 1 - Bug] PHPStan `Casting to int something that's already int` in HandlesTaxTagging**
- **Found during:** PHPStan run after Task 1
- **Issue:** `$cpId = (int) $this->batchSuggestion['counterpartyId']` where shape declares `counterpartyId: int`
- **Fix:** Removed redundant cast: `$cpId = $this->batchSuggestion['counterpartyId']`
- **Files modified:** `HandlesTaxTagging.php`
- **Commit:** `5e57edc`

### Known Stubs

None — all UI surfaces render real data from the database via HandlesTaxTagging + TaxTagQuery.

## Threat Flags

No new network endpoints, auth paths, or file access patterns introduced. All tag/untag operations delegate to user-scoped actions (T-07-18). `taxTagStateFor` scopes to `user_id` via TaxTagQuery (T-07-19/20). Cross-user isolation verified in tests.

## Test Results

- **Before plan:** 3555 passed
- **After plan:** 3584 passed (17 new TaxBadgeSurfacesTest + 12 from other phases in this session), 0 failures
- **PHPStan:** 0 errors (full module scan)
- **Pint:** clean

## Commits

| Hash | Message |
|------|---------|
| `8c21d01` | `feat(07-06): shared tax-badge + picker + HandlesTaxTagging trait` |
| `5e57edc` | `feat(07-06): integrate tax badge on all four surfaces + TaxBadgeSurfacesTest` |

## Self-Check: PASSED

Files exist:
- `Modules/Tax/Resources/views/components/tax-badge.blade.php` ✓
- `Modules/Tax/Resources/views/components/tax-tag-popover.blade.php` ✓
- `Modules/Tax/Resources/views/components/tax-tag-popover-body.blade.php` ✓
- `Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php` ✓
- `Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php` ✓

Commits exist: `8c21d01`, `5e57edc` both in `git log --oneline`.
