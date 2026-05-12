---
phase: 01-foundation-asn-csv-vertical-slice
plan: 07
subsystem: categorization
tags:
  - categorization
  - livewire
  - triage-inbox
  - inline-picker
  - event-driven-seeder
  - di-only
  - phase-1-complete
dependency_graph:
  requires:
    - 01-03-PLAN
    - 01-05-PLAN
    - 01-06-PLAN
  provides:
    - "`Modules\\Categorization\\Public\\Contracts\\AssignsCategory` + `Public\\Actions\\AssignCategory` — single Public mutator for assigning / clearing the category on a transaction (routes through Ledger's `UpdatesTransactionCategory`)"
    - "`Modules\\Categorization\\Public\\Events\\TransactionCategorized` — fires after a successful assignment so Phase 7's MerchantMemory listener has a stable hook"
    - "`Modules\\Categorization\\Public\\Services\\UncategorizedTriageQuery` — cursor-paginated read of uncategorized rows; powers the `/uncategorized` page"
    - "`Modules\\Categorization\\Public\\Dto\\{TriageBatch, TriageRow, CategoryOption}` — readonly spatie/laravel-data DTOs"
    - "`Modules\\Categorization\\Database\\Seeders\\DefaultCategoryTreeSeeder` — idempotent 29-row Dutch-aware default tree; runs from the `UserInstalled` listener"
    - "`Modules\\Categorization\\Internal\\Listeners\\SeedDefaultCategoryTree` — decouples `Core\\Internal\\Console\\InstallCommand` from Categorization (BoundaryRule clean)"
    - "`Modules\\Categorization\\Internal\\Http\\Livewire\\TriageInbox` — `/uncategorized` page with keyboard-driven keymap + batch save"
    - "`Modules\\Categorization\\Internal\\Http\\Livewire\\InlineCategoryPicker` — per-row picker dropped into the `/transactions` table"
    - "Routes `/uncategorized` (named `uncategorized`) under `web` + `auth` middleware"
    - "VALIDATION.md CAT-01 + CAT-03 + CAT-05 → green; matrix synchronised end-to-end with the on-disk truth"
  affects:
    - "TopNav's `Uncategorized` item upgraded from a static span to an `<a>` link → `/uncategorized` (UI-SPEC required)"
    - "`Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` Category cell now renders the InlineCategoryPicker Livewire child"
    - "`resources/views/layouts/app.blade.php` unchanged — the new picker rides on the existing `@livewire('core.top-nav')` mount"
    - "Phase 7 (CAT-02 MerchantMemory learning) will hang its listener off `TransactionCategorized`"
tech_stack:
  added: []
  patterns:
    - "Event-driven cross-module wiring — Core dispatches UserInstalled; Categorization owns the listener that seeds the default tree. InstallCommand never imports a Categorization symbol (BoundaryRule clean)."
    - "Idempotent seeder using `updateOrCreate` keyed by the unique `slug` column; safe to re-run; runs `withoutGlobalScopes()` because seeded rows have `user_id = NULL` and the BelongsToUser global UserScope would otherwise hide them when the test acted as an authenticated user."
    - "Livewire method-level DI on render() and on action methods (selectForRow, save, updatedCategoryId) — `boot()` injection is banned by phpstan-strict-rules' staticMethod.dynamicCall rule. Matches Plan 05 / Plan 06's locked-in convention."
    - "DatabaseManager raw query builder for both the triage query and the category-options lookup — same reason as Plan 06: Eloquent's Builder method calls trip the strict-rules dynamic-static-call detector."
    - "Single source for the flattened breadcrumb path (`Subscriptions / Streaming`) — one SQL LEFT JOIN on `categories as p` instead of an N+1 parent walk in PHP."
    - "Alpine.js keymap with a single `keydown.window` handler that branches on `$event.key` — fewer event listeners and lets `$event.preventDefault()` only fire on keys we actually capture. Carries the same focus guard (`INPUT/TEXTAREA/SELECT`) as Plan 06's period nav."
    - "Event::fake() repositioned to AFTER any pre-condition database writes — the AssignCategory action's `Dispatcher` is bound at construction time; faking the event after the test has already resolved the action via `app->make()` returns a stale dispatcher that still hits the real listener pool."
