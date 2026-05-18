# Phase 10: Cash-Flow Forecasting + What-If Scenarios - Pattern Map

**Mapped:** 2026-05-18
**Files analyzed:** 53 new + 6 modified
**Analogs found:** 53 / 53 (100% coverage; Phase 10 is a composition phase — every primitive has a precedent)

The new module is `Modules/Forecasting/`. The single closest skeletal analog is `Modules/DriftAlerts/` (Phase 9, last shipped) because it (a) was built with the project's now-mature DI/Public-Internal/arch-test conventions, (b) ships an event-listening pipeline with `ShouldBeUniqueUntilProcessing` jobs, and (c) already wires both a top-nav slot composer and a dashboard tile. Phase 8 `Modules/Recurring/` is the secondary analog for chart-bearing Livewire SFCs (`RecurringSeriesDetailPage` is the only existing ApexCharts integration in the codebase). Phase 5 `Modules/Chains/` is the analog for the `ChainAwareForecastRouter` (since it owns `CardStatementQuery::nextSettlementForUser`).

---

## File Classification

### `Modules/Forecasting/` module skeleton

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/composer.json` | config | static | `Modules/DriftAlerts/composer.json` | exact |
| `Modules/Forecasting/Providers/ForecastingServiceProvider.php` | provider | request-response (boot) | `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` | exact |
| `Modules/Forecasting/tests/Pest.php` | test-bootstrap | static | `Modules/DriftAlerts/tests/Pest.php` | exact |
| `Modules/Forecasting/tests/TestCase.php` | test-bootstrap | static | `Modules/DriftAlerts/tests/TestCase.php` | exact |
| `Modules/Forecasting/Routes/web.php` | route | request-response | `Modules/DriftAlerts/Routes/web.php` | exact |

### Migrations (six)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Database/Migrations/*_create_forecast_scenarios_table.php` | migration | schema | `Modules/DriftAlerts/Database/Migrations/2026_05_19_010001_create_drift_alerts_table.php` | role-match |
| `*_create_forecast_scenario_mutations_table.php` | migration | schema (with JSON payload) | `Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php` (JSON evidence column) | role-match |
| `*_create_forecast_shortfall_windows_table.php` | migration | schema | `Modules/DriftAlerts/Database/Migrations/2026_05_19_010001_create_drift_alerts_table.php` (per-row threshold_used pattern → buffer_used) | exact |
| `*_create_forecast_runs_table.php` | migration | schema (audit table) | `Modules/Chains/Database/Migrations/2026_05_16_010005_create_chain_resolution_runs_table.php` | exact |
| `*_add_forecast_columns_to_accounts.php` | migration | schema (column-add on foreign module's table) | `Modules/Recurring/Database/Migrations/2026_05_19_010002_add_drift_threshold_percent_to_recurring_series.php` | exact |

### Eloquent Models

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Models/ForecastScenario.php` | model | CRUD | `Modules/DriftAlerts/Models/DriftAlert.php` | exact |
| `Modules/Forecasting/Models/ForecastScenarioMutation.php` | model | CRUD (JSON payload cast) | `Modules/Chains/Models/ChainLink.php` (`evidence` JSON cast lines 60-67) | exact |
| `Modules/Forecasting/Models/ForecastShortfallWindow.php` | model | CRUD | `Modules/DriftAlerts/Models/DriftAlert.php` | role-match |
| `Modules/Forecasting/Models/ForecastRun.php` | model | CRUD | `Modules/Chains/Models/ChainResolutionRun.php` | exact |
| `Modules/Forecasting/Database/Factories/ForecastScenarioFactory.php` | factory | static | `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php` | exact |
| `Modules/Forecasting/Database/Factories/ForecastScenarioMutationFactory.php` | factory | static | `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php` | role-match |
| `Modules/Forecasting/Database/Factories/ForecastShortfallWindowFactory.php` | factory | static | `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php` | role-match |
| `Modules/Forecasting/Database/Factories/ForecastRunFactory.php` | factory | static | `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php` | role-match |

### Public Surface — DTOs

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Public/Dto/ForecastDto.php` | dto | transform | `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php` + `Modules/Chains/Public/Dto/CardStatementForecastTile.php` | exact |
| `Modules/Forecasting/Public/Dto/ForecastPointDto.php` | dto | transform | `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php` | role-match |
| `Modules/Forecasting/Public/Dto/ScenarioDto.php` | dto | transform | `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php` | role-match |
| `Modules/Forecasting/Public/Dto/ScenarioMutationDto.php` (union envelope per kind) | dto | transform (tagged union) | `Modules/Chains/Public/Dto/ChainTreeNode.php` + `Modules/Chains/Models/ChainLink.php` JSON cast | role-match |
| `Modules/Forecasting/Public/Dto/ForecastHighlightsDto.php` | dto | transform | `Modules/Chains/Public/Dto/CardStatementForecastTile.php` | exact |
| `Modules/Forecasting/Public/Dto/ShortfallWindowDto.php` | dto | transform | `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php` | role-match |
| `Modules/Forecasting/Public/Dto/BalanceAnchorDto.php` | dto | transform | `Modules/DriftAlerts/Public/Dto/CancellationImpactDto.php` | role-match |
| `Modules/Forecasting/Public/Dto/SeriesConfidenceDto.php` | dto | transform | `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php` | role-match |

### Public Surface — Events

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Public/Events/ScenarioCreated.php` | event | pub-sub | `Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php` | exact |
| `Modules/Forecasting/Public/Events/ScenarioMutated.php` | event | pub-sub | `Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php` | exact |
| `Modules/Forecasting/Public/Events/ScenarioDeleted.php` | event | pub-sub | `Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php` | exact |
| `Modules/Forecasting/Public/Events/ForecastShortfallDetected.php` | event | pub-sub | `Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php` | exact |

### Public Surface — Actions

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Public/Actions/CreateScenario.php` | action | CRUD (write) | `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` | exact |
| `Modules/Forecasting/Public/Actions/RenameScenario.php` | action | CRUD (write) | `Modules/Recurring/Public/Actions/EditRecurringSeriesName.php` | exact |
| `Modules/Forecasting/Public/Actions/DeleteScenario.php` | action | CRUD (write) | `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` (state-flip pattern, here a hard delete) | role-match |
| `Modules/Forecasting/Public/Actions/AddScenarioMutation.php` | action | CRUD (write) | `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` | exact |
| `Modules/Forecasting/Public/Actions/RemoveScenarioMutation.php` | action | CRUD (write) | `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` | exact |
| `Modules/Forecasting/Public/Actions/SetAccountForecastBuffer.php` | action | CRUD (write, on `accounts`) | `Modules/Recurring/Public/Actions/EditRecurringSeriesVarianceTolerance.php` (per-row threshold setter) | exact |
| `Modules/Forecasting/Public/Actions/SetAccountOpeningBalance.php` | action | CRUD (write, on `accounts`) | `Modules/Recurring/Public/Actions/EditRecurringSeriesVarianceTolerance.php` | exact |
| `Modules/Forecasting/Public/Actions/CreateCancellationScenarioForAlert.php` | action | CRUD (write, cross-action) | `Modules/DriftAlerts/Public/Actions/DismissDriftAlertAsCancelled.php` (composes state-flip + event) | role-match |
| `Modules/Forecasting/Public/Actions/CreateCancellationScenarioForSeries.php` | action | CRUD (write, cross-action) | same as above | role-match |
| `Modules/Forecasting/Public/Actions/CreateAmountChangeScenarioForSeries.php` | action | CRUD (write, cross-action) | same as above | role-match |

### Public Surface — Services (Read APIs)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Public/Services/ForecastQuery.php` | service | CRUD (read) | `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` | exact |
| `Modules/Forecasting/Public/Services/ScenarioQuery.php` | service | CRUD (read) | `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` | exact |
| `Modules/Forecasting/Public/Services/ForecastHighlightsQuery.php` | service | CRUD (read, dashboard tile) | `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php::openCountForUser` + `Modules/Chains/Public/Services/CardStatementQuery.php` | exact |

### Internal Pipeline

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Internal/Pipeline/BalanceAnchorResolver.php` | utility | transform | `Modules/DriftAlerts/Internal/DriftEvaluator.php` (DI shape) + `Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php` | role-match |
| `Modules/Forecasting/Internal/Pipeline/RangeProjector.php` | utility | transform | `Modules/DriftAlerts/Internal/DriftEvaluator.php` (DI + Clock pattern) | role-match |
| `Modules/Forecasting/Internal/Pipeline/ChainAwareForecastRouter.php` | utility | transform | `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` | role-match |
| `Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php` | utility | transform | `Modules/DriftAlerts/Internal/DriftEvaluator.php` | role-match |
| `Modules/Forecasting/Internal/Pipeline/ProjectionPipeline.php` (orchestrator) | utility | transform | `Modules/DriftAlerts/Internal/DriftEvaluator.php` | exact |
| `Modules/Forecasting/Internal/Mapping/ForecastDtoMapper.php` | utility | transform | `Modules/DriftAlerts/Internal/Mapping/DriftAlertDtoMapper.php` | exact |

### Internal Jobs

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php` | job | event-driven | `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php` | exact |

### Internal Listeners

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Internal/Listeners/ProjectForecastOnRecurringChange.php` | listener | event-driven | `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` | exact |
| `Modules/Forecasting/Internal/Listeners/ProjectForecastOnDriftDismissed.php` | listener | event-driven | `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` | exact |
| `Modules/Forecasting/Internal/Listeners/ProjectForecastOnScenarioChange.php` | listener | event-driven | `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` | exact |

### Internal Livewire SFCs

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php` | livewire-sfc | request-response | `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` + `Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php` (for chart) | exact |
| `Modules/Forecasting/Internal/Http/Livewire/ScenarioEditorSidebar.php` | livewire-sfc | request-response | `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` (action-handlers + toast dispatch) | role-match |
| `Modules/Forecasting/Internal/Http/Livewire/AccountBufferEditor.php` | livewire-sfc | request-response (popover) | `Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php` | exact |
| `Modules/Forecasting/Internal/Http/Livewire/OpeningBalanceEditor.php` | livewire-sfc | request-response | `Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php` | role-match |
| `Modules/Forecasting/Internal/Http/Livewire/ForecastHighlightsTile.php` | livewire-sfc | request-response (dashboard tile) | `Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php` | exact |
| `Modules/Forecasting/Internal/Http/Livewire/ModelWhatIfDropdown.php` | livewire-sfc | request-response | `Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php` (popover shape) | role-match |

### Blade Views

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php` | blade-view | template | `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php` + `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` (chart) | exact |
| `Modules/Forecasting/Resources/views/livewire/scenario-editor-sidebar.blade.php` | blade-view | template | `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php` | role-match |
| `Modules/Forecasting/Resources/views/livewire/account-buffer-editor.blade.php` | blade-view | template | `Modules/DriftAlerts/Resources/views/livewire/drift-threshold-editor.blade.php` | exact |
| `Modules/Forecasting/Resources/views/livewire/opening-balance-editor.blade.php` | blade-view | template | `Modules/DriftAlerts/Resources/views/livewire/drift-threshold-editor.blade.php` | role-match |
| `Modules/Forecasting/Resources/views/livewire/forecast-highlights-tile.blade.php` | blade-view | template | `Modules/DriftAlerts/Resources/views/livewire/dashboard-drift-badge.blade.php` | exact |
| `Modules/Forecasting/Resources/views/livewire/model-what-if-dropdown.blade.php` | blade-view | template | `Modules/DriftAlerts/Resources/views/livewire/drift-threshold-editor.blade.php` | role-match |
| `Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php` | blade-partial | template (Alpine + ApexCharts) | `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` lines 95-113 | exact |
| `Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php` | blade-partial | template | same as above | exact |
| `Modules/Forecasting/Resources/views/livewire/partials/net-diff-tile.blade.php` | blade-partial | template (pure Blade) | `Modules/Core/Resources/views/livewire/dashboard.blade.php` Next-ICS-settlement tile region (lines 56-71) | role-match |

### Tests

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `tests/Contracts/BoundaryArchTest.php` (extends; 5 new invariants) | test | static | `tests/Contracts/BoundaryArchTest.php` (existing) | exact |
| `tests/Contracts/ForecastingProjectionContractTest.php` | test | end-to-end | `tests/Contracts/RecurringDetectionContractTest.php` + `tests/Contracts/DriftDetectionContractTest.php` | exact |
| `tests/Contracts/ScenarioIsolationContractTest.php` | test | invariant | `tests/Contracts/IdempotencyContractTest.php` | role-match |
| `Modules/Forecasting/tests/Unit/RangeProjectorTest.php` | test | unit | `Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php` | exact |
| `Modules/Forecasting/tests/Unit/ChainAwareForecastRouterTest.php` | test | unit | `Modules/Chains/tests/Unit/Resolvers/IcsSettlementResolverTest.php` | exact |
| `Modules/Forecasting/tests/Unit/BalanceAnchorResolverTest.php` | test | unit | `Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php` | role-match |
| `Modules/Forecasting/tests/Unit/ProjectForecastJobUniqueTest.php` | test | unit | `Modules/DriftAlerts/tests/Unit/DetectDriftAlertsJobUniqueTest.php` | exact |
| `Modules/Forecasting/tests/Unit/EvaluateForecastListenerTest.php` (× 3 listeners) | test | unit | `Modules/DriftAlerts/tests/Unit/EvaluateDriftListenerTest.php` | exact |
| `Modules/Forecasting/tests/Feature/ForecastPageTest.php` | test | feature | `Modules/DriftAlerts/tests/Feature/DriftPageTest.php` | exact |
| `Modules/Forecasting/tests/Feature/ScenarioCrudTest.php` | test | feature | `Modules/DriftAlerts/tests/Feature/AcknowledgeDriftAlertTest.php` | exact |
| `Modules/Forecasting/tests/Feature/AccountBufferEditorTest.php` | test | feature | `Modules/DriftAlerts/tests/Feature/DriftThresholdOverrideEditorTest.php` | exact |
| `Modules/Forecasting/tests/Feature/ForecastHighlightsTileTest.php` | test | feature | `Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php` (**PORT existing assertions per Pitfall 5**) | exact |
| `Modules/Forecasting/tests/Feature/ForecastCrossUser404Test.php` | test | feature | `Modules/DriftAlerts/tests/Feature/DriftAlertCrossUser404Test.php` | exact |
| `Modules/Forecasting/tests/Feature/TopNavForecastBadgeTest.php` | test | feature | `Modules/DriftAlerts/tests/Feature/TopNavDriftBadgeTest.php` | exact |
| `Modules/Forecasting/tests/Feature/ModelCancelLaunchpadTest.php` | test | feature | `Modules/DriftAlerts/tests/Feature/DismissDriftAlertAsCancelledTest.php` | role-match |
| `Modules/Forecasting/tests/fixtures/*` (Wave 0 corpus) | fixture | static | `Modules/DriftAlerts/tests/fixtures/drift-corpus/*.php` + `Modules/Recurring/tests/fixtures/synthesised/*.php` | exact |

### Modified Files (no new SFC; surgical changes)

| Modified File | Change | Closest Analog (precedent for the change shape) |
|---------------|--------|--------------------------------------------------|
| `tests/Contracts/BoundaryArchTest.php` | Add `arch('Modules\\Forecasting\\Internal is only used inside Modules\\Forecasting')` (line ~50 area); add `ProjectForecastJob` to facade-ignore carve-out; add the two `it(...)` content-grep invariants for `noTransactionWritesFromForecasting` + `noScenarioMutationsJoinedToTransactionQueries`; add native arch for `noSynchronousForecastingInRequestLifecycle` | existing rules at lines 8-50 (native) + lines 534-581 (content-grep `noTransactionWritesFromRecurring`) + lines 92-99 (facade carve-out list) |
| `tests/Pest.php` | Add `'Modules/Forecasting' => Modules\\Forecasting\\Tests\\TestCase::class` to the foreach loop (line 22-34) | existing entries at lines 23-33 |
| `phpunit.xml` | Add Forecasting Unit/Feature/Contracts directories to the `<testsuite>` block | existing entries at lines 15, 27, 45-46 |
| `composer.json` (root) | Add `"Modules\\Forecasting\\Tests\\": "Modules/Forecasting/tests/"` to `autoload-dev.psr-4` | existing entries at lines 52-62 |
| `routes/console.php` | Add `Schedule::call(fn (DatabaseManager $db, Dispatcher $bus) => ...)->name('forecasting.daily-sweep')->daily()->withoutOverlapping(30)` that iterates users and dispatches `ProjectForecastJob` per `(user_id, null=baseline, [30,60,90])` | existing `email-scan.incremental` (lines 33-58) and `email-scan.discovery` (lines 63-78) |
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` | Append `forecastSettings` keyed array property + `mount()` extension reading the three new account columns + `save()` extension calling `SetAccountForecastBuffer` + `SetAccountOpeningBalance` actions per account | existing recurring + drift threshold field handling at lines 67-105, 131-145 |
| `Modules/Core/Resources/views/livewire/settings-page.blade.php` | Append `<section id="forecast-buffers">` block | existing settings sections |
| `Modules/Core/Resources/views/livewire/dashboard.blade.php` | REPLACE existing Next-ICS-settlement tile block (lines 56-71 area: `@if (isset($nextSettlement)...)`) with `@livewire('forecasting.forecast-highlights-tile')` | the existing tile region itself |
| `Modules/Core/Resources/views/livewire/top-nav.blade.php` | Insert new `Forecast` slot with `$forecastShortfallCount` badge between existing Recurring and Settings slots | existing Drift slot, Recurring slot, Chains slot composers (Phase 5/8/9) |
| `Modules/Core/Internal/Http/Livewire/Dashboard.php` | Remove `nextIcsSettlement()` call + pass-through (line 170, 205) when the partial is fully replaced (RESEARCH Pitfall 5 — port assertions to new tile test) | existing call surface |
| `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` | Add `Model what-if ↗` link + `@livewire('forecasting.model-what-if-dropdown', ['seriesId' => $series->id])` mount under detail header | existing threshold-editor mount nearby |
| `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php` (or `partials/drift-alert-row.blade.php`) | Add `Model cancel ↗` chip to action row | existing `Snooze` / `I cancelled this` chips |

---

## Pattern Assignments

### `Modules/Forecasting/composer.json` (config, static)

**Analog:** `Modules/DriftAlerts/composer.json` (lines 1-16)

**Copy the whole file verbatim:**

```json
{
    "name": "diederik/forecasting",
    "description": "Forecasting module — 30/60/90-day cash-flow projection, what-if scenarios, /forecast page, dashboard highlights tile.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\Forecasting\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Forecasting\\Tests\\": "tests/"
        }
    }
}
```

---

### `Modules/Forecasting/Providers/ForecastingServiceProvider.php` (provider, request-response)

**Analog:** `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` (entire file, lines 1-137)

Five separate excerpts to copy:

**1. Imports + class header (lines 1-49):**

```php
<?php

declare(strict_types=1);

namespace Modules\Forecasting\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
// ... module internal/public imports
```

**2. `register()` singleton bindings (lines 51-60):**

```php
public function register(): void
{
    $this->app->singleton(BalanceAnchorResolver::class);
    $this->app->singleton(RangeProjector::class);
    $this->app->singleton(ChainAwareForecastRouter::class);
    $this->app->singleton(ScenarioApplier.php::class);
    $this->app->singleton(ProjectionPipeline::class);
    $this->app->singleton(ForecastQuery::class);
    $this->app->singleton(ScenarioQuery::class);
    $this->app->singleton(ForecastHighlightsQuery::class);
    $this->app->singleton(CreateScenario::class);
    // ... etc for all seven+ Public Actions
}
```

**3. `boot()` shape (lines 62-80):**

```php
public function boot(LivewireManager $livewire, Dispatcher $events): void
{
    if (is_dir(__DIR__.'/../Database/Migrations')) {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
    if (is_file(__DIR__.'/../Routes/web.php')) {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
    }
    if (is_dir(__DIR__.'/../Resources/views')) {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'forecasting');
    }

    $livewire->component('forecasting.forecast-page', ForecastPage::class);
    $livewire->component('forecasting.forecast-highlights-tile', ForecastHighlightsTile::class);
    // ... etc

    $this->registerListeners($events);
    $this->registerTopNavBadgeComposer();
}
```

**4. Multi-event listener registration — extend the DriftAlerts single-event shape (lines 82-95):**

```php
private function registerListeners(Dispatcher $events): void
{
    // Phase 8 events
    $events->listen(RecurringSeriesApproved::class, [ProjectForecastOnRecurringChange::class, 'handle']);
    $events->listen(RecurringSeriesCadenceFlipped::class, [ProjectForecastOnRecurringChange::class, 'handle']);
    $events->listen(RecurringSeriesRejected::class, [ProjectForecastOnRecurringChange::class, 'handle']);
    $events->listen(RecurringSeriesMetricsRefreshed::class, [ProjectForecastOnRecurringChange::class, 'handle']);
    // Phase 9 event
    $events->listen(DriftAlertDismissedCancelled::class, [ProjectForecastOnDriftDismissed::class, 'handle']);
    // Phase 10 internal events
    $events->listen(ScenarioCreated::class, [ProjectForecastOnScenarioChange::class, 'handle']);
    $events->listen(ScenarioMutated::class, [ProjectForecastOnScenarioChange::class, 'handle']);
}
```

**5. Top-nav badge composer with memo (lines 113-136) — verbatim shape, swap `driftOpenCount` for `forecastShortfallCount` and `DriftAlertQuery::openCountForUser` for `ForecastHighlightsQuery::activeShortfallCountForUser`:**

```php
private function registerTopNavBadgeComposer(): void
{
    $app = $this->app;
    $factory = $app->make(ViewFactoryContract::class);

    /** @var array<int, int> $cache */
    $cache = [];

    $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app, &$cache): void {
        $currentUser = $app->make(CurrentUser::class);
        if (! $currentUser->isAuthenticated()) {
            $compose->with('forecastShortfallCount', 0);

            return;
        }
        $user = $currentUser->user();
        $userId = $user->id;
        if (! array_key_exists($userId, $cache)) {
            $query = $app->make(ForecastHighlightsQuery::class);
            $cache[$userId] = $query->activeShortfallCountForUser($user);
        }
        $compose->with('forecastShortfallCount', $cache[$userId]);
    });
}
```

---

### Migration: `*_create_forecast_shortfall_windows_table.php` (migration, schema)

**Analog:** `Modules/DriftAlerts/Database/Migrations/2026_05_19_010001_create_drift_alerts_table.php` (lines 1-119)

Key elements to copy:

**Imports + DatabaseManager resolver pattern (lines 1-10, 47-118):**

```php
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('forecast_shortfall_windows', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained('forecast_scenarios')->cascadeOnDelete();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->bigInteger('lowest_balance_minor');
            $table->string('currency', 3);
            $table->bigInteger('buffer_used_minor');   // mirrors drift_alerts.threshold_percent_used pattern
            $table->timestamps();

            $table->index(['user_id', 'account_id', 'starts_at']);
            $table->index(['user_id', 'scenario_id']);
        });
    }
    // ... down() + schema() + db() helpers verbatim
};
```

The `buffer_used_minor` column directly mirrors the `threshold_percent_used` pattern at line 62 of the DriftAlert migration — "audit captured at detection time".

---

### Migration: `*_add_forecast_columns_to_accounts.php` (migration, schema — cross-module column-add)

**Analog (canonical pattern):** `Modules/Recurring/Database/Migrations/2026_05_19_010002_add_drift_threshold_percent_to_recurring_series.php` (entire file)

**Note the convention precedent:** the migration that adds `drift_threshold_percent` to `recurring_series` lives in `Modules/Recurring/Database/Migrations/`, not `Modules/DriftAlerts/`. Phase 10 CONTEXT D-1014 deliberately deviates and places the `add_forecast_columns_to_accounts` migration inside `Modules/Forecasting/Database/Migrations/`. **The migration body itself is copied verbatim from the Recurring analog** — only the directory placement differs.

**Body (copy lines 25-44):**

```php
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('accounts', static function (Blueprint $table): void {
            $table->bigInteger('forecast_min_buffer_minor')->nullable()->after('default_currency');
            $table->bigInteger('opening_balance_minor')->nullable()->after('forecast_min_buffer_minor');
            $table->date('opening_balance_as_of_date')->nullable()->after('opening_balance_minor');
        });
    }

    public function down(): void
    {
        $this->schema()->table('accounts', static function (Blueprint $table): void {
            $table->dropColumn(['forecast_min_buffer_minor', 'opening_balance_minor', 'opening_balance_as_of_date']);
        });
    }
    // ... schema() + db() helpers verbatim
};
```

---

### `Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php` (job, event-driven)

**Analog:** `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php` (entire file, lines 1-79)

**Key changes vs the analog (CONTEXT D-1017, RESEARCH Pattern 3, RESEARCH Pitfall 1):**

| Element | Analog (DetectDriftAlertsJob) | Phase 10 (ProjectForecastJob) |
|---------|-------------------------------|--------------------------------|
| Constructor args | `int $userId, int $recurringSeriesId` | `int $userId, ?int $scenarioId, int $horizonDays` |
| `uniqueId()` | `"{$userId}:{$seriesId}"` | `"{$userId}:" . ($scenarioId !== null ? (string)$scenarioId : 'baseline') . ":{$horizonDays}"` — **use `'baseline'` sentinel, NOT `?? 0`** (RESEARCH Pitfall 1) |
| `uniqueFor()` | 600 | 600 (verbatim) |
| `uniqueVia()` | `Cache::driver('redis')` (one of three permitted facade carve-outs) | **Same — must be added to `BoundaryArchTest.php` facade-ignore list at lines 92-99** |
| `handle()` collaborator | `DriftEvaluator $evaluator` | `ProjectionPipeline $pipeline` |

Copy lines 1-79 wholesale, change the three constructor args, change `uniqueId()` to the 3-part sentinel-bearing key, change the handle() collaborator.

---

### `Modules/Forecasting/Internal/Listeners/ProjectForecastOnRecurringChange.php` (listener, event-driven)

**Analog:** `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` (entire file, lines 1-35)

**Single-event shape — Phase 10 needs three listeners because it subscribes to FOUR Phase 8 events + ONE Phase 9 event + THREE Phase 10 internal events. Pattern is identical per listener but the `handle()` method's $event type widens to a union via either (a) three separate `handle()` overloads with PHPDoc unions, or (b) one method-per-event-class on the same listener (Laravel `[Listener::class, 'handleApproved']`).**

**Verbatim shape (lines 24-35):**

```php
final readonly class ProjectForecastOnRecurringChange
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(RecurringSeriesApproved|RecurringSeriesCadenceFlipped|RecurringSeriesRejected|RecurringSeriesMetricsRefreshed $event): void
    {
        // Trigger projections for baseline (scenarioId=null) AND every saved scenario × every horizon.
        // The job's ShouldBeUniqueUntilProcessing key collapses concurrent dispatches per (user, scenario, horizon).
        foreach ([30, 60, 90] as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $event->userId,
                scenarioId: null,
                horizonDays: $horizon,
            ));
            // ... fan out to saved scenarios via ScenarioQuery (DI)
        }
    }
}
```

**Listener test pattern** — verbatim from `Modules/DriftAlerts/tests/Unit/EvaluateDriftListenerTest.php` (lines 1-37): `Bus::fake()` + construct event + `$listener->handle($event)` + `Bus::assertDispatchedTimes(...)`. Triple the assertions for the three-horizons-per-user fan-out.

---

### `Modules/Forecasting/Public/Services/ForecastQuery.php` (service, CRUD read)

**Analog:** `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` (entire file, lines 1-285)

**DI shape (lines 49-55) — copy verbatim, swap collaborators:**

```php
final readonly class ForecastQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
        private ChainLinkQuery $chainQuery,
        private CardStatementQuery $cardStatementQuery,
        // The actual projection happens in ProjectForecastJob — this query READS pre-computed
        // forecast_shortfall_windows + a JSON-cached per-(scenario, horizon) projection result.
        // The noSynchronousForecastingInRequestLifecycle arch test enforces this.
    ) {}
    // ...
}
```

**Per-user scope pattern (lines 104-108) — repeat in every method:**

```php
return $this->db->connection()->table('forecast_shortfall_windows')
    ->where('user_id', $user->id)
    // ... filters
    ->get();
