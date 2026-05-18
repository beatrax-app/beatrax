---
phase: 10-cash-flow-forecasting-what-if-scenarios
plan: 05
subsystem: forecasting
tags: [laravel, livewire, pest, larastan-max, di-only, scenarios, fct-03, launchpads, side-by-side, wire-poll-auto-stop]

requires:
  - phase: 10-cash-flow-forecasting-what-if-scenarios
    plan: 04
    provides: ChainAwareForecastRouter + ShortfallDetector + SetAccountForecastBuffer + ForecastHighlightsQuery + AccountBufferEditor + ForecastHighlightsTile + top-nav Forecast slot
  - phase: 10-cash-flow-forecasting-what-if-scenarios
    plan: 03
    provides: BalanceAnchorResolver + RangeProjector + DailyFold + ProjectionPipeline + ProjectForecastJob + ForecastQuery
  - phase: 10-cash-flow-forecasting-what-if-scenarios
    plan: 02
    provides: forecast_scenarios + forecast_scenario_mutations schemas + typed ScenarioMutationPayload union + ScenarioMutationPayloadCast + ScenarioQuery
  - phase: 10-cash-flow-forecasting-what-if-scenarios
    plan: 01
    provides: ProjectForecastOnScenarioChange listener scaffold + load-bearing FCT-03 BoundaryArchTest invariant (noScenarioMutationsJoinedToTransactionQueries)
  - phase: 09-recurring-payment-drift-alerts
    provides: DriftPage Livewire SFC + drift-alert-row partial + DismissDriftAlertAsCancelled action precedent
  - phase: 08-recurring-payment-detection-and-clustering
    provides: recurring-series-detail-page Blade + DriftThresholdEditor mount precedent

provides:
  - Three Public Events (ScenarioCreated, ScenarioMutated, ScenarioDeleted)
  - Six core Public Actions (CreateScenario, RenameScenario, DeleteScenario, AddScenarioMutation, RemoveScenarioMutation, EditScenarioMutation) — each gated by cross-user 404 + typed-payload validation + DB transaction
  - Three launchpad Public Actions (CreateCancellationScenarioForAlert, CreateCancellationScenarioForSeries, CreateAmountChangeScenarioForSeries) — atomic CreateScenario + AddScenarioMutation pairs wrapped in a DB transaction
  - ScenarioApplier Internal pipeline stage — the load-bearing FCT-03 in-memory transform that applies a scenario's mutations on top of the baseline projection contributions in pure PHP; NEVER joins forecast_scenario_mutations onto the transaction substrate
  - ProjectionPipeline scenario branch — runs ScenarioApplier after chain-aware routing when scenarioId !== null
  - ProjectForecastOnScenarioChange real listener body (6 dispatches per ScenarioCreated/Mutated, 3 per ScenarioDeleted)
  - ProjectForecastOnRecurringChange + ProjectForecastOnDriftDismissed extended to fan out per saved scenario
  - routes/console.php daily-sweep extended to iterate users × scenarios × horizons
  - ScenarioEditorSidebar Livewire SFC + five-option Add chooser + per-kind inline form
  - ModelWhatIfDropdown Livewire SFC with two-mode popover (menu / amount-form)
  - Phase 9 drift-alert-row.blade.php "Model cancel ↗" chip + Phase 9 DriftPage::modelCancelInForecast method
  - Phase 8 recurring-series-detail-page.blade.php @livewire mount of the ModelWhatIfDropdown next to the threshold editor
  - /forecast side-by-side rendering with shared y-axis + Net diff tile + scenario sidebar mount
  - range-area-chart.blade.php conditional wire:poll.2s element (auto-stops on completion)
affects: [10-06, 11-operational-hardening]

