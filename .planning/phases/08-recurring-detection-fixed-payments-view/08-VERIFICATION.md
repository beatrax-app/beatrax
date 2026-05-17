---
phase: 08-recurring-detection-fixed-payments-view
verified: 2026-05-17T19:30:00Z
status: human_needed
score: 5/5 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Open /recurring in browser after approving at least one expense and one income series — confirm the Recurring expenses section and Recurring income section both render rows with display name, monthly-equivalent EUR amount, funding-chain icon stack, category badge, and next-expected-charge date."
    expected: "Both sections visible, data is real (not placeholder text), chain badge appears when a series has a linked chain_link, next-expected text shows relative date."
    why_human: "The grouped section structure and visual rendering of six per-row elements (name, amount, monthly-equivalent, chain icon, category, next-expected) requires visual confirmation in the browser. The feature tests verify data-attribute markers (data-chain-badge, data-confidence-low, data-eur-shadow) but cannot confirm the calm Linear/Notion aesthetic holds or that all elements are visible simultaneously in the actual layout."
  - test: "Open /recurring/series/{id} in browser for an approved recurring series that has occurrences — confirm the ApexCharts amount-over-time chart renders with actual data points, and the occurrences table below lists real historical occurrences each linking to the transaction drill-in."
    expected: "Chart renders with at least one series line (native currency), EUR shadow line visible for USD-priced series, occurrence table shows date and amount for each occurrence with a clickable link."
    why_human: "The ApexCharts chart initialisation via Alpine x-init is client-side JavaScript. The feature tests confirm the data-options JSON attribute is emitted but cannot confirm the chart actually renders in the browser without a headless browser setup."
  - test: "Open /recurring/review, select multiple pending rows via checkboxes, then click 'Bulk approve' — confirm the sticky action bar appears on selection, the bulk action completes, and the Undo toast fires with the count of approved series."
    expected: "Sticky bar appears at bottom of page on first checkbox selection; 'N approved' toast fires after bulk action; selected rows move out of the Pending tab."
    why_human: "The bulk-action bar's conditional rendering (wire:model.live on checkboxes triggering the sticky bar appearance) is a Livewire reactive UI behaviour. The feature tests confirm the action methods pass but visual confirmation of the sticky bar appearing/disappearing is a browser-only check."
---

# Phase 8: recurring-detection-fixed-payments-view Verification Report