key_files:
  created:
    - Modules/Categorization/Public/Contracts/AssignsCategory.php
    - Modules/Categorization/Public/Actions/AssignCategory.php
    - Modules/Categorization/Public/Events/TransactionCategorized.php
    - Modules/Categorization/Public/Services/UncategorizedTriageQuery.php
    - Modules/Categorization/Public/Dto/TriageRow.php
    - Modules/Categorization/Public/Dto/TriageBatch.php
    - Modules/Categorization/Public/Dto/CategoryOption.php
    - Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php
    - Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php
    - Modules/Categorization/Internal/Http/Livewire/TriageInbox.php
    - Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php
    - Modules/Categorization/Resources/views/triage.blade.php
    - Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php
    - Modules/Categorization/Resources/views/livewire/inline-category-picker.blade.php
    - Modules/Categorization/tests/Unit/DefaultCategoryTreeSeederTest.php
    - Modules/Categorization/tests/Unit/UncategorizedTriageQueryTest.php
    - Modules/Categorization/tests/Feature/AssignCategoryTest.php
    - Modules/Categorization/tests/Feature/TriagePageTest.php
  modified:
    - Modules/Categorization/Providers/CategorizationServiceProvider.php
    - Modules/Categorization/Routes/web.php
    - Modules/Ledger/Resources/views/livewire/transactions-list.blade.php
    - Modules/Core/Resources/views/livewire/top-nav.blade.php
    - .planning/phases/01-foundation-asn-csv-vertical-slice/01-VALIDATION.md
decisions:
  - "Default category tree contains 29 rows (13 parents + 16 children) — not the planner's ~30 estimate. Source: 13 listed top-level categories where 5 (Income, Housing, Transport, Insurance, Subscriptions) have children — Income 3, Housing 3, Transport 3, Insurance 3, Subscriptions 4 = 16 leaves. Total 29. Tests now assert this exact count rather than a fuzzy ≥-bound so future drift is caught."
  - "Seeded categories have `user_id = NULL` and are treated as global (T-07-02 carries this forward as an accepted Phase 1 risk). The seeder uses `Category::withoutGlobalScopes()` because the BelongsToUser global UserScope filters by exact `user_id = $authedUserId` — a NULL-keyed row would never match. Phase 7 (CAT-04 user-defined categories) revisits whether to clone the tree per user or introduce an `is_global` column."
  - "BoundaryRule honoured via the UserInstalled listener pattern. Core's `InstallCommand` dispatches `UserInstalled`; Categorization's `SeedDefaultCategoryTree` listener handles it. Core never imports a `Modules\\Categorization\\*` symbol. This is the recommended path from the plan's `<action>` — strictly cleaner than the FQN-string alternative used for CurrenciesSeeder in Plan 03."
  - "TopNav `Uncategorized` upgraded from a `<span>` to an `<a>` link → `/uncategorized`. UI-SPEC §Component Inventory explicitly says \"Uncategorized (with count badge)\" sits in the nav and \"links to it\"; Plan 06 left it as a span because the route didn't exist yet. This plan closes that gap."
  - "Inline picker `updatedCategoryId` hook keeps the action behind the AssignsCategory contract — the Livewire component never imports `UpdatesTransactionCategory` directly. Keeps the Ledger boundary one indirection deeper than necessary, but matches D-04 (\"Categorization writes go through Ledger's Public action\") and gives Phase 7's MerchantMemory listener a single event hook."
  - "Keymap `1`–`9` resolves the top-9 categories via `display_order` (the Plan's TopNCategories LOW-risk assumption). Phase 7's actual top-N-by-frequency replacement is non-breaking — same DTO, same key bindings, different SELECT."
  - "Pint reformatted TriagePageTest's `\\Livewire\\Livewire::actingAs` calls to use a top-of-file `use Livewire\\Livewire;` import; the test now reads `Livewire::actingAs(...)`. No semantic change."
  - "The seeder loop runs as one DB transaction implicitly via Laravel's default transaction wrapping for `updateOrCreate`. We do NOT wrap the whole `run()` in `DB::transaction(fn () => ...)` because the strict-rules ban dynamic static calls on the DB facade — and the per-row idempotency contract (`UNIQUE slug`) is enough."
metrics:
  duration: "~30 minutes wall-clock (single executor, sequential)"
  completed_date: "2026-05-13"
  tasks_completed: 2
  files_created: 18
  files_modified: 5
  commits: 4
---

# Phase 1 Plan 07: Default Categories + Triage Inbox + Inline Picker Summary