```

**DTO hydration delegation (lines 231-247) — mirrors `DriftAlertDtoMapper` pattern.**

---

### `Modules/Forecasting/Public/Actions/SetAccountForecastBuffer.php` (action, CRUD write)

**Analog:** `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` (entire file, lines 1-71)

**Verbatim shape (the cross-user 404 guard is load-bearing):**

```php
final class SetAccountForecastBuffer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $accountId, User $user, ?int $bufferMinor): void
    {
        // Cross-user 404 guard — exact mirror of AcknowledgeDriftAlert lines 40-48
        $account = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first();

        if ($account === null) {
            throw new NotFoundHttpException('Account not found.');
        }

        if ($bufferMinor !== null && $bufferMinor < 0) {
            throw new \InvalidArgumentException('Buffer must be zero or positive.');
        }

        $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->update([
                'forecast_min_buffer_minor' => $bufferMinor,
                'updated_at' => $this->clock->now()->toDateTimeString(),
            ]);

        // No public event required — the projection job is triggered by the
        // Livewire SFC dispatching the existing ScenarioMutated / a per-account
        // re-projection path. (planner picks the exact trigger).
    }
}
```

---

### `Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php` (livewire-sfc, request-response)

**Primary analog:** `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` (entire file, lines 1-162) — for the page lifecycle, `#[Url]`, action dispatch, toast, and `render()`-DI shape.

