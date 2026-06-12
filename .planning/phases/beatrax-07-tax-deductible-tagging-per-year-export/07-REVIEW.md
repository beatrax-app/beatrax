---
phase: beatrax-07-tax-deductible-tagging-per-year-export
reviewed: 2026-06-12T00:00:00Z
depth: deep
files_reviewed: 73
files_reviewed_list:
  - Modules/Auth/tests/Feature/CrossUserIsolationTest.php
  - Modules/CashBook/Internal/Http/Livewire/CashBookPage.php
  - Modules/CashBook/Resources/views/livewire/cash-book-page.blade.php
  - Modules/Core/Public/Services/NavCountsService.php
  - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/settings-page.blade.php
  - Modules/Counterparties/Internal/Http/Livewire/CounterpartyProfile.php
  - Modules/Counterparties/Resources/views/livewire/counterparty-profile.blade.php
  - Modules/Counterparties/Resources/views/livewire/profile-tabs/_recent-activity.blade.php
  - Modules/DevMode/Providers/DevModeServiceProvider.php
  - Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php
  - Modules/Ledger/Internal/Http/Livewire/TransactionsList.php
  - Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php
  - Modules/Ledger/Resources/views/livewire/transactions-list.blade.php
  - Modules/Onboarding/Internal/Http/Livewire/Steps/TaxCountryStep.php
  - Modules/Onboarding/Internal/Services/WizardStepRegistry.php
  - Modules/Onboarding/Resources/views/livewire/steps/tax-country-step.blade.php
  - Modules/Tax/Database/Migrations/2026_06_12_000004_alter_tax_deduction_categories_short_name_nullable.php
  - Modules/Tax/Internal/Actions/TaxCategoryWriter.php
  - Modules/Tax/Internal/Corpus/TaxCorpusLoader.php
  - Modules/Tax/Internal/Http/Livewire/TaxPage.php
  - Modules/Tax/Internal/Http/Livewire/TaxSettingsSection.php
  - Modules/Tax/Internal/Http/Livewire/TaxSummaryCard.php
  - Modules/Tax/Internal/Services/TaxCsvExporter.php
  - Modules/Tax/Internal/Services/TaxPdfRenderer.php
  - Modules/Tax/Internal/Services/TaxYearQuery.php
  - Modules/Tax/Providers/TaxServiceProvider.php
  - Modules/Tax/Public/Actions/TagTransaction.php
  - Modules/Tax/Public/Actions/UntagTransaction.php
  - Modules/Tax/Public/Dto/BatchTagSuggestion.php
  - Modules/Tax/Public/Dto/TaxTagData.php
  - Modules/Tax/Public/Dto/TaxYearData.php
  - Modules/Tax/Public/Dto/TaxYearSummary.php
  - Modules/Tax/Public/Events/TransactionTagged.php
  - Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php
  - Modules/Tax/Public/Services/TaxCategoryWriter.php
  - Modules/Tax/Public/Services/TaxCorpusLoader.php
  - Modules/Tax/Public/Services/TaxCountrySetup.php
  - Modules/Tax/Public/Services/TaxCsvExporter.php
  - Modules/Tax/Public/Services/TaxPdfRenderer.php
  - Modules/Tax/Public/Services/TaxTagQuery.php
  - Modules/Tax/Public/Services/TaxYearQuery.php
  - Modules/Tax/Resources/views/components/tax-badge.blade.php
  - Modules/Tax/Resources/views/components/tax-tag-popover-body.blade.php
  - Modules/Tax/Resources/views/components/tax-tag-popover.blade.php
  - Modules/Tax/Resources/views/livewire/tax-page.blade.php
  - Modules/Tax/Resources/views/livewire/tax-settings-section.blade.php
  - Modules/Tax/Resources/views/livewire/tax-summary-card.blade.php
  - Modules/Tax/Resources/views/pdf/export.blade.php
  - Modules/Tax/Routes/web.php
  - Modules/Tax/module.json
  - Modules/Tax/tests/Arch/TaxBoundaryTest.php
  - Modules/Tax/tests/Feature/NavCountsTaxTest.php
  - Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php
  - Modules/Tax/tests/Feature/TaxExportCsvTest.php
  - Modules/Tax/tests/Feature/TaxExportPdfTest.php
  - Modules/Tax/tests/Feature/TaxPageTest.php
  - Modules/Tax/tests/Feature/TaxSettingsTest.php
  - Modules/Tax/tests/Feature/TaxTagActionTest.php
  - Modules/Tax/tests/Pest.php
  - Modules/Tax/tests/TestCase.php
  - Modules/Tax/tests/Unit/TaxCorpusLoaderTest.php
  - Modules/Tax/tests/Unit/TaxCsvExporterTest.php
  - Modules/Tax/tests/Unit/TaxYearQueryTest.php
  - resources/corpus/tax/be.yaml
  - resources/corpus/tax/de.yaml
  - resources/corpus/tax/fr.yaml
  - resources/corpus/tax/gb.yaml
  - resources/corpus/tax/nl.yaml
  - resources/corpus/tax/us.yaml
  - resources/css/app.css
  - tests/Pest.php
