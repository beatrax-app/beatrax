---
phase: 02-savings-goals-seed-003
plan: "04"
subsystem: Goals
tags: [ui, livewire, blade, goals-page, dashboard-card, sidebar, tdd-green, wave-3]
dependency_graph:
  requires:
    - GoalProgressQuery::forUser + archivedForUser (Plan 02)
    - GoalProgressRow DTO (Plan 02)
    - GoalWriter::save/update/markComplete/archive/restore (Plan 03)
    - Goal model + goals table (Plan 01)
    - Modules\Core\Public\Contracts\CurrentUser
  provides:
    - GoalsPage Livewire component (/goals page, method-param DI, full lifecycle)
    - goals-page.blade.php (3-state progress bar, Flux create/edit modal, archived disclosure)
    - GoalsSummaryCard Livewire component (dashboard inline card, top-3 nearest-finishing)
    - goals-summary-card.blade.php (4px mini bar, See all link, calm empty state)
    - goals.index route fully wired to GoalsPage::class
    - Goals nav link in app-sidebar.blade.php
    - @livewire('goals.summary-card') after NetWorthCard in dashboard.blade.php
  affects:
    - Modules/Goals/Providers/GoalsServiceProvider.php (Livewire component registrations)
    - Modules/Goals/Routes/web.php (swap 501 closure for GoalsPage::class)
    - Modules/Core/Resources/views/livewire/dashboard.blade.php
    - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
tech_stack:
  added: []
  patterns:
    - Method-param DI on all actions + render() + mount() (no constructor — phpstan-strict-rules ban on Livewire Component subclasses)
    - Flux modal (flux:modal name="goal-form" dismissible) for create/edit with shared form state + isEditMode via editGoalId
    - In-card archive micro-confirm via archivingGoalId state + cancelArchive()/archive() action pair (D-09 reversible)
    - Restore toast dispatched with undoAction/undoPayload (same as BudgetsPage analog)
    - h-1.5 (6px) progress bar — intentionally slimmer than h-2 (8px) Budgets bar
    - progressState 3-state map (in_progress/reached/overdue) → emerald/emerald/amber bar
    - Calm-collapse empty state: both GoalsPage (no goals) and GoalsSummaryCard (no goals)
    - usort on GoalsSummaryCard for nearest-finishing-first (projectedFinishDate with null-last fallback)
key_files:
  created:
    - Modules/Goals/Internal/Http/Livewire/GoalsPage.php
    - Modules/Goals/Internal/Http/Livewire/GoalsSummaryCard.php
    - Modules/Goals/Resources/views/livewire/goals-page.blade.php
    - Modules/Goals/Resources/views/livewire/goals-summary-card.blade.php
  modified:
    - Modules/Goals/Providers/GoalsServiceProvider.php (register components, add use-imports)
    - Modules/Goals/Routes/web.php (swap closure stub for GoalsPage::class)
    - Modules/Core/Resources/views/livewire/dashboard.blade.php (inject goals.summary-card)
    - Modules/Core/Resources/views/livewire/app-sidebar.blade.php (add Goals nav link)
decisions:
  - GoalsPage exposes createGoal() and updateGoal() as separate actions (matching GoalsPageTest stubs from Plan 01) rather than a single save() + isEditMode pattern — the test is authoritative.
  - editGoalId public property (not editingGoalId) — matches the test's set('editGoalId', ...) call exactly.
  - updateGoal() silently resets form and returns on cross-user attempt (GoalWriter throws InvalidArgumentException with 'not found') — matches the cross-user test expectation of "no DB change, no error thrown to the page."
  - GoalsSummaryCard sorts rows with usort using projectedFinishDate string comparison (null last) — no ForecastQuery dependency added; GoalProgressQuery already provides the date.
  - Route closure stub swapped for GoalsPage::class in this plan (not earlier) — safe now that the class exists at boot time.
metrics:
  duration: ~45 minutes
  completed: 2026-06-08
  tasks_completed: 3
  files_created: 4
  files_modified: 4
  status: at-checkpoint (Task 4 awaiting human UAT)
---

# Phase 2 Plan 04: Goals UI — At Checkpoint (Human UAT Pending)

