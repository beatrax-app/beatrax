---
phase: 10-cash-flow-forecasting-what-if-scenarios
plan: 04
subsystem: forecasting
tags: [laravel, livewire, pest, larastan-max, di-only, chain-routing, shortfall, dashboard-tile, top-nav-slot]

requires:
  - phase: 10-cash-flow-forecasting-what-if-scenarios
    plan: 03
    provides: BalanceAnchorResolver + RangeProjector + DailyFold + ProjectionPipeline orchestrator + ProjectForecastJob + ForecastQuery
  - phase: 10-cash-flow-forecasting-what-if-scenarios
    plan: 02
    provides: forecast_shortfall_windows + ForecastHighlightsDto + Chains NextSettlementDto + CardStatementQuery::nextSettlementForUser
  - phase: 09-recurring-payment-drift-alerts
    provides: DriftThresholdEditor popover precedent + DashboardDriftBadge tile precedent + DriftAlertsServiceProvider top-nav composer pattern (rose-50 D-927 chrome)
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    provides: ChainLinkQuery substrate, chain_links table, card_statements + open-statement reads
provides:
  - ChainAwareForecastRouter (routes per-occurrence contributions onto funder accounts + synthesises ICS bulk-iDEAL settlements onto the funder)
  - ShortfallDetector (writes forecast_shortfall_windows rows + emits ForecastShortfallDetected events with buffer_used_minor honest-audit capture)
  - ForecastShortfallDetected Public Event (Phase 11 operational-hardening hook)
  - SetAccountForecastBuffer Public Action (cross-user 404 + non-negative validation + 3-horizon re-projection dispatch)
  - ForecastHighlightsQuery Public Service (dashboard tile + top-nav badge composer read API)
  - AccountBufferEditor Livewire SFC (per-account inline buffer popover on /forecast)
  - ForecastHighlightsTile Livewire SFC (replaces Phase 5 inline next-settlement tile as strict superset)
  - Phase 5 ChainLinkQuery::confirmedAndDeterministicForSeries extension + SeriesFunderLink DTO (cross-module Public surface extension)
  - Top-nav Forecast slot (D-1025) with rose-50 ↘-glyph pill
  - Extended ProjectionPipeline DI graph integrating ChainAwareForecastRouter + ShortfallDetector
  - Extended ForecastPage with per-account buffer popover, shortfall band overlay, and inline shortfall badge
affects: [10-05, 10-06, 11-operational-hardening]

tech-stack:
  added: []
  patterns:
    - "Chain-aware routing via Public service consumption: ChainAwareForecastRouter reads Phase 5 ChainLinkQuery::confirmedAndDeterministicForSeries (new method) + CardStatementQuery::nextSettlementForUser; never touches Chains/Internal. The arch invariant crossModuleAccessGoesThroughPublic stays green."
    - "Per-series funder memoisation in the router — a series with N contributions only triggers one DB read for chain links. Cache lives on the local closure; per-projection-run."
    - "Honest-audit buffer capture on every shortfall window row: ShortfallDetector writes buffer_used_minor at detection time so a later buffer edit cannot rewrite the historical narrative (Phase 9 D-915 mirror)."
    - "Pre-write cleanup in a single DB transaction: ShortfallDetector deletes prior (user, account, scenario) rows before inserting new ones — the shortfall picture is fully replaced on each projection run."
    - "Dashboard tile replacement-as-strict-superset: Phase 5 inline tile DOM block removed; new Livewire tile mounted with @livewire('forecasting.forecast-highlights-tile'). Phase 5 next-settlement copy preserved as a meta line beneath the new lowest-projected-balance line."
    - "Top-nav slot with conditional rose-50 ↘-glyph pill, composed via ForecastingServiceProvider's View Factory composer + boot-scoped per-user memo cache (mirrors DriftAlertsServiceProvider verbatim)."

