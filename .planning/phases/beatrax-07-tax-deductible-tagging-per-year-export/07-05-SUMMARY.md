---
phase: 07-tax-deductible-tagging-per-year-export
plan: "05"
subsystem: Tax
tags: [tax, cockpit, livewire, dashboard, sidebar, cross-user-isolation]
dependency_graph:
  requires: [07-02, 07-03, 07-04]
  provides: [07-06]
  affects: [Tax/TaxPage, Tax/TaxSummaryCard, Core/dashboard, Core/app-sidebar, DevMode/palette]
tech_stack:
  added: []
  patterns:
    - "#[Url(as:'year', except:0)] seasonal-default pattern (DriftPage analog)"
    - "streamDownload export actions (beatrax-tax-{year}.csv/.pdf) with ResponseFactory DI"
    - "DatabaseManager::value('tax_country_code') for typed column not on User model"
    - "details/summary collapsible sections (HTML5 native, no JS dependency)"
    - "@layer components tax-* CSS primitives — 8 new classes, zero new @theme variables"
    - "Two-user XUI probe pattern (xuiTransaction + tax_transaction_tags seeding)"
key_files:
  created:
    - Modules/Tax/Internal/Http/Livewire/TaxPage.php
    - Modules/Tax/Resources/views/livewire/tax-page.blade.php
    - Modules/Tax/Internal/Http/Livewire/TaxSummaryCard.php
    - Modules/Tax/Resources/views/livewire/tax-summary-card.blade.php
  modified:
    - Modules/Tax/Routes/web.php
    - Modules/Tax/Providers/TaxServiceProvider.php
    - resources/css/app.css
    - Modules/Core/Resources/views/livewire/dashboard.blade.php
    - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
    - Modules/DevMode/Providers/DevModeServiceProvider.php
    - Modules/Auth/tests/Feature/CrossUserIsolationTest.php
    - Modules/Tax/tests/Feature/TaxPageTest.php
decisions:
  - "tax_country_code read via DatabaseManager::value() in TaxPage::render() — not on typed User model, same approach as TaxSettingsSection"
  - "HTML5 <details>/<summary> for collapsible sections — zero JS, keyboard+screen-reader accessible, collapsed on phone / open on desktop via @if(!isNoCategory) open attribute"
  - "Year switcher: button array from array_unique(merge([$year], $availableYears)) sliced to max 5 — matches DriftPage tab model but for arbitrary int years"
  - "Counterparty fixture in TaxPageTest must create a counterparties row and link counterparty_id — TaxYearQuery joins cp.display_name not t.counterparty_name"
metrics:
  duration: "~10 minutes"
  completed: "2026-06-12T17:17:00Z"
  tasks_completed: 2
  files_modified: 12
---

# Phase 07 Plan 05: /tax Cockpit + Exports + Dashboard Card + Sidebar + Cross-User Isolation

**One-liner:** /tax year cockpit with seasonal default + grouped category sections + CSV/PDF stream-download exports + dashboard summary card + sidebar/⌘K entry + real two-user data-isolation probe replacing the Plan-01 stub.

## What Was Built

### Task 1: TaxPage cockpit + CSS (commit 8b70a33)

`Modules/Tax/Internal/Http/Livewire/TaxPage` — full cockpit Livewire component:

- `#[Url(as:'year', except:0)] public int $year = 0` — URL-synced year param for deep links
- `mount(CurrentUser, Clock)` — applies D-22 seasonal default when year=0 (month≤4 → year-1; month≥5 → year)
- `exportCsv(ResponseFactory, TaxCsvExporter, CurrentUser)` — stream-downloads `beatrax-tax-{year}.csv` with `text/csv; charset=UTF-8` (T-07-16 user-scoped)
- `exportPdf(ResponseFactory, TaxPdfRenderer, CurrentUser)` — stream-downloads `beatrax-tax-{year}.pdf` with `application/pdf`
- `render(CurrentUser, TaxYearQuery, ViewFactory, DatabaseManager)` — reads `tax_country_code` via DB, calls `forUser()` + `availableYears()`, unauthenticated branch returns empty view (T-07-17)