**Phase Goal:** User can see the curated list of monthly fixed payments — every recurring expense and recurring income (salary, regular transfers) — normalized to a monthly-equivalent amount with funding-source chain icons, and drill into any series to see its full historical occurrences.
**Verified:** 2026-05-17T19:30:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | User opens the fixed-monthly-payments view and sees each detected recurring series with its name, normalized monthly equivalent, funding source + chain icon, category, and next expected charge date | VERIFIED | `RecurringPage` Livewire SFC at `/recurring` exists; `FixedPaymentsViewQuery::viewForUser()` sources grouped expense+income DTOs with `monthly_equivalent_minor`, `latestFundingChainLinkId`, `nextExpectedAt`. Blade renders `Recurring expenses` + `Recurring income` sections with all six per-row elements. `FixedPaymentsCard` dashboard card sources top-6 via `topByMonthlyEquivalent()`. All routes confirmed registered: `GET /recurring` (recurring.index). |
| 2 | Recurring detection tolerates moderate amount variance (e.g. Spotify €9.99 → €11.49) within a single series rather than fragmenting it | VERIFIED | `ExpenseSeriesDetector` implements variance tolerance via `variance_tolerance_percent` (default 25%) sourced per-series via `existingToleranceFor()`. `DetectRecurringSeriesJobTest::variance-tolerance` slice passes. `drifting-monthly-spotify.php` fixture confirms one series for the €9.99→€11.49 drift. `EditRecurringSeriesVarianceTolerance` Public Action allows user adjustment (10/25/50%). |
| 3 | Detected series are surfaced as suggestions and only appear on the fixed-payments view once the user approves them (suggest-never-auto-apply) | VERIFIED | New series inserted with `state='pending'` by both detectors. `FixedPaymentsViewQuery::viewForUser()` scopes to `state='approved'` only. `RecurringSeriesQuery::approvedForUser()` is the sole read API for `/recurring`. `RecurringReviewPageTest::suggest-not-applied` slice asserts pending rows do not appear in `approvedForUser()` but appear after approval. Five Public Actions (Approve/Reject/Snooze/EditName/UnReject) provide the user controls. |
| 4 | User can click into any fixed payment and see every historical occurrence plus an amount trend over time | VERIFIED | `RecurringSeriesDetailPage` Livewire SFC at `/recurring/series/{id}` (route: recurring.series.show). `RecurringSeriesQuery::occurrencesForSeries()` reads real DB rows. `RecurringSeriesQuery::amountTrendForSeries()` builds the `RecurringSeriesAmountTrendDto` payload. ApexCharts is globally available via `window.ApexCharts` (app.js). Blade emits `data-options` JSON via `json_encode()` into an Alpine `x-init` handler. Cross-user 404 enforced via `mount()`. `RecurringSeriesDetailPageTest` passes 6 cases including cross-user-404 and EUR-shadow verification. |
| 5 | Recurring income (monthly salary, regular transfers in) appears alongside recurring expenses so cash-flow logic can balance both sides | VERIFIED | `IncomeSeriesDetector` implements `SeriesDetector`, tagged `recurring.detector` alongside `ExpenseSeriesDetector`. Income series inserted with `direction='income'`. `FixedPaymentsViewQuery::viewForUser()` returns `['expenses'=>…, 'income'=>…, 'transfers'=>[]]`. `recurring-page.blade.php` renders both "Recurring expenses" and "Recurring income" section headings with per-row rendering. Net-flow summary header shows `expenseTotal + incomeTotal = netTotal`. `IncomeDetectorTest` passes 6 slices including two-employer split, threshold filtering, and IBAN-primary clustering. |

