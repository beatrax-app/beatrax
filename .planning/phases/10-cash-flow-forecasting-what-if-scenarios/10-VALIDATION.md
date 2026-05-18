---
phase: 10
slug: cash-flow-forecasting-what-if-scenarios
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-05-18
---

# Phase 10 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (built on PHPUnit 11) [VERIFIED: composer.json] |
| **Config file** | `phpunit.xml` (root) + `tests/Pest.php` + per-module `Modules/Forecasting/tests/Pest.php` (added by Plan 10-01 Task 1) |
| **Quick run command** | `./vendor/bin/pest --filter=Forecasting --parallel` |
| **Full suite command** | `./vendor/bin/pest --parallel` (project `composer test` script) |
| **Estimated runtime** | ~10 seconds quick / ~80 seconds full (Phase 1-10) |

---

## Sampling Rate

- **After every task commit:** Run `./vendor/bin/pest --filter=Forecasting --parallel` (Wave 0-2 tests + arch + contract).
- **After every plan wave:** Run `./vendor/bin/pest --parallel` (full suite green).
- **Before `/gsd:verify-work`:** Full suite + `vendor/bin/phpstan analyse --level=max` + `vendor/bin/pint --test` all green.
- **Max feedback latency:** 10 seconds for `--filter=Forecasting --parallel`.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 10-01-01 | 01 | 0 | FCT-01..05 | T-10-01-01 / T-10-01-05 | Module skeleton + DI singleton bindings + listener wiring without facade leaks | unit | `composer dump-autoload && vendor/bin/pest --filter="Forecasting" --list-tests` | ❌ W0 creates | ⬜ pending |
| 10-01-02 | 01 | 0 | FCT-03 | T-10-01-02 / T-10-01-03 / T-10-01-04 | Five BoundaryArchTest invariants — `noScenarioMutationsJoinedToTransactionQueries` is load-bearing | arch | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` | ❌ W0 creates | ⬜ pending |
| 10-01-03 | 01 | 0 | FCT-01..05 | — | 10-scenario fixture corpus shape validation | unit | `vendor/bin/pest Modules/Forecasting/tests/Unit/FixtureCorpusTest.php` | ❌ W0 creates | ⬜ pending |
| 10-02-01 | 02 | 1 | FCT-01, FCT-02, FCT-05 | T-10-02-06, T-10-02-07 | Five migrations apply + roll back cleanly + accounts gains three nullable columns | unit | `vendor/bin/pest Modules/Forecasting/tests/Unit/MigrationsTest.php` | ❌ W1 creates | ⬜ pending |
| 10-02-02 | 02 | 1 | FCT-03 | T-10-02-01, T-10-02-02, T-10-02-03, T-10-02-05 | Typed JSON cast routes per kind; Larastan level 10 catches cross-kind property access | unit | `vendor/bin/pest Modules/Forecasting/tests/Unit/ScenarioMutationPayloadCastTest.php && vendor/bin/phpstan analyse Modules/Forecasting/Models Modules/Forecasting/Internal/Casts --level=max` | ❌ W1 creates | ⬜ pending |
| 10-02-03 | 02 | 1 | FCT-01..05 | T-10-02-04 | ForecastRunStateMachine sole-mutator enforces lifecycle transitions | unit | `vendor/bin/pest Modules/Forecasting/tests/Unit/ForecastRunStateMachineTest.php` | ❌ W1 creates | ⬜ pending |
| 10-03-01 | 03 | 2 | FCT-01, FCT-02 | T-10-03-05, T-10-03-07, T-10-03-08 | Pipeline trio: anchor + envelope projector + quadrature daily fold; load-bearing `sqrt(2)*10 = 14` math test | unit | `vendor/bin/pest Modules/Forecasting/tests/Unit/{BalanceAnchorResolverTest,RangeProjectorTest,DailyFoldTest}.php` | ❌ W2 creates | ⬜ pending |
| 10-03-02 | 03 | 2 | FCT-01 | T-10-03-01, T-10-03-02 | ShouldBeUniqueUntilProcessing with `baseline` sentinel; listener fan-out 3 dispatches/event | unit | `vendor/bin/pest Modules/Forecasting/tests/Unit/{ProjectForecastJobUniqueTest,EvaluateForecastListenerTest}.php` | ❌ W2 creates | ⬜ pending |
| 10-03-03 | 03 | 2 | FCT-01, FCT-02 | T-10-03-03, T-10-03-06 | ForecastQuery cross-user 404 + ForecastPage skeleton + rangeArea chart + 6 Wave 0 fixtures end-to-end | contract + feature | `vendor/bin/pest tests/Contracts/ForecastingProjectionContractTest.php Modules/Forecasting/tests/Feature/{ForecastPageTest,ForecastCrossUser404Test}.php` | ❌ W2 creates | ⬜ pending |
| 10-04-01 | 04 | 3 | FCT-01, FCT-05 | T-10-04-03 | ChainAwareForecastRouter + ShortfallDetector with honest buffer_used_minor audit + ICS settlement synthesis | unit + contract | `vendor/bin/pest Modules/Forecasting/tests/Unit/{ChainAwareForecastRouterTest,ShortfallDetectorTest}.php tests/Contracts/ForecastingProjectionContractTest.php` | ❌ W3 creates | ⬜ pending |
| 10-04-02 | 04 | 3 | FCT-05 | T-10-04-01, T-10-04-02, T-10-04-05 | SetAccountForecastBuffer cross-user 404 + validation + AccountBufferEditor popover with UI-SPEC copy verbatim | feature | `vendor/bin/pest Modules/Forecasting/tests/Feature/AccountBufferEditorTest.php` | ❌ W3 creates | ⬜ pending |
| 10-04-03 | 04 | 3 | FCT-05 | T-10-04-04, T-10-04-06, T-10-04-07 | Dashboard tile replacement + top-nav slot + Phase 5 NextIcsSettlementTileTest assertions ported | feature | `vendor/bin/pest Modules/Forecasting/tests/Feature/{ForecastHighlightsTileTest,TopNavForecastSlotTest}.php Modules/Chains/tests --parallel` | ❌ W3 creates | ⬜ pending |
| 10-05-01 | 05 | 4 | FCT-03 | T-10-05-01..05 | Scenario CRUD Public Actions + ScenarioApplier in-memory transform + 18 cases in ScenarioCrudTest | feature | `vendor/bin/pest Modules/Forecasting/tests/Feature/ScenarioCrudTest.php tests/Contracts/BoundaryArchTest.php` | ❌ W4 creates | ⬜ pending |
| 10-05-02 | 05 | 4 | FCT-04 | T-10-05-02, T-10-05-08, T-10-05-09 | Side-by-side panel rendering + shared y-axis + Net diff tile + ScenarioEditorSidebar + wire:poll.2s overlay | feature | `vendor/bin/pest Modules/Forecasting/tests/Feature/{SideBySideRenderTest,ScenarioSidebarTest}.php` | ❌ W4 creates | ⬜ pending |
| 10-05-03 | 05 | 4 | FCT-04 | T-10-05-06 | Phase 9 drift `Model cancel ↗` + Phase 8 `Model what-if ↗` launchpads with atomic Create+AddMutation | feature | `vendor/bin/pest Modules/Forecasting/tests/Feature/{ModelCancelLaunchpadTest,ModelWhatIfDropdownTest}.php` | ❌ W4 creates | ⬜ pending |
| 10-06-01 | 06 | 5 | FCT-02 | T-10-06-01..03, T-10-06-07 | Percentile R-7 + CadenceJitter + RangeProjector percentile tier + viewByFunder collapse + full 10-fixture contract | unit + feature + contract | `vendor/bin/pest Modules/Forecasting/tests/Unit/{PercentileTest,CadenceJitterTest}.php Modules/Forecasting/tests/Feature/ViewByFunderToggleTest.php tests/Contracts/ForecastingProjectionContractTest.php` | ❌ W5 creates | ⬜ pending |
| 10-06-02 | 06 | 5 | FCT-01, FCT-02 | T-10-06-04, T-10-06-05, T-10-06-06 | SetAccountOpeningBalance + soft-warning + OpeningBalanceEditor + confidence legend + All accounts aggregate `line` chart | feature | `vendor/bin/pest Modules/Forecasting/tests/Feature/{OpeningBalanceEditorTest,ConfidenceLegendTest,AllAccountsAggregateTabTest}.php` | ❌ W5 creates | ⬜ pending |
| 10-06-03 | 06 | 5 | FCT-03 | T-10-06-08 | ScenarioIsolationContractTest — runtime end-to-end proof of FCT-03; complements the structural arch test | contract | `vendor/bin/pest tests/Contracts/ScenarioIsolationContractTest.php tests/Contracts/BoundaryArchTest.php` | ❌ W5 creates | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `Modules/Forecasting/composer.json`, `Providers/ForecastingServiceProvider.php`, `Routes/web.php`, `tests/Pest.php`, `tests/TestCase.php` — module skeleton (Plan 10-01 Task 1)
- [ ] `Modules/Forecasting/Internal/Listeners/ProjectForecastOnRecurringChange.php` + `ProjectForecastOnDriftDismissed.php` + `ProjectForecastOnScenarioChange.php` — listener scaffolds (Plan 10-01 Task 1; Wave 0 body throws RuntimeException; Waves 2-4 swap in real bodies)
- [ ] Five BoundaryArchTest invariants appended to `tests/Contracts/BoundaryArchTest.php` (Plan 10-01 Task 2): `noFacadeCallsFromForecasting` carve-out extension, `noTransactionWritesFromForecasting`, `crossModuleAccessGoesThroughPublic`, `noSynchronousForecastingInRequestLifecycle`, **`noScenarioMutationsJoinedToTransactionQueries`** (load-bearing FCT-03 invariant)
- [ ] `Modules/Forecasting/tests/fixtures/forecast-corpus/` — 10-scenario synthesised fixture corpus per CONTEXT.md `<domain>` "Synthesised fixture-first Wave 0" bullet (Plan 10-01 Task 3)
- [ ] `Modules/Forecasting/tests/Unit/FixtureCorpusTest.php` — corpus loader test (Plan 10-01 Task 3)
- [ ] PSR-4 wire-up: `composer.json` autoload-dev + `phpunit.xml` testsuite + `tests/Pest.php` per-module map row (Plan 10-01 Task 1)
- [ ] `Modules/Forecasting/Public/{Contracts,Dto,Events,Actions,Services}/.gitkeep` + `Modules/Forecasting/Internal/{Pipeline,Jobs,Http/Livewire}/.gitkeep` + `Modules/Forecasting/Resources/views/livewire/.gitkeep` — empty namespace markers (Plan 10-01 Task 1)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| ApexCharts `rangeArea` band + center line render visually correctly with translucent fill | FCT-02 | Visual aesthetic; chart library renders client-side; Pest covers the data shape but not the pixel rendering | Open `/forecast?account={asn_id}&horizon=30` in browser; verify band has fill opacity ~0.2, center line is solid 2.5px slate-900, tooltip on hover reads `€X – €Y (≈ €Z) on d M` |
| Two-panel side-by-side renders with shared y-axis on wide viewport AND stacks on narrow viewport | FCT-04 | Responsive layout; Tailwind breakpoint behavior verified visually | Open `/forecast?scenarioId={id}` on desktop (≥1024px) and on mobile (≤768px); confirm side-by-side on desktop, stacked on mobile |
| Soft-warning banner on opening-balance divergence renders the two-chip choice | FCT-01 / D-1029 | UX aesthetic; Pest can assert HTML class but the visual flow is verified manually | On `/settings#forecast-buffers`, enter an opening balance €1000 off the computed sum; verify amber banner appears with `[Use diederik's number]` and `[Use my number]` chips; click each to verify behavior |
| Top-nav rose-50 ↘ pill renders only when active shortfall exists | UI-SPEC § Navigation Decision | Visual surface; Pest covers via HTML grep but the visual cue is verified manually | With zero shortfalls: confirm bare Forecast slot; trigger a shortfall (lower a buffer below current balance + re-project); confirm rose-50 pill appears |
| Phase 9 `Model cancel ↗` chip + Phase 8 `Model what-if ↗` link redirect to the new scenario on /forecast | UI-SPEC § /forecast page interaction labels | End-to-end UI flow; Pest tests the actions but the manual click-through verifies the redirect lands on the right URL with the scenario pre-selected | Visit `/drift`; click `Model cancel ↗`; verify URL becomes `/forecast?scenarioId={n}` with the scenario sidebar showing one `Cancel ...` mutation card |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify
- [x] Sampling continuity: no 3 consecutive tasks without automated verify (every plan has 3 tasks, all with `<automated>`)
- [x] Wave 0 covers all MISSING references (Plan 10-01 lands the module skeleton + arch tests + fixtures before Waves 1+ depend on them)
- [x] No watch-mode flags
- [x] Feedback latency < 10s (per-task `--filter=Forecasting --parallel`)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending — gsd-plan-checker