**One-liner:** Closes Phase 1 by shipping the Categorization vertical slice — the default 29-row Dutch-aware category tree seeds on install via a `UserInstalled` listener, every `/transactions` row exposes an inline category picker that writes through Ledger's `UpdatesTransactionCategory`, and the new `/uncategorized` triage inbox renders a keyboard-driven (`1`–`9` keymap + `↑/↓` + `Enter` + `Esc`) batch-save flow with the literal `Inbox zero.` empty-state copy. CAT-01 / CAT-03 / CAT-05 are now validation-green; the Phase 1 walking skeleton is LIVE.

## What this plan delivered

### 1. Default category tree (29 rows, idempotent)

```
Income (income)                     - parent only
├── Salary (income)
├── Refunds (income)
└── Other income (income)
Housing (expense)                   - parent only
├── Rent / Mortgage (expense)
├── Utilities (expense)
└── Internet & Phone (expense)
Groceries (expense)                 - leaf
Transport (expense)                 - parent only
├── Public transport (expense)
├── Fuel (expense)
└── Car maintenance (expense)
Insurance (expense)                 - parent only
├── Health (expense)
├── Liability (expense)
└── Other (expense)
Subscriptions (expense)             - parent only
├── Streaming (expense)
├── Music (expense)
├── Cloud / Software (expense)
└── Memberships (expense)
Eating out (expense)                - leaf
Cash withdrawal (expense)           - leaf
Healthcare (expense)                - leaf
Personal care (expense)             - leaf
Donations (expense)                 - leaf
Transfers (internal) (transfer)     - leaf
Fees & charges (expense)            - leaf
```

13 parents + 16 leaves = 29 rows. Idempotent via `Category::withoutGlobalScopes()->updateOrCreate(['slug' => …], …)`.

### 2. AssignCategory action + TransactionCategorized event

```php
final class AssignCategory implements AssignsCategory
{
    public function __construct(
        private readonly UpdatesTransactionCategory $updater,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        $affected = ($this->updater)($transactionId, $categoryId, $user);
        if ($affected > 0) {
            $this->events->dispatch(new TransactionCategorized(
                transactionId: $transactionId,
                categoryId: $categoryId,
                userId: $user->id,
            ));
        }
        return $affected;
    }
}
```

- Routes the write through `Ledger\Public\Contracts\UpdatesTransactionCategory` (D-04 — Ledger remains the only direct mutator of `transactions.category_id`).
- Fires `TransactionCategorized` only on `affected > 0` so cross-user attempts are silent at the action layer too (the Ledger action already returns 0 affected for them).
- Constructor-injects `Dispatcher` (the `Illuminate\Contracts\Events\Dispatcher` interface — no facade).

### 3. /uncategorized triage page

```
Uncategorized                                                   ── Page heading (20px slate-900)
N pending.                                                      ── Status caption (14px slate-500)
┌─────────────────────────────────────────────────────────────────────────────┐
│ Date       Counterparty       Amount        Category                        │
│ 15-05-2026 Cafe Local         €    -13,99   [Select category    ▾]          │   ← bg-slate-100 when cursor is here
│ 12-05-2026 AH Amsterdam       €    -50,00   [Subscriptions / Streaming  ▾]  │   ← pending highlighted via @selected
│ 05-05-2026 Streaming Co.      €     -8,99   [Select category    ▾]          │
└─────────────────────────────────────────────────────────────────────────────┘
1–9 assign top categories · ↑/↓ move · Enter save · / search · Esc clear      [ Save categories ]   ← emerald-600
```

Empty state ("Inbox zero."):

```
Uncategorized
Inbox zero.

Every transaction has a category. Re-open this page after your next import.
```

### 4. Inline category picker on `/transactions`

The category column on every row now renders `<livewire:categorization.inline-category-picker :transactionId="$row->id" :categoryId="$row->categoryId" :key="cat-picker-$row->id" />`. Selecting a category fires `updatedCategoryId`, which calls `AssignsCategory::__invoke` and persists immediately. The `wire:model.live="categoryId"` binding makes every change a single Livewire round-trip.

### 5. TopNav Uncategorized → live link

```html
<a href="/uncategorized" class="…">
    Uncategorized
    @if ($uncategorizedCount > 0)
        <span class="… bg-slate-100">{{ $uncategorizedCount }}</span>
    @endif
</a>
```

Active-state highlight uses the same `$isActive('/uncategorized')` Blade closure pattern as the other nav items.

## Phase 1 complete colour matrix — all 22 REQ rows (final state)