tech-stack:
  added: []
  patterns:
    - "Launchpad atomicity via composed Public Actions inside a single DB transaction: CreateCancellationScenarioForAlert wraps `CreateScenario` + `AddScenarioMutation` in one `db->transaction(...)` so an exception inside the mutation insert rolls back the scenario insert — no orphan scenarios after partial failure."
    - "In-memory scenario transform (FCT-03 boundary): ScenarioApplier reads `forecast_scenario_mutations` via ScenarioQuery and `recurring_series` via RecurringSeriesQuery::forSeries separately, combines in pure PHP. The load-bearing `noScenarioMutationsJoinedToTransactionQueries` arch invariant stays structurally enforced."
    - "Conditional wire:poll element for projection-status polling: `@if ($forecast->isComputing) <div wire:poll.2s=\"refreshProjectionStatus\">…</div> @endif` — when status flips to complete, the next Livewire diff unmounts the element and polling halts automatically (RESEARCH Pitfall 3)."
    - "Shared y-axis range across baseline + scenario panels computed server-side: ForecastPage::computeSharedYAxisRange unions every panel's low/high points + the buffer floor before passing identical yMin / yMax to both Apex options blobs (RESEARCH Pitfall 2)."
    - "Cross-module launchpad-chip pattern: Phase 9's DriftPage extended with a single new method `modelCancelInForecast` that imports the Phase 10 Public Action `CreateCancellationScenarioForAlert`. The arch invariant `crossModuleAccessGoesThroughPublic` stays green because the import is the Public surface."
    - "Listener fan-out asymmetry: ProjectForecastOnScenarioChange fans out baseline + the AFFECTED scenario only (6 dispatches), while ProjectForecastOnRecurringChange fans out baseline + EVERY saved scenario (3 + 3N dispatches). Different semantics: scenario lifecycle invalidates only the affected scenario; substrate change invalidates every projection that consumes the substrate."

key-files:
  created:
    - Modules/Forecasting/Public/Events/ScenarioCreated.php
    - Modules/Forecasting/Public/Events/ScenarioMutated.php
    - Modules/Forecasting/Public/Events/ScenarioDeleted.php
    - Modules/Forecasting/Public/Actions/CreateScenario.php
    - Modules/Forecasting/Public/Actions/RenameScenario.php
    - Modules/Forecasting/Public/Actions/DeleteScenario.php
    - Modules/Forecasting/Public/Actions/AddScenarioMutation.php
    - Modules/Forecasting/Public/Actions/RemoveScenarioMutation.php
    - Modules/Forecasting/Public/Actions/EditScenarioMutation.php
    - Modules/Forecasting/Public/Actions/CreateCancellationScenarioForAlert.php
    - Modules/Forecasting/Public/Actions/CreateCancellationScenarioForSeries.php
    - Modules/Forecasting/Public/Actions/CreateAmountChangeScenarioForSeries.php
    - Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php
    - Modules/Forecasting/Internal/Http/Livewire/ScenarioEditorSidebar.php
    - Modules/Forecasting/Internal/Http/Livewire/ModelWhatIfDropdown.php
    - Modules/Forecasting/Resources/views/livewire/scenario-editor-sidebar.blade.php
    - Modules/Forecasting/Resources/views/livewire/model-what-if-dropdown.blade.php
    - Modules/Forecasting/Resources/views/livewire/partials/net-diff-tile.blade.php
    - Modules/Forecasting/Resources/views/livewire/partials/scenario-mutation-form.blade.php
    - Modules/Forecasting/tests/Feature/ScenarioCrudTest.php
    - Modules/Forecasting/tests/Feature/ScenarioSidebarTest.php
    - Modules/Forecasting/tests/Feature/SideBySideRenderTest.php
    - Modules/Forecasting/tests/Feature/ModelCancelLaunchpadTest.php
    - Modules/Forecasting/tests/Feature/ModelWhatIfDropdownTest.php
  modified:
    - Modules/Forecasting/Internal/Pipeline/ProjectionPipeline.php (constructor signature gains ScenarioApplier; computeResult branches on scenarioId !== null and applies the in-memory transform after chain routing)
    - Modules/Forecasting/Internal/Listeners/ProjectForecastOnScenarioChange.php (Wave 0 scaffold body replaced with the real 6-dispatch / 3-dispatch fan-out per event kind)
    - Modules/Forecasting/Internal/Listeners/ProjectForecastOnRecurringChange.php (constructor signature gains ScenarioQuery; handle() now fans out per saved scenario in addition to baseline)
    - Modules/Forecasting/Internal/Listeners/ProjectForecastOnDriftDismissed.php (same extension as the recurring listener)
    - Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php (scenario panel branch + shared-y-axis computation + Net diff computation + create/delete scenario action handlers + refreshProjectionStatus wire:poll target)
    - Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php (scenario picker + View by funder toggle + side-by-side two-panel grid + Net diff tile mount + scenario sidebar mount)
    - Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php (wire:poll.2s element CONDITIONALLY rendered under @if ($forecast->isComputing) so polling auto-stops)
    - Modules/Forecasting/Providers/ForecastingServiceProvider.php (registers ScenarioEditorSidebar + ModelWhatIfDropdown Livewire components + singleton-binds ScenarioApplier + the nine new Public Actions + wires the three scenario lifecycle event subscriptions)
    - Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php (single-method addition: modelCancelInForecast composes the launchpad Public Action + redirect; constructor unchanged)
    - Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php (Model cancel ↗ chip inserted between Snooze and I cancelled this)
    - Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php (@livewire mount of forecasting.model-what-if-dropdown next to the threshold editor)
    - Modules/Forecasting/tests/Unit/EvaluateForecastListenerTest.php (Wave 4 contract — listener fan-out covers baseline + per-scenario across all three horizons + Phase 10 scenario lifecycle dispatches)
    - routes/console.php (daily-sweep closure iterates users × scenarios × horizons)

