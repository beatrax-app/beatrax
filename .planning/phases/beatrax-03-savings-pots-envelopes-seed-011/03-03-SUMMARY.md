---
phase: 03-savings-pots-envelopes-seed-011
plan: "03"
subsystem: /pots page — Livewire component, Blade view, route, sidebar entry
tags: [pots, livewire, blade, ui, wave-3, tdd-green]
dependency_graph:
  requires:
    - "03-01 Pots module scaffold (pots + pot_movements tables, Pot model, DTOs, exceptions)"
    - "03-02 PotBalanceQuery + PotWriter domain services"
  provides:
    - "PotsPage Livewire component (all CRUD actions, method-parameter DI)"
    - "/pots route wired to PotsPage::class (replaces abort(501) stub)"
    - "pots.pots-page Livewire component registration in PotsServiceProvider"
    - "Pots sidebar entry after Goals in app-sidebar.blade.php (D-14)"
    - "pots-page.blade.php: cards grouped by account, reconciliation header, 4 Flux modals, inline history"
  affects:
    - "Modules/Core/Resources/views/livewire/app-sidebar.blade.php (Pots nav entry)"
    - "Modules/Pots/tests/Feature/PotsPageTest.php (import_run_id bug fixed)"
tech_stack:
  added: []
  patterns:
    - "Method-parameter DI on every Livewire action (GoalsPage pattern — no constructor injection)"
    - "render() builds $groups keyed by accountId, $reconciliations per account"
    - "Alpine x-data/x-show/x-collapse for inline history toggle (D-17)"
    - "Flux modals for create/edit/fund/move/withdraw (4 distinct flux:modal blocks)"
    - "Archive micro-confirm strip replaces card footer (matches GoalsPage pattern)"
key_files:
  created:
    - Modules/Pots/Internal/Http/Livewire/PotsPage.php
    - Modules/Pots/Resources/views/livewire/pots-page.blade.php
  modified:
    - Modules/Pots/Providers/PotsServiceProvider.php
    - Modules/Pots/Routes/web.php
    - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
    - Modules/Pots/tests/Feature/PotsPageTest.php
decisions:
  - "PotsPage render() groups flat PotRow list by accountId (dict) — view iterates groups dict; reconciliation built per-account in render()"
  - "Task 3 checkpoint:human-verify bypassed per checkpoint_override (standing user instruction: autonomous, no mid-plan checkpoints); human visual UAT deferred to phase-end browser UAT"
  - "goalsForPicker excludes goals already linked to OTHER pots (current pot's goal allowed in edit mode)"
  - "categoriesForPicker uses QueryBuilder type annotation to satisfy PHPStan level 10 method.nonObject"
metrics:
  duration: "22m"
  completed_date: "2026-06-10"
  tasks_completed: 2
  files_changed: 6
---

# Phase 3 Plan 03: /pots Page — Livewire Component + Blade View Summary

Delivers the complete `/pots` user-facing surface: a Livewire component wired into the sidebar next to Goals, backed by all domain services from Plans 01-02. Turns the Wave 0 RED tests for POTS-01 and POTS-02 GREEN.

## What Was Built

### Task 1: PotsPage Livewire Component + Provider + Route + Sidebar Entry

`Modules/Pots/Internal/Http/Livewire/PotsPage.php` — final class, `declare(strict_types=1)`, method-parameter DI on ALL actions.

**Public state:**
- Create/edit: `name`, `amount`, `accountId`, `linkType` (goal|category|none), `goalId`, `categoryId`, `editPotId`
- Operation modal: `operationPotId`, `operationAmount`, `operationMemo`, `operationKind`, `transferTargetPotId`
- Lifecycle: `archivingPotId`, `showArchived`
- Errors: `errorName`, `errorAmount`

**Actions implemented (all with `isAuthenticated()` guard):**
- `createPot(CurrentUser, PotWriter)` — validates blank name, resolves linkType XOR, calls `$writer->save()`, maps InsufficientUnallocatedException to errorAmount, toast "Pot created."
- `openEdit(int $potId, PotBalanceQuery, CurrentUser)` + `updatePot(CurrentUser, PotWriter)` — prefill from forUser(), call `$writer->update()`, toast "Pot updated."
- `fundPot(CurrentUser, PotWriter, PotBalanceQuery)` — calls `$writer->fund()`, over-allocation error shows unallocated available, toast "Pot funded."
- `withdrawPot(CurrentUser, PotWriter, PotBalanceQuery)` — calls `$writer->withdraw()`, over-source error shows pot balance, toast "Withdrawn from pot."
- `movePot(CurrentUser, PotWriter, PotBalanceQuery)` — calls `$writer->transfer()`, toast "Funds moved."
- `confirmArchive`/`cancelArchive`/`archivePot`/`restorePot` — mirrors GoalsPage pattern; archivePot dispatches toast with undoAction
- `cancel` — closes modal + resetForm