**Secondary analog:** `Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php` (entire file, lines 1-179) — for the ApexCharts options-building pattern.

**Critical patterns to copy:**

**1. `#[Url]` properties (DriftPage lines 37-42, RESEARCH Code Example lines 869-879):**

```php
#[Url(as: 'account', except: 'all')]
public string $account = 'all';

#[Url(as: 'horizon', except: '30')]
public int $horizon = 30;

#[Url(as: 'scenarioId', except: null)]
public ?int $scenarioId = null;

#[Url(as: 'viewByFunder', except: false)]
public bool $viewByFunder = false;
```

**2. Action handler + toast dispatch (DriftPage lines 54-58):**

```php
public function setHorizon(int $days): void
{
    if (! in_array($days, [30, 60, 90], true)) {
        return;
    }
    $this->horizon = $days;
    $this->dispatch('forecast:updated');   // browser event for the Alpine x-on:forecast-updated.window chart re-render
}
```

**3. Method-parameter DI (DriftPage lines 60-83, 91-97) — Livewire strict-rules ban constructor DI:**

```php
public function addMutation(int $scenarioId, string $kind, array $payload, CurrentUser $currentUser, AddScenarioMutation $action): void
{
    ($action)($scenarioId, $currentUser->user(), $kind, $payload);
    $this->dispatch('toast', message: 'Mutation added');
}

public function render(
    CurrentUser $currentUser,
    ForecastQuery $forecastQuery,
    ScenarioQuery $scenarioQuery,
    ViewFactory $views,
    Clock $clock,
): View {
    // ...
}
```