`tax-page.blade.php` — page view per UI-SPEC Section 8:
- Page header: H1 "Tax {year}", year segmented switcher (last 5 years), two `.tax-export-btn` buttons with `aria-busy` while in flight
- Year-totals strip (`.tax-totals-strip`): deductions / income (if > 0) / item count KPIs
- First-visit empty state (no tax country): centered card with CTA to Settings → Tax (Section 9)
- Empty-year calm note: "Nothing tagged for {year} yet." + "tagged items in other years" sub-copy when applicable (Section 9)
- Category sections (`<details class="tax-section">`): one per category + "No category" last; `<summary class="tax-section-header">` with name + count chip + subtotal + chevron; desktop table + phone card-per-row
- Amber year-override chip (`.tax-badge--amber`) in the Year column for D-10 rows

`resources/css/app.css` — 8 new `@layer components` classes (UI-SPEC Section 14):
| Class | Purpose |
|-------|---------|
| `.tax-badge` | Tagged state pill (emerald fill, xs, tabular-nums) |
| `.tax-badge--untagged` | Ghost hover tag button (row-cta reveal pattern) |
| `.tax-badge--amber` | Year-override amber chip |
| `.tax-section` | Category section container (card primitive) |
| `.tax-section-header` | Sticky section header row |
| `.tax-totals-strip` | KPI bar (flex, min-height 64px) |
| `.batch-tag-banner` | Batch suggestion banner (blue fill + fade-in animation) |
| `.tax-export-btn` | Export button (pill-btn-ghost + border) |

Zero new `@theme` variables. `npm run build` compiles cleanly.

Route: `tax.index` swapped from 501 closure to `TaxPage::class`.
TaxServiceProvider: registers `tax.tax-page` and `tax.summary-card` (stub for Task 2).

`TaxPageTest` — 11 tests green:
- GET /tax → 200 for authenticated user
- GET /tax → redirect /login for guest
- Route resolves to TaxPage (not closure)
- Seasonal default Feb→2025, Aug→2026
- Grouped sections visible (counterparty, category)
- Year switcher changes results
- Empty-year calm note
- exportCsv / exportPdf return non-null StreamedResponse
- First-visit empty state shows "Which country do you file taxes in?"

### Task 2: Dashboard card + sidebar + palette + cross-user probe (commit 3943c78)

`TaxSummaryCard` — GoalsSummaryCard analog:
- `render(CurrentUser, TaxTagQuery, ViewFactory, Clock)` applies D-22 seasonal rule, calls `summaryForUser()`
- Passes `total` (minor int), `count`, `year` to view
- No `$view->extends()` — renders inline

`tax-summary-card.blade.php` — UI-SPEC Section 10:
- `.card` primitive + anchor to `route('tax.index')`
- Heading "Tax {year}" at `--text-xs` uppercase
- `--text-xl` tabular KPI total, `--text-xs` "{N} items tagged" sub-line
- Calm empty state "No items tagged yet for {year}" when count=0

`dashboard.blade.php` — `@livewire('tax.summary-card')` after `goals.summary-card`.

`app-sidebar.blade.php` — Tax entry with ⊞ glyph + `.side-badge muted` showing `tax_tagged` count when > 0 (D-17).

`DevModeServiceProvider.php` — `tax.index` palette entry with keywords `[deduction, aangifte, export, records]` (D-17 ⌘K).

`CrossUserIsolationTest` — real two-user data-isolation probe:
- Seeds owner with a tagged transaction (`OWNER SECRET DEDUCTIBLE MERCHANT BV`, category `Owner Secret Category`, note `owner-secret-note`)
- Seeds partner with their own tagged transaction
- Partner visits `/tax?year=2026` — asserts owner's counterparty/category/note are absent
- `tax.index` moved from `ISOLATION_ROUTE_ALLOW_LIST` to `ISOLATION_ROUTE_COVERED` (honors Plan-01 contract)

## Test Results

```
php artisan test --filter="Tax|CrossUserIsolation"
Tests: 101 passed (345 assertions)
```

- TaxPageTest: 11/11 passed
- CrossUserIsolationTest: 23/23 passed (including new tax probe)
- All prior Tax tests: 67/67 passed (TaxTagActionTest, TaxYearQueryTest, NavCountsTaxTest, TaxExportCsvTest, TaxExportPdfTest, TaxCsvExporterTest, TaxCorpusLoaderTest, TaxSettingsTest, TaxBoundaryTest unchanged)