findings:
  critical: 4
  warning: 11
  info: 8
  total: 23
status: issues_found
---

# Phase 07: Code Review Report — Tax Deductible Tagging + Per-Year Export

**Reviewed:** 2026-06-12
**Depth:** deep (cross-file: import graph, Livewire event/listener wiring, SQL correctness, user-scoping, XSS, dompdf, CSS↔blade contract)
**Files Reviewed:** 73
**Status:** issues_found

## Summary

The security posture is strong: every Tax read/write I traced is user-scoped (`tag.user_id` / `t.user_id` as the first predicate), ownership is verified before writes with 404-not-403, Livewire-tamperable inputs (`batchSuggestion`, `pickerCategoryId`, dispatched event ids) are all re-validated server-side, dompdf runs with `isRemoteEnabled=false`, and the PDF/blade output is Blade-escaped. COALESCE year-override SQL is correct and well-tested.

However, the deep pass found four critical defects: a non-existent Blade directive (`@return`) that breaks the /tax first-visit empty state, the D-10 year-override picker UI being entirely unreachable (its gate variable is never populated by any surface), a state-merge bug in `TransactionsList` that visually un-tags previously-loaded phone rows and opens a real data-loss path through the destructive one-tap re-tag, and CSS reveal-selector mismatches that make the untagged "Tag" affordance invisible on two of the four desktop surfaces. Several warnings concern unhandled exception paths (corpus seed name collisions), event wiring that exists but is consumed by nothing, and wall-clock time-bomb tests.

Verification notes: `@return` was confirmed non-compiling by running the actual BladeCompiler against the string; no custom `@return` directive is registered anywhere in `app/`, `Modules/`, or `vendor/`. `Clock` is bound to `SystemClock` in tests (no global fake), confirming the time-bomb finding.

## Critical Issues

### CR-01: `@return` is not a Blade directive — first-visit /tax page renders literal "@return" plus the full cockpit below the setup prompt

**File:** `Modules/Tax/Resources/views/livewire/tax-page.blade.php:118`
**Issue:** Line 118 uses `@return` to short-circuit the template after the "Which country do you file taxes in?" card. There is no `@return` directive in Blade, Livewire, or any registered package (verified by compiling the string through `BladeCompiler` — it passes through verbatim). Consequence for every user with `tax_country_code` unset: (a) the literal text `@return` is rendered into the page, and (b) the template does NOT stop — `$data` is non-null (the query already ran in `TaxPage::render()`), so the year-totals strip, empty-year state, and category sections all render below the first-visit prompt. The covering test (`TaxPageTest.php:289` "shows the first-visit empty state") only asserts the heading is present, so it passes despite the broken output — a masking test.
**Fix:**
```blade
@if (! $hasTaxCountry)
    <div class="mx-auto max-w-md">…setup card…</div>
@else
    {{-- totals strip + sections + empty-year state --}}
    …
@endif
```
Wrap the rest of the body in the `@else` branch (or `@if ($hasTaxCountry && $data !== null)`), delete the `@return` line, and strengthen the test with `->assertDontSee('@return')` and `->assertDontSee('Total deductions')`.