**4. `extends('layouts.app', ...)` page-rendering shape (DriftPage lines 156-160):**

```php
$view = $views->make('forecasting::livewire.forecast-page', [/* ... */]);
/** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
$view->extends('layouts.app', ['title' => 'Forecast · diederik']);
return $view;
```

**5. ApexCharts options building (RecurringSeriesDetailPage lines 116-173) — adapt for `rangeArea` + `line` combo per RESEARCH Pattern 1. Critical: compute `yMin` / `yMax` server-side as the UNION across both panels (RESEARCH Pitfall 2). The fully-formed `rangeArea` Apex options structure is given in RESEARCH lines 375-455.**

---

### `Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php` (blade-partial, template)

**Primary analog:** `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` (lines 85-114, especially 95-113)

**Verbatim shape (copy lines 95-113):**

```blade
<div
    x-data="{ chart: null }"
    x-init="
        if (! window.ApexCharts) { return; }
        chart = new window.ApexCharts(
            $el.querySelector('#{{ $chartElementId }}'),
            JSON.parse($el.dataset.options),
        );
        chart.render();
    "
    x-on:forecast-updated.window="
        if (! chart) { return; }
        chart.updateOptions(JSON.parse($el.dataset.options), true, false);
    "
    data-options="{{ json_encode($apexOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
>
    <div id="{{ $chartElementId }}"></div>
    <noscript>
        <p class="text-xs text-slate-500">
            Chart requires JavaScript. Range covers {{ $horizonDays }} days.
        </p>
    </noscript>
</div>
```

