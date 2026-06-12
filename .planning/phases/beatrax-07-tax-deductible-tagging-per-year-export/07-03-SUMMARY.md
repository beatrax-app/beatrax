---
phase: 07-tax-deductible-tagging-per-year-export
plan: "03"
subsystem: tax-export-layer
tags: [tax, csv-export, pdf-export, dompdf, league-csv, tdd]
dependency_graph:
  requires: [07-02]
  provides: [07-05]
  affects: [Tax/TaxCsvExporter, Tax/TaxPdfRenderer]
tech_stack:
  added:
    - dompdf/dompdf v3.1.5 (legitimacy gate approved by human)
  patterns:
    - Internal/Public split — Internal holds full implementation, Public is thin proxy
    - league/csv Writer::createFromString() for in-memory CSV generation
    - dompdf v3 Options pattern (isHtml5ParserEnabled, isRemoteEnabled=false, defaultFont)
    - CSS 2.1 table-only Blade PDF template — no Tailwind/flex/grid
    - number_format(abs(minor)/100, 2) for presentation-layer money formatting
key_files:
  created:
    - Modules/Tax/Internal/Services/TaxCsvExporter.php
    - Modules/Tax/Internal/Services/TaxPdfRenderer.php
    - Modules/Tax/Resources/views/pdf/export.blade.php
    - Modules/Tax/tests/Unit/TaxCsvExporterTest.php
  modified:
    - Modules/Tax/Public/Services/TaxCsvExporter.php
    - Modules/Tax/Public/Services/TaxPdfRenderer.php
    - Modules/Tax/tests/Feature/TaxExportCsvTest.php
    - Modules/Tax/tests/Feature/TaxExportPdfTest.php
    - composer.json
    - composer.lock
decisions:
  - "dompdf/dompdf:^3.0 installed after human legitimacy approval (T-07-SC gate — packagist verified 183.7M downloads, LGPL-2.1)"
  - "TaxCsvExporter and TaxPdfRenderer both follow Internal/Public proxy pattern from Plan 02 TaxYearQuery — provider binding unchanged, no provider edit needed"
  - "PDF output() no longer returns nullable in dompdf v3 (returns string) — ?? fallback removed after PHPStan nullCoalesce.expr error"
  - "Pint applied to TaxCsvExporter.php + TaxExportPdfTest.php — minor formatting (braces_position, not_operator_with_successor_space, fully_qualified_strict_types)"
metrics:
  duration: "~45 minutes"
  completed: "2026-06-12T17:00:28Z"
  tasks_completed: 2
  files_modified: 10
---

# Phase 07 Plan 03: TaxCsvExporter + TaxPdfRenderer (D-15 CSV + D-14 PDF) Summary

Installed dompdf/dompdf v3.1.5 (post legitimacy gate) and built both export services. `TaxCsvExporter` produces the full D-15 audit-extra CSV; `TaxPdfRenderer` produces a non-empty, escaped, network-free A4 PDF mirroring the /tax cockpit. Both read exclusively from `TaxYearQuery::forUser()` — cockpit and exports can never diverge.

## What Was Built

### dompdf Install

`dompdf/dompdf:^3.0` (v3.1.5) installed via `composer require` after blocking human legitimacy approval. 5 transitive deps: `dompdf/php-font-lib`, `dompdf/php-svg-lib`, `masterminds/html5`, `sabberworm/php-css-parser`, `thecodingmachine/safe`.

### TaxCsvExporter (D-15 audit-extra CSV)

`Modules/Tax/Internal/Services/TaxCsvExporter.php` — full implementation:
- `export(User $user, int $year): string` uses `League\Csv\Writer::createFromString()`
- Writes the exact 16-column header from the D-15 spec in documented order
- Iterates `TaxYearQuery::forUser($user->id, $year)` category groups and rows
- Money formatting: `number_format(abs(minor) / 100, 2, '.', '')` — presentation-layer string only (not arithmetic; per PATTERNS.md)
- `tax_year_override` is used for the `tax_year` column value when present
- `str()` helper converts `mixed` row values safely to strings (PHPStan level 10 clean)
- Empty year → header-only CSV, no error

`Modules/Tax/Public/Services/TaxCsvExporter.php` — thin proxy to Internal (same pattern as TaxYearQuery proxy, provider binding unchanged).

### TaxPdfRenderer (D-14 A4 PDF)