### CR-02: The D-10 year-override picker row is unreachable — `pickerBookedYear` is never populated by any surface

**File:** `Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php:348`, `Modules/Tax/Resources/views/components/tax-tag-popover-body.blade.php:179`
**Issue:** The popover's year-assignment row is gated on `$pickerBookedYear !== null && $pickerBookedYear !== $pickerTaxYear`. `openPickerFor()` sets `$this->pickerBookedYear = null; // Will be populated by the surface if needed.` — and a repo-wide grep confirms **no surface ever populates it** (the only other references are the blade reads). Therefore the "Assign to tax year" buttons never render, `pickerYearOverride` can never be set from any UI, and `tax_year_override` — the headline D-10 capability of this phase, with full backend, query (COALESCE), export, and amber-chip display support — is dead UI. The backend/`/tax` rendering tests pass because they insert overrides directly into the DB, masking the missing UI path.
**Fix:** In `openPickerFor()` (or in `tagTransaction`/`editTaxTag` before calling it), resolve the booked year from the transaction:
```php
$bookedAt = $this->db->connection()->table('transactions')
    ->where('id', $id)->where('user_id', $u->user()->id)->value('booked_at');
$this->pickerBookedYear = is_string($bookedAt) ? (int) substr($bookedAt, 0, 4) : null;
```
(inject `DatabaseManager` as a method parameter, or add a `bookedYearFor(int $userId, int $txId)` helper to `TaxTagQuery`). Add a Livewire test that opens the picker on a row booked in a different year and asserts the year row renders and `saveTaxCategory` persists the override.

### CR-03: `TransactionsList::render()` resets tax state to "untagged" on all previously-accumulated phone rows — stale ghost badge enables silent wipe of category/note via one-tap re-tag