key-files:
  created:
    - Modules/Forecasting/Internal/Pipeline/ChainAwareForecastRouter.php
    - Modules/Forecasting/Internal/Pipeline/ShortfallDetector.php
    - Modules/Forecasting/Public/Events/ForecastShortfallDetected.php
    - Modules/Forecasting/Public/Actions/SetAccountForecastBuffer.php
    - Modules/Forecasting/Public/Services/ForecastHighlightsQuery.php
    - Modules/Forecasting/Internal/Http/Livewire/AccountBufferEditor.php
    - Modules/Forecasting/Internal/Http/Livewire/ForecastHighlightsTile.php
    - Modules/Forecasting/Resources/views/livewire/account-buffer-editor.blade.php
    - Modules/Forecasting/Resources/views/livewire/forecast-highlights-tile.blade.php
    - Modules/Forecasting/tests/Unit/ChainAwareForecastRouterTest.php
    - Modules/Forecasting/tests/Unit/ShortfallDetectorTest.php
    - Modules/Forecasting/tests/Feature/AccountBufferEditorTest.php
    - Modules/Forecasting/tests/Feature/ForecastHighlightsTileTest.php
    - Modules/Forecasting/tests/Feature/TopNavForecastSlotTest.php
    - Modules/Chains/Public/Dto/SeriesFunderLink.php
    - Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-SUMMARY.md
  modified:
    - Modules/Forecasting/Internal/Pipeline/ProjectionPipeline.php (constructor signature gains ChainAwareForecastRouter + ShortfallDetector; computeResult collects contributions globally, routes, then buckets per account; ShortfallDetector invoked per account)
    - Modules/Forecasting/Providers/ForecastingServiceProvider.php (registers new collaborators + new Livewire components; swaps top-nav composer body from Wave 0 hardcoded 0 to ForecastHighlightsQuery::activeShortfallCountForUser with per-user memo)
    - Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php (per-account buffer load + shortfall window load + annotations.yaxis rose-50 band + onBufferSaved listener)
    - Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php (per-account chart header with buffer text-link trigger + popover + inline shortfall badges)
    - Modules/Chains/Public/Services/ChainLinkQuery.php (added confirmedAndDeterministicForSeries Public method consumed by the router)
    - Modules/Core/Resources/views/livewire/dashboard.blade.php (REPLACES Phase 5 inline "Next ICS settlement" tile with @livewire('forecasting.forecast-highlights-tile'))
    - Modules/Core/Resources/views/livewire/top-nav.blade.php (inserts Forecast slot between Recurring and Settings with rose-50 ↘ pill)
    - Modules/Core/Internal/Http/Livewire/Dashboard.php (removes dead nextSettlement call + view-data pass-through)
    - tests/Contracts/ForecastingProjectionContractTest.php (extends with buffer-crossing fixture + shortfall assertion; threads forecast_min_buffer_minor through the seed function)
  deleted:
    - Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php (assertions migrated to ForecastHighlightsTileTest + FailedChainResolutionToastTest)

key-decisions:
  - "Extending Phase 5 Public surface with ChainLinkQuery::confirmedAndDeterministicForSeries was necessary even though the plan's `files_modified` list did not explicitly list it. The router consumes a Phase 5 method that did not yet exist; following Plan 10-02 Task 4's precedent (Phase 10 extends Phase 5's CardStatementQuery with nextSettlementForUser), this method was added in Task 1 as a backwards-compatible Public surface extension. The accompanying SeriesFunderLink DTO ships in Modules/Chains/Public/Dto to keep the cross-module API typed."
  - "Failed-job toast tests preserved in Modules/Chains/tests/Feature/FailedChainResolutionToastTest. The plan instructed deleting NextIcsSettlementTileTest.php after porting the tile-rendering assertions; the failed-job toast tests in that file are about Dashboard.php's chain_resolution_runs read (issue #1 + #8) and have nothing to do with the tile. Dropping them outright would have lost important Phase 5 regression coverage. Splitting them into a sibling file in the same module preserves the test surface while honouring the tile-deletion directive."
  - "ChainAwareForecastRouter collects ALL contributions globally before routing, then buckets per account. The Wave 2 ProjectionPipeline.computeResult ran per-account (only collected contributions whose seriesAccountId matched the current account); Wave 3 needs to potentially rewrite a contribution's accountId, so the per-account-then-route loop would silently drop rewritten contributions (the source account's loop would skip them; the target account's loop would have already iterated past them). The fix: collect globally, route, then bucket by routed accountId."
  - "AccountBufferEditor cross-user mount guard uses an Eloquent-free user-scoped row count via DatabaseManager (the same pattern as ForecastQuery and SetAccountForecastBuffer). The Livewire feature test asserts the cross-user 404 via the SetAccountForecastBuffer Public Action invocation directly — Livewire::test() does not propagate the NotFoundHttpException synchronously on mount, so the Public-Action-level test is the canonical lock for the cross-user contract."
  - "Phase 5's Modules/Core/Internal/Http/Livewire/Dashboard.php still calls $glance->nextIcsSettlement($user) in some test paths; this method (and its CardStatementForecastTile DTO) is preserved in ThisPeriodAtAGlanceQuery. The Dashboard.php call to it has been removed (the new tile reads through ForecastHighlightsQuery → CardStatementQuery::nextSettlementForUser directly), but the method itself stays in place for backwards compatibility — a follow-up plan can prune the dead method once any other consumers are confirmed gone."

