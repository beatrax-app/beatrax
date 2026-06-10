---
phase: "04-responsive-installable-pwa-seed-008"
plan: "07"
subsystem: "responsive-charts-power-surfaces"
tags: ["mobile", "responsive", "apexcharts", "overflow-x", "charts", "import", "devmode", "d-11", "d-20"]
dependency_graph:
  requires:
    - "04-01 (ApexCharts v5 upgrade)"
    - "04-03 (mobile shell primitives + CSS tokens)"
  provides:
    - "D-11: ApexCharts responsive[] breakpoints at 768px in ForecastPage, aggregate-line-chart, and recurring-detail-chart-options — chart fills container width at phone width with 4 x-axis labels and hidden legend"
    - "D-20: overflow-x-auto wrappers on all 9 /dev/* surfaces and all 4 Import power surfaces"
    - "Modules/Recurring/Resources/views/livewire/partials/recurring-detail-chart-options.blade.php (new partial)"
  affects:
    - "Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php"
    - "Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php"
    - "Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php"
    - "Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php"
    - "Modules/Recurring/Resources/views/livewire/partials/recurring-detail-chart-options.blade.php"
    - "Modules/Import/Resources/views/livewire/preview-wizard.blade.php"
    - "Modules/Import/Resources/views/livewire/preview-wizard-rows.blade.php"
    - "Modules/Import/Resources/views/livewire/upload-wizard.blade.php"
    - "Modules/Import/Resources/views/livewire/import-results.blade.php"
    - "Modules/DevMode/Resources/views/livewire/ (9 files)"
tech_stack:
  added: []
  patterns:
    - "ApexCharts native responsive[] array in server-rendered data-options: breakpoint 768, tickAmount 4, legend hidden, height 240 — zero extra JS"
    - "overflow-x-auto on outer page root div for power/occasional surfaces — no card conversion, desktop markup unchanged"
    - "Recurring chart partial extraction: recurring-detail-chart-options.blade.php merges $apexOptions with responsive[] at the view layer (presentation concern separate from data concern)"
key_files:
  created:
    - "Modules/Recurring/Resources/views/livewire/partials/recurring-detail-chart-options.blade.php"
  modified:
    - "Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php"
    - "Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php"
    - "Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php"
    - "Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php"
    - "Modules/Import/Resources/views/livewire/preview-wizard.blade.php"
    - "Modules/Import/Resources/views/livewire/preview-wizard-rows.blade.php"
    - "Modules/Import/Resources/views/livewire/upload-wizard.blade.php"
    - "Modules/Import/Resources/views/livewire/import-results.blade.php"
    - "Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/log-tailer-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/queue-inspector-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/sql-panel-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/audit-log-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/system-snapshot-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/doctor-panel-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/artisan-runner-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/horizon-frame-page.blade.php"
decisions:
  - "responsive[] breakpoints added to ForecastPage::buildApexOptions() (PHP class) not the Blade partial — chart data options are a server-side concern; presentation breakpoints travel inside the JSON payload that the Alpine init reads"
  - "aggregate-line-chart.blade.php builds options in Blade @php — responsive[] added inline alongside the other options, same approach as the existing option construction"
  - "recurring-detail-chart-options.blade.php extracts the chart init from recurring-series-detail-page.blade.php and merges responsive[] at the view layer via array_merge — separates presentation concern (phone tuning) from data concern (series/options built in RecurringSeriesDetailPage::buildApexOptions)"
  - "overflow-x-auto applied to outer div of every power surface rather than wrapping a nested table — most dev surfaces have varied content (grids, forms, iframes, code panes) not a single table, so the outer wrapper is the correct boundary"
  - "dev-overview console pane dark styling untouched — localized dark exception per SKILL sketch-findings-beatrax"
  - "FirstImportStep @media (720px) responsive layout lives in app.css and is unmodified"
metrics:
  duration: "~25 minutes"
  completed_date: "2026-06-10"
  tasks_completed: 2
  files_changed: 18
---

# Phase 04 Plan 07: Chart Responsive Resize + Import/DevMode Power Surface Phone Wrappers