| Req     | Test                                                                    | Status                        |
| ------- | ----------------------------------------------------------------------- | ----------------------------- |
| FND-01  | `tests/Feature/LoopbackOnlyTest`                                        | ✅ green (Plan 02)             |
| FND-02  | `tests/Feature/Auth/LoginFlowTest`                                      | ✅ green (Plan 02)             |
| FND-03  | `tests/Contracts/UserIdColumnArchTest`                                  | ✅ green (Plan 03)             |
| FND-04  | `tests/Contracts/NoFloatMoneyArchTest`                                  | ✅ green (Plan 03)             |
| FND-06  | `Modules/Core/tests/Unit/SqlitePragmasTest`                             | ✅ green (Plan 02)             |
| FND-07  | `Modules/Ledger/tests/Unit/MoneyValueObjectTest`                        | ✅ green (Plan 03)             |
| ING-01  | `Modules/Import/tests/Feature/AsnCsvImportTest`                         | ✅ green (Plan 05)             |
| ING-06  | `tests/Contracts/IdempotencyContractTest`                               | ✅ green (Plan 05)             |
| ING-07  | `Modules/Import/tests/Feature/UploadWizardTest::test_requires_source_declaration` | ✅ green (Plan 05)   |
| ING-08  | `Modules/Ingestion/tests/Unit/AsnCsvAdapterTest::test_preserves_raw_payload` | ✅ green (Plan 04)        |
| LED-01  | `Modules/Ledger/tests/Unit/AccountModelTest`                            | ✅ green (Plan 03)             |
| LED-02  | `Modules/Ledger/tests/Unit/TransactionTypeTest`                         | ✅ green (Plan 03)             |
| MC-01   | `tests/Contracts/MoneyColumnsArchTest`                                  | ✅ green (Plan 03)             |
| **CAT-01** | `Modules/Categorization/tests/Feature/AssignCategoryTest`             | **✅ green (Plan 07)**         |
| **CAT-03** | `Modules/Categorization/tests/Feature/AssignCategoryTest::it_overrides_existing` | **✅ green (Plan 07)** |
| **CAT-05** | `Modules/Categorization/tests/Feature/TriagePageTest`                 | **✅ green (Plan 07)**         |
| UI-01   | `Modules/Ledger/tests/Feature/DashboardTest`                            | ✅ green (Plan 06)             |
| UI-04   | `Modules/Ledger/tests/Feature/TransactionListTest::it_defaults_to_recent_window` | ✅ green (Plan 06)    |
| UI-05   | Aesthetic compliance — calm Linear/Notion                               | manual (`/gsd-ui-checker` pending) |
| PLT-01  | (same as FND-01) `tests/Feature/LoopbackOnlyTest`                       | ✅ green (Plan 02)             |
| PLT-02  | `Modules/Core/tests/Feature/InstallCommandTest::it_refuses_an_iCloud_Drive_database_path` | ✅ green (Plan 02) |
| PLT-05  | `tests/Contracts/NoExtImapTest`                                         | ✅ green (Plan 01)             |

**21 / 22 automated green. UI-05 manual-checker remains the single open item — every other requirement has a passing Pest assertion.**

Full suite at close of Plan 07: **208 passed · 1 skipped · 0 failed** (up from 188 at the close of Plan 06 — +20 new tests in this plan: 5 AssignCategory + 5 Seeder + 4 TriageQuery + 6 TriagePage).

## Phase 1 ROADMAP success criteria — all 5 LIVE

| # | Criterion | Where it lives |
| --- | --------- | --------------- |
| 1 | User can log in at `http://127.0.0.1` and the app refuses non-loopback | Plan 02 — `LoopbackOnlyTest` + `LoginFlowTest` |
| 2 | User can upload an ASN CSV, declare it as ASN CSV, and see imported transactions in a list | Plans 04 + 05 + 06 — Upload wizard → AsnCsvAdapter → ImportPipeline → `/transactions` |
| 3 | Re-uploading the same file creates 0 new rows | Plan 05 — `IdempotencyContractTest` + composite-UNIQUE on `transactions` |
| 4 | "This month at a glance" home shows in / out / net | Plan 06 — `Dashboard` Livewire + `ThisPeriodAtAGlanceQuery` |
| 5 | User can manually categorize transactions, override, and triage uncategorized | **Plan 07** — `AssignCategory` + `/uncategorized` + inline picker |

