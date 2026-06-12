---
phase: beatrax-07-tax-deductible-tagging-per-year-export
fixed_at: 2026-06-12T00:00:00Z
review_path: .planning/phases/beatrax-07-tax-deductible-tagging-per-year-export/07-REVIEW.md
iteration: 1
findings_in_scope: 23
fixed: 23
skipped: 0
status: all_fixed
---

# Phase 07: Code Review Fix Report

**Fixed at:** 2026-06-12
**Source review:** 07-REVIEW.md (deep, 4 critical / 11 warning / 8 info)
**Iteration:** 1
**Branch:** release/v1.3 (main working tree, per orchestrator instruction)

**Summary:**
- Findings in scope: 23 (CR-01..04, WR-01..11, IN-01..08)
- Fixed: 23
- Skipped / rejected: 0

## Final gate results

| Gate | Result |
|------|--------|
| `vendor/bin/pest` (full suite) | **3619 passed**, 0 failed (19 todos, 6 skipped — pre-existing). Baseline was 3598 passed; +21 new regression tests, no regressions. |
| `vendor/bin/pint` | passed (explicit run over every changed file; `--dirty` also clean) |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` (level 10, full project) | **No errors** |
| `npm run build` | rebuilt after CSS/blade changes |

## Fixed Issues

### Critical

### CR-01: `@return` is not a Blade directive — first-visit /tax broken
**Commit:** `998af2f`
**Files:** `Modules/Tax/Resources/views/livewire/tax-page.blade.php`, `Modules/Tax/tests/Feature/TaxPageTest.php`
**Applied fix:** Replaced the bogus `@return` with an `@if (! $hasTaxCountry) … @elseif ($data !== null) … @endif` structure so the setup prompt renders INSTEAD of the cockpit body. Strengthened the masking test with `assertDontSee('@return')`, `assertDontSee('Total deductions')`, `assertDontSee('Nothing tagged for')`.
**Bonus finding surfaced:** the test fixture passed `tax_country_code` to `User::create()`, but the column is not in `$fillable` — it was silently dropped, so every "with country" test user actually had NO country, and three other TaxPage tests only passed because the broken `@return` rendered the body unconditionally. The fixture now persists the column via `DatabaseManager` (the same write path the app uses).

### CR-02: D-10 year-override picker row unreachable
**Commit:** `7e8bbfb`
**Files:** `Modules/Tax/Public/Services/TaxTagQuery.php`, `Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php`, `Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php`
**Applied fix:** New user-scoped `TaxTagQuery::bookedYearFor(int $userId, int $txId): ?int`; `openPickerFor()` now populates `pickerBookedYear` from it (it was hardcoded `null` with a comment promising "populated by the surface" that no surface honoured). Regression tests: the "Assign to tax year" row renders when booked year ≠ tax year, `saveTaxCategory` persists `tax_year_override`, and the row is absent when years match.

### CR-03: Accumulated phone rows reset to "untagged" → destructive stale re-tag
**Commit:** `a755013`
**Files:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php`, `Modules/Tax/Public/Actions/TagTransaction.php`, tests
**Applied fix (both halves):**
1. `render()` batch-loads tag state for current page ids PLUS all `$accumulatedRows` ids (still one `whereIn` — Pitfall-1 honoured), so earlier pages never flip back to the ghost after `loadMore()`. Regression test simulates 51 rows → tag page-1 row → `loadMore()` → tagged badge must survive.
2. `TagTransaction` rewritten from `updateOrInsert` to exists→update/insert; when the row exists and the payload is all-null (one-tap path), only `updated_at` is touched — a stale ghost tap can no longer wipe a curated category/note/override. Regression test included. Trade-off (documented in code): clearing ALL fields of an existing tag via the picker is a no-op; "Remove tag" + re-tag is the escape hatch — accepted per the review's suggested option.

### CR-04: CSS reveal selectors never match — Tag button invisible on 2 of 4 surfaces
**Commit:** `25000f7`
**Files:** `resources/css/app.css`, `Modules/Counterparties/Resources/views/livewire/profile-tabs/_recent-activity.blade.php`
**Applied fix:** `.always-show-touch .tax-badge--untagged` (descendant, never matches) → compound `.tax-badge--untagged.always-show-touch` (fixes TransactionDetail desktop). Counterparty recent-activity `<li>` gains `class="group"`, and the reveal rules gain `.group:hover` / `.group:focus-within` variants. All four surfaces verified: TransactionsList `tr.group`, CashBook `li.group`, TransactionDetail `always-show-touch`, CounterpartyProfile `li.group`. `npm run build` run.

### Warnings

### WR-01: seedFromCorpus unique(user_id, name) collision → 500
**Commit:** `6170310` — Seed loop now also skips when a `(user_id, name)` row exists; the user's own category wins. Test: user-created "Giften" + NL seed no longer throws.

### WR-02: `updateOrInsert` clobbered created_at on every re-tag
**Commit:** `1dd0d11` — The write-path split shipped in the CR-03 commit (UPDATE path excludes `created_at` by construction); this commit pins it with a dedicated regression test (backdated `created_at` must survive an edit).

### WR-03: `??` on snapshot keys conflated "No category" with "no snapshot"
**Commit:** `72bc020` — `applyBatchTag` uses `array_key_exists` for `categoryId`/`note`. Test: trigger tag saved with No category, picker then polluted via an unrelated row — siblings still receive NULL category/note.

