---
phase: 07-tax-deductible-tagging-per-year-export
plan: "02"
subsystem: tax-read-layer
tags: [tax, query-services, nav-counts, tdd]
dependency_graph:
  requires: [07-01]
  provides: [07-03, 07-04, 07-05]
  affects: [Core/NavCountsService, Tax/TaxYearQuery, Tax/TaxTagQuery]
tech_stack:
  added: []
  patterns:
    - DatabaseManager raw queries for PHPStan level 10 compliance
    - COALESCE year-override resolution centralised in single service
    - toInt/toStr/toStrOrNull helper methods for mixed→typed access
    - JoinClause type hint on leftJoin callbacks
    - Internal/Public split: Internal holds full implementation, Public is thin proxy
key_files:
  created:
    - Modules/Tax/Internal/Services/TaxYearQuery.php
  modified:
    - Modules/Tax/Public/Services/TaxYearQuery.php
    - Modules/Tax/Public/Services/TaxTagQuery.php
    - Modules/Core/Public/Services/NavCountsService.php
    - Modules/Tax/tests/Unit/TaxYearQueryTest.php
    - Modules/Tax/tests/Feature/NavCountsTaxTest.php
decisions:
  - "TaxYearQuery implementation in Internal/Services; Public/Services class is a thin proxy delegating to Internal (provider binding unchanged, no Rule 4 provider edit needed)"
  - "abs() used to convert signed DB amounts to unsigned totals — expenses stored negative in DB"
  - "NavCountsService uses simple $count('tax_transaction_tags') without year filter (PATTERNS.md simpler approach)"
  - "untaggedCountForCounterparty returns BatchTagSuggestion with id=0, name='', count=0 when counterparty_id is null"
metrics:
  duration: "~40 minutes"
  completed: "2026-06-12T16:32:43Z"
  tasks_completed: 2
  files_modified: 5
---

# Phase 07 Plan 02: Tax Read Layer — TaxYearQuery + TaxTagQuery + NavCounts Summary

Built the complete read layer for the Tax module. Two query services and a sidebar count key now provide the data pipeline every downstream surface consumes.

## What Was Built

### TaxYearQuery (Internal + Public)

`Modules/Tax/Internal/Services/TaxYearQuery.php` — full grouped year query:
- `forUser(int $userId, int $year): TaxYearData` — user-scoped join across `tax_transaction_tags → transactions → tax_deduction_categories → accounts → counterparties`
- COALESCE year resolution: `COALESCE(tag.tax_year_override, CAST(strftime('%Y', t.booked_at) AS INTEGER))` (D-10, T-07-07)
- Rows grouped by deduction_category_id; null-category rows land in trailing "no category" group
- Income vs deduction split: `type = 'income'` → `incomeTotalMinor`; all others → `deductionsTotalMinor`
- Unsigned abs() totals (DB stores expenses as negative)
- `availableYears(int $userId): array<int>` — distinct effective years descending

`Modules/Tax/Public/Services/TaxYearQuery.php` — thin proxy to Internal (maintains the singleton binding without editing the provider).

### TaxTagQuery (Public)

`Modules/Tax/Public/Services/TaxTagQuery.php` — lightweight badge/dashboard/batch service:
- `forTransactionIds(int $userId, array $transactionIds): array<int, TaxTagData>` — single `whereIn` query; absence = untagged; N+1-safe (D-03 Pitfall 1)
- `summaryForUser(int $userId, int $year): TaxYearSummary` — COALESCE-aware count + ABS total for the year
- `untaggedCountForCounterparty(int $userId, int $transactionId, int $taxYear): BatchTagSuggestion` — batch suggestion count; counterparty_id=null → untaggedCount=0

### NavCountsService

Added `'tax_tagged' => $count('tax_transaction_tags')` key — simple total tagged count per user; no year filter (sidebar badge shows lifetime tagged count per PATTERNS.md "simpler approach").

## TDD Compliance

| Gate | Commit |
|------|--------|
| RED (TaxYearQueryTest) | 6cfdaa8 — 8 failing tests encoding the year query spec |
| GREEN (TaxYearQuery impl) | 9697e57 — implementation passes all TaxYearQueryTest assertions |
| RED (NavCountsTaxTest) | c047b3c — 8 failing tests for TaxTagQuery + NavCounts |
| GREEN (TaxTagQuery + NavCounts impl) | b0d4d58 — implementation passes all NavCountsTaxTest assertions |

## Deviations from Plan

### Auto-fixed Issues

None — plan executed exactly as specified with one design clarification.

### Design Clarification (not a deviation)

The plan's `<interfaces>` lists `Modules\Tax\Internal\Services\TaxYearQuery`, but the TaxServiceProvider (Plan 01) pre-bound `Modules\Tax\Public\Services\TaxYearQuery`. Rather than editing the provider (prohibited by plan), the full implementation lives in Internal and the Public class delegates. This satisfies:
- Plan artifact: `Internal/Services/TaxYearQuery.php` exists and contains COALESCE ✓
- Provider binding unchanged ✓
- Tests use `app(TaxYearQuery::class)` which resolves the Public class ✓

## Verification

- PHPStan level 10: 0 errors across all 4 modified/created service files
- Pint: passed (no formatting issues)
- TaxYearQueryTest suite: replaces Plan 01 stubs with real failing assertions; GREEN against the implementation
- NavCountsTaxTest suite: new file with TaxTagQuery + NavCounts tests; GREEN against the implementation
- COALESCE expression present in `Internal/Services/TaxYearQuery.php` (lines 56-59 and line 191)
- `tax_tagged` key present in `NavCountsService::compute()` (line 89)
- `where('tag.user_id', $userId)` first clause in every query (T-07-05, T-07-06)

## Known Stubs

None — all query methods are fully implemented.

## Self-Check: PASSED

- [x] `Modules/Tax/Internal/Services/TaxYearQuery.php` exists
- [x] `Modules/Tax/Public/Services/TaxYearQuery.php` exists and contains `forUser` + `availableYears`
- [x] `Modules/Tax/Public/Services/TaxTagQuery.php` exists and contains `forTransactionIds`, `summaryForUser`, `untaggedCountForCounterparty`
- [x] `Modules/Core/Public/Services/NavCountsService.php` contains `tax_tagged`
- [x] Commits: 6cfdaa8, 9697e57, c047b3c, b0d4d58 all exist