**One-liner:** D-11 ApexCharts responsive[] breakpoints baked into server-rendered data-options (breakpoint 768, tickAmount 4, legend hidden, height 240) across Forecasting + Recurring charts, plus overflow-x-auto wrappers on all 4 Import and 9 /dev/* power surfaces completing D-20 maximal breadth.

---

## Tasks Completed

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 | Chart responsive resize (Forecasting + Recurring) — D-11 | `d431e79` | ForecastPage.php, range-area-chart.blade.php, aggregate-line-chart.blade.php, recurring-series-detail-page.blade.php, recurring-detail-chart-options.blade.php (new) |
| 2 | Import + DevMode power surfaces — overflow-x-auto phone wrappers | `bb1e0df` | 4 Import views + 9 DevMode views |

---

## Deferred Human Verification

Per the project checkpoint policy (approved-deferred), the following checkpoint is recorded for phase-end UAT:

**Checkpoint: Verify chart resize and import + dev power surfaces and desktop parity**

Items to verify at phase-end UAT:

1. With the dev app running (dev DB migrated), resize to ~390px width.
2. Forecast surface + a recurring series detail: the charts fill the column width, show fewer x-axis labels (max 4), hide the legend, and remain legible in light + dark; tooltips work on tap.
3. /import preview (and upload + results): the preview table scrolls horizontally inside its wrapper; the page itself does not overflow; the wizard cards still look right.
4. Walk the /dev/* surfaces (overview, log tailer, queue inspector, sql panel, audit log, system snapshot, doctor, artisan runner, horizon): each dense table/console scrolls horizontally inside its wrapper; no page-level horizontal overflow; the dark console pane stays dark.
5. Resize to desktop: charts and all tables are back to their original full layout.

---

## Deviations from Plan

None — plan executed exactly as written. The extraction of the Recurring chart options into a partial was part of the plan spec.

---

## Quality Gate Results

| Gate | Status | Notes |
|------|--------|-------|
| Forecasting + Recurring Pest tests | PASS | 474 passed, 3 todos |
| Import + DevMode Pest tests | PASS | 580 passed, 2 skipped (pre-existing) |
| `vendor/bin/pint --test ForecastPage.php` | PASS | Passed |
| Acceptance criteria grep checks (all 18 files) | PASS | overflow-x present in all Import + DevMode files; responsive + width:100% in chart files |
| chart type/series unchanged | VERIFIED | No diff to series construction or chart type in any file |
| FirstImportStep @media (720px) | VERIFIED | Lives in app.css (unmodified); no wizard blade files touched |

---

## Known Stubs

None. All responsive changes are complete. The chart responsive[] options are fully wired into the server-rendered data-options JSON for all three chart surfaces.

---

## Threat Flags

No new threat surface outside the plan's threat model.

| Threat ID | Mitigation Status |
|-----------|-----------------|
| T-04-07-01 (dev gate weakening) | Confirmed: only CSS overflow wrappers added; is_developer gate and all route/middleware checks untouched |
| T-04-07-02 (chart data leakage) | Confirmed: responsive[] only adjusts ticks/legend/labels; no new series or data |
| T-04-07-SC (package installs) | Confirmed: zero new packages |

---

## Self-Check: PASSED

- [x] `Modules/Recurring/Resources/views/livewire/partials/recurring-detail-chart-options.blade.php` — created, contains `responsive`, `width:100%`, `beatraxApplyChartTheme`
- [x] `Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php` — contains `responsive` array with breakpoint 768
- [x] `Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php` — contains `width:100%`
- [x] `Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php` — contains `responsive` and `width:100%`
- [x] `Modules/Import/Resources/views/livewire/preview-wizard.blade.php` — contains `overflow-x-auto`
- [x] `Modules/Import/Resources/views/livewire/preview-wizard-rows.blade.php` — contains `overflow-x-auto`
- [x] `Modules/Import/Resources/views/livewire/upload-wizard.blade.php` — contains `overflow-x-auto`
- [x] `Modules/Import/Resources/views/livewire/import-results.blade.php` — contains `overflow-x-auto`
- [x] All 9 /dev/* surfaces — contain `overflow-x-auto`
- [x] Task 1 commit `d431e79` — confirmed in git log
- [x] Task 2 commit `bb1e0df` — confirmed in git log
- [x] Forecasting + Recurring tests: 474 passed
- [x] Import + DevMode tests: 580 passed