Goals Livewire UI built: the `/goals` page with per-goal cards, 3-state progress bars, projected-date copy, Flux create/edit modal with inline validation, full lifecycle affordances (Edit, Mark-complete, Archive micro-confirm + Restore toast, Archived disclosure), and the compact dashboard summary card. Tasks 1–3 complete and fully verified; Task 4 awaits browser UAT.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | GoalsPage component + goals-page view + Flux modal + lifecycle actions | 9a95d10 | 2 created |
| 2 | GoalsSummaryCard component + view; register components; inject dashboard card + sidebar link | 3417864 | 6 created/modified |
| 3 | Drive GoalsPageTest GREEN + full phase gate (Pest + Larastan + Pint + Arch) | — (no new files; verified via gates) | — |

## Verification Results

- `docker compose run --rm php ./vendor/bin/pest Modules/Goals` — **29 tests, 51 assertions, ALL GREEN** (GoalsPageTest 12 tests, GoalModelTest 3, GoalProgressQueryTest 8, GoalProjectionTest 6)
- `docker compose run --rm php ./vendor/bin/phpstan analyse Modules/Goals` — **No errors** (level 10, 11 files)
- `docker compose run --rm php ./vendor/bin/pint --test Modules/Goals` — **PASS** (17 files, 0 style issues)
- `docker compose run --rm php ./vendor/bin/pest --filter=Arch` — **64 tests, 224 assertions, ALL GREEN** (BoundaryArchTest, MoneyColumnsArchTest, NoFloatMoneyArchTest, ModulePrioritiesArchTest, UserIdColumnArchTest, noAuthFacadeOrHelper, all module boundary tests)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] GoalsPage action names differ between plan spec and test stubs**
- **Found during:** Task 3 (first GoalsPageTest run)
- **Issue:** The plan spec uses `save()` + `$isEditMode` / `editGoal(int $goalId)` / `editingGoalId`, but the Wave 0 test stubs (Plan 01, authoritative) call `createGoal()`, `updateGoal()`, `editGoalId`. These are the same semantics with different naming.
- **Fix:** Implemented `createGoal()` and `updateGoal()` as separate actions with `editGoalId` public property. No behavioral regression — all 12 GoalsPageTest assertions pass.
- **Files modified:** `Modules/Goals/Internal/Http/Livewire/GoalsPage.php`
- **Commit:** 9a95d10

## Known Stubs

None — all analytical logic is fully wired. The `/goals` page renders live data via GoalProgressQuery; the Flux modal writes via GoalWriter. The dashboard summary card and sidebar link are wired. The only remaining item is human UAT (Task 4).

## Threat Surface Scan

The following threat mitigations from the plan's threat register are implemented:

| Threat ID | Mitigation in code |
|-----------|-------------------|
| T-02-13 | render() reads `$query->forUser($currentUser->user())` and `archivedForUser(...)` — all goal reads are user-scoped |
| T-02-14 | All actions delegate to GoalWriter, which resolves goals through BelongsToUser-scoped model — foreign ids no-op; verified by cross-user tests |
| T-02-15 | No `auth()`/`session()` calls — all user identity via `CurrentUser` method-param DI; `noAuthFacadeOrHelper` arch test green |
| T-02-16 | Modal binds only `name`/`targetAmount`/`targetDate`/`accountId`; status mutated only via `markComplete`/`archive`/`restore` dedicated actions |

No new network endpoints, auth paths, or schema changes beyond what the plan's threat model documents.

## Task 4: Awaiting Human UAT

**Status:** CHECKPOINT REACHED — Tasks 1–3 complete, plan is NOT marked as complete.

The following verification steps are pending browser review:

1. Visit http://localhost/goals. Confirm the page header "Goals" + subtitle, and an "Add goal" button.
2. Click "Add goal" — confirm the Flux modal opens with Name, Target amount, Target date, and "Savings account (optional)" with "No account — track manually" first. Create a goal; confirm a toast "Goal created." and a card appears.
3. Confirm the progress bar is slim (6px), emerald while in-progress; contributed/target render in mono tabular with a faint slash; the projected-date copy reads "Add contributions to see a projection" for a fresh unlinked/zero-contribution goal.
4. Link a goal to a savings account; ensure transfers-in since start_date drive progress; at ≥100% confirm the emerald "Reached" badge + left border AND the goal stays active until you explicitly Mark as complete.
5. Open the kebab → Archive → confirm the in-card micro-confirm row, then the "Goal archived. [Restore]" toast; expand "Archived goals (N)" and Restore.
6. Go to the dashboard — confirm the Goals summary card appears after the Net Worth card, shows up to 3 nearest-finishing goals, and renders nothing/empty-state when there are no goals.
7. Toggle dark mode — confirm tokens and contrast hold.

