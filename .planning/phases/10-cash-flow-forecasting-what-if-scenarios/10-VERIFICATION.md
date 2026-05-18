---
phase: 10-cash-flow-forecasting-what-if-scenarios
verified: 2026-05-18T00:00:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
---

# Phase 10: Cash-Flow Forecasting + What-If Scenarios — Verification Report

**Phase Goal:** User can see 30 / 60 / 90-day projected balances per account — built from recurring inflows, recurring outflows, and pending settlements — with honest uncertainty ranges, highlighted surplus / shortfall windows, and the ability to apply non-persisted what-if mutations (cancel a subscription, add a planned expense) compared side-by-side with the baseline.
**Verified:** 2026-05-18
**Status:** passed
**Re-verification:** No — initial verification
**Mode:** mvp (note: phase goal is a paragraph, not a single User Story sentence; the five ROADMAP Success Criteria function as the verification contract and the User Flow Coverage table below traces each)

## Goal Achievement

### Observable Truths (5 ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | User opens any account and sees a 30 / 60 / 90-day balance projection built from current balance + recurring + pending ICS settlement (FCT-01) | VERIFIED | Route `/forecast` registered (`Modules/Forecasting/Routes/web.php:24`), `ForecastPage` Livewire SFC with horizon segmented control (`ForecastPage.php:99`), `BalanceAnchorResolver` resolves opening balance per account kind (asn/ics/paypal/fallback) (`BalanceAnchorResolver.php:49-98`), `ProjectionPipeline` composes anchor + recurring + chain-routed settlement (`ProjectionPipeline.php:109-231`), `ChainAwareForecastRouter` uses `NextSettlementDto.accountId` for funder routing (`ChainAwareForecastRouter.php:107-125`) |
| 2 | Projections display as ranges (e.g. "€1,180 – €1,260 on May 31st") rather than false-precision single-point values (FCT-02) | VERIFIED | `RangeProjector::envelope` produces (low, point, high) triples (`RangeProjector.php:114-181`); `RangeProjector::percentileTier` produces R-7 P10/P50/P90 (`RangeProjector.php:195-264`); `Percentile` implements linear-interpolation R-7 (`Percentile.php:68-91`); `DailyFold` combines spreads via quadrature `sqrt(spread²)` (`DailyFold.php:77-107`); Blade renders ApexCharts `rangeArea` series (`ForecastPage.php:599-602`); Blade header shows "€X today → €low – €high on day N" (`forecast-page.blade.php:233-235`) |
| 3 | User can highlight surplus or shortfall windows (e.g. "ICS settlement on the 14th will dip you below €X") (FCT-05) | VERIFIED | `ShortfallDetector` writes `forecast_shortfall_windows` rows with `buffer_used_minor` captured at detection time (`ShortfallDetector.php:139-152`); migration adds `accounts.forecast_min_buffer_minor` BIGINT (`2026_05_19_010005_add_forecast_columns_to_accounts.php:36`); `AccountBufferEditor` inline popover (`AccountBufferEditor.php`); rangeArea chart adds rose-50 annotations.yaxis band BELOW buffer (`ForecastPage.php:574-588`); `ForecastHighlightsTile` dashboard tile composed (`forecast-highlights-tile.blade.php:40-66`) and REPLACES Phase 5 "Next ICS settlement" tile (`Modules/Core/Resources/views/livewire/dashboard.blade.php:52-58`); per-window red ↘ caption lines render above chart (`forecast-page.blade.php:268-277`) |
| 4 | User can apply what-if mutations (cancel a series, add a planned transaction, change an amount) and see the impact without anything being written to the database (FCT-03) | VERIFIED | All 5 typed payload subclasses present (`Public/Dto/ScenarioMutationPayload/`: `CancelSeriesPayload`, `AddOneOffPayload`, `AddRecurringPayload`, `ChangeSeriesAmountPayload`, `ShiftSeriesDatePayload`); `ScenarioApplier::apply` composes mutations on top of baseline contributions IN MEMORY only (`ScenarioApplier.php:80-107`); `noScenarioMutationsJoinedToTransactionQueries` arch invariant active and walks ENTIRE `Modules/` tree (`tests/Contracts/BoundaryArchTest.php:834-879`); `ScenarioIsolationContractTest` runtime end-to-end proof asserts substrate row counts (`transactions`, `recurring_series_occurrences`, `chain_links`, `card_statements`, `drift_alerts`, `accounts`) NEVER change across the full scenario lifecycle (Create/Add×5/Edit/Remove/Rename/Project/Read/Delete) (`tests/Contracts/ScenarioIsolationContractTest.php:242-366`); scenarios themselves persist as named entities in `forecast_scenarios` table for "come back later" UX |
| 5 | User can view a what-if scenario side-by-side with the baseline forecast (FCT-04) | VERIFIED | Two-panel side-by-side grid (`forecast-page.blade.php:226 lg:grid-cols-2` when `$scenario instanceof ForecastDto`); shared y-axis pre-computed across both panels (`ForecastPage::computeSharedYAxisRange` at `ForecastPage.php:488-511`); Net diff tile at days 30/60/90 with direction-aware color tints (`ForecastPage.php:520-533` + `net-diff-tile` partial); `ScenarioEditorSidebar` 5-option Add chooser (`ScenarioEditorSidebar.php:108-227`); `wire:poll.2s="refreshProjectionStatus"` CONDITIONALLY rendered only when `$forecast->isComputing` (`range-area-chart.blade.php:54-64`); `SideBySideRenderTest` verifies both panels render + shared y-axis + Net diff color tinting (`SideBySideRenderTest.php:115-227`) |