**`render(CurrentUser, PotBalanceQuery, DatabaseManager, ViewFactory): View`:**
- Unauthenticated early return with empty groups/reconciliations/archived/accounts (IN-03 defence-in-depth)
- Builds `$groups[accountId][]` from `$query->forUser($user)` flat list
- Builds `$reconciliations[accountId]` per group via `$query->reconciliationForAccount()`
- Fetches `$archived` from `$query->archivedForUser()`
- Fetches `$accounts` for the picker (ordered by name)
- Fetches `$goalsForPicker` — excludes goals linked to OTHER active pots (edit mode respects current pot's goal)
- Fetches `$categoriesForPicker` — global OR user-owned (QueryBuilder type annotation added)
- Builds `$potsForMove[accountId][]` for move modal destination picker
- Returns `pots::livewire.pots-page` view with `$view->extends('layouts.app', ...)` + `@phpstan-ignore`

**PotsServiceProvider:** `$livewire->component('pots.pots-page', PotsPage::class)` uncommented (Plan 03 activation).

**Routes/web.php:** `Route::get('/pots', PotsPage::class)->name('pots.index')` — closure stub replaced.

**app-sidebar.blade.php:** Pots `<a>` entry inserted after Goals at line 140 (D-14 sidebar entry next to Goals).

**Verification:** 6/6 PotsPageTest GREEN (exit 0); PHPStan level 10 0 errors; Pint clean.

### Task 2: pots-page Blade View

`Modules/Pots/Resources/views/livewire/pots-page.blade.php` — full 623-line view matching 03-UI-SPEC.md.

**@php block:** `use Modules\Ledger\Public\ValueObjects\Money; $fmt = static fn(...)` — verbatim from goals-page.

**Page structure per 03-UI-SPEC:**
- Header: h1 "Pots" (text-2xl font-semibold) + subtitle "Virtual sub-balances that always add up to your real account balance." + "Add pot" CTA (absent when no accounts)
- Empty state: "No pots yet" + "Create virtual sub-balances…" body + "Add your first pot" CTA
- Account groups (D-14): `space-y-8`, each with account name header + inline "Add pot" ghost button + reconciliation line
- Amber warning banner (D-02): `role="alert"`, `<flux:icon.exclamation-triangle>`, "Pots exceed real balance by {amount} — rebalance to fix" — rendered above reconciliation line when `$rec->isOverAllocated`
- Reconciliation line (D-15): font-mono tabular-nums, unallocated switches to amber+font-medium when negative
- Pot cards: name + link chip (emerald goal / neutral category), balance (font-mono tabular-nums --text-md weight 600), coverage insight for category-linked pots (D-12: "{category}: {spent} spent · {balance} in pot")
- Archive micro-confirm: "Archive this pot? Balance of {amount} will return to unallocated." with Cancel + rose Archive confirm `aria-label="Confirm archive of {name}"`
- Card footer: Fund / Move ghost buttons + `<flux:dropdown>` kebab (Edit / Withdraw / Archive), `aria-label="More actions for {name}"`
- Inline history (D-17): Alpine `x-data="{ open: false }"`, `x-show="open" x-collapse`, `aria-expanded`, `aria-controls="pot-history-{id}"`. Shows "Show history ↓" / "Hide history ↑"; movement rows with date/label/amount (emerald for fund/transfer_in, muted for others); memo italic faint
- Archived pots disclosure: collapsible list with "Archived" chip + Restore kebab item
- 4 Flux modals: `pot-form` (520px, create+edit with linkType radio), `pot-fund` (480px), `pot-move` (480px, destination select), `pot-withdraw` (480px)

**Copywriting Contract compliance:** All exact copy strings used per 03-UI-SPEC.md §Copywriting Contract.

**Accessibility Contract:** `role="alert"` on warning banner, `aria-expanded`/`aria-controls` on history toggle, `aria-label` on kebab and archive confirm, `font-variant-numeric: tabular-nums` on all money figures.

**Verification:** `number_format(` count = 0; `Money::ofMinor` present; `isOverAllocated`/`recentMovements`/`categorySpentMinor` referenced; 4 flux:modal blocks (pot-form/pot-fund/pot-move/pot-withdraw); all 14 Pots tests GREEN.

### Task 3: Human UAT (Deferred)

Per the standing checkpoint_override instruction ("work autonomously, no mid-plan checkpoints"), Task 3's checkpoint:human-verify gate was bypassed. Automated test suite (6/6 PotsPageTest, 14/14 full Pots suite) serves as verification. Human browser UAT to be performed at phase-end.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PotsPageTest import_run_id NOT NULL constraint violation**
- **Found during:** Task 1 test run after enabling Pots module
- **Issue:** PotsPageTest (written in Plan 01) inserted a transaction row with `'import_run_id' => null`, but the transactions table has `foreignId('import_run_id')->constrained('import_runs')` (NOT NULL). This caused `SQLSTATE[23000]: Integrity constraint violation` on the fundPot test.
- **Fix:** Added `use Modules\Ledger\Models\ImportRun;` + `$this->run = ImportRun::create([...])` in `beforeEach()` (mirrors PotBalanceQueryTest pattern); updated the transaction insert to use `$this->run->id`
- **Files modified:** `Modules/Pots/tests/Feature/PotsPageTest.php`
- **Commit:** 4cbc195

**2. [Rule 2 - Missing critical functionality] PHPStan level 10 fixes in PotsPage**
- **Found during:** Task 1 PHPStan run
- **Issue 1:** Unnecessary nullsafe `?->` on `$pot` / `$sourcePot` after null checks (5 occurrences) — `nullsafe.neverNull` errors
- **Issue 2:** `$q` parameter in closure typed as `mixed` — `method.nonObject` on `whereNull`/`orWhere` calls
- **Fix:** Replaced nullsafe access with explicit null-guarded blocks; added `use Illuminate\Database\Query\Builder as QueryBuilder;` import and typed closure parameter
- **Files modified:** `Modules/Pots/Internal/Http/Livewire/PotsPage.php`
- **Commit:** 4cbc195

**3. [Rule 3 - Blocking] Docker vendor bind-mount (worktree isolation)**
- **Found during:** Task 1/2 test runs
- **Issue:** Worktree has no vendor/ directory; PHPStan/Pest unavailable
- **Fix:** Temporarily added bind-mount to docker-compose.yml for test runs; reverted before each commit (per parallel execution instructions — bind-mount never committed)
- **Files modified:** `docker-compose.yml` (reverted, not committed)

### Checkpoint Override Applied

**Task 3 (checkpoint:human-verify):** Per the standing `checkpoint_override` instruction in the execution context ("do NOT pause — complete implementation, run automated test suite as verification, note in SUMMARY.md that human visual verification is deferred to phase-end browser UAT"), Task 3 was handled autonomously. Human browser UAT to be performed at phase-end.

## Known Stubs

None — the pots-page.blade.php and PotsPage.php are fully wired to real domain services. All modal actions call PotWriter; all render data comes from PotBalanceQuery. No hardcoded empty arrays, no placeholder copy.

## Threat Flags

No new threat surface beyond what the plan's `<threat_model>` already registers:
- T-03-08 (Spoofing/Elevation): Every action starts with `if (! $currentUser->isAuthenticated()) return;`; user is always `$currentUser->user()`, never a client field — implemented.
- T-03-09 (Tampering): account_id/goal_id/category_id/pot_id passed to PotWriter which validates ownership server-side — implemented.
- T-03-10 (Information Disclosure): picker lists use `->where('user_id', $user->id)` or global-OR-user-owned for categories — implemented.

## Self-Check: PASSED

Files verified to exist at expected paths:
- `Modules/Pots/Internal/Http/Livewire/PotsPage.php` ✓
- `Modules/Pots/Resources/views/livewire/pots-page.blade.php` ✓
- `Modules/Pots/Providers/PotsServiceProvider.php` (modified) ✓
- `Modules/Pots/Routes/web.php` (modified) ✓
- `Modules/Core/Resources/views/livewire/app-sidebar.blade.php` (modified) ✓
- `Modules/Pots/tests/Feature/PotsPageTest.php` (modified) ✓

Commits verified:
- `4cbc195`: feat(03-03): PotsPage Livewire component + provider + route + sidebar entry
- `82e8539`: feat(03-03): pots-page Blade view — cards, reconciliation header, Flux modals, inline history