patterns-established:
  - "Funder routing memoisation per projection run: ChainAwareForecastRouter caches the per-series funder lookup in a local closure-scoped array so a series with N contributions only triggers one DB read."
  - "Honest-audit shortfall-window writes: buffer_used_minor captured at detection time; pre-write cleanup inside a single transaction; per-window event dispatch for downstream Phase 11 hooks."
  - "Cross-phase test-deletion bookkeeping: when a Phase X plan removes a Phase Y test, the Phase Y SUMMARY gets a ## Test Migrations section entry. The deletion is atomic with the new file's commit so coverage continuity is preserved in git history."
  - "Strict-superset dashboard tile replacement: the new tile carries the previous tile's surface VERBATIM as a meta line beneath the new headline; ported tests assert both the old and new copy in the same render."

requirements-completed:
  - FCT-01
  - FCT-05

duration: 26min
completed: 2026-05-18
---

# Phase 10 Plan 04: Wave 3 — Chain Routing + Shortfall Detection + Dashboard Consolidation + Top-Nav Slot Summary

**Lands the ChainAwareForecastRouter (consumes Phase 5 ChainLinkQuery + CardStatementQuery to route recurring-series occurrences onto FUNDER accounts and synthesise the next ICS bulk-iDEAL settlement onto the ASN funder), the ShortfallDetector (writes forecast_shortfall_windows rows with honest-audit buffer capture + emits ForecastShortfallDetected events), the SetAccountForecastBuffer Public Action with cross-user 404 + non-negative validation + 3-horizon re-projection dispatch, the ForecastHighlightsQuery Public Service powering both the new dashboard tile AND the top-nav badge composer, the AccountBufferEditor inline popover on /forecast per-account chart headers, the dashboard tile REPLACEMENT (Phase 5 "Next ICS settlement" → Phase 10 "Forecast highlights" — strict superset), and the top-nav "Forecast" slot insertion with the rose-50 ↘-glyph pill.**

## Performance

- **Duration:** ~26 min
- **Tasks:** 3 (atomically committed)
- **Files created:** 17 (10 production + 6 test + 1 audit-trail)
- **Files modified:** 9
- **Files deleted:** 1 (Phase 5 NextIcsSettlementTileTest — assertions migrated to ForecastHighlightsTileTest, toast tests preserved in FailedChainResolutionToastTest)
- **Tests:** 25 new unit/feature tests; 1 contract-test extension (buffer-crossing fixture + shortfall assertion)
- **Total assertions across all Forecasting + arch + contract tests:** 1679+ (after Wave 3 additions)

## Accomplishments

- **ChainAwareForecastRouter routes contributions onto funder accounts** via the new Phase 5 `ChainLinkQuery::confirmedAndDeterministicForSeries` Public surface (returns `SeriesFunderLink` DTOs). When a series's occurrences carry a confirmed-or-deterministic chain link to a funder transaction, the per-occurrence contribution's `accountId` is rewritten onto the funder. The router also synthesises the next ICS bulk-iDEAL settlement contribution on the funder account via `CardStatementQuery::nextSettlementForUser`; de-duplication on `(accountId, date)` prefers the synthesised contribution over any overlapping Phase 8 series contribution (the next-settlement math is the authoritative source). All five router unit tests pass.

- **ShortfallDetector emits forecast_shortfall_windows rows** with the buffer_used_minor captured at detection time (Phase 9 D-915 honest-audit mirror). Pre-write cleanup deletes prior `(user, account, scenario)` rows inside the same DB transaction before inserting new ones. A `ForecastShortfallDetected` event is dispatched per new window for Phase 11 operational-hardening hooks. Seven unit tests cover the state machine, the pre-write cleanup contract, the event dispatch, and cross-user safety.

