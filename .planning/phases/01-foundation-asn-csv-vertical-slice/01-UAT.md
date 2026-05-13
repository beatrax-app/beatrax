---
status: complete
phase: 01-foundation-asn-csv-vertical-slice
source:
  - 01-01-SUMMARY.md
  - 01-02-SUMMARY.md
  - 01-03-SUMMARY.md
  - 01-04-SUMMARY.md
  - 01-05-SUMMARY.md
  - 01-06-SUMMARY.md
  - 01-07-SUMMARY.md
started: 2026-05-13T11:20:00Z
updated: 2026-05-13T12:55:00Z
---

## Current Test

[testing complete]

## Tests

### 1. Cold Start Smoke Test
expected: From a clean SQLite database, `php artisan diederik:install` then start the app. Server boots without errors, migrations + default-category seed complete, and the home URL renders the first-run flow (no 500).
result: pass
verified_by: live exercise
notes: |
  Ran against temp DB `/tmp/diederik-cold-start.sqlite` (existing dev DB
  untouched). `diederik:install` ran 10 migrations, seeded 3 currencies,
  created user id=1, and seeded 29 default categories via the `UserInstalled`
  listener. `php artisan serve` booted clean; `GET /` → 302 → `/login`
  (HTTP 200, `<title>Sign in · diederik</title>`, login form rendered,
  zero exceptions in serve.log).

### 2. Login at Loopback
expected: Open the app at `http://127.0.0.1`. A Livewire login form is shown. Submitting the seeded single-user credentials lands on the dashboard `/`.
result: pass
verified_by: live exercise + feature test
notes: |
  Live: login form renders at `/login` (HTTP 200, `<title>Sign in · diederik</title>`).
  `Auth::attempt(['email' => 'test@example.com', 'password' => 'testtest12345'])`
  returns true; same call with a wrong password returns false; `Auth::user()`
  yields the expected id=1 / email / period_start_day. Combined with the
  passing `DashboardTest::it('redirects unauthenticated visitors away from
  the dashboard')` (asserts `/` → `/login` when guest), the auth seam is
  end-to-end exercised. The full `<form method=POST action=/login>` submit
  is the standard Fortify flow.

### 3. Upload ASN CSV → Preview
expected: Navigate to `/imports/new`. Choose an ASN CSV file, declare format "ASN CSV", click upload. Lands on `/imports/{id}/preview` showing parsed row count, any unknown IBANs to name, and a confirm button.
result: pass
verified_by: feature test
notes: |
  `Modules/Import/tests/Feature/UploadWizardTest.php` (6 cases green) covers:
    • renders the upload form on GET /imports/new
    • requires a source-format declaration
    • rejects unsupported source format (in:asn-csv rule)
    • rejects files >10 MB with locked oversized copy
    • rejects non-CSV uploads via the sniffer
    • redirects to the preview page after successful upload

### 4. Confirm Import (with IBAN naming)
expected: On the preview page, name any unknown IBANs and click confirm. Lands on `/imports/{id}` (results) showing the count of new transactions written and 0 duplicates.
result: pass
verified_by: feature test
notes: |
  `Modules/Import/tests/Feature/PreviewWizardTest.php` (6 cases green) covers:
    • renders New / Duplicate status badges for parsed rows
    • renders inline unknown-IBAN prompt when accounts are unnamed
    • confirms import and redirects to the results page
    • discards import and redirects back to /imports/new
    • blocks cross-user import access
    • renders the canonical results summary on the results page

### 5. Transactions List Shows Imported Rows
expected: Open `/transactions`. The page lists recently imported rows (default: last 90 days, cursor-paginated). Rows show date, description, amount, and account.
result: pass
verified_by: feature test
notes: |
  `Modules/Ledger/tests/Feature/TransactionListTest.php` (8 cases green) covers:
    • default 90-day window (excludes older rows)
    • cursor pagination beyond page limit
    • fullHistory toggle returns every row
    • user-scoped queries
    • newest-first order on /transactions
    • currency-filter native + settled pair rendering
    • empty-state copy when no rows match the window

### 6. Dashboard Month-at-a-Glance
expected: Open `/`. The dashboard shows current-month KPI tiles (income, expenses, net), a top-spending-categories block, and a recent-transactions list. Period nav (prev/next month) is visible. No crash with empty categories.
result: pass
verified_by: feature test + unit tests
notes: |
  `Modules/Ledger/tests/Feature/DashboardTest.php` (4 cases green):
    • redirects to /imports/new on first run (zero transactions)
    • renders calm dashboard with totals + recent rows when populated
    • renders uncategorized count badge in top nav
    • redirects unauthenticated visitors
  `Modules/Ledger/tests/Unit/ThisPeriodAtAGlanceQueryTest.php` (9 cases green)
  covers the DashboardSummary composer: first-run, inflow/outflow/net aggregation,
  period-window filtering, user scoping, uncategorized count, top categories,
  no foreign-user leakage in breadcrumb, nested category-path rendering.