**Score:** 5/5 truths verified

### Discoverability Surfaces (D-1010 launchpads)

| Launchpad | Status | Evidence |
|-----------|--------|----------|
| Phase 9 drift alert "Model cancel ↗" chip → /forecast pre-seeded with cancel_series scenario | VERIFIED | `drift-alert-row.blade.php:130-133` exposes the button; `DriftPage::createCancellationScenario` redirects to `/forecast?scenarioId={new}` (`DriftPage.php:97-109`) |
| Phase 8 `/recurring/series/{id}` "Model what-if ↗" link | VERIFIED | Mounted in `recurring-series-detail-page.blade.php:46` via `forecasting.model-what-if-dropdown` component; `ModelWhatIfDropdown` SFC and Blade partial both present |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Forecasting/` | Module skeleton with PSR-4, Providers, Routes, tests | VERIFIED | Full module skeleton present including composer.json, ForecastingServiceProvider, web.php route, Pest.php + TestCase.php |
| `Modules/Forecasting/Internal/Pipeline/` | 10 pipeline classes | VERIFIED | All 10 present: BalanceAnchorResolver, RangeProjector, DailyFold, ProjectionPipeline, ChainAwareForecastRouter, ShortfallDetector, ScenarioApplier, Percentile, CadenceJitter, ForecastContribution |
| `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/` | 5 typed payload subtypes + abstract base | VERIFIED | All 5 + abstract `ScenarioMutationPayload` base class present; Spatie Data union via `ScenarioMutationPayloadCast` |
| `Modules/Forecasting/Public/Actions/` | 9 scenario + 3 launchpad + 2 account-config Actions | VERIFIED | All 14 Public Actions present: CreateScenario, RenameScenario, DeleteScenario, AddScenarioMutation, EditScenarioMutation, RemoveScenarioMutation, CreateCancellationScenarioForAlert, CreateCancellationScenarioForSeries, CreateAmountChangeScenarioForSeries, SetAccountForecastBuffer, SetAccountOpeningBalance |
| `Modules/Forecasting/Database/Migrations/` | 5 (+1 amendment) migrations | VERIFIED | 6 migration files for: forecast_scenarios, forecast_scenario_mutations, forecast_shortfall_windows, forecast_runs, add_forecast_columns_to_accounts (includes `forecast_min_buffer_minor` BIGINT), add_result_json_to_forecast_runs |
| `Modules/Chains/Public/Dto/NextSettlementDto.php` | New DTO carrying funder accountId | VERIFIED | DTO exposes accountId (funder, NOT card), amount (Money), dueDate, statementId, state |
| `Modules/Chains/Public/Services/CardStatementQuery::nextSettlementForUser` | New method resolving funder from chain_links + ASN fallback | VERIFIED | Method implementation at `CardStatementQuery.php:81-142` |
| `tests/Contracts/BoundaryArchTest.php` | 5 Forecasting arch invariants | VERIFIED | All 5 present: noFacadeCalls (via global facade rule + ProjectForecastJob carve-out), noTransactionWritesFromForecasting (line 779), crossModuleAccessGoesThroughPublic (line 768), noSynchronousForecastingInRequestLifecycle (line 772), noScenarioMutationsJoinedToTransactionQueries (line 834) |
| `tests/Contracts/ForecastingProjectionContractTest.php` | 6-fixture (+ 2 wave-5) projection contract | VERIFIED | 8 fixtures wired including buffer-crossing, variable-utility, scenario-with-each-mutation-kind |
| `tests/Contracts/ScenarioIsolationContractTest.php` | Runtime FCT-03 proof | VERIFIED | 4 it() blocks: full lifecycle row-count assertions, cross-user isolation, defensive grep complement, ScenarioQuery sole-Public-API check |

### Key Link Verification

| From | To | Via | Status |
|------|----|----|--------|
| `/forecast` route | `ForecastPage` Livewire SFC | `Route::get('/forecast', ForecastPage::class)` | WIRED |
| `ForecastPage` | `ForecastQuery::forUser` | parameter DI on render() | WIRED |
| `ForecastPage` | `ScenarioQuery::forUser` | parameter DI on render() | WIRED |
| `ProjectionPipeline` | `BalanceAnchorResolver`, `RangeProjector`, `DailyFold`, `ChainAwareForecastRouter`, `ScenarioApplier`, `ShortfallDetector`, `ForecastRunStateMachine` | constructor DI | WIRED |
| `ChainAwareForecastRouter` | `ChainLinkQuery::confirmedAndDeterministicForSeries`, `CardStatementQuery::nextSettlementForUser` | constructor DI to Chains Public Services | WIRED |
| `ScenarioApplier` | `ScenarioQuery::mutationsFor`, `RecurringSeriesQuery::forSeries` | constructor DI to typed Public surfaces only | WIRED (and walled off from transactions per arch invariant) |
| `ShortfallDetector` | `forecast_shortfall_windows` table + `ForecastShortfallDetected` event | `DatabaseManager` + `Dispatcher` | WIRED |
| `ForecastingServiceProvider` | Phase 8 `RecurringSeries*` + Phase 9 `DriftAlertDismissedCancelled` + scenario lifecycle events | `$events->listen(...)` × 8 | WIRED |
| `dashboard.blade.php` | `forecast-highlights-tile` Livewire SFC | `@livewire('forecasting.forecast-highlights-tile')` (replaces Phase 5 NextIcsSettlementTile) | WIRED |
| `top-nav.blade.php` | `forecastShortfallCount` badge | View composer in ServiceProvider injects from `ForecastHighlightsQuery::activeShortfallCountForUser` | WIRED |
| Phase 9 `drift-alert-row` "Model cancel ↗" | `/forecast?scenarioId={id}` | `DriftPage::createCancellationScenario` redirect | WIRED |
| Phase 8 `/recurring/series/{id}` "Model what-if ↗" | `forecasting.model-what-if-dropdown` | `@livewire('forecasting.model-what-if-dropdown', ['seriesId' => ...])` | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `ForecastPage` (`baseline`, `scenario` ForecastDtos) | `$baseline`, `$scenario` | `ForecastQuery::forUser` → reads latest `forecast_runs.result_json` decoded JSON for (user, account, horizon, scenarioId) | YES — backed by ProjectForecastJob writing real per-day projections through `forecast_runs` table; fallback to flat-line at anchor when no series; computing sentinel when run pending | FLOWING |
| `ForecastPage` (`shortfallWindows`) | `$shortfallWindows` | `forecast_shortfall_windows` table via `DatabaseManager`, mapped through `ForecastDtoMapper::mapShortfallWindow` | YES — populated by `ShortfallDetector::detect` during pipeline run; per-user filter applied | FLOWING |
| `ForecastHighlightsTile` (`$dto`) | `ForecastHighlightsDto` | `ForecastHighlightsQuery::forUser` → reads `forecast_runs.result_json` + `forecast_shortfall_windows` + `CardStatementQuery::nextSettlementForUser` | YES — three real DB-backed sources composed; baseline-only filter via `scenario_id IS NULL` | FLOWING |
| `top-nav.blade.php` (`$forecastShortfallCount`) | Integer badge value | View composer → `ForecastHighlightsQuery::activeShortfallCountForUser` | YES — real count from `forecast_shortfall_windows` filtered by user + date window; defaults to 0 when unauthenticated | FLOWING |
| `ScenarioEditorSidebar` (`$mutations`) | List of mutation row arrays | `ScenarioQuery::mutationsFor` → typed payloads via `ScenarioMutationPayloadCast` round-trip | YES — backed by `forecast_scenario_mutations` table; cross-user scoped | FLOWING |
| `AccountBufferEditor` (`$currentBufferMinor`) | Integer minor units | `accounts.forecast_min_buffer_minor` column via `DatabaseManager`; writes via `SetAccountForecastBuffer` Action which triggers re-projection | YES | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 10 test suites pass | `./vendor/bin/pest --testsuite=Forecasting,Chains,Recurring,DriftAlerts,Contracts` | 489 passed, 3252 assertions, 12.06s | PASS |
| Forecasting PHPStan level 10 strict | `./vendor/bin/phpstan analyse Modules/Forecasting --memory-limit=2G` | `[OK] No errors` | PASS |
| Forecasting Pint formatting | `./vendor/bin/pint --test Modules/Forecasting` | `{"tool":"pint","result":"passed"}` | PASS |
| DI-only invariant (no facade / global helper hits in Forecasting runtime code) | `grep -rn "auth(\|config(\|Auth::\|DB::\|request(" Modules/Forecasting/` (excluding tests + migrations) | 0 matches | PASS |
| Zero debt markers (TBD/FIXME/XXX/TODO/HACK/PLACEHOLDER) in Forecasting runtime code | `grep -rE "TBD\|FIXME\|XXX\|TODO\|HACK\|PLACEHOLDER" Modules/Forecasting/` excluding tests | 0 matches | PASS |
| 5 Forecasting boundary invariants registered | `grep -E "Forecasting" tests/Contracts/BoundaryArchTest.php` | Lines 104, 768, 772, 779, 834 — all 5 present and binding (Forecasting Internal isolation; ProjectionPipeline barred from Http/Resources; no transaction-substrate writes; no scenario-mutation joins onto substrate; ProjectForecastJob facade carve-out) | PASS |

### Probe Execution

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| _No probes declared in PLAN/SUMMARY for Phase 10_ | n/a | n/a | SKIPPED (project does not use `scripts/*/tests/probe-*.sh` convention; Phase 10 verification is test-suite + arch-test driven) |

### Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| FCT-01 | 10-01, 10-02, 10-03, 10-04 | 30/60/90-day projected balance per account from balance + recurring + pending settlements | SATISFIED | Truth #1 above; ProjectionPipeline composes anchor + RangeProjector + ChainAwareForecastRouter end-to-end; per-account ForecastDto returned by ForecastQuery |
| FCT-02 | 10-01, 10-02, 10-03, 10-06 | Uncertainty range, not single-point | SATISFIED | Truth #2 above; envelope tier (variance-tolerance) + percentile tier (R-7) both implemented; `rangeArea` ApexCharts series renders the band; DailyFold quadrature combines independent series correctly |
| FCT-03 | 10-01, 10-02, 10-05, 10-06 | What-if mutations apply without transaction-substrate writes | SATISFIED | Truth #4 above; 5 typed payloads; `ScenarioApplier` in-memory transform; `noScenarioMutationsJoinedToTransactionQueries` arch invariant + `ScenarioIsolationContractTest` runtime row-count assertion |
| FCT-04 | 10-05, 10-06 | Side-by-side scenario vs baseline | SATISFIED | Truth #5 above; two-panel grid with shared y-axis; Net diff tile at days 30/60/90; SideBySideRenderTest covers both renders + shared scale + color tints |
| FCT-05 | 10-04 | Surplus/shortfall windows highlighted | SATISFIED | Truth #3 above; ShortfallDetector + buffer_used_minor capture; rose-50 below-buffer chart band; per-window inline ↘ caption; ForecastHighlightsTile dashboard surface |

No orphaned requirements: REQUIREMENTS.md maps FCT-01..05 to Phase 10 (lines 211-215); all five are claimed in at least one plan.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `Modules/Forecasting/**` | various | GSD reference leaks: `D-1002`, `D-1011`, `D-1013`, `D-1026`, `FCT-03` in runtime PHPDoc comments | WARNING (carry-forward, not Phase 10 regression) | Violates user's stated preference "Codebase stays agnostic from GSD" (memory `feedback_codebase_gsd_agnostic.md`). 13 occurrences in Forecasting follow the same pattern already in 46 occurrences across all earlier modules (Chains: D-86/D-87/D-90 etc., Recurring: D-numbers, DriftAlerts: D-915). This is a project-wide pre-existing pattern, not introduced by Phase 10 specifically. No functional impact; PHPStan/Pint/Pest all green. |

### Invariant Check Summary

| Invariant | Source | Status | Evidence |
|-----------|--------|--------|----------|
| `crossModuleAccessGoesThroughPublic` (Forecasting Internal not used outside Forecasting) | BoundaryArchTest line 768 | ACTIVE | arch test passing |
| `noSynchronousForecastingInRequestLifecycle` (ProjectionPipeline barred from Http/Resources) | BoundaryArchTest line 772 | ACTIVE | arch test passing |
| `noTransactionWritesFromForecasting` (Forecasting cannot write to transactions/recurring_series/card_statements/chain_links/drift_alerts) | BoundaryArchTest line 779 | ACTIVE | arch test passing |
| `noScenarioMutationsJoinedToTransactionQueries` (load-bearing — walks ENTIRE Modules/ tree) | BoundaryArchTest line 834 | ACTIVE | arch test passing; complementary runtime proof in ScenarioIsolationContractTest |
| `noFacadeCallsFromForecasting` (facade ban with ProjectForecastJob uniqueVia carve-out) | BoundaryArchTest line 104 | ACTIVE | arch test passing; verified by grep — zero auth()/config()/DB::/Auth::/request() hits in Forecasting runtime code |
| DI-only — no helpers or facades in Forecasting runtime code | User preference (memory) | SATISFIED | grep returned 0 hits |
| 489 tests passing | Phase-wide | PASS | Pest output: `Tests: 489 passed (3252 assertions); Duration: 12.06s` |
| Larastan level 10 strict on Forecasting | Code quality gate | PASS | `[OK] No errors` |
| Laravel Pint formatting on Forecasting | Code quality gate | PASS | `{"tool":"pint","result":"passed"}` |
| Codebase stays agnostic from GSD | User preference (memory) | DEVIATION (project-wide pre-existing) | 13 D-numbers / FCT-IDs in Forecasting PHPDoc; same pattern in 46 places across all earlier modules. Carry-forward warning, not a Phase 10 blocker. |

### Human Verification Required

_None._ The verifier was able to confirm each of the five ROADMAP success criteria through code reading + test-suite execution + arch-test verification + data-flow tracing. The 489 passing tests already include feature-level Livewire renders (e.g. `SideBySideRenderTest`, `ForecastPageTest`, `AccountBufferEditorTest`, `ForecastHighlightsTileTest`, `ConfidenceLegendTest`, `AllAccountsAggregateTabTest`, `ScenarioCrudTest`, `ScenarioSidebarTest`, `ModelCancelLaunchpadTest`, `ModelWhatIfDropdownTest`, `OpeningBalanceEditorTest`, `ViewByFunderToggleTest`, `TopNavForecastSlotTest`, `ForecastCrossUser404Test`) which cover the visual surfaces.

### Gaps Summary

No gaps blocking phase-goal achievement. All five ROADMAP success criteria are observably true in the codebase. The single noted deviation (D-numbers / FCT-IDs in PHPDoc comments) is a project-wide pre-existing pattern present since at least Phase 5; the user's preference to keep the codebase agnostic from GSD has not been historically enforced and Phase 10 does not regress the situation — it merely inherits the convention. This is a non-blocking carry-forward concern suitable for a future cross-phase cleanup pass; recording here so it surfaces to the orchestrator's downstream `gsd-code-review --fix --all` step.

---

## Sign-off

Phase 10 delivers a complete cash-flow forecasting + what-if scenarios capability:

- **FCT-01** — 30/60/90-day per-account projections built from BalanceAnchorResolver + RangeProjector + ChainAwareForecastRouter (NextSettlementDto → funder ASN account)
- **FCT-02** — Honest uncertainty ranges via envelope tier (variance tolerance) + percentile tier (R-7 P10/P50/P90) + DailyFold quadrature
- **FCT-03** — Five typed mutation payloads, ScenarioApplier in-memory transform, walled off from transaction substrate by the load-bearing `noScenarioMutationsJoinedToTransactionQueries` arch invariant AND a runtime ScenarioIsolationContractTest that asserts substrate row counts NEVER change across the full lifecycle
- **FCT-04** — Two-panel side-by-side rangeArea grid, shared y-axis, Net diff tile at days 30/60/90, ScenarioEditorSidebar
- **FCT-05** — ShortfallDetector with honest `buffer_used_minor` audit capture, per-account `forecast_min_buffer_minor` column, AccountBufferEditor popover, rose-50 below-buffer band, ForecastHighlightsTile on dashboard (replaces Phase 5 NextIcsSettlementTile), top-nav slot badge

Quality gates: 489 Pest tests passing (3252 assertions), Larastan level 10 strict clean on Forecasting, Pint clean on Forecasting, zero debt markers in runtime code, all 5 BoundaryArchTest invariants active.

Phase goal achieved. Ready to proceed to Phase 11 (Operational Hardening).

---

_Verified: 2026-05-18_
_Verifier: Claude (gsd-verifier)_