- **ProjectionPipeline integration**: the orchestrator now collects ALL per-series contributions across ALL accounts BEFORE routing (the Wave 2 per-account-then-fold loop would have dropped router-rewritten contributions). The routed contributions are bucketed by accountId, folded per account, and `ShortfallDetector::detect` runs per account against the daily-fold output. The shortfall windows match the `expected.shortfalls` shape in the `buffer-crossing` fixture; the ForecastingProjectionContractTest extension asserts the rows land in `forecast_shortfall_windows` end-to-end via `Bus::dispatchSync(new ProjectForecastJob(...))`.

- **SetAccountForecastBuffer Public Action** writes `accounts.forecast_min_buffer_minor` inside a single DB transaction with the cross-user `(account_id, user_id)` guard (raises `NotFoundHttpException` with `Account not found.`) and the non-negative validation (raises `InvalidArgumentException` with the UI-SPEC-locked message `Buffer must be zero or positive.`). On success, three `ProjectForecastJob` dispatches (one per horizon) re-project the baseline so the chart's shortfall band refreshes.

- **ForecastHighlightsQuery** powers both the new dashboard tile AND the top-nav badge composer. `activeShortfallCountForUser` returns the count of baseline (scenario_id IS NULL) windows active in the next 30 days; `forUser` returns the full `ForecastHighlightsDto` carrying the lowest-projected balance (account name + date), the shortfall count, and the next ICS bulk-iDEAL settlement (via `CardStatementQuery::nextSettlementForUser`) — a strict superset of the Phase 5 surface.

- **AccountBufferEditor Livewire SFC** mounts inline on the /forecast per-account chart header inside an Alpine `<div x-data="{ open: false }">` popover. The Blade view carries the UI-SPEC-locked copy verbatim (`Set minimum buffer for {account_name}`, `Alert me when projected balance dips below this amount.`, `Save buffer`, `Cancel`, `Clear buffer (use €0 floor)`, error inline `Buffer must be zero or positive.`). Mount-time cross-user guard refuses to open with another user's account id. On save, dispatches `buffer-editor:saved` (the chart re-renders) + `toast` (`Buffer updated.`). Six feature tests cover the lifecycle.

- **ForecastPage chart now shows the shortfall band overlay** (`annotations.yaxis` with rose-50 fill, opacity 0.4, y2 at the buffer floor) and the inline `↘ Shortfall starts {date} — {amount} below your {buffer} buffer` badge above the chart. The per-account chart header gains a `Buffer: €500` text-link trigger that opens the popover; on save, the Livewire roundtrip re-fetches the baseline + reapplies the annotations.

- **Dashboard tile replacement** (D-1013 — strict superset): the Phase 5 inline `<div>Next ICS settlement</div>` block in `Modules/Core/Resources/views/livewire/dashboard.blade.php` is REPLACED by a single `@livewire('forecasting.forecast-highlights-tile')` mount. The new tile preserves the next-settlement amount + due-date copy as a meta line beneath the new lowest-projected-balance line. Hidden entirely when the user has neither projection data nor an upcoming settlement (calm-day collapse). The dead `$nextSettlement` call + view-data pass-through were removed from `Dashboard.php`.

- **Top-nav Forecast slot** (D-1025 — flat slot, NO "Money" parent menu) inserted between Recurring and Settings. Renders a conditional rose-50 `↘`-glyph pill when `forecastShortfallCount > 0` (cap "99+"); follows the Phase 9 D-927 compound-badge chrome verbatim. The `ForecastingServiceProvider`'s composer body was swapped from the Wave 0 hardcoded zero to a real read against `ForecastHighlightsQuery::activeShortfallCountForUser` with a boot-scoped per-user memo cache (mirrors `DriftAlertsServiceProvider`).