### WR-04: Picker state bleeds between rows
**Commit:** `771fb00` — `openPickerFor()` resets note/category/year-override up front; `editTaxTag()` opens first, prefills after (a tag-lookup miss now leaves clean fields). Test: edit row A, type values, ghost-tag row B without closing — fields are clean.

### WR-05: Batch suggestion keyed to the seasonal year, not the trigger row's year
**Commit:** `a4a8a48` — Suggestion year now derives from the trigger transaction's booked year (just resolved by `openPickerFor`) and is snapshotted into `batchSuggestion['taxYear']`; `applyBatchTag` reuses the snapshot (seasonal year only as stale-snapshot fallback). Test: June-2026 clock, trigger booked 2024 → 2024 siblings counted and tagged, 2026 sibling untouched.

### WR-06: No listeners; nav count never invalidated; untag had no event
**Commit:** `d3f6ba1` — New `Modules/Tax/Internal/Listeners/InvalidateNavCounts` calls Core-Public `NavCountsService::forget($userId)`; registered in `TaxServiceProvider::boot` for `TransactionTagged` AND a new `TransactionUntagged` Public event (dispatched by `UntagTransaction` only when a row was actually deleted). `TransactionTagged` docblock no longer claims phantom consumers. Tests: warmed cache reflects tag/untag immediately.

### WR-07: Internal type leaked through the Public trait; Public stubs were lies
**Commit:** `efc8ee8` — `Modules/Tax/Public/Services/TaxCategoryWriter` is now a real delegating facade (TaxYearQuery/TaxCountrySetup precedent) carrying seedFromCorpus/add/rename/archive/listForUser (+ unarchive from WR-11); the trait type-hints it, so Ledger/CashBook/Counterparties components no longer depend on a Tax-Internal class at runtime. The dead `Public/Services/TaxCorpusLoader` stub (zero consumers, fatal-if-resolved) is deleted. Facade bound as singleton in the provider.

### WR-08: CSV formula injection
**Commit:** `f14e490` — `$writer->addFormatter(new EscapeFormula)` (league/csv). Test asserts `=HYPERLINK(...)` / `=2+5+cmd…` cells come out quote-prefixed.

### WR-09: Wall-clock time-bomb tests (fail from May 2027)
**Commit:** `569f26a` — Both batch-suggestion tests now mock `Clock` to a frozen June-2026 instant consistent with their fixtures (TaxPageTest pattern). WR-05 already removed the deepest clock dependency; the frozen clock pins the remaining seasonal path.

### WR-10: PDF double-escapes category name
**Commit:** `a399948` — Removed the redundant `e()` inside `{{ }}`.

### WR-11: rename dead UI / uncaught exceptions / archiving irreversible
**Commit:** `137759b` —
(a) Inline Rename affordance on each active category row (Alpine edit-in-place → `$wire.renameCategory`, Enter/Escape handling).
(b) `rename()` gains a duplicate-name guard mirroring `add()` (self-rename allowed); the component catches `InvalidArgumentException|RuntimeException` into a new inline `renameError` instead of 500ing.
(c) New `unarchive()` (Internal writer + Public facade + `unarchiveCategory` component action) with a Restore button in the Archived disclosure. 6 new tests.

### Info

### IN-01: Copy-pasted amber `@class` on the booked-year button
**Commit:** `f606c64` — Booked-year button keeps only its inline selected styling; amber stays exclusively on the override button.

### IN-02: Dead Alpine `x-data` + inert `.defer` modifiers
**Commit:** `6a9f8c1` — Dropped the unused `x-data="{ open: … }"` and both `wire:model.defer` → `wire:model` (deferred is the v3/v4 default).

### IN-03: Corpus short_names exceed the documented 12-char budget
**Commit:** `fe98003` — All 11 offenders shortened to ≤12 chars (the review listed 6; a scan also found gb "Property exp.", be "Beroepskosten", us "Mortgage int." and "Business exp."). Keys/names/hints untouched, so existing seeds are unaffected.

### IN-04: Seed sort_order restarts at 0 per country → interleave
**Commit:** `1ff183a` — Seed offsets by the user's `max(sort_order) + 1` (matching `add()`); second country appends as a block. Test included.

### IN-05: Year switcher silently caps at 5 years
**Commit:** `ad21b8d` — Removed the `array_slice` cap; the container already flex-wraps, and "full history retained forever" is a project constraint.

### IN-06: `Carbon::now()` instead of Clock; non-atomic upsert
**Commit:** `50b6897` — `TagTransaction` injects `Clock` for the ±10-year window and timestamps (test proves the window follows a faked clock); the insert race catches `UniqueConstraintViolationException` and retries as the guarded update.

### IN-07: Empty-year copy references a non-existent ⌾ icon
**Commit:** `2ad65d1` — Copy now says "Look for the "Tag" button on any transaction row".

### IN-08: Dashboard card folds income + deductions into one ABS sum
**Commit:** `aa86a7b` — `summaryForUser` total now excludes income rows (`CASE WHEN t.type = 'income' THEN 0 …`), matching the /tax cockpit's headline "Total deductions" KPI; item count still covers all tagged rows. Chose the review's "show the deductions total" option over relabeling so card and cockpit show the SAME number.

### Housekeeping

- `134bf92` `style(07): pint pass over review-fix files` — formatting only.

## Skipped Issues

None — all 23 findings were fixed; no review suggestion was found to be incorrect.

---

_Fixed: 2026-06-12_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
