---
phase: 01-foundation-asn-csv-vertical-slice
fixed_at: 2026-05-13T00:23:49Z
review_path: .planning/phases/01-foundation-asn-csv-vertical-slice/01-REVIEW.md
iteration: 3
fix_scope: all
findings_in_scope: 7
fixed: 7
skipped: 0
status: all_fixed
---

# Phase 1: Code Review Fix Report (Iteration 3)

**Fixed at:** 2026-05-13T00:23:49Z
**Source review:** `.planning/phases/01-foundation-asn-csv-vertical-slice/01-REVIEW.md`
**Iteration:** 3 (final iteration of the `--auto` fix loop)
**Fix scope:** all

**Summary:**

- Findings in scope: 7 (1 Blocker, 6 Warnings)
- Fixed: 7
- Skipped: 0
- Status: all_fixed

Each fix lands as an atomic `fix(01): {id} ...` commit with paired regression tests. Tier-1 verification (re-read modified ranges) and Tier-2 verification (`php -l`) pass for every commit. The test suite was not executed in-worktree (no `vendor/` dependency tree present); per CLAUDE.md the project's Larastan-level-10 / Pint / Pest gates run from the main checkout.

## Fixed Issues

### B-03: `DefaultCategoryTreeSeeder` matches per-user duplicates and silently demotes them to global

**Files modified:**
- `Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php`
- `Modules/Categorization/tests/Unit/DefaultCategoryTreeSeederTest.php`

**Commit:** `aee0aea`

**Applied fix:** Keyed both `updateOrCreate` calls (parent + child loop) by `(slug, user_id = NULL)` so the seeder lookup only ever matches the global default-tree row. Dropped the now-redundant `'user_id' => null` entry from the update payload. Updated the class PHPDoc to describe the new key. Added a regression test that pre-seeds a per-user `groceries` row, runs the seeder, and asserts (a) the user row's `user_id` and `name` are unchanged and (b) a separate `user_id = NULL` global row also exists.

### W-08: `TransactionListQuery::recent` filters by `settled_currency` but renders `amount_minor` / `currency`

**Files modified:**
- `Modules/Ledger/Public/Services/TransactionListQuery.php`
- `Modules/Ledger/tests/Feature/TransactionListTest.php`

**Commit:** `5f90b78`

**Applied fix:** Branched the SELECT in `baseQuery()` on the presence of `$currency`. When a filter is supplied, the query projects `settled_amount_minor as display_minor` and `settled_currency as display_currency`; otherwise it projects the native `amount_minor` / `currency`. `mapRow()` now reads `display_minor` / `display_currency` so the dashboard's "Recent transactions" panel renders every row in the same currency as its KPI tiles and Top Categories panel, while the `/transactions` full-history view (no filter) keeps its multi-currency shape. Added two tests covering the EUR-filtered view (a native-USD/settled-EUR row renders as EUR) and the unfiltered full-history view (native USD remains USD).

### W-09: `UpdateTransactionCategory` does not verify the category belongs to the user

**Files modified:**
- `Modules/Ledger/Public/Actions/UpdateTransactionCategory.php`
- `Modules/Ledger/tests/Feature/UpdateTransactionCategoryTest.php`

**Commit:** `aae270e`

**Applied fix:** Before the `transactions.category_id` update, verify with `Category::withoutGlobalScopes()` that the category id either has `user_id = NULL` (default tree) OR belongs to the supplied user. Foreign category ids return `0` (mirrors the FK-miss behaviour callers already handle) and leave the row untouched. Updated the action's PHPDoc to describe the new defence-in-depth predicate. Added two feature tests: foreign-user category id is refused; global default-tree category id is accepted.

**Note: requires human verification** — the cross-user defence is correct, but the `return 0` short-circuit on a foreign category id is semantically distinct from a successful no-op (e.g. user not allowed to mutate). Worth confirming `AssignCategory` and the `InlineCategoryPicker` UX do the right thing on `affected == 0` for this new path (today they treat it the same as a missing transaction).

### W-10: `TopCategoriesByPeriodQuery::loadCategories` does not scope the parent walk by user_id

**Files modified:**
- `Modules/Ledger/Public/Services/TopCategoriesByPeriodQuery.php`
- `Modules/Ledger/tests/Unit/ThisPeriodAtAGlanceQueryTest.php`

**Commit:** `07cc2e9`

**Applied fix:** Plumbed `$user->id` through `for() → loadCategories(...)` and applied the `whereNull('user_id')->orWhere('user_id', $userId)` predicate to every level of the recursive walk. A `parent_id` pointing cross-tenant now terminates the chain at the filtered-out parent (the existing cycle guard handles the early break). Added the W-05-style regression test that creates a foreign-user parent + a local leaf whose `parent_id` points at the foreign parent, then asserts the dashboard breadcrumb omits the foreign parent name and shows only the local leaf.

### W-11: `PreviewWizard::rules()` is declared but never invoked

**Files modified:**
- `Modules/Import/Internal/Http/Livewire/PreviewWizard.php`

**Commit:** `adfc794`

**Applied fix:** `nameAccount()` now syncs `$this->accountName = $name` (covers callers that bypass the `wire:model` lifecycle, e.g. `Livewire::test(...)->call('nameAccount', ...)`) and calls `$this->validate()`, which reads the declared `rules()` map. The Livewire `ValidationException` populates the error bag and short-circuits the flow before the service is touched. The service-layer `InvalidAccountNameException` remains the authoritative second check. Inline comment added explaining why the property sync is needed.

### W-12: `AccountNamer` does not guard against `Str::slug()` returning an empty string

**Files modified:**
- `Modules/Import/Public/Services/AccountNamer.php`
- `Modules/Import/tests/Unit/AccountNamerTest.php`

**Commit:** `3d9467a`

**Applied fix:** After the existing length bound, derive the slug body once into `$slugBody` and throw `InvalidAccountNameException('Account name must contain at least one letter or digit.')` when it is empty. Updated the slug composition to reuse `$slugBody` to avoid a second `Str::slug` call. Updated the class PHPDoc to mention the new validation. Added four unit tests covering emoji-only input, punctuation-only input, sub-minimum length (whitespace-only), and above-maximum length.

### W-13: `DiscardImport` is allowed against an already-confirmed import run

**Files modified (new file + edits):**
- `Modules/Import/Public/Exceptions/ImportAlreadyConfirmedException.php` (new)
- `Modules/Import/Public/Actions/DiscardImport.php`
- `Modules/Import/tests/Feature/DiscardImportTest.php` (new)

**Commit:** `9145ccc`

**Applied fix:** Added a typed `ImportAlreadyConfirmedException` in the Import module's Public namespace (matches the existing `InvalidAccountNameException` / `PreviewExpiredException` shape) carrying the offending `importRunId`. `DiscardImport::__invoke` throws the exception when the loaded run already has `status = 'confirmed'`, leaving the audit row untouched. Mirrors the early-return guard in `ConfirmImport::__invoke`. Added a new feature test file covering both the happy path (previewed → discarded) and the refusal path (confirmed → exception + status preserved).

The Livewire `PreviewWizard::discard()` action is only reachable from the pre-confirm preview page (status `previewed`), so no UI catch is required; the typed exception protects programmatic / future-CLI callers.

## Skipped Issues

_None._ All 7 in-scope findings were fixed.

---

_Fixed: 2026-05-13T00:23:49Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 3 of 3 (final `--auto` iteration)_