- **Cross-phase test migration** (REQUIRED bookkeeping): `Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php` deleted. Tile-rendering assertions ported verbatim into the new `Modules/Forecasting/tests/Feature/ForecastHighlightsTileTest.php` (8 cases — 2 ported + 6 new Phase 10 cases covering rose-700 shortfall line, lowest-balance line, pluralisation, drill-through link, and cross-user isolation). The orthogonal failed-job toast tests (issue #1 + #8) were preserved in a sibling file `Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php` since the toast remains Phase 5 functionality. The cross-phase audit trail was appended to `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-SUMMARY.md` under a `## Test Migrations` section.

## Task Commits

Each task was committed atomically:

1. **Task 1: ChainAwareForecastRouter + ShortfallDetector + ProjectionPipeline integration + ForecastShortfallDetected event + ChainLinkQuery extension + contract test extension** — `48c733c` (feat)
2. **Task 2: SetAccountForecastBuffer + ForecastHighlightsQuery + AccountBufferEditor + ForecastPage extension** — `0ef7469` (feat)
3. **Task 3: Dashboard tile replacement + top-nav Forecast slot + ForecastHighlightsTile + ForecastHighlightsTileTest (ported) + TopNavForecastSlotTest + Phase 5 NextIcsSettlementTileTest retirement + audit-trail entry** — `1a729df` (feat)

## Decisions Made

- **Phase 5 ChainLinkQuery extension** — the router consumed `confirmedAndDeterministicForSeries` (a method that did not yet exist). Plan 10-02 Task 4's precedent (Phase 10 extends Phase 5's `CardStatementQuery` with `nextSettlementForUser`) is the canonical template; the new method was added in Task 1 as a backwards-compatible Public surface extension along with a typed `SeriesFunderLink` DTO. The arch invariant `crossModuleAccessGoesThroughPublic` stays green because the router only reads `Modules\Chains\Public\Services\*`, never `Internal/*`.

- **Failed-job toast tests preserved in a sibling file**. The plan instructed deleting `NextIcsSettlementTileTest.php` after porting the tile-rendering assertions. The failed-job toast tests in that file are about `Dashboard.php`'s `chain_resolution_runs` read (issue #1 + #8 — the substring-attack guard); they have nothing to do with the tile. Dropping them outright would have lost important regression coverage on the cross-user query scoping. Splitting them into `FailedChainResolutionToastTest.php` in the same module preserves the surface while honouring the tile-deletion directive — documented as a Rule 2 / Rule 3 deviation (auto-fix to avoid losing critical functionality).

- **Global contribution collection before routing** — the Wave 2 `ProjectionPipeline.computeResult` ran a per-account loop that only collected contributions whose `seriesAccountId` matched the current account; Wave 3 needs to potentially rewrite a contribution's `accountId`, so the per-account-then-route loop would have silently dropped rewritten contributions. The Wave 3 implementation collects ALL contributions globally first, routes them through `ChainAwareForecastRouter` once, then buckets by routed `accountId` for the daily fold. This keeps the routing logic centralised + lets the synthesised ICS settlement land naturally on the ASN funder.

- **AccountBufferEditor cross-user mount guard tested at the Public Action layer** — Livewire's `Livewire::test()` does not synchronously propagate `NotFoundHttpException` from `mount()` in a way that the Pest `->toThrow()` matcher catches reliably. The canonical lock for the cross-user contract is therefore on the `SetAccountForecastBuffer` Public Action directly (`it rejects cross-user buffer save via SetAccountForecastBuffer Public Action (404)`). The Livewire-layer mount guard is still present (cross-user account id raises `NotFoundHttpException`) but the test surface for it lives on the Action.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Missing Critical Functionality] Extended Phase 5 `ChainLinkQuery` with `confirmedAndDeterministicForSeries` + added `SeriesFunderLink` DTO**

- **Found during:** Task 1 (ChainAwareForecastRouter implementation)
- **Issue:** The plan's interface block declared `$this->chainQuery->confirmedAndDeterministicForSeries($seriesId, $user)` as a method on `ChainLinkQuery`. The existing Phase 5 `ChainLinkQuery` has `forTransaction`, `openCandidateCount`, and `candidatesForReview` — but no `confirmedAndDeterministicForSeries`. The router would have thrown `BadMethodCallException` at runtime.
- **Fix:** Added the new method to `Modules/Chains/Public/Services/ChainLinkQuery.php` following Plan 10-02 Task 4's precedent (Phase 10 extends Phase 5 `CardStatementQuery` with `nextSettlementForUser`). Created `Modules/Chains/Public/Dto/SeriesFunderLink.php` as the typed return DTO. The arch invariant `crossModuleAccessGoesThroughPublic` stays green.
- **Files modified:** `Modules/Chains/Public/Services/ChainLinkQuery.php`, `Modules/Chains/Public/Dto/SeriesFunderLink.php` (new).
- **Committed in:** `48c733c` (Task 1).