**Note:** RESEARCH Pattern 2 (lines 519-559) expands this with the `opacity-60 pointer-events-none` overlay for the `wire:poll.2s` Updating state. RESEARCH explicitly warns against `wire:ignore` (lines 762).

---

### `Modules/Forecasting/Internal/Http/Livewire/ForecastHighlightsTile.php` (livewire-sfc, dashboard tile)

**Analog:** `Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php` (entire file; lines 1-38; 38 lines total).

The dashboard "Forecast highlights" tile replaces the Phase 5 `Next ICS settlement` partial. The current Phase 5 tile lives inline in `Modules/Core/Resources/views/livewire/dashboard.blade.php` at lines 56-71 (and is wired via `$nextSettlement` passed from `Modules/Core/Internal/Http/Livewire/Dashboard.php::render()` line 170). The replacement is a Livewire SFC, not an inline partial.

**Per UI-SPEC line 504:** mount via `@livewire('forecasting.forecast-highlights-tile')`.

**Per Pitfall 5:** the existing `Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php` assertions must be PORTED into a new `ForecastHighlightsTileTest` (Phase 5 surface is preserved by the new tile because `ForecastHighlightsQuery` internally calls `CardStatementQuery::nextSettlementForUser` for the next-settlement line).

---

### Blade dashboard.blade.php modification