`Modules/Tax/Internal/Services/TaxPdfRenderer.php` — full dompdf v3 implementation:
- `render(User $user, int $year): string` follows RESEARCH.md Pattern 6 exactly
- Options: `isHtml5ParserEnabled=true`, `isRemoteEnabled=false` (T-07-09), `defaultFont=Helvetica`
- Calls `view('tax::pdf.export', ['year'=>$year, 'data'=>$data])->render()` → `loadHtml` → `setPaper('A4','portrait')` → `render()` → `output()`

`Modules/Tax/Resources/views/pdf/export.blade.php` — self-contained HTML document:
- CSS 2.1 table-only layout (no Tailwind, no flex, no grid — dompdf constraint)
- Summary block: year, item count, deductions total, income total
- One `<table>` per deduction category, "Uncategorised" table last
- `page-break-inside: avoid` on `<tr>` for clean multi-page pagination
- All dynamic values via `{{ }}` Blade auto-escaping (T-07-08 XSS mitigation)
- No external CSS/font refs (consistent with `isRemoteEnabled=false`)

`Modules/Tax/Public/Services/TaxPdfRenderer.php` — thin proxy to Internal.

## TDD Compliance

| Gate | Commit |
|------|--------|
| RED (TaxCsvExporter tests) | 64c326d — 10 failing tests (unit + feature) encoding D-15 CSV spec |
| GREEN (TaxCsvExporter impl) | 86f3e0f — implementation passes all 10 assertions |
| RED (TaxPdfRenderer tests) | 2e81794 — 4 failing tests encoding PDF spec |
| GREEN (TaxPdfRenderer impl) | e7d6635 — implementation passes all 4 assertions |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] dompdf v3 output() returns string, not string|null**
- **Found during:** PHPStan check after Task 2 GREEN
- **Issue:** PHPStan reported `nullCoalesce.expr` — `$pdf->output()` returns `string` in v3, not `string|null`. The RESEARCH.md pattern showed `?? ''` which was copied from v2 docs.
- **Fix:** Removed the `?? ''` null-coalescing fallback; `return $pdf->output();` directly.
- **Files modified:** `Modules/Tax/Internal/Services/TaxPdfRenderer.php`
- **Commit:** e7d6635

**2. [Rule 1 - Bug] PHPStan cast.string errors on mixed row array values**
- **Found during:** PHPStan check after Task 1 GREEN
- **Issue:** `$row` values are `mixed` from the `TaxYearData::$categories` array. Direct `(string)` cast on `mixed` fails PHPStan level 10.
- **Fix:** Added `str(mixed $value): string` helper using `is_string` / `is_scalar` checks — same pattern as TaxYearQuery's `toStr()` helper.
- **Files modified:** `Modules/Tax/Internal/Services/TaxCsvExporter.php`
- **Commit:** 86f3e0f

## Threat Surface Compliance

| Threat | Status |
|--------|--------|
| T-07-SC: dompdf supply-chain | Mitigated — blocking human gate satisfied before install |
| T-07-08: free-text note XSS in PDF | Mitigated — `{{ }}` Blade auto-escaping; `<script>` test asserts `&lt;script&gt;` |
| T-07-09: dompdf SSRF via remote URL | Mitigated — `isRemoteEnabled=false` set and asserted in test |
| T-07-10: export leaks another user's data | Mitigated — both exporters only call `TaxYearQuery::forUser($user->id, ...)` |

## Verification

- `php artisan test --filter=TaxExport` — 9 tests pass (5 CSV feature + 4 PDF feature)
- `php artisan test --filter=TaxCsvExporter` — 5 unit tests pass
- `./vendor/bin/phpstan analyse Modules/Tax/Internal/Services` — 0 errors (level 10)
- `./vendor/bin/pint --test Modules/Tax` — passed
- `composer show dompdf/dompdf` — v3.1.5 confirmed installed
- Template grep: no `display: flex` or `display: grid` rules in `export.blade.php`
- Manual-only (deferred to Plan 05 UAT): multi-page PDF visual layout quality

## Known Stubs

None — both exporter services are fully implemented.

## Self-Check: PASSED

- [x] `Modules/Tax/Internal/Services/TaxCsvExporter.php` exists
- [x] `Modules/Tax/Public/Services/TaxCsvExporter.php` delegates to Internal
- [x] `Modules/Tax/Internal/Services/TaxPdfRenderer.php` exists with `isRemoteEnabled`
- [x] `Modules/Tax/Public/Services/TaxPdfRenderer.php` delegates to Internal
- [x] `Modules/Tax/Resources/views/pdf/export.blade.php` exists with `<table`
- [x] Commits: 214bbb2 (dompdf install), 64c326d (RED CSV), 86f3e0f (GREEN CSV), 2e81794 (RED PDF), e7d6635 (GREEN PDF + Pint)