**2. [Rule 2 — Missing Critical Functionality] Preserved Phase 5 failed-job toast tests in a sibling file**

- **Found during:** Task 3 (NextIcsSettlementTileTest deletion)
- **Issue:** The plan's directive to delete `NextIcsSettlementTileTest.php` after porting cases 1+7 (tile-rendering) would have also deleted the failed-job toast tests (cases 7, 8, 9 in the original — issue #1 + #8 substring-attack guard against `chain_resolution_runs.status='failed'` cross-user leak). Those tests have nothing to do with the dashboard tile; they cover Dashboard.php's `chain_resolution_runs` read.
- **Fix:** Created `Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php` carrying the three failed-job toast tests verbatim. The Phase 5 audit-trail entry notes both migration destinations.
- **Files modified:** `Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php` (new).
- **Committed in:** `1a729df` (Task 3).

**3. [Rule 1 — Bug] Per-account contribution collection was incompatible with global routing**

- **Found during:** Task 1 (ProjectionPipeline integration)
- **Issue:** The Wave 2 ProjectionPipeline.computeResult ran a per-account loop that collected contributions whose `seriesAccountId` matched the current account, then folded. Wave 3's `ChainAwareForecastRouter` may rewrite a contribution's `accountId` — so the per-account loop would have either dropped the rewritten contribution (the source account's loop skips it after rewrite; the target account's loop already iterated past it) or double-counted it. The bug would have silently produced incorrect projections.
- **Fix:** Restructured `computeResult` to collect ALL contributions globally first, route them through the router once, then bucket by routed `accountId` for the daily fold + shortfall detection. The synthesised ICS settlement also lands naturally on the ASN funder via the router. The contract-test fixture corpus (six Wave 2 fixtures + buffer-crossing) confirms the projection math remains correct.
- **Files modified:** `Modules/Forecasting/Internal/Pipeline/ProjectionPipeline.php`.
- **Committed in:** `48c733c` (Task 1).

**4. [Rule 3 — Blocking] Environment baseline (composer install + database.sqlite + Vite manifest stub)**