**Existing surface to replace (lines 56-71 — VERBATIM EXCERPT from existing file):**

```blade
{{-- Status tile row — "Next ICS settlement" + "Email scan health"
@if ((isset($nextSettlement) && $nextSettlement !== null) || (isset($emailScanHealth) && $emailScanHealth !== null))
    {{-- "Next ICS settlement" tile. Hides entirely when
         `$nextSettlement` is null. Border / radius / padding
    @if (isset($nextSettlement) && $nextSettlement !== null)
        <p class="text-base font-semibold text-slate-900">Next ICS settlement</p>
        {{ $nextSettlement->amount->format('nl_NL') }}
        <p class="mt-1 text-xs text-slate-500">due ~{{ $nextSettlement->dueDate->format('d M') }}</p>
```

**Phase 10 replaces the entire `@if ($nextSettlement)` ... `@endif` block with a single mount:**

```blade
@livewire('forecasting.forecast-highlights-tile')
```

`Dashboard.php::render()` line 170 (`$nextSettlement = $glance->nextIcsSettlement($user);`) becomes dead code and must be removed. The companion variable pass-through at line 205 (`'nextSettlement' => $nextSettlement,`) also goes.

---

### BoundaryArchTest extensions

**Analog file:** `tests/Contracts/BoundaryArchTest.php` (entire file, lines 1-760)