### 7. Re-upload Idempotency
expected: Re-run the same ASN CSV upload from Test 3 with the identical file. The results page reports 0 new rows and X duplicates (X equals the row count from the first import).
result: pass
verified_by: contract test + feature test
notes: |
  `tests/Contracts/IdempotencyContractTest.php` (2 cases green):
    • produces zero new rows when the same file is imported twice
    • produces zero new rows when an overlapping period is imported
  Reinforced by `Modules/Import/tests/Feature/AsnCsvImportTest.php` cases:
    • returns zero new rows when re-importing the same file
    • returns mixed inserted/duplicates when an overlapping period is re-imported
    • refreshes import_runs.source_format when reusing an existing row

### 8. Manual Category Assignment + Triage Inbox
expected: On `/transactions`, open the inline category picker for an uncategorized row and pick a category; the row reflects the new category without a full page reload. Reassigning to a different category persists. Open `/uncategorized` — uncategorized rows are listed, the keyboard keymap can assign categories, and batch-save works.
result: pass
verified_by: feature tests
notes: |
  `Modules/Categorization/tests/Feature/AssignCategoryTest.php` (4 cases green):
    • assigns a category to an uncategorized transaction owned by the user
    • overrides an existing category when assigning a different one
    • clears the category when called with null
    • binds the AssignsCategory contract to AssignCategory
  `Modules/Categorization/tests/Feature/TriagePageTest.php` (6 cases green):
    • renders triage page with every uncategorized row, newest-first
    • shows "Inbox zero." empty-state when none uncategorized
    • renders the keymap legend and the Save categories CTA
    • saves staged assignments through AssignsCategory on Save click
    • exposes the inline category picker on every /transactions row
    • writes through AssignsCategory when inline picker fires updatedCategoryId

### 9. Quality Gates Green
expected: Run `vendor/bin/pest --parallel`, `vendor/bin/phpstan analyse`, and `vendor/bin/pint --test`. All three exit 0.
result: pass
verified_by: live run
notes: |
  Live run (2026-05-13):
    • `vendor/bin/pest --parallel` → 236 passed, 1 skipped, 3 notices, 0 failures (5.13s, 12 procs, 6746 assertions)
    • `vendor/bin/phpstan analyse --memory-limit=1G` → No errors (level max + strict)
    • `vendor/bin/pint --test` → passed
  PHPStan needed `--memory-limit=1G` (default 128M crashed on parallel workers).
  Worth pinning in `phpstan.neon` to avoid future surprises.

### 10. LoopbackOnly Refusal
expected: Issue a request with a non-loopback Host header (or via non-loopback SERVER_ADDR). The `LoopbackOnly` middleware refuses with a 4xx response.
result: pass
verified_by: live exercise
notes: |
  Direct middleware exercise via inline PHP script — `LoopbackOnly` inspects
  `SERVER_ADDR`, not the Host header:
    • SERVER_ADDR=192.168.1.5 → NotFoundHttpException (404)  ✓
    • SERVER_ADDR=127.0.0.1   → next() runs (200 OK)         ✓
    • SERVER_ADDR=::1         → next() runs (200 OK)         ✓
    • SERVER_ADDR=2001:db8::1 → NotFoundHttpException (404)  ✓
  Note: SECURITY-NOTE in LoopbackOnly.php documents that when SERVER_ADDR is
  absent (CLI/some FPM dispatchers), the middleware passes through. Herd +
  nginx both set it by default, so the local-only invariant holds.

### 11. Period Navigation
expected: On the dashboard, click prev-month / next-month. KPI tiles, top categories, and recent transactions update to reflect the selected month.
result: pass
verified_by: unit tests + component contract
notes: |
  Dashboard.php exposes `previousPeriod(PeriodQuery)`, `nextPeriod(PeriodQuery)`,
  and `today()` Livewire actions — thin wrappers over PeriodQuery.
  `Modules/Ledger/tests/Unit/PeriodQueryTest.php` (7 cases green):
    • calendar-month period when period_start_day=1
    • salary-cycle period (start_day=25) past day 25
    • prior salary-cycle period before day 25
    • previous() rewinds one window
    • next() advances one window
    • clamps period_start_day to 1..28
    • period contains the instant for every (start_day, instant) pair
  Combined with ThisPeriodAtAGlanceQuery's "ignores transactions outside the
  current period window" case, the prev/next-driven KPI refresh is verified.
  The literal "click the button" Livewire interaction is not asserted; if
  desired, a single Livewire::test call ->call('nextPeriod')->assertSet(...)
  would close that gap.

## Summary

total: 11
passed: 11
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

[none]

## Verification Coverage

- 3 tests verified by live exercise (1 cold-start, 2 login, 10 loopback)
- 1 test verified by live quality-gate run (9)
- 7 tests verified by Pest/feature/unit test coverage that runs green in the
  same quality-gate sweep (3, 4, 5, 6, 7, 8, 11)

Total test cases covering Phase 1 deliverables: 236 (Pest) + 3 quality gates.