**Score:** 5/5 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Recurring/composer.json` | Module manifest `diederik/recurring` | VERIFIED | File exists with PSR-4 autoload root |
| `Modules/Recurring/Providers/RecurringServiceProvider.php` | Bootable service provider | VERIFIED | Registered in `bootstrap/providers.php` (grep returns 1); registers singletons, Livewire components, and View Factory composers |
| `Modules/Recurring/Database/Migrations/` (4 files) | recurring_series, occurrences, transitions tables + users columns | VERIFIED | All 4 migration files exist; state-validation triggers present (`recurring_series_state_check_insert` count=2); UNIQUE `rec_series_uniq` present |
| `Modules/Recurring/Models/RecurringSeries.php` | Eloquent model with `BelongsToUser` | VERIFIED | Exists; `BelongsToUser` grep returns 2; `latestFundingChainLink()`, `transitions()`, `occurrences()` relations present |
| `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` | Sole-mutator state machine | VERIFIED | Exists; `ALLOWED_TRANSITIONS` count=3; `PRAGMA busy_timeout` count=3; `lockForUpdate` count=2 |
| `Modules/Recurring/Public/Contracts/SeriesDetector.php` | Public interface | VERIFIED | Exists |
| `Modules/Recurring/Public/Dto/RecurringSeriesDto.php` (+ 3 others) | 4 Public DTOs | VERIFIED | All 4 DTO files exist under `Public/Dto/` |
| `Modules/Recurring/Public/Events/` (4 files) | 4 Public events | VERIFIED | All 4 event files exist |
| `Modules/Recurring/Internal/CadenceInferrer.php` | Cadence algorithm with missed-interval tolerance | VERIFIED | `MISSED_INTERVAL_MULTIPLIER` count=2; `WEEKLY_MAX_EXCLUSIVE` boundary fixed; snap bands per D-843; confidence_low logic present |
| `Modules/Recurring/Internal/Detection/ClusterKeyComposer.php` | Deterministic cluster key | VERIFIED | Exists; `compose()` method present |
| `Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php` | Expense detector implementing SeriesDetector | VERIFIED | Exists; implements interface; variance tolerance honoured via `existingToleranceFor()` |
| `Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php` | Income detector | VERIFIED | Exists; implements SeriesDetector; tagged as `recurring.detector` alongside Expense detector |
| `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` | Queued job with per-user uniqueness | VERIFIED | `ShouldBeUniqueUntilProcessing` count=1; `Cache::driver('redis')` present (facade carve-out) |
| `routes/console.php` — `recurring.detect` entry | Daily scheduler entry | VERIFIED | Non-comment grep returns 1 |
| `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` | 6 read methods including `occurrencesForSeries`, `amountTrendForSeries` | VERIFIED | All 6 plan methods present; both drill-in methods added in Plan 05 confirmed |
| `Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php` | 3 methods: `viewForUser`, `topByMonthlyEquivalent`, `monthlyEquivalentTotals` | VERIFIED | All 3 methods present; N+1 budget test passes (≤3 queries for N=12) |
| `Modules/Recurring/Public/Actions/` (6 files) | 5 core + `EditRecurringSeriesVarianceTolerance` | VERIFIED | All 6 action files exist |
| `Modules/Recurring/Internal/Http/Livewire/RecurringReviewPage.php` | /recurring/review SFC | VERIFIED | Exists; no constructor DI (grep returns 0); `bulkApprove` + `bulkReject` present; `setTab()` present |
| `Modules/Recurring/Internal/Http/Livewire/RecurringPage.php` | /recurring SFC | VERIFIED | Exists; no constructor DI; `toggleTransfers()` + `reDetect()` present |
| `Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php` | /recurring/series/{id} SFC with ApexCharts | VERIFIED | Exists; `editVarianceTolerance`, `occurrencesForSeries`, `amountTrendForSeries`, `apexOptions` all present |
| `Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php` | Dashboard inline card | VERIFIED | Exists; `#[Url]` filter toggle; `topByMonthlyEquivalent` sourcing confirmed |
| `Modules/Recurring/Routes/web.php` | 3 routes: /recurring, /recurring/review, /recurring/series/{id} | VERIFIED | All 3 routes registered; `php artisan route:list --path=recurring` confirms all 3 |
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` | Recurring window + income-minimum fields | VERIFIED | `recurringDetectionWindowMonths` grep returns 8 (validate, mount, save, messages) |
| `resources/js/app.js` | `window.ApexCharts` global | VERIFIED | `window.ApexCharts` grep returns 2 |
| `tests/Contracts/BoundaryArchTest.php` | 5 Recurring arch invariants | VERIFIED | `noTransactionWritesFromRecurring`, `noOtherRecurringSeriesStateMutator`, `noSynchronousDetectionInRequestLifecycle`, Internal containment, `DetectRecurringSeriesJob` facade carve-out — all 24 arch tests pass (49 assertions) |
| `Modules/Recurring/Providers/RecurringServiceProvider.php` | Top-nav badge composer via ViewFactoryContract | VERIFIED | `ViewFactoryContract` grep returns 3; `view()->composer` absent (grep returns 0); `@livewire('recurring.fixed-payments-card')` in dashboard.blade.php confirmed |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `bootstrap/providers.php` | `RecurringServiceProvider` | framework provider registration | VERIFIED | `RecurringServiceProvider::class` grep returns 1 |
| `DetectRecurringSeriesJob` | `ExpenseSeriesDetector` + `IncomeSeriesDetector` | `tagged('recurring.detector')` | VERIFIED | Provider `tag([ExpenseSeriesDetector, IncomeSeriesDetector], 'recurring.detector')` present; `IncomeSeriesDetector` grep on provider returns 3 |
| `ApproveRecurringSeries` | `RecurringSeriesStateMachine` | constructor DI + transition | VERIFIED | Pattern consistent across all action files |
| `routes/console.php` | `DetectRecurringSeriesJob` | `Schedule::call()` daily dispatch | VERIFIED | `recurring.detect` non-comment grep returns 1 |
| `Modules/Recurring/Routes/web.php` | `RecurringPage`, `RecurringReviewPage`, `RecurringSeriesDetailPage` | `Route::get()` | VERIFIED | All 3 routes confirmed in `php artisan route:list` |
| `RecurringSeriesDetailPage` | `window.ApexCharts` | Alpine `x-init` + `data-options` JSON | VERIFIED | Blade emits `json_encode($apexOptions)` into `data-options`; Alpine `x-init` uses `window.ApexCharts`; app.js assigns global |
| `RecurringServiceProvider` | `core::livewire.top-nav` view composer (pending count) | `$this->app->make(ViewFactoryContract::class)` | VERIFIED | `ViewFactoryContract` grep returns 3; `registerTopNavBadgeComposer` method present; `top-nav.blade.php` Recurring grep returns 4 |
| `Modules/Core/Resources/views/livewire/dashboard.blade.php` | `FixedPaymentsCard` | `@livewire('recurring.fixed-payments-card')` | VERIFIED | Dashboard grep returns 1 |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `RecurringPage.php` | `$sections` (expenses, income) | `FixedPaymentsViewQuery::viewForUser($user)` → DB query with LEFT JOIN chain_links | Yes — reads from `recurring_series` WHERE `state='approved'` | FLOWING |
| `RecurringSeriesDetailPage.php` | `$occurrences` | `RecurringSeriesQuery::occurrencesForSeries()` → DB query on `recurring_series_occurrences` | Yes — reads real occurrence rows ordered by `observed_at DESC` | FLOWING |
| `RecurringSeriesDetailPage.php` | `$apexOptions` | `RecurringSeriesQuery::amountTrendForSeries()` → DB query with JOIN to `transactions` for EUR shadow | Yes — reads real occurrence data with `transactions.settled_amount_minor` | FLOWING |
| `FixedPaymentsCard.php` | `$rows` | `FixedPaymentsViewQuery::topByMonthlyEquivalent($user, 6)` → DB query | Yes — top-6 approved series by monthly_equivalent | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| All Unit/Feature/Contracts tests pass | `vendor/bin/pest --testsuite=Unit,Feature,Contracts` | 1042 passed, 5 skipped, 0 failed | PASS |
| Larastan level max + strict | `composer analyse` | OK — no errors over 350 files | PASS |
| Pint format check | `composer format:check` | `{"tool":"pint","result":"passed"}` | PASS |
| BoundaryArchTest (all 5 Recurring invariants) | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` | 24 passed (49 assertions) | PASS |
| Routes registered | `php artisan route:list --path=recurring` | 3 routes: recurring.index, recurring.review, recurring.series.show | PASS |
| RecurringDetectionContractTest | Full Wave-0 corpus | 12 passed (24 assertions) including idempotency slice | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| REC-01 | Plans 01, 03 | Recurring transactions detected by clustering + cadence inference | SATISFIED | `ExpenseSeriesDetector` + `IncomeSeriesDetector` + `CadenceInferrer` + `ClusterKeyComposer` implemented and tested |
| REC-02 | Plans 01, 03, 05 | Moderate amount variance tolerance (±25%) | SATISFIED | `variance_tolerance_percent` column; `existingToleranceFor()` in detector; `EditRecurringSeriesVarianceTolerance` Action; `drifting-monthly-spotify` fixture passes |
| REC-03 | Plans 02, 03 | Suggest-never-auto-apply | SATISFIED | New series born `state='pending'`; `/recurring` only shows `state='approved'`; review queue is the approval surface |
| REC-04 | Plans 03, 04, 05 | Fixed-monthly-payments overview with name, monthly-equivalent, funding-source, category, next-expected | SATISFIED | `RecurringPage` + `FixedPaymentsCard` implement all 5 columns; `FixedPaymentsViewQuery` joins chain_links + MerchantMemory; D-829 chain fallback implemented |
| REC-05 | Plan 05 | Drill-in to historical occurrences + amount-drift trend | SATISFIED | `RecurringSeriesDetailPage` at `/recurring/series/{id}`; `occurrencesForSeries` + `amountTrendForSeries` + ApexCharts chart |
| LED-06 | Plans 02, 04 | Recurring income detected same way as expenses | SATISFIED | `IncomeSeriesDetector` implements `SeriesDetector`; tagged alongside ExpenseSeriesDetector; IBAN-primary clustering; `recurring_income_min_amount_minor` threshold |
| UI-03 | Plans 04, 05 | From any fixed payment, drill into history + amount-drift | SATISFIED | `recurring.series.show` route; full ApexCharts trend chart + occurrences table |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `Modules/Recurring/tests/fixtures/real/anonymised-asn-ics-6mo.php` | 15 | `TODO_REAL_FIXTURE` marker | INFO | Intentional stub — acknowledged in Plan 01 SUMMARY as a deliberate phase-close-out deferral. The file returns empty transactions (series_count=0) and does not affect correctness of any production code. The contract test handles this gracefully (the stub produces 0 expense, 0 income series as declared in `expected`). The SUMMARY, REVIEW, and deferred-items.md all document this explicitly. |