**Pattern A — native `arch()` (Pest plugin):** Copy the existing module rule at lines 44-46 verbatim and adapt:

```php
arch('Modules\\Forecasting\\Internal is only used inside Modules\\Forecasting')
    ->expect('Modules\\Forecasting\\Internal')
    ->toOnlyBeUsedIn('Modules\\Forecasting');
```

**Pattern B — facade carve-out (lines 91-99):** Add `'Modules\\Forecasting\\Internal\\Jobs\\ProjectForecastJob'` to the `->ignoring([...])` list.

**Pattern C — synchronous-projection guard (lines 121-126 precedent):**

```php
arch('ProjectionPipeline is never imported by Modules\\Forecasting\\Internal\\Http (noSynchronousForecastingInRequestLifecycle)')
    ->expect('Modules\\Forecasting\\Internal\\Pipeline\\ProjectionPipeline')
    ->not->toBeUsedIn([
        'Modules\\Forecasting\\Internal\\Http',
        'Modules\\Forecasting\\Resources',
    ]);
```

**Pattern D — content-grep `it(...)` test (lines 534-581 `noTransactionWritesFromRecurring` precedent):** Copy lines 534-581 verbatim, swap `'Modules/Recurring'` for `'Modules/Forecasting'` and the error message. Optionally widen the regex to also block writes to `recurring_series`, `card_statements`, `chain_links`, `drift_alerts` (CONTEXT D-1015 §2):

```php
it('does not allow any file under Modules/Forecasting/ to mutate transactions / recurring_series / card_statements / chain_links / drift_alerts tables (noTransactionWritesFromForecasting)', function (): void {
    // ... iterator + comment-strip identical to lines 542-568
    // grep widens:
    if (
        preg_match('/Transaction::query|Transaction::where|Transaction::create|RecurringSeries::query|RecurringSeries::create/', $stripped) === 1
        || preg_match("/->table\(['\"](transactions|recurring_series|card_statements|chain_links|drift_alerts)['\"]\)[^;]*->(update|insert|delete)\\s*\\(/", $stripped) === 1
    ) {
        $hits[] = $path;
    }
    // ...
});
```

**Pattern E — load-bearing FCT-03 invariant (RESEARCH Pattern 5, the example at lines 718-755):** Copy verbatim from RESEARCH.md. This is the single most important new invariant in Phase 10.

---

### `tests/Contracts/ForecastingProjectionContractTest.php` (test, end-to-end)

**Primary analog:** `tests/Contracts/RecurringDetectionContractTest.php` (228 lines).
**Secondary analog:** `tests/Contracts/DriftDetectionContractTest.php` (388 lines).

Use Pest dataset feature with `Modules/Forecasting/tests/fixtures/synthesised/*.php` files. The Wave 0 fixture corpus is enumerated in CONTEXT D-1018; mirror the synthesised-fixture layout from `Modules/Recurring/tests/fixtures/synthesised/` (12 fixture files there).

---

## Shared Patterns

### Shared #1: DI-only constructor / method-parameter injection

**Source:** Every Public service and Action in `Modules/DriftAlerts/Public/` and `Modules/Recurring/Public/`.
**Apply to:** All Phase 10 Public Actions, Services, Internal Pipeline classes.
**Rule of thumb:** Services / Actions / Listeners use constructor DI (`final readonly class X { public function __construct(private DatabaseManager $db, ...) {} }`). Livewire `Component` subclasses use method-parameter DI (strict-rules ban constructor DI on Livewire components). Migrations use the `private ?DatabaseManager $resolvedDb` + `Container::getInstance()->make(...)` lazy resolver shape.

**Verbatim excerpt:** `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` lines 49-55.

### Shared #2: Cross-user 404 guard

**Source:** `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` (lines 40-48).
**Apply to:** Every Phase 10 Public Action (`CreateScenario`, `RenameScenario`, `DeleteScenario`, `AddScenarioMutation`, `RemoveScenarioMutation`, `SetAccountForecastBuffer`, `SetAccountOpeningBalance`) AND every Livewire mount that takes a route-bound id (`/forecast` does not have a route-bound id, but `ScenarioEditorSidebar::mount(int $scenarioId, ...)` does).

```php
$entity = Model::query()
    ->where('id', $entityId)
    ->where('user_id', $user->id)
    ->first();

if ($entity === null) {
    throw new NotFoundHttpException('{Entity} not found.');
}
```

### Shared #3: `ShouldBeUniqueUntilProcessing` job with Cache facade carve-out

**Source:** `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php` (entire file, lines 1-79).
**Apply to:** `Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php`.
**Critical:** the unique key uses `'baseline'` sentinel for null scenarioId (RESEARCH Pitfall 1). The class FQN must be added to `BoundaryArchTest.php`'s facade-ignore list (lines 92-99).

### Shared #4: View Factory composer for top-nav badge

**Source:** `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` lines 113-136 (with memo cache).
**Apply to:** The new `Forecast` top-nav slot in `ForecastingServiceProvider::boot()`.
**Variant** for the dashboard tile (which is now a Livewire component, not a composer): use `@livewire('forecasting.forecast-highlights-tile')` in `dashboard.blade.php` — NO composer needed (analog: how `dashboard-drift-badge` is mounted, per `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` line 75 + the dashboard.blade reference).

### Shared #5: ApexCharts initialization via Alpine `x-data` + `x-init`