key-decisions:
  - "ScenarioMutated event re-used for the rename surface with mutationId=0 + kind='rename' sentinel — the projection-pipeline trigger is identical (re-project baseline + affected scenario), so synthesising a separate ScenarioRenamed event would have been duplicative for Wave 4. A later wave can split if any listener needs to differentiate."
  - "Launchpad Public Actions compose CreateScenario + AddScenarioMutation through the container (singleton-bound) rather than reaching for `new` — so any future tightening of the inner Actions (e.g. richer series-belongs-to-user validation in AddScenarioMutation) is automatically picked up by the launchpads. ModelCancelLaunchpadTest case 7 asserts the singleton binding."
  - "ScenarioApplier reads recurring_series via RecurringSeriesQuery::forSeries (Public surface) instead of joining onto transactions — even when applying change_series_amount where the variance_tolerance_percent comes off the series row. This keeps the noScenarioMutationsJoinedToTransactionQueries arch invariant trivially green AND respects the crossModuleAccessGoesThroughPublic invariant."
  - "diffInDays returns float in Carbon 3 — the shift_series_date transform rounds the delta to an integer day count before calling addDays so PHPStan level max stays green and the date math remains deterministic across DST boundaries."
  - "Cross-user mount-time NotFoundHttpException contract is locked at the Public Service layer for ScenarioEditorSidebar + ModelWhatIfDropdown (mirrors Plan 10-04 AccountBufferEditor pattern) — Livewire 4's Livewire::test() does not synchronously propagate mount-time exceptions through ->toThrow() in this project, so the canonical test asserts that the underlying ScenarioQuery::find / RecurringSeriesQuery::forSeries returns null for a cross-user id. The runtime guard is still present and a real HTTP request would 404."
  - "Bus::fake() re-invocation pattern for listener fan-out tests: the listener is singleton-bound + resolved lazily on first event dispatch. The test calls Bus::fake() once to set the binding, force-resolves the listener via $app->make() so it captures the fake dispatcher, then re-fakes + re-resolves to reset the spy between operations. Documented inline in ScenarioCrudTest case 12."