**File:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php:276-282`
**Issue:** The merge loop iterates **all** of `$this->accumulatedRows` (pages 1..N) but `$taxState` is loaded only for the **current page's** `$rowIds`. For every accumulated row not on the current page, `$taxState[$accRowId]['taxTagged'] ?? false` resolves to `false`, overwriting the correct state stored on a previous render. After the first `loadMore()` on the phone infinite-scroll list, every tagged row from earlier pages flips back to the untagged "Tag" ghost. This is not just cosmetic: tapping that stale ghost dispatches `tax-tag`, and `tagTransaction` calls `TagTransaction::execute($userId, $id, null, null, null)`, whose `updateOrInsert` **overwrites** the existing tag's `deduction_category_id`, `note`, and `tax_year_override` with NULL — silent data loss on an already-curated tag.
**Fix:** Batch-load state for the accumulated ids, not just the page ids:
```php
$accIds = array_map(static fn (array $r): int => $r['id'], $this->accumulatedRows);
$taxState = $this->taxTagStateFor(array_values(array_unique([...$rowIds, ...$accIds])), $taxTagQuery, $currentUser);
```
Additionally harden `TagTransaction` so the one-tap path cannot destroy existing data: on update, only touch `updated_at` (or skip the write entirely when a tag row already exists and all three payload values are null).

### CR-04: CSS reveal selectors don't match the blade markup — untagged "Tag" button is invisible on desktop TransactionDetail and CounterpartyProfile

**File:** `resources/css/app.css:3274-3282`, `Modules/Tax/Resources/views/components/tax-badge.blade.php:28-32`, `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php:96`, `Modules/Counterparties/Resources/views/livewire/profile-tabs/_recent-activity.blade.php:31-35`
**Issue:** `.tax-badge--untagged` has `opacity: 0` at rest. The reveal rules are `(a) .triage-row:hover .tax-badge--untagged`, `(b) .always-show-touch .tax-badge--untagged`, `(c) :focus-visible`, plus a `(pointer: coarse)` media override. Two mismatches:
1. The badge blade puts `always-show-touch` **on the same element** as `tax-badge--untagged`, but rule (b) is a *descendant* selector — it can never match. On `TransactionDetail` (rendered with `:showAlways="true"` on desktop, where `pointer: coarse` does not apply and no Tailwind `opacity-0/group-hover` classes are emitted), the Tag button is permanently invisible except via keyboard focus.
2. The counterparty `_recent-activity.blade.php` rows are plain `<li style="…">` elements with **no `.group` or `.triage-row` ancestor**, so the `:showAlways="false"` badge's Tailwind `group-hover:opacity-100` classes never fire and rule (a) never matches — the Tag affordance is invisible on desktop counterparty profiles too.
Net effect: on a desktop pointer, the tagging affordance shipped by this phase works on only 2 of the 4 surfaces (TransactionsList table rows have `class="group"`; CashBook desktop `<li class="group …">`). Feature tests pass because they assert the `data-testid` exists in the HTML, not that it is visible.
**Fix:** Change rule (b) to a compound selector `.tax-badge--untagged.always-show-touch { opacity: 1; }`, and add `class="group"` to the `_recent-activity` `<li>` (or add `.tax-badge--untagged` reveal scoped to `li:hover`). Re-verify all four surfaces at desktop width.

## Warnings

### WR-01: `seedFromCorpus` collides with the `unique(user_id, name)` constraint when a user-created category shares a corpus entry's name — unhandled `QueryException` (500) in settings and the setup wizard

**File:** `Modules/Tax/Internal/Actions/TaxCategoryWriter.php:56-83`, `Modules/Tax/Database/Migrations/2026_06_12_000001_create_tax_deduction_categories_table.php` (unique `[user_id, name]`)
**Issue:** The seed loop checks only `corpus_key` for existence before inserting. If the user has already created a category named e.g. "Giften" (inline add via the picker, or settings) and then selects NL as their country, the insert of corpus entry `nl_giften` (name "Giften") violates `unique(user_id, name)` and throws an uncaught `QueryException`, 500-ing `TaxSettingsSection::setTaxCountry` and `TaxCountryStep::continue`. (Cross-country corpus names were verified unique, so country switching alone is safe — the user-created-name path is the live hazard.)
**Fix:** In the loop, also skip when a row with the same `(user_id, name)` exists (one extra `->where('name', $name)->exists()` check), or wrap the insert in a try/catch on the unique violation and `continue`.

### WR-02: `TagTransaction::updateOrInsert` clobbers `created_at` on every re-tag

**File:** `Modules/Tax/Public/Actions/TagTransaction.php:75-87`
**Issue:** The values array passed to `updateOrInsert` includes `'created_at' => $now`, which is applied on the UPDATE path too. Every edit of a tag (save from the picker, batch apply over an existing row after a future change) rewrites `created_at`, destroying the "when was this first tagged" audit signal in a tax-audit-oriented feature.
**Fix:** Use the two-step form: `exists()` → `update([...without created_at])` or `insert([...with created_at])`, or Laravel's `upsert()` with an explicit update-column list excluding `created_at`.

### WR-03: `applyBatchTag` uses `??` on snapshot keys whose value can legitimately be `null` — falls through to live picker state from a different row

**File:** `Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php:259-260`
**Issue:** `$this->batchSuggestion['categoryId'] ?? $this->pickerCategoryId` cannot distinguish "snapshot says No category (null)" from "no snapshot taken". If the trigger tag was saved with **No category**, the snapshot stores `categoryId => null`, and `??` falls back to the live `pickerCategoryId` — which, if the user has meanwhile opened the picker on an unrelated row and selected a category, applies *that row's* category (and note) to the whole batch, violating the D-03 "same category as the trigger tag" contract.
**Fix:**
```php
$categoryId = array_key_exists('categoryId', $this->batchSuggestion)
    ? $this->batchSuggestion['categoryId']
    : $this->pickerCategoryId;