**Source:** `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` lines 95-113.
**Apply to:** Both `range-area-chart.blade.php` partials (per-account band) AND `aggregate-line-chart.blade.php` (All-accounts tab).
**Critical:** No `wire:ignore` on the chart container (RESEARCH Anti-Pattern + Pattern 2). Re-render is via `x-on:forecast-updated.window` → `chart.updateOptions(newOptions, true, false)`.

### Shared #6: Pest unit-test "listener emits one job per event" pattern

**Source:** `Modules/DriftAlerts/tests/Unit/EvaluateDriftListenerTest.php` (entire file, lines 1-37).
**Apply to:** `EvaluateForecastListenerTest.php` (× 3 listeners — Recurring, Drift, Scenario).
**Critical mutation for Phase 10:** the assert count must reflect the per-listener fan-out (per CONTEXT D-1017: baseline + every saved scenario × every horizon). For a user with N saved scenarios, one inbound event → `(N + 1) × 3` `ProjectForecastJob` dispatches.

### Shared #7: Module-local TestCase + Pest registration (three-step PSR-4)

**Source:** `Modules/DriftAlerts/tests/TestCase.php` + `Modules/DriftAlerts/tests/Pest.php` + `tests/Pest.php` foreach entry (line 26) + `composer.json` autoload-dev entry (line 58) + `phpunit.xml` Unit/Feature/Contracts directories (lines 15, 27, 45-46).
**Apply to:** `Modules/Forecasting/tests/TestCase.php` + `Modules/Forecasting/tests/Pest.php` + the four registration files (`tests/Pest.php`, `composer.json`, `phpunit.xml`).

### Shared #8: Eloquent JSON cast for typed-union DTO

**Source:** `Modules/Chains/Models/ChainLink.php` lines 60-67 (`'evidence' => 'array'`) — BUT Phase 10's `forecast_scenario_mutations.payload` MUST use a typed union via Spatie LaravelData (RESEARCH Don't Hand-Roll line 780 + Pitfall 4 line 821-829).
**Apply to:** `Modules/Forecasting/Models/ForecastScenarioMutation.php`.
**Pattern:** Define one DTO per `kind` (`CancelSeriesPayload`, `AddOneOffPayload`, `AddRecurringPayload`, `ChangeSeriesAmountPayload`, `ShiftSeriesDatePayload`) and a union `ScenarioMutationPayload`. Map via Eloquent's custom cast — LaravelData ships a `castAttribute` integration. Larastan level 10 catches cross-kind property access at static-analysis time.

### Shared #9: Wave-0 synthesised-fixture corpus

**Source:** `Modules/Recurring/tests/fixtures/synthesised/` (12 fixture files, e.g., `stable-monthly-spotify.php`, `drifting-monthly-spotify.php`, `quarterly-insurance.php`) + `Modules/DriftAlerts/tests/fixtures/drift-corpus/` (3 fixture files).
**Apply to:** `Modules/Forecasting/tests/fixtures/forecast-corpus/` per CONTEXT D-1018's 10-scenario enumeration.
**Convention:** Each fixture is a `.php` file returning an array of input rows + an array of expected outputs. The `ForecastingProjectionContractTest` runs Pest's `->with([fixture path list])` dataset over the corpus.

### Shared #10: Scheduled-task closure DI (no facades)

**Source:** `routes/console.php` lines 30-58 (`email-scan.incremental`) and 63-78 (`email-scan.discovery`).
**Apply to:** The new `forecasting.daily-sweep` scheduled task. Mirror the closure signature `function (DatabaseManager $db, Dispatcher $bus): void {...}` — `Schedule::call(...)` is in `routes/console.php` (outside the `Modules\` namespace, so the facade-ban arch test does not apply).

---

## No Analog Found

| File | Role | Data Flow | Reason | Recommended Approach |
|------|------|-----------|--------|---------------------|
| (none — Phase 10 is 100% composition) | — | — | Every primitive has a project precedent or is in the locked stack. | Refer to RESEARCH.md for the math (quadrature daily fold, P10/P50/P90 R-7 interpolation) and to the analogs cited above for every code shape. |

The closest thing to "no analog" is the **quadrature daily-fold math** in `RangeProjector::dailyFold()` — but RESEARCH Pattern 4 (lines 639-697) provides the full PHP implementation with math citation. The planner copies that body verbatim.

---

## Metadata

**Analog search scope:**
- `Modules/DriftAlerts/` (Phase 9 — primary skeletal analog; entire module read)
- `Modules/Recurring/` (Phase 8 — chart + detail page + actions analog)
- `Modules/Chains/` (Phase 5 — chain routing + dashboard tile + JSON cast analog)
- `Modules/Core/` (existing dashboard + settings extension targets)
- `tests/Contracts/` (BoundaryArchTest + ContractTest precedents)
- `composer.json` + `phpunit.xml` + `tests/Pest.php` + `routes/console.php` (registration patterns)

**Files scanned:** ~95 PHP + Blade files across the six analog modules.

**Key patterns identified (cheat-sheet for the planner):**
- DriftAlerts module = the canonical Phase 10 skeleton. ServiceProvider, top-nav composer, dashboard tile, listener, job, Public surface — all already-blessed-by-CI.
- The RecurringSeriesDetailPage Blade is the ONLY existing ApexCharts integration; the chart-options-builder shape is non-trivial and worth copying line-for-line into ForecastPage.
- The `noScenarioMutationsJoinedToTransactionQueries` arch test is load-bearing FCT-03; RESEARCH.md provides the full implementation.
- The Phase 5 `Next ICS settlement` tile is removed inline-in-dashboard.blade and replaced with a Livewire-mounted tile; the existing `NextIcsSettlementTileTest` assertions PORT verbatim into the new test (Pitfall 5 — do NOT just delete).
- The cross-module column-add migration goes in Forecasting's dir per CONTEXT D-1014 (deviates from the Recurring `add_drift_threshold_percent` precedent which lives in Recurring's dir — body identical, location differs).

**Pattern extraction date:** 2026-05-18