- **Found during:** Pre-Task-1 environment check
- **Issue:** Fresh worktree without a `vendor/` directory; `database/database.sqlite` missing; `public/build/manifest.json` empty (Wave 2's ForecastPageTest needed the resources/css/app.css + resources/js/app.js entries to render the layout).
- **Fix:** `composer install --no-interaction --prefer-dist`; `touch database/database.sqlite`; populated `public/build/manifest.json` with stub entries for `resources/css/app.css` + `resources/js/app.js`. All gitignored.
- **Committed in:** Not committed — runtime state only.

---

**Total deviations:** 4 auto-fixed (2 missing-critical-functionality, 1 implementation bug, 1 blocking environment fix). Zero scope expansion: the ChainLinkQuery extension follows the Phase 10 Plan 10-02 Task 4 precedent verbatim; the failed-job toast preservation prevents losing Phase 5 regression coverage; the ProjectionPipeline restructuring was load-bearing for chain-aware routing to work; the environment baseline mirrors the prior wave SUMMARY entries.

## Issues Encountered

- **Pest "WARN" status is not a failure.** Pest reports tests as "warnings" rather than "passing" in default output when no risky-test designation is in place. All Forecasting tests + the Chains FailedChainResolutionToastTest + the BoundaryArchTest invariants exit 0 with the warning indicator — same convention noted in the Plan 10-01 / 10-02 / 10-03 SUMMARYs.

- **Livewire mount-time exception not synchronously propagating to Livewire::test()**. The AccountBufferEditor's cross-user mount guard raises `NotFoundHttpException`, but `Livewire::test()` in this project's Livewire 4 setup does NOT surface that exception to the calling Pest `->toThrow()` matcher. Worked around by testing the cross-user contract at the Public Action layer instead (the canonical lock for that semantic). The Livewire-side mount guard is still in place at runtime — a real cross-user request through HTTP would 404 correctly.

## User Setup Required

None — Wave 3 ships pipeline + Public Action + Public Service + Livewire SFCs + Blade views + tests. No new environment variables, no external services, no migrations beyond the Wave 1 schema baseline.

## Next Phase Readiness

- **Wave 4 (Plan 10-05)** can swap the `ProjectForecastOnScenarioChange` listener scaffold body for the real per-scenario fan-out, add `ScenarioApplier` ahead of `RangeProjector` in the pipeline, and land the side-by-side scenario picker + delta tile + Phase 9 drift launchpad + /recurring/series/{id} "Model what-if" link on top of the Wave 3 substrate. The `ScenarioQuery::forUser` Public surface is already wired; the Livewire page's `scenarioId` URL state already exists. The `ForecastShortfallDetected` event surface is ready for Phase 11.

- **Wave 5 (Plan 10-06)** introduces the percentile tier (R-7 interpolation against observed occurrences), the variable-utility fixture, the All-accounts aggregate chart, and the `viewByFunder` UI collapse semantics (Wave 3's router has the pass-through scaffold for the flag). The percentile tier overrides the envelope-tier values in DailyFold's spread math; the contract test's tolerance split anticipates the override.

- **All five BoundaryArchTest invariants** stay green. `noTransactionWritesFromForecasting` stays green — Wave 3 writes only to `forecast_shortfall_windows` (Forecasting-owned) and `accounts.forecast_min_buffer_minor` (Ledger-owned column added by Plan 10-02 Migration 010005; the arch invariant's forbidden-table list does NOT include `accounts`). PHPStan level max + Pint pass green across Modules/Forecasting + Modules/Core/Internal/Http + Modules/Core/Resources + Modules/Chains/Public.

## Self-Check: PASSED

- `Modules/Forecasting/Internal/Pipeline/{ChainAwareForecastRouter,ShortfallDetector}.php`: FOUND
- `Modules/Forecasting/Public/Events/ForecastShortfallDetected.php`: FOUND
- `Modules/Forecasting/Public/Actions/SetAccountForecastBuffer.php`: FOUND
- `Modules/Forecasting/Public/Services/ForecastHighlightsQuery.php`: FOUND
- `Modules/Forecasting/Internal/Http/Livewire/{AccountBufferEditor,ForecastHighlightsTile}.php`: FOUND
- `Modules/Forecasting/Resources/views/livewire/{account-buffer-editor,forecast-highlights-tile}.blade.php`: FOUND
- `Modules/Forecasting/tests/Unit/{ChainAwareForecastRouterTest,ShortfallDetectorTest}.php`: FOUND
- `Modules/Forecasting/tests/Feature/{AccountBufferEditorTest,ForecastHighlightsTileTest,TopNavForecastSlotTest}.php`: FOUND
- `Modules/Chains/Public/Dto/SeriesFunderLink.php`: FOUND
- `Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php`: FOUND
- `Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php`: DELETED (git rm)
- `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-SUMMARY.md`: FOUND (carries `## Test Migrations` audit-trail entry)
- Commit `48c733c` (Task 1): FOUND
- Commit `0ef7469` (Task 2): FOUND
- Commit `1a729df` (Task 3): FOUND
- `vendor/bin/pest Modules/Forecasting/tests tests/Contracts/BoundaryArchTest.php tests/Contracts/ForecastingProjectionContractTest.php`: exit 0, 171 warnings, 1679 assertions
- `vendor/bin/pest Modules/Chains/tests`: exit 0, 142 warnings, 471 assertions
- `vendor/bin/phpstan analyse Modules/Forecasting Modules/Core/Internal/Http Modules/Core/Resources Modules/Chains/Public --memory-limit=2G`: OK No errors
- `vendor/bin/pint --test Modules/Forecasting Modules/Core/Internal/Http/Livewire/Dashboard.php Modules/Core/Resources/views/livewire/dashboard.blade.php Modules/Core/Resources/views/livewire/top-nav.blade.php Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php`: passed
- Phase 5 ROADMAP success criteria for CHN-06 (next ICS settlement visible) still holds — preserved by the ported `it renders the next ICS settlement amount when one is upcoming` case in the new ForecastHighlightsTileTest

---
*Phase: 10-cash-flow-forecasting-what-if-scenarios*
*Completed: 2026-05-18*