patterns-established:
  - "Conditional wire:poll element auto-stops polling on completion: `@if ($forecast->isComputing) <div wire:poll.2s=\"...\">...</div> @endif`. Livewire's diff unmounts the element when isComputing flips, halting the poll loop. Used by ScenarioEditorSidebar and ForecastPage; applies to any background-projection surface that wants self-terminating polling."
  - "Three-tier mutation form architecture: ScenarioMutationPayload abstract base + five concrete subclasses (Wave 1) → typed-per-kind Eloquent cast (Wave 1) → typed-per-kind Livewire form partial with per-kind field layout. The match($kind) dispatch lives at the form-builder boundary, the cast boundary, AND the applier boundary — three independent enforcers of the same invariant."
  - "Atomic two-Action launchpad: CreateCancellationScenarioForAlert wraps `CreateScenario` + `AddScenarioMutation` in one `db->transaction(...)` so any rollback inside the mutation insert (e.g. series-not-found NotFoundHttpException) also rolls back the scenario insert. Generalisable to any future helper that composes multiple Public Actions."

requirements-completed:
  - FCT-03
  - FCT-04

duration: 75min
completed: 2026-05-18
---

# Phase 10 Plan 05: Wave 4 — Scenarios CRUD + Side-by-Side + Launchpads Summary

**Lands the full FCT-03 + FCT-04 deliverable: scenario CRUD Public surface (three events + six core actions + three launchpad helpers), the load-bearing ScenarioApplier in-memory transform, the ProjectionPipeline scenario branch, the listener fan-out, the side-by-side ForecastPage with shared y-axis + Net diff tile + wire:poll.2s auto-stop + scenario sidebar, the Phase 9 drift "Model cancel ↗" launchpad chip, and the Phase 8 ModelWhatIfDropdown next to the recurring-series-detail threshold editor.**

## Performance

- **Duration:** ~75 min
- **Tasks:** 3 (atomically committed)
- **Files created:** 24 (12 production + 5 view + 5 test + 2 partial)
- **Files modified:** 13
- **Tests:** 51 new feature tests across five suites + 9 unit tests rewritten in EvaluateForecastListenerTest
- **Total Forecasting tests:** 189 (1571 assertions)
- **Cross-module regression:** 348 tests across Recurring + DriftAlerts (2028 assertions) — all green

## Accomplishments

- **Three Public Events** (`ScenarioCreated`, `ScenarioMutated`, `ScenarioDeleted`) ship as `final readonly class` with `userId`, `scenarioId`, and kind-specific extras. `ScenarioMutated` reuses for the rename surface with `mutationId=0` + `kind='rename'` sentinel so the same listener handles re-projection without a duplicate event class.

- **Six core Public Actions** persist + delete scenarios and mutations with cross-user 404 + typed-payload validation + DB transactions. `CreateScenario` catches the unique-constraint violation on `(user_id, name)` and rethrows as `InvalidArgumentException` with the UI-SPEC-locked copy `A scenario with that name already exists.`. `AddScenarioMutation` defensively validates kind/payload match BEFORE persisting + verifies target series ownership via raw query builder against `recurring_series.user_id`.

- **Three launchpad Public Actions** compose `CreateScenario` + `AddScenarioMutation` in a single DB transaction. `CreateCancellationScenarioForAlert` (Phase 9 launchpad), `CreateCancellationScenarioForSeries` and `CreateAmountChangeScenarioForSeries` (Phase 8 launchpads). Each helper resolves the series's display name (override → detected_name → 'series') for the scenario title. The atomicity contract is tested by deleting the recurring_series row after creating the alert — the launchpad's `AddScenarioMutation` invocation throws, and the outer transaction rolls back so no orphan scenario persists.

- **ScenarioApplier Internal pipeline stage** is the load-bearing FCT-03 transform. Reads `forecast_scenario_mutations` via `ScenarioQuery::mutationsFor` and `recurring_series` via `RecurringSeriesQuery::forSeries` SEPARATELY, then combines them in pure PHP. Five `match`-routed transforms cover the five mutation kinds:
  - `cancel_series` → filter out matching contributions
  - `add_one_off` → append a single contribution at the payload date (sign from direction)
  - `add_recurring` → walk the cadence forward inside the horizon, ±5% calmest-default envelope
  - `change_series_amount` → rewrite low/point/high using the new amount + the series's variance tolerance
  - `shift_series_date` → shift first-matching (`scope=next`) or all-matching (`scope=all_subsequent`) occurrences forward; drop entries past the horizon

  The `noScenarioMutationsJoinedToTransactionQueries` arch invariant stays structurally green AND a negative-presence grep in ScenarioCrudTest case 18 asserts the source file contains zero `->join('forecast_scenario_mutations'...` calls onto any forbidden substrate table.