No TBD, FIXME, or XXX markers found in production code. No GSD planning references (D-xxx, Plan N, Phase N, Wave N, issue #N) found in any PHP or Blade file outside of the tests/ directories.

### Human Verification Required

### 1. /recurring grouped view visual rendering

**Test:** Log in to the app, run the detector (`php artisan recurring:detect` or click "Re-detect now" on `/recurring`), approve at least one expense series and one income series via `/recurring/review`. Then open `/recurring`.
**Expected:** Both "Recurring expenses" and "Recurring income" sections visible with real data; net-flow header shows expense total, income total, and net; each row shows display name, latest amount (original currency primary, EUR shadow for non-EUR rows), monthly-equivalent EUR, chain badge (when chain_link exists), category badge (when merchant_memory exists), and next-expected-charge text in relative+absolute format; "Recurring transfers" section collapsed by default.
**Why human:** Visual layout, calm aesthetic, and simultaneous presence of six per-row elements require browser rendering. Feature tests verify data-attribute markers only.

### 2. /recurring/series/{id} ApexCharts chart rendering

**Test:** Open `/recurring/series/{id}` for an approved series that has multiple historical occurrences.
**Expected:** ApexCharts line/bar chart renders with amount data points on a date x-axis; for USD-priced series a second EUR shadow line appears in a dimmer color alongside the native-currency line; the occurrences table below the chart lists each occurrence with date, amount, and a clickable link to the underlying transaction.
**Why human:** ApexCharts initialisation is client-side JavaScript. The feature tests confirm the `data-options` JSON is emitted correctly, but the actual browser rendering of the chart cannot be verified without a headless browser.

### 3. Bulk Approve / Bulk Reject sticky action bar UX

**Test:** Open `/recurring/review` with multiple pending suggestions. Select two or more rows via checkboxes. Observe the sticky bottom action bar. Click "Bulk approve". Observe the toast.
**Expected:** The sticky action bar with "N selected · Approve all / Reject all" appears at the bottom of the page immediately after the first checkbox selection (Livewire `wire:model.live`). After clicking bulk-approve the toast fires reading `"N approved"` with a 10-second undo affordance. The approved rows disappear from the Pending tab.
**Why human:** The conditional sticky bar appearance via `wire:model.live` checkbox binding is a reactive Livewire UI behaviour. The feature tests confirm the action methods succeed but visual confirmation of bar appearance/disappearance requires a browser.

### Gaps Summary

No gaps identified. All 5 phase success criteria are fully verified in the codebase. All 7 requirement IDs (REC-01 through REC-05, LED-06, UI-03) are satisfied by real implementations backed by passing tests (1042 Unit/Feature/Contracts tests; 0 failures). Larastan level max + strict passes clean with 0 errors over 350 files. Pint format check passes. All 5 Recurring boundary arch invariants pass. All 3 expected routes are registered.

The `TODO_REAL_FIXTURE` marker in the anonymised real-export stub is a planned stub explicitly accepted in the Plan 01 SUMMARY under "Known Stubs" and does not constitute a blocker — it affects no production code path and the contract test handles the empty-fixture case correctly.

Code-review findings (CR-01 through CR-04 critical, WR-01 through WR-10 warnings, IN-01 through IN-08 info) were all resolved in the post-execution review (status=resolved at 2026-05-17T18:55:00Z, 21/22 fixed, 1 wontfix). The wontfix item (IN-02, cosmetic test helper naming) does not affect any must-have.

The 3 human verification items above are needed to confirm the visual/interactive aspects of the phase deliverable that cannot be verified programmatically.

---

_Verified: 2026-05-17T19:30:00Z_
_Verifier: Claude (gsd-verifier)_