```
(same for `note`).

### WR-04: `openPickerFor()` does not reset `pickerNote` / `pickerCategoryId` / `pickerYearOverride` — state bleeds between rows

**File:** `Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php:333-349`
**Issue:** Opening the picker for row B while row A's picker state is still loaded (edit row A via the emerald pill, then — without saving — tap the ghost "Tag" on row B; `tagTransaction` never calls `closePicker()`) leaves row A's note, category, and year override pre-filled in row B's picker. Pressing Save then writes row A's note/category onto row B. `editTaxTag` has the same hole when the tag lookup misses (`isset($tags[$id])` false → stale values persist).
**Fix:** Reset `pickerNote`, `pickerCategoryId`, and `pickerYearOverride` at the top of `openPickerFor()` (before the optional prefill in `editTaxTag`), e.g. by extracting the field-reset portion of `closePicker()` and calling it from `openPickerFor()`.

### WR-05: Batch suggestion year is the seasonal *current* tax year, not the tagged transaction's year

**File:** `Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php:119-132, 252-255`
**Issue:** After tagging a transaction booked in, say, 2024 (while the wall clock says June 2026), `tagTransaction` computes the suggestion with `resolveCurrentTaxYear()` → 2026, so the banner counts and `applyBatchTag` tags the counterparty's untagged 2026 siblings — not siblings from the year of the row the user just tagged. The user intent ("also tag the other gym payments like this one") is keyed to the trigger row's year; tagging old history during filing season produces a banner about, and applies tags to, a different year's rows.
**Fix:** Derive the suggestion year from the trigger transaction's effective year (booked year, or override once CR-02 is fixed) and store it in `batchSuggestion` so `applyBatchTag` reuses the same year instead of re-resolving the seasonal year (which can even drift between the tag action and the banner click across a year boundary).

### WR-06: `TransactionTagged` has zero listeners, `UntagTransaction` dispatches nothing, and the `tax_tagged` nav count is never invalidated

**File:** `Modules/Tax/Public/Events/TransactionTagged.php`, `Modules/Tax/Public/Actions/UntagTransaction.php`, `Modules/Core/Public/Services/NavCountsService.php:87-89`
**Issue:** A repo-wide search finds no listener for `TransactionTagged` (only the action that dispatches it and a test asserting dispatch). The event docblock claims it is "Consumed by badge surfaces, the dashboard summary card" — false. `NavCountsService`'s contract states "writes that materially change a count call forget()", but neither tag nor untag invalidates the per-user cache, so the sidebar `tax_tagged` badge is stale for up to 300s after tagging/untagging (e.g. tag 10 rows, badge still shows the old number). Untag additionally has no event at all, so even a future listener could not fix the untag path.
**Fix:** Register a listener (e.g. in `TaxServiceProvider::boot`) that calls `NavCountsService::forget($event->userId)`; add a `TransactionUntagged` event (or call `forget` directly in `UntagTransaction`); correct the docblock.

### WR-07: Public `HandlesTaxTagging` trait type-hints `Modules\Tax\Internal\Actions\TaxCategoryWriter` — Internal type leaks into Ledger/CashBook/Counterparties components; the Public `TaxCategoryWriter`/`TaxCorpusLoader` classes are empty stubs

**File:** `Modules/Tax/Public/Http/Livewire/Concerns/HandlesTaxTagging.php:10`, `Modules/Tax/Public/Services/TaxCategoryWriter.php`, `Modules/Tax/Public/Services/TaxCorpusLoader.php`
**Issue:** The trait is the designated cross-module surface (consumed by `TransactionsList`, `TransactionDetail`, `CashBookPage`, `CounterpartyProfile`), yet its `tagTransaction`/`editTaxTag`/`addInlineCategory` signatures inject the **Internal** writer. At runtime, other modules' components therefore depend on a Tax-Internal class; the arch rule (`Modules\Tax\Internal` only used in `Modules\Tax`) passes only because the trait *file* lives under `Modules/Tax` — the boundary is honored textually, not semantically. Meanwhile `Modules/Tax/Public/Services/TaxCategoryWriter.php` and `TaxCorpusLoader.php` are `final class … {}` stubs whose docblocks still say "Stub — full implementation ships in Plan 04" (Plan 04 shipped, in Internal). Any consumer that container-resolves the Public writer (the natural choice given every other Tax service has a working Public facade) gets a method-less class and a fatal at call time.
**Fix:** Implement the Public `TaxCategoryWriter` as a delegating facade (mirroring `Public\Services\TaxYearQuery`), switch the trait's type-hints to it, and either implement or delete the Public `TaxCorpusLoader` stub.

### WR-08: CSV export does not mitigate spreadsheet formula injection

**File:** `Modules/Tax/Internal/Services/TaxCsvExporter.php:35-77`
**Issue:** `description`, `note`, `counterparty`, and `account` columns carry free text (notes are user-typed; descriptions/counterparties originate from imported bank files). A value beginning with `=`, `+`, `-`, or `@` executes as a formula when the export — explicitly designed to be handed to an accountant/opened in Excel — is opened. league/csv ships `League\Csv\EscapeFormula` for exactly this.
**Fix:**
```php
$writer->addFormatter(new \League\Csv\EscapeFormula());
```

### WR-09: Batch-suggestion tests are wall-clock time bombs — they will start failing in May 2027

**File:** `Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php:258-333`
**Issue:** `Clock` is bound to `SystemClock` in tests (no fake in these two tests, unlike `TaxPageTest`, which mocks `Clock`). The fixtures hardcode `booked_at` in June 2026, and the trait resolves the suggestion year seasonally from the real clock. From 2027-05-01 onward, `resolveCurrentTaxYear()` returns 2027, `untaggedCountForCounterparty(..., 2027)` finds zero siblings, `batchSuggestion` stays null, and both the "batch suggestion fires" and "applyBatchTag applies the SAME category" tests fail. Until then they only pass by luck of the calendar.
**Fix:** Mock `Clock` (as `TaxPageTest` does) to a frozen instant consistent with the fixture dates, or derive fixture `booked_at` from `now()`.

### WR-10: PDF template double-escapes the category name — `&`, `<`, `'` render as HTML entities in the PDF