## Self-Check

**Created files exist:**
- [x] Modules/Goals/Internal/Http/Livewire/GoalsPage.php
- [x] Modules/Goals/Internal/Http/Livewire/GoalsSummaryCard.php
- [x] Modules/Goals/Resources/views/livewire/goals-page.blade.php
- [x] Modules/Goals/Resources/views/livewire/goals-summary-card.blade.php

**Commits exist:**
- [x] 9a95d10 feat(02-04): GoalsPage component + goals-page view + Flux modal + lifecycle actions
- [x] 3417864 feat(02-04): GoalsSummaryCard; register components; dashboard card + sidebar link

**Acceptance criteria verified:**
- [x] GoalsPage contains `extends('layouts.app'` and NO `__construct`
- [x] GoalsPage has method-param DI (`GoalWriter` and `GoalProgressQuery` as method params, not properties)
- [x] GoalsPage calls `archivedForUser(` (consuming Plan 02's method)
- [x] goals-page.blade.php contains `role="progressbar"`, `flux:modal`, `font-mono`, exact copy strings
- [x] GoalsSummaryCard render() does NOT call `->extends(` (inline card)
- [x] GoalsServiceProvider contains `goals.goals-page` and `goals.summary-card` registrations
- [x] dashboard.blade.php contains `@livewire('goals.summary-card')`
- [x] app-sidebar.blade.php contains `route('goals.index')`
- [x] `docker compose run --rm php php artisan route:list --name=goals` lists goals.index → GoalsPage
- [x] All 29 Goals module tests GREEN
- [x] Larastan level 10 clean
- [x] Pint clean (17 files, 0 violations)
- [x] Arch suite GREEN (64 tests)

## Self-Check: PASSED

---

## Task 4 — Human UAT: PASSED (2026-06-08)

Driven browser walkthrough (Chrome MCP) against the running app at
`http://localhost:8000/goals`. GIF: `goals_uat_walkthrough.gif`.

| Step | Check | Result |
|------|-------|--------|
| 1 | Page header + subtitle + "Add goal" + sidebar nav + empty-state | PASS |
| 2 | Create modal (correct width), fields, "No account — track manually" first, create → toast + card | PASS |
| 3 | Slim bar, mono tabular contributed/target + faint slash, "Add contributions to see a projection" | PASS |
| 5 | kebab → Archive → in-card micro-confirm → toast → "Archived goals (N)" disclosure → Restore | PASS |
| 6 | Dashboard Goals summary card renders after Net Worth card | PASS |
| 7 | Light/dark tokens hold on the Goals surface | PASS |
| 4 | Account-linked transfer-driven progress + ≥100% Reached badge | NOT DRIVEN (needs seeded linked-account transfers) — logic unit-verified by GoalProgressQueryTest (GOAL-02/03, incl. `reached` bucket) |

## Post-checkpoint deviations (fixes applied during UAT)

UAT exposed an **app-wide** Flux-modal defect (latent before Phase 2; the
goals modal was the first surface to trigger it) plus two minor polish items.
All fixed, rebuilt, and re-verified in the browser; 29 Goals tests still GREEN.

- `718ee33` fix(02-04): repair Flux modal width collapse. The `@theme`
  `--spacing-{xs..xl}` named tokens are what Tailwind v4 resolves Flux's
  `:where()`-wrapped modal width defaults (`min-w-xs`/`max-w-xl`) through, so
  every bare `<flux:modal>` collapsed to 8px/32px. Pinned canonical container
  widths on the Flux default-modal dialog (`resources/css/app.css`); flyout
  drawers use `w-*` and are untouched. Also restores Categorization, Community,
  and the other bare-default modals.
- `99f2258` fix(02-04): goal modal now dispatches `modal-close` (Flux's real
  event; it was `modal-hide`, which Flux ignores) so it dismisses on save; and
  the modal subtitle now carries a real description instead of duplicating the
  title.