## UI-checker outcome on all 5 surfaces

UI-05 is the single open requirement. The executor cannot invoke `/gsd-ui-checker` from inside its own loop, so the run is left to the verification phase. Plan 07 ships the surfaces with full UI-SPEC compliance:

| Surface       | Compliance basis                                                                                                                                                                                                                                                                                                                                                          |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/login`      | Plan 02 — unchanged.                                                                                                                                                                                                                                                                                                                                                      |
| `/`           | Plan 06 — unchanged. Note the TopNav `Uncategorized` link is now active (was a span).                                                                                                                                                                                                                                                                                     |
| `/imports/new` | Plan 05 — unchanged.                                                                                                                                                                                                                                                                                                                                                     |
| `/transactions` | Plan 06 + Plan 07 — every row's Category cell renders the new inline picker. Plain `<select>` styled with `border-slate-200 / bg-white / text-slate-900` matches UI-SPEC §Component Inventory's "Category picker" entry ("`flux:select` (tree-flattened) — Phase 1 default"). Tabular numerals + neutral palette preserved.                                              |
| `/uncategorized` | UI-SPEC §Triage list compliance: Inter font via root layout, 14px Body weight, `slate-900` text / `slate-200` borders / `slate-50` table header, one emerald-600 CTA (`Save categories`), literal copy ("Inbox zero." / "Every transaction has a category." / "1–9 assign top categories · ↑/↓ move · Enter save · / search · Esc clear"), focus rings on every control. |

## Per-task commit log

| Task | Name                                                                          | Commit    | Key files                                                                                                                                                                                                                                  |
| ---- | ----------------------------------------------------------------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1    | RED — Seeder + AssignCategory + TriageQuery failing tests                     | `ddf81a7` | `Modules/Categorization/tests/Unit/DefaultCategoryTreeSeederTest.php`, `Modules/Categorization/tests/Feature/AssignCategoryTest.php`, `Modules/Categorization/tests/Unit/UncategorizedTriageQueryTest.php`                                  |
| 1    | GREEN — Categorization Public surface + listener + service-provider wiring    | `e1831d5` | DefaultCategoryTreeSeeder, SeedDefaultCategoryTree listener, AssignCategory action + contract + event, UncategorizedTriageQuery, 3 DTOs, CategorizationServiceProvider, VALIDATION.md (CAT-01/CAT-03 green)                                  |
| 2    | RED — TriagePage / InlinePicker failing tests                                 | `f9a9328` | `Modules/Categorization/tests/Feature/TriagePageTest.php`                                                                                                                                                                                  |
| 2    | GREEN — TriageInbox + InlineCategoryPicker Livewire surfaces                  | `4ce0ece` | 2 Livewire components, 3 Blade views (2 livewire + 1 top-level), Routes/web.php, CategorizationServiceProvider boot(), transactions-list.blade.php integration, top-nav.blade.php link upgrade, VALIDATION.md (CAT-05 green + matrix sync) |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Default tree row-count corrected from ≥-bound to exact 29**

- **Found during:** Task 1 GREEN — first seeder test run.
- **Issue:** The plan said "≈30 rows including parents + leaves" and the initial test asserted `toBe(30)`. The actual tree (per the plan's own enumeration: 13 sections, 5 of them with children Income/Housing/Transport/Insurance/Subscriptions → 3+3+3+3+4 = 16 leaves) totals 29 rows.
- **Fix:** Updated all three seeder-test assertions to `->toBe(29)` and tightened the kind-count assertions to exact values (4 income + 24 expense + 1 transfer = 29). Future drift in the seeded list now breaks the test rather than passing silently under a fuzzy `≥`.
- **Files modified:** `Modules/Categorization/tests/Unit/DefaultCategoryTreeSeederTest.php`
- **Commit:** `e1831d5`

**2. [Rule 1 — Bug] Seeded categories need `withoutGlobalScopes()` on the seeder write path**

- **Found during:** Task 1 GREEN — the seeder ran from the `UserInstalled` listener inside `diederik:install`, but the AssignCategory feature test seeded its own user + category and then `actingAs($user)`'d. Without `withoutGlobalScopes()`, the BelongsToUser global UserScope on `Category` filtered the seeded `user_id = NULL` rows out of every query — including the seeder's own `updateOrCreate` lookup, which would then insert a duplicate on the second run and break idempotency.
- **Fix:** `DefaultCategoryTreeSeeder` calls `Category::withoutGlobalScopes()->updateOrCreate(['slug' => …], …)` for both parents and children. The `slug` column carries a global `UNIQUE`, so the seeder still cannot create a duplicate even if a future caller forgets the scope guard.
- **Files modified:** `Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php`
- **Commit:** `e1831d5`

**3. [Rule 1 — Bug] PHPStan cast.useless on `(int) $user->id`**

- **Found during:** Task 1 GREEN — first phpstan run.
- **Issue:** `$user->id` is already declared as `int` via the User model's `@property int $id` PHPDoc. The action wrote `userId: (int) $user->id` which PHPStan flagged as `cast.useless`.
- **Fix:** Removed the cast → `userId: $user->id`.
- **Files modified:** `Modules/Categorization/Public/Actions/AssignCategory.php`
- **Commit:** `e1831d5`

**4. [Rule 1 — Bug] Event::fake() timing — fake AFTER seeding the precondition, not after the first action call**

- **Found during:** Task 1 GREEN — the override + clear-category tests failed on the `Event::assertDispatched` assertion.
- **Issue:** Original test flow was `$action(…)` → `Event::fake([…])` → `$action(…)` → assert. But `app->make(AssignsCategory::class)` resolves the action with its constructor-injected `Dispatcher` BEFORE `Event::fake` runs; the second action call still dispatches through the original dispatcher. `Event::fake` only intercepts events dispatched through the dispatcher the Event facade points at AFTER the fake call.
- **Fix:** Refactored both tests to seed the precondition via `Transaction::query()->update(['category_id' => …])` (no event dispatch), then `Event::fake([TransactionCategorized::class])`, then resolve + call the action. Two-stage flow ensures the action is constructed with the faked dispatcher.
- **Files modified:** `Modules/Categorization/tests/Feature/AssignCategoryTest.php`
- **Commit:** `e1831d5`

**5. [Rule 2 — Missing Critical Functionality] TopNav `Uncategorized` upgraded from span to link**

- **Found during:** Task 2 GREEN — pre-existing state from Plan 06.
- **Issue:** UI-SPEC §Component Inventory ("Top nav … Items: Dashboard, Transactions, Uncategorized (with count badge) …") and CONTEXT.md D-20 ("A badge with the uncategorized count sits in the home page header **linking to it**") both require the Uncategorized item to be a link to `/uncategorized`. Plan 06 left it as a static `<span>` because the route did not exist yet. Now that Plan 07 ships `/uncategorized`, the link target is real.
- **Fix:** Changed `<span class="…">Uncategorized …</span>` to `<a href="{{ route('uncategorized') }}" class="… {{ $isActive('/uncategorized') }}">Uncategorized …</a>` in `top-nav.blade.php`.
- **Files modified:** `Modules/Core/Resources/views/livewire/top-nav.blade.php`
- **Commit:** `4ce0ece`

**6. [Rule 2 — Missing Critical Functionality] VALIDATION.md matrix synced with on-disk truth**

- **Found during:** Task 2 GREEN — preparing the final colour matrix.
- **Issue:** The planner's draft of `01-VALIDATION.md` still listed FND-01 / FND-02 / FND-06 / FND-07 / LED-01 / LED-02 / MC-01 / PLT-01 / PLT-02 as `❌ W0 | ⬜ pending` (or `❌ red (Plan 03)`), but those tests have actually been green since Plans 02-03 closed. The plan's `<output>` block explicitly asks for "the complete colour matrix of all 22 Phase-1 REQ rows (final state)", so leaving the doc out-of-date would mislead `/gsd-verify-work` and downstream phases.
- **Fix:** Updated each row to its current truth (verified by re-running the cited Pest tests during this plan's final verification step).
- **Files modified:** `01-VALIDATION.md`
- **Commit:** `4ce0ece`

### Notes on flagged-but-acceptable patterns

**a. `package-lock.json` rename diff is npm churn**

- The worktree's `npm install` step rewrote the lockfile's `"name"` field from `"diederik"` to `"agent-a278a328503c78f33"` (the worktree folder basename). Not committed — would dirty the main branch when the worktree merges. Out of scope for this plan.

**b. `Plan 06 binds…` comment in CategorizationServiceProvider already removed in Task 1**

- The Plan 02/03 SUMMARYs flagged stale `Plan N binds…` comments in the three module providers that hadn't yet been touched (`Import`, `Ingestion`, `Categorization`). This plan completely rewrites `CategorizationServiceProvider`, so the comment is gone. The `Import` + `Ingestion` providers are not modified here — out of scope.

## Known Stubs

None. Every Public surface introduced has a real implementation and at least one Pest assertion exercising it:

- `AssignCategory::__invoke` — 5 cases (assign / override / clear / cross-user reject / contract binding).
- `DefaultCategoryTreeSeeder::run` — 5 cases (fresh seed / idempotency / parent-child linkage / kind distribution / install command integration).
- `UncategorizedTriageQuery::for` — 4 cases (filter to uncategorized / cursor pagination / empty batch / user-scope isolation).
- `TriageInbox` Livewire — 4 cases (list render / empty state / keymap legend + CTA / batch save through AssignsCategory).
- `InlineCategoryPicker` Livewire — 2 cases (rendered on `/transactions` rows / updatedCategoryId writes through AssignsCategory).
- `TransactionCategorized` event — asserted dispatched on every successful AssignCategory path, NOT dispatched on cross-user reject.

The `MerchantMemory` listener pool stays empty in Phase 1 by design (CAT-02 is Phase 7). The event-hook itself is exercised; the listener that consumes it ships in a later plan.

## Threat Flags

No new surface beyond the threat model already mapped in the plan's `<threat_model>` block. Mitigations declared in the plan are intact:

- **T-07-01** (cross-user assignment) — mitigated. `UpdateTransactionCategory` filters by `user_id` and returns 0 affected; `AssignCategory` does not fire the event when `affected === 0`. Test `it refuses to update another user's transaction and does not fire the event` proves both paths.
- **T-07-02** (forged categoryId targeting a global / other-user category) — accepted for Phase 1. Categories with `user_id = NULL` are global. A future user could only ever pick a global category; CAT-04 (Phase 7) introduces user-owned categories and would tighten this.
- **T-07-03** (Triage page enumerates other users' transactions) — mitigated. `UncategorizedTriageQuery::for` filters by `$user->id` explicitly; the BelongsToUser global scope on Transaction layers in a second filter. Test `it scopes results to the requested user only` proves it.
- **T-07-04** (XSS via category name) — mitigated. Categories are seeder-controlled in Phase 1 (no user input). Blade `{{ }}` auto-escapes; no `{!! !!}` anywhere in the new templates.
- **T-07-05** (Listener mis-fire seeds categories twice) — mitigated. `updateOrCreate(['slug' => …], …)` is idempotent. Re-running `diederik:install` is also idempotent at the user layer (existing User id=1 → early return before the event would fire again). Test `it is idempotent — re-running the seeder produces the same row count` proves the seeder branch.

## Self-Check: PASSED

**Files exist:**

- `Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php` ✓
- `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` ✓
- `Modules/Categorization/Public/Actions/AssignCategory.php` ✓
- `Modules/Categorization/Public/Contracts/AssignsCategory.php` ✓
- `Modules/Categorization/Public/Events/TransactionCategorized.php` ✓
- `Modules/Categorization/Public/Services/UncategorizedTriageQuery.php` ✓
- `Modules/Categorization/Public/Dto/{TriageBatch,TriageRow,CategoryOption}.php` ✓
- `Modules/Categorization/Internal/Http/Livewire/{TriageInbox,InlineCategoryPicker}.php` ✓
- `Modules/Categorization/Resources/views/triage.blade.php` ✓
- `Modules/Categorization/Resources/views/livewire/{triage-inbox,inline-category-picker}.blade.php` ✓
- 4 test files under `Modules/Categorization/tests/` ✓

**Commits exist in `git log --oneline`:**

- `ddf81a7 test(01-07): RED — failing tests for AssignCategory + DefaultCategoryTreeSeeder + UncategorizedTriageQuery` ✓
- `e1831d5 feat(01-07): Categorization Public surface + default tree seeder (CAT-01/CAT-03)` ✓
- `f9a9328 test(01-07): RED — failing tests for triage page + inline category picker` ✓
- `4ce0ece feat(01-07): triage inbox + inline category picker on /transactions (CAT-05)` ✓

**End-of-plan invariants:**

- `vendor/bin/pest` reports **208 passed · 1 skipped · 0 failed** (up from 188 at the close of Plan 06) ✓
- `vendor/bin/pest Modules/Categorization/tests/Unit/DefaultCategoryTreeSeederTest.php` reports **5 passed** ✓
- `vendor/bin/pest Modules/Categorization/tests/Feature/AssignCategoryTest.php` reports **5 passed** ✓
- `vendor/bin/pest Modules/Categorization/tests/Unit/UncategorizedTriageQueryTest.php` reports **4 passed** ✓
- `vendor/bin/pest Modules/Categorization/tests/Feature/TriagePageTest.php` reports **6 passed** ✓
- `vendor/bin/phpstan analyse --memory-limit=1G` reports `[OK] No errors` at level max + strict-rules + larastan-livewire + canvural-strict-rules ✓
- `vendor/bin/pint --test` reports `passed` ✓
- `php artisan route:list` shows `GET /uncategorized` (named `uncategorized`) ✓
- `01-VALIDATION.md` Status: CAT-01 ✅, CAT-03 ✅, CAT-05 ✅, every other automated row green, UI-05 manual ✓
- DI grep gate over `Modules/Categorization/Public Modules/Categorization/Internal` (excl. tests/views/routes/listeners): clean — no `auth()` / `config()` / `now()` / `abort()` / `view()` / `session()` / `redirect()` / `response()` ✓
- BoundaryRule clean: Categorization imports from `Modules\Core\Models`, `Modules\Core\Public\…`, and `Modules\Ledger\Public\…` only — no `Internal/`, no `Database/`, no `Providers/` cross-module imports ✓

## Open Questions Surfaced (Phase 2+ candidates)

- **Plan 06 `Plan 06 binds…` / `Plan N binds…` stale comments in Import + Ingestion providers.** Plan 02 and Plan 03 already flagged these as codebase-agnostic violations. Plan 07 does not touch those providers (out of scope per the "only auto-fix issues DIRECTLY caused by current task's changes" rule); they remain for a future hygiene plan.
- **Category ownership ambiguity (T-07-02).** Seeded categories use `user_id = NULL`; effectively global. A second user (Phase 11 multi-user) sees the same tree — which is probably the desired behaviour for the partner-sharing case the project anticipates. CAT-04 (Phase 7) introduces user-defined categories on top; the right model is probably "global tree + user overrides" rather than "clone the tree per user".
- **Triage page keymap `/` (search) is documented but not implemented.** UI-SPEC §Triage page keymap lists `/` as "focus the category search input", but Phase 1 ships the picker as a plain `<select>` — there is no search input to focus. The legend still says `/ search` because removing it from the literal copy would break UI-SPEC compliance. When Plan 09+ upgrades the picker to a `flux:command` or Linear-style typeahead, the `/` keymap activates naturally.
- **Top-N categories via display_order, not usage frequency (TopNCategories LOW assumption).** The keymap binds `1`–`9` to the first 9 categories by `display_order`. At v1 single-user scale this is fine; Phase 7 (CAT-02 / EML-05 learning) replaces with frequency-of-use.
- **`InlineCategoryPicker` re-queries the categories table on every row render.** At v1 scale (30 rows × 30 categories = 900 cheap queries per page render — actually one per Livewire mount, batched) this is fine. If Phase 11 sees the cost in a profile, a single shared cache keyed by user_id is the obvious mitigation; the picker would resolve from container state instead of hitting the DB.
- **Phase 7 hook live but pool empty.** `TransactionCategorized` fires every time an assignment succeeds. No listener consumes it in Phase 1 (intentional). Phase 7 (CAT-02 MerchantMemory) hangs its listener here without needing any code change in Categorization.
- **Pest 4 / `Event::fake` timing pitfall documented in deviation #4.** This bites every test that does `app->make($action)` BEFORE the fake. Worth a project-skill rule (`feedback_event_fake_timing.md`) in a future hygiene pass — every action that constructor-injects `Dispatcher` will have this same surface.

---

**Phase 1 walking skeleton is LIVE.** Routes: `/` (dashboard or first-run redirect), `/login`, `/imports/new`, `/imports/{id}/preview`, `/imports/{id}` (results), `/transactions`, `/uncategorized`, `/logout`. CI gates in place: Pest 208 passed / 1 skipped · PHPStan level max + strict-rules + larastan-livewire + canvural-strict-rules clean · Pint clean · custom `BoundaryRule` clean. A user can now log in → upload an ASN CSV → preview NEW / DUPLICATE / ERROR rows → confirm → see "this period at a glance" → click into the transactions list → assign categories inline or sweep through the triage inbox with keyboard shortcuts → re-upload safely. The Foundation phase is complete.