PHPStan level 10 on Modules/Tax: 0 errors.
Pint on all modified PHP files: passed.
`npm run build`: compiled cleanly (289 kB CSS, 603 kB JS).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] DatabaseManager::value() for tax_country_code — not on typed User model**
- **Found during:** PHPStan check on TaxPage.php (property.notFound error)
- **Issue:** `$user->tax_country_code` is undefined on the typed `User` model (added via migration, not declared in model fillable/property). Direct access triggers PHPStan level 10 errors.
- **Fix:** Changed render() to read via `$db->connection()->table('users')->where('id', $user->id)->value('tax_country_code')` — same pattern as TaxSettingsSection uses.
- **Files modified:** Modules/Tax/Internal/Http/Livewire/TaxPage.php
- **Commit:** 8b70a33

**2. [Rule 1 - Bug] Removed stale @phpstan-ignore-next-line annotations on export actions**
- **Found during:** PHPStan check (ignore.unmatchedLine errors on lines 73 and 99)
- **Issue:** PHPStan v2 strict mode reports unused ignores as errors. The `StreamedResponse` return type was correctly inferred — no suppression was needed.
- **Fix:** Removed `/** @phpstan-ignore-next-line return.type */` + the `Illuminate\Http\Response` import.
- **Files modified:** Modules/Tax/Internal/Http/Livewire/TaxPage.php

**3. [Rule 1 - Bug] TaxPageTest counterparty fixture must create a counterparties row**
- **Found during:** RED phase — tests failed because `counterpartyName` returned null
- **Issue:** `TaxYearQuery::forUser()` joins on `cp.display_name` via a left join on `counterparties` — but the test fixture only set `counterparty_name` on the transaction. No counterparty record = null display_name = the assertion `assertSee('Apotheek BV')` fails.
- **Fix:** Added counterparty `INSERT` in `taxPageTaggedTransaction()` fixture and linked via `counterparty_id` on the transaction.
- **Files modified:** Modules/Tax/tests/Feature/TaxPageTest.php

**4. [Rule 1 - Bug] TaxPageTest unique constraint on tax_deduction_categories (user_id, name)**
- **Found during:** Second test that called taxPageTaggedTransaction with the same category name for the same user
- **Issue:** The fixture created a new category each call; second call with 'Healthcare' violated the unique constraint.
- **Fix:** Added existence check before INSERT — reuse existing category for (user_id, name) if present.
- **Files modified:** Modules/Tax/tests/Feature/TaxPageTest.php

**5. [Rule 1 - Bug] assertRedirectToRoute('auth.login') — route named 'login' not 'auth.login'**
- **Found during:** Test run
- **Issue:** `assertRedirectToRoute('auth.login')` throws RouteNotFoundException — the login route is named `login`.
- **Fix:** Changed to `assertRedirect('/login')`.
- **Files modified:** Modules/Tax/tests/Feature/TaxPageTest.php

## Known Stubs

None. All TaxPage methods are fully implemented and call real services.

## Checkpoint

Task 3 (`checkpoint:human-verify`) reached after completing Tasks 1 and 2. Human UAT is required for:
- /tax cockpit visual layout, year switching, grouped sections, empty states
- CSV and PDF export downloads (including manual PDF layout check)
- Dashboard card, sidebar entry, ⌘K palette entry

## Threat Surface Scan

All threats from the plan's threat register are mitigated:
- T-07-15: TaxYearQuery is user-scoped; real two-user probe in CrossUserIsolationTest confirms no bleed
- T-07-16: exportCsv/exportPdf pass `CurrentUser->user()` to user-scoped services only; unauthenticated branch returns empty StreamedResponse
- T-07-17: Route group has `auth` middleware; unauthenticated render branch returns empty view as defence-in-depth

No new threat surfaces introduced beyond those in the plan.

## Self-Check: PASSED

Files verified:
- FOUND: Modules/Tax/Internal/Http/Livewire/TaxPage.php
- FOUND: Modules/Tax/Resources/views/livewire/tax-page.blade.php
- FOUND: Modules/Tax/Internal/Http/Livewire/TaxSummaryCard.php
- FOUND: Modules/Tax/Resources/views/livewire/tax-summary-card.blade.php
- FOUND: resources/css/app.css (8 new tax-* classes confirmed via grep)

Commits verified:
- FOUND: 8b70a33 feat(07-05): TaxPage cockpit
- FOUND: 3943c78 feat(07-05): dashboard tax card, sidebar entry, ⌘K palette, real cross-user probe
- FOUND: 4751619 style(07-05): pint formatting on TaxPageTest.php