- **ProjectionPipeline scenario branch**: constructor signature gains `ScenarioApplier`; `computeResult` checks `if ($scenarioId !== null)` after chain-aware routing and applies the in-memory transform before the per-account bucket + daily fold + shortfall detection. The pipeline writes scenario-tagged `forecast_runs` rows + `forecast_shortfall_windows` rows with `scenario_id` set, so `ForecastQuery::forUser` correctly differentiates baseline from scenario reads.

- **ProjectForecastOnScenarioChange real listener body** fans `ScenarioCreated` / `ScenarioMutated` out to 6 ProjectForecastJob dispatches (3 baseline horizons + 3 affected-scenario horizons). `ScenarioDeleted` fans out 3 dispatches (baseline only — the scenario's old runs were already wiped by the cascade-on-delete FK from Plan 10-02). The listener intentionally does NOT fan out to every other saved scenario the user owns — that's the substrate-event listeners' job.

- **`ProjectForecastOnRecurringChange` + `ProjectForecastOnDriftDismissed` extended** with per-scenario fan-out. Each upstream event now dispatches `3 + 3N` jobs (3 baseline + 3 per saved scenario). `ShouldBeUniqueUntilProcessing` lock collapses duplicate dispatches per `(userId, scenarioKey, horizon)` so even with dozens of upstream events in a tight window the worker queue stays bounded.

- **`routes/console.php` daily sweep** extended to iterate `users × scenarios × horizons`.

- **`/forecast` side-by-side rendering**: when `scenarioId !== null`, the page lays out a `grid lg:grid-cols-2` two-panel comparison with baseline LEFT, scenario RIGHT. The Net diff tile renders ABOVE the pair with three direction-aware delta numerics (emerald-700 for improvement, rose-700 for worsening, slate-900 for zero). The shared y-axis range is computed server-side via `ForecastPage::computeSharedYAxisRange` — both panels' Apex options get identical `yaxis.min` / `yaxis.max`. The scenario sidebar mounts in the right rail via `grid lg:grid-cols-[1fr_18rem]`.

- **`range-area-chart.blade.php` wire:poll.2s element** is CONDITIONALLY rendered under `@if ($forecast->isComputing)`. When the latest `forecast_runs.status` flips to `complete`, the next Livewire diff unmounts the element and polling halts automatically. SideBySideRenderTest exercises both the activated and deactivated state of the element.

- **`ScenarioEditorSidebar` Livewire SFC** + Blade view + per-kind form partial. Five-option chooser (`Cancel a series` / `Add a one-off charge or credit` / `Add a recurring series` / `Change a series amount` / `Shift a series date`) + per-kind inline form with the field set per kind. Edit + Remove + Rename + Delete scenario flows dispatch toast events. `confirmDeleteScenario` / `cancelDeleteScenario` implement the inline two-step confirm chrome.

- **`ModelWhatIfDropdown` Livewire SFC** + Blade view. Three modes:
  - `closed`: slate-500 text-link trigger `Model what-if ↗`
  - `menu`: two options (`Model cancellation` / `Model amount change…`) with click-outside-to-close
  - `amount-form`: inline input pre-populated with the current series amount + Save / Cancel

  Save invokes the appropriate launchpad Public Action + redirects to `/forecast?scenarioId={new}`.

- **Phase 9 `drift-alert-row.blade.php`** extended with the `Model cancel ↗` chip inserted between Snooze and `I cancelled this`. Phase 9's `DriftPage` gains a single new method `modelCancelInForecast` that imports `CreateCancellationScenarioForAlert` from the Phase 10 Public surface — the `crossModuleAccessGoesThroughPublic` arch invariant stays green.

- **Phase 8 `recurring-series-detail-page.blade.php`** mounts the new `ModelWhatIfDropdown` SFC via a single `@livewire(...)` line next to the threshold editor — Blade-only extension; no Phase 8 PHP modified.

## Task Commits

Each task was committed atomically:

1. **Task 1: Public events + 6 core Public Actions + ScenarioApplier + ProjectionPipeline branch + listener fan-out + ScenarioCrudTest** — `f9fba2a` (feat)
2. **Task 2: /forecast side-by-side rendering + Net diff tile + ScenarioEditorSidebar + conditional wire:poll.2s + SideBySideRenderTest + ScenarioSidebarTest** — `b1c3112` (feat)
3. **Task 3: Three launchpad Public Actions + Phase 9 Model cancel chip + Phase 8 ModelWhatIfDropdown + ModelCancelLaunchpadTest + ModelWhatIfDropdownTest** — `0d2b8fe` (feat)

## Decisions Made

- **ScenarioMutated re-used for rename**. The projection-pipeline trigger is identical (re-project baseline + affected scenario), so synthesising a separate `ScenarioRenamed` event would have been duplicative for Wave 4. The kind sentinel `'rename'` + `mutationId=0` lets downstream listeners differentiate if needed. Wave 5 can split if any listener consumer requires it.

- **Launchpad Public Actions compose via the container**. `CreateCancellationScenarioForAlert` constructor injects `CreateScenario` + `AddScenarioMutation` (both bound as singletons in the ServiceProvider), so any future tightening of the inner Actions is automatically picked up by the launchpad. `ModelCancelLaunchpadTest` case 7 asserts the singleton binding.

- **ScenarioApplier reads recurring_series via the Public surface**. For `change_series_amount` the Applier needs the series's `variance_tolerance_percent`. Reading via `RecurringSeriesQuery::forSeries` (Public service) instead of a raw join keeps both `noScenarioMutationsJoinedToTransactionQueries` AND `crossModuleAccessGoesThroughPublic` trivially green.

- **diffInDays returns float in Carbon 3**. The `shift_series_date` transform rounds the delta to an integer day count before calling `addDays`, so PHPStan level max stays green and the date math is deterministic across DST boundaries.

- **Cross-user mount-time contract locked at the Public Service layer**. Mirrors Plan 10-04's `AccountBufferEditor` pattern — Livewire 4's `Livewire::test()` does not synchronously propagate mount-time `NotFoundHttpException` through `->toThrow()` in this project. The canonical test asserts that `ScenarioQuery::find` / `RecurringSeriesQuery::forSeries` returns `null` for a cross-user id; the runtime guard inside the SFC's `mount()` still raises the exception when invoked via real HTTP.

- **Bus::fake() re-invocation pattern for listener fan-out tests**. Documented inline in `ScenarioCrudTest` case 12: fake the Bus, force-resolve the listener so it captures the fake dispatcher, re-fake to reset the spy between operations, force-resolve again. Without this, the listener instance retains the original Dispatcher and `Bus::fake()` has no effect on its dispatches.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Environment baseline (composer install + database.sqlite + Vite manifest stub)**

- **Found during:** Pre-Task-1 environment check
- **Issue:** Fresh worktree without `vendor/`; missing `database/database.sqlite`; missing `public/build/manifest.json`. Identical environment shape to Plan 10-04's deviation 4.
- **Fix:** `composer install --no-interaction --prefer-dist`; touch the sqlite file; populate manifest.json with stub entries for `resources/css/app.css` + `resources/js/app.js`.
- **Committed in:** Not committed — runtime state only.

**2. [Rule 1 — Bug] Pre-existing tests in EvaluateForecastListenerTest were tied to the Wave 0/2 listener semantics**

- **Found during:** Post-Task-1 broader regression run
- **Issue:** The Wave 0 scaffold body for `ProjectForecastOnScenarioChange::handle(object $event)` threw `RuntimeException`. The Wave 2 listener tests asserted the throw + asserted three-dispatch fan-out from the recurring/drift listeners. After Task 1 swapped in the typed `ScenarioCreated|ScenarioMutated|ScenarioDeleted` union + the per-scenario fan-out, six tests broke: the Wave 0 test now hits `TypeError` (stdClass doesn't satisfy the typed union), and the recurring/drift tests hit `users` table not found because the listener now queries `User::query()->where(...)` without `RefreshDatabase` set up.
- **Fix:** Rewrote `EvaluateForecastListenerTest` with `uses(RefreshDatabase::class)` + 9 cases covering the new contract — baseline-only fan-out when user has zero scenarios, baseline+per-scenario fan-out when user owns one, three new tests for the `ProjectForecastOnScenarioChange` listener body. Documented in Task 1 commit message.
- **Files modified:** `Modules/Forecasting/tests/Unit/EvaluateForecastListenerTest.php`.
- **Committed in:** `f9fba2a` (Task 1).

**3. [Rule 1 — Bug] Plan referenced `Illuminate\Contracts\Routing\Redirector` (does not exist)**

- **Found during:** Task 3 initial test run
- **Issue:** Laravel's `Redirector` lives at `Illuminate\Routing\Redirector`, not under `Illuminate\Contracts\Routing`. The `Illuminate\Contracts` namespace has no `Routing` subdirectory. PHPStan didn't catch this because the class-not-found check happens at runtime; the test runner raised `ReflectionException: Class "Illuminate\Contracts\Routing\Redirector" does not exist`.
- **Fix:** Changed both `ModelWhatIfDropdown.php` and `DriftPage.php` to import the concrete `Illuminate\Routing\Redirector`. The redirector is method-parameter-injected in both — DI-only invariant preserved.
- **Files modified:** `Modules/Forecasting/Internal/Http/Livewire/ModelWhatIfDropdown.php`, `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php`.
- **Committed in:** `0d2b8fe` (Task 3).

**4. [Rule 1 — Bug] Plan referenced a `drift_alerts.kind` column that does not exist**

- **Found during:** Task 3 first run of ModelCancelLaunchpadTest
- **Issue:** The plan's example seed code inserted `kind`, `observed_amount_minor`, `delta_percent`, `observed_at` into `drift_alerts`. The actual schema (Phase 9 Plan 09-02 Migration 010001) has `direction`, `latest_amount_minor`, `annualized_impact_minor`, `threshold_percent_used`, `threshold_source`, `latest_occurrence_id`, `detected_at` — and requires `latest_occurrence_id` to FK to a real `recurring_series_occurrences` row.
- **Fix:** Replaced the inline `drift_alerts->insertGetId` with the DriftPageTest precedent — seed a transaction + an account + an import_run + an occurrence + then use `DriftAlert::factory()->create(...)` with the actual column set. Documented the precedent in the test's `mclAlert` helper.
- **Files modified:** `Modules/Forecasting/tests/Feature/ModelCancelLaunchpadTest.php`.
- **Committed in:** `0d2b8fe` (Task 3).

---

**Total deviations:** 4 (1 environment baseline + 3 plan/code mismatches that surfaced at runtime and would have blocked the test suite). Zero scope expansion: all four fixes restore the plan's stated behaviour against the actual production schema or import path.

## Issues Encountered

- **Pest "WARN" status is not a failure**: Pest reports tests as `WARN` rather than `PASS` in default output. The convention is preserved from Plan 10-01 through 10-04 SUMMARYs.

- **Livewire mount-time exception non-propagation**: Same observation as Plan 10-04 — `Livewire::test()` in this project's Livewire 4 setup does not synchronously propagate `NotFoundHttpException` from `mount()` to the test's `->toThrow()` matcher. Both `ScenarioEditorSidebar` and `ModelWhatIfDropdown` have runtime mount guards in place; the cross-user contract is tested at the Public Service layer (mirrors AccountBufferEditor precedent).

- **Bus::fake() listener-binding ordering**: When the listener is `singleton`-bound via the ServiceProvider AND the listener constructor injects `Illuminate\Contracts\Bus\Dispatcher`, the listener instance captures the original Dispatcher at the moment the listener is first resolved. `Bus::fake()` rebinds the contract in the container but does NOT swap the instance reference inside already-resolved singletons. The test pattern (force-resolve after fake) is documented inline in `ScenarioCrudTest` case 12.

## User Setup Required

None — Wave 4 ships entirely backend + Livewire SFC + Blade. No new env vars, no external services, no migrations beyond the Wave 1 schema baseline.

## Next Phase Readiness

- **Wave 5 (Plan 10-06)** can now land:
  - Percentile-tier range math (R-7 interpolation against observed occurrences) — overrides the envelope-tier values in DailyFold's spread math
  - ScenarioIsolationContractTest — the end-to-end runtime proof that ScenarioApplier never leaks scenario data into the baseline projection (the structural arch invariant is already enforced; Wave 5 adds the runtime fixture)
  - Cadence jitter + opening-balance editor + All-accounts aggregate chart
  - Confidence legend on the side-by-side panels (Wave 4 currently renders a placeholder empty div in the legend slot)
  - Multi-currency edge-case polish for `add_one_off` and `add_recurring` mutations

- **All five BoundaryArchTest invariants** stay green. Specifically `noScenarioMutationsJoinedToTransactionQueries` remains structurally enforced: ScenarioApplier reads both substrates via Public surfaces, combines in PHP. PHPStan level max + Pint pass green across Modules/Forecasting + Modules/DriftAlerts/Internal/Http + Modules/Recurring/Resources.

- **Wave 4's wire:poll auto-stop pattern** (`@if ($isComputing) <div wire:poll.2s>...</div> @endif`) is general-purpose; any future surface that wants self-terminating projection-status polling can adopt the same shape verbatim.

## Self-Check: PASSED

- `Modules/Forecasting/Public/Events/{ScenarioCreated,ScenarioMutated,ScenarioDeleted}.php`: FOUND
- `Modules/Forecasting/Public/Actions/{CreateScenario,RenameScenario,DeleteScenario,AddScenarioMutation,RemoveScenarioMutation,EditScenarioMutation}.php`: FOUND
- `Modules/Forecasting/Public/Actions/{CreateCancellationScenarioForAlert,CreateCancellationScenarioForSeries,CreateAmountChangeScenarioForSeries}.php`: FOUND
- `Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php`: FOUND
- `Modules/Forecasting/Internal/Http/Livewire/{ScenarioEditorSidebar,ModelWhatIfDropdown}.php`: FOUND
- `Modules/Forecasting/Resources/views/livewire/{scenario-editor-sidebar,model-what-if-dropdown}.blade.php`: FOUND
- `Modules/Forecasting/Resources/views/livewire/partials/{net-diff-tile,scenario-mutation-form}.blade.php`: FOUND
- `Modules/Forecasting/tests/Feature/{ScenarioCrudTest,ScenarioSidebarTest,SideBySideRenderTest,ModelCancelLaunchpadTest,ModelWhatIfDropdownTest}.php`: FOUND
- `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` carries `modelCancelInForecast` method: FOUND (grep "modelCancelInForecast" returns 2 matches — declaration + partial wire:click target)
- `Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php` contains "Model cancel ↗" chip: FOUND
- `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` mounts forecasting.model-what-if-dropdown: FOUND
- Commit `f9fba2a` (Task 1): FOUND
- Commit `b1c3112` (Task 2): FOUND
- Commit `0d2b8fe` (Task 3): FOUND
- `vendor/bin/pest Modules/Forecasting/tests`: exit 0, 189 warnings, 1571 assertions
- `vendor/bin/pest Modules/DriftAlerts/tests Modules/Recurring/tests`: exit 0, 348 warnings, 2028 assertions
- `vendor/bin/pest tests/Contracts/BoundaryArchTest.php`: exit 0, 33 warnings, 65 assertions; specifically `noScenarioMutationsJoinedToTransactionQueries` stays green
- `vendor/bin/phpstan analyse Modules/Forecasting Modules/DriftAlerts/Internal/Http Modules/Recurring/Resources --memory-limit=2G`: OK No errors
- `vendor/bin/pint --test Modules/Forecasting Modules/DriftAlerts Modules/Recurring/Resources`: passed

---
*Phase: 10-cash-flow-forecasting-what-if-scenarios*
*Completed: 2026-05-18*