**File:** `Modules/Tax/Resources/views/pdf/export.blade.php:152`
**Issue:** `{{ $isNoCategory ? 'Uncategorised' : e($catName) }}` — `{{ }}` already escapes, so `e()` double-encodes: a category named "Books & Media" prints as "Books &amp; Media" in the generated PDF heading.
**Fix:** `{{ $isNoCategory ? 'Uncategorised' : $catName }}`.

### WR-11: `renameCategory` is dead UI with uncaught exception paths; archived categories cannot be restored

**File:** `Modules/Tax/Internal/Http/Livewire/TaxSettingsSection.php:114-125`, `Modules/Tax/Internal/Actions/TaxCategoryWriter.php:155-180`, `Modules/Tax/Resources/views/livewire/tax-settings-section.blade.php`
**Issue:** (a) The settings blade wires only Archive and Add — no rename control exists anywhere, so the rename action (and the Pitfall-4 "user renames survive corpus updates" behavior) is unreachable. (b) If/when wired: `renameCategory` catches only `NotFoundHttpException`; an empty name throws an uncaught `InvalidArgumentException` and a rename to an existing name throws an uncaught `QueryException` (writer's `rename()` has no duplicate-name guard, unlike `add()`). (c) There is no unarchive path in the writer or UI — archiving is irreversible from the product.
**Fix:** Wire a rename affordance (or remove the action), add empty/duplicate-name guards mirroring `add()`, and add an `unarchive` action + UI row in the Archived disclosure.

## Info

### IN-01: Year-row buttons carry a copy-pasted `@class` condition

**File:** `Modules/Tax/Resources/views/components/tax-tag-popover-body.blade.php:188,194`
**Issue:** Both year buttons apply `'tax-badge--amber' => $pickerYearOverride !== null` — the booked-year (override = null) button gets the amber class precisely when the *other* button is selected. Dead-ish given CR-02, but fix together with it.
**Fix:** First button: `$pickerYearOverride === null` styling only; second: `!== null`.

### IN-02: Dead/stale Alpine + Livewire artifacts in the popover

**File:** `Modules/Tax/Resources/views/components/tax-tag-popover-body.blade.php:114,17,119`
**Issue:** `x-data="{ open: $wire.pickerIsNewCatOpen }"` declares an `open` that nothing reads (the row is server-rendered via `@if`). `wire:model.defer` is a Livewire v2 modifier — deferred is the default in v3/v4; the modifier is inert noise.
**Fix:** Drop the `x-data` and the `.defer` modifiers.

### IN-03: Corpus `short_name` values violate the documented ≤12-char schema

**File:** `resources/corpus/tax/gb.yaml:25` ("Prof. subscr." 13), `resources/corpus/tax/gb.yaml:26` ("Empl. expenses" 14), `resources/corpus/tax/de.yaml` ("Werbungskosten"/"Sonderausgaben" 14, "Außergewöhnl." 13), `resources/corpus/tax/be.yaml` ("Levensverzek."/"Pensioenspaar" 13)
**Issue:** Every corpus file's own header comment says `short_name … (≤12 chars)` (badge label budget). Six entries exceed it; the badge pill will be wider than the design budget. Country placement of all entries was checked and is correct.
**Fix:** Shorten the offending labels or update the documented limit.

### IN-04: `seedFromCorpus` restarts `sort_order` at 0 per country — multi-country seeds interleave

**File:** `Modules/Tax/Internal/Actions/TaxCategoryWriter.php:78`
**Issue:** Each corpus uses `$index` (0..5). A user who switches NL → DE gets both sets interleaved pairwise in the picker and /tax ordering (`orderBy sort_order, name`).
**Fix:** Offset by the user's current `max(sort_order) + 1`, as `add()` already does.

### IN-05: Year switcher silently caps at 5 years

**File:** `Modules/Tax/Resources/views/livewire/tax-page.blade.php:49-53`
**Issue:** `array_slice($years, 0, 5)` — with >4 historical tagged years, older years are reachable only by hand-editing `?year=`. "Full history retained forever" is a project constraint, so this cap will eventually bite.
**Fix:** Render all available years, or add an overflow affordance (select/dropdown) past 5.

### IN-06: `TagTransaction` uses `Carbon::now()` instead of the injected `Clock`; `updateOrInsert` is non-atomic

**File:** `Modules/Tax/Public/Actions/TagTransaction.php:66,75-87`
**Issue:** The codebase routes time through the `Clock` contract elsewhere (settings, trait, summary card); the range check and timestamps here bypass it, making the ±10-year validation untestable via the standard clock fake. `updateOrInsert` is select-then-write; concurrent requests can race into a unique-constraint exception (low risk single-user, worth knowing).
**Fix:** Inject `Clock`; optionally catch the unique violation and retry as update.

### IN-07: Empty-year copy references a "⌾ icon" that does not exist

**File:** `Modules/Tax/Resources/views/livewire/tax-page.blade.php:174`
**Issue:** "Look for the ⌾ icon on any transaction row" — the badge renders the text "Tag", not ⌾. Misleading guidance, especially combined with CR-04 (the affordance may also be invisible).
**Fix:** Align the copy with the actual badge ("the Tag button").

### IN-08: `summaryForUser` folds income and deductions into one `SUM(ABS(...))` total

**File:** `Modules/Tax/Public/Services/TaxTagQuery.php:88`
**Issue:** The dashboard card shows a single "tagged total" that adds the absolute values of deductions *and* tax-relevant income — a €500 deduction plus €500 tagged income displays as €1.000, which matches neither figure on /tax (which splits them). If intentional, document it; users comparing card vs. cockpit will see different numbers.
**Fix:** Either show the deductions total only (matching the cockpit's headline KPI) or label the card "total tagged volume".

---

_Reviewed: 2026-06-12_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
