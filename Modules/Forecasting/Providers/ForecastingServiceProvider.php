<?php

declare(strict_types=1);

namespace Modules\Forecasting\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Forecasting\Internal\Http\Livewire\AccountBufferEditor;
use Modules\Forecasting\Internal\Http\Livewire\ForecastHighlightsTile;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Internal\Http\Livewire\ScenarioEditorSidebar;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnDriftDismissed;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnRecurringChange;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnScenarioChange;
use Modules\Forecasting\Internal\Mapping\ForecastDtoMapper;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Modules\Forecasting\Internal\Pipeline\ChainAwareForecastRouter;
use Modules\Forecasting\Internal\Pipeline\DailyFold;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Internal\Pipeline\RangeProjector;
use Modules\Forecasting\Internal\Pipeline\ScenarioApplier;
use Modules\Forecasting\Internal\Pipeline\ShortfallDetector;
use Modules\Forecasting\Internal\StateMachines\ForecastRunStateMachine;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Actions\EditScenarioMutation;
use Modules\Forecasting\Public\Actions\RemoveScenarioMutation;
use Modules\Forecasting\Public\Actions\RenameScenario;
use Modules\Forecasting\Public\Actions\SetAccountForecastBuffer;
use Modules\Forecasting\Public\Events\ScenarioCreated;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Modules\Forecasting\Public\Events\ScenarioMutated;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

/**
 * Wires the Forecasting module.
 *
 * register() binds the in-tree event listeners, the projection
 * pipeline collaborators (anchor resolver, range projector, daily
 * fold, pipeline orchestrator, state machine), and the Public read
 * services (ForecastQuery, ScenarioQuery, ForecastDtoMapper) as
 * singletons.
 *
 * boot() conditionally loads migrations, routes, and views (each
 * guard remains for forward compatibility with module surfaces added
 * by later phases), registers the /forecast Livewire SFC, wires the
 * cross-module event listeners to the Recurring (Phase 8) + DriftAlerts
 * (Phase 9) Public events, and installs the top-nav badge composer.
 * The badge composer injects `forecastShortfallCount` into
 * `core::livewire.top-nav` through the ViewFactoryContract — the
 * global `view()` helper is forbidden by project convention.
 *
 * The `/forecast` SFC renders the baseline-only surface: per-account
 * tab bar, 30 / 60 / 90 horizon segmented control, and a single
 * rangeArea chart for the user's first per-account projection.
 * Subsequent phases extend the surface with the shortfall band
 * overlay, the buffer editor trigger, the scenario picker and
 * side-by-side, and the percentile-tier confidence legend.
 */
final class ForecastingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProjectForecastOnRecurringChange::class);
        $this->app->singleton(ProjectForecastOnDriftDismissed::class);
        $this->app->singleton(ProjectForecastOnScenarioChange::class);

        // Wave 2 projection pipeline + state machine. The job itself is
        // constructor-positional (userId, scenarioId, horizonDays) so it
        // is dispatched, not container-resolved — no singleton entry.
        $this->app->singleton(BalanceAnchorResolver::class);
        $this->app->singleton(RangeProjector::class);
        $this->app->singleton(DailyFold::class);
        $this->app->singleton(ProjectionPipeline::class);
        $this->app->singleton(ForecastRunStateMachine::class);

        // Wave 3 chain-aware routing + shortfall detection.
        $this->app->singleton(ChainAwareForecastRouter::class);
        $this->app->singleton(ShortfallDetector::class);

        // Wave 4 scenario applier (in-memory transform on top of the
        // baseline routed contributions — the FCT-03 boundary).
        $this->app->singleton(ScenarioApplier::class);

        // Wave 3 Public Action + read service for the buffer editor and
        // the dashboard / top-nav surfaces.
        $this->app->singleton(SetAccountForecastBuffer::class);
        $this->app->singleton(ForecastHighlightsQuery::class);

        // Wave 4 scenario CRUD Public Actions.
        $this->app->singleton(CreateScenario::class);
        $this->app->singleton(RenameScenario::class);
        $this->app->singleton(DeleteScenario::class);
        $this->app->singleton(AddScenarioMutation::class);
        $this->app->singleton(RemoveScenarioMutation::class);
        $this->app->singleton(EditScenarioMutation::class);

        // Wave 3 dashboard tile Livewire SFC (singleton-bound so it
        // resolves once per request).
        $this->app->singleton(ForecastHighlightsTile::class);

        // Wave 2 Public read API + DTO mapper.
        $this->app->singleton(ForecastDtoMapper::class);
        $this->app->singleton(ForecastQuery::class);
        $this->app->singleton(ScenarioQuery::class);
    }

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
        $livewire->component('forecasting.account-buffer-editor', AccountBufferEditor::class);
        $livewire->component('forecasting.forecast-highlights-tile', ForecastHighlightsTile::class);
        $livewire->component('forecasting.scenario-editor-sidebar', ScenarioEditorSidebar::class);

        $this->registerListeners($events);
        $this->registerTopNavBadgeComposer();
    }

    /**
     * Subscribe the Forecasting projection listeners to the upstream
     * Recurring (Phase 8) and DriftAlerts (Phase 9) Public events. Each
     * listener handle() fans the event out into one queued
     * ProjectForecastJob per (user, scenario, horizon) tuple when the
     * later wave swaps the scaffold bodies in.
     *
     * The scenario-side internal events (created, mutated, deleted) are
     * wired in a later wave once those event classes land. The
     * ProjectForecastOnScenarioChange listener scaffold is already
     * singleton-bound in register() so the swap-in is a single
     * $events->listen() line per event.
     */
    private function registerListeners(Dispatcher $events): void
    {
        $events->listen(RecurringSeriesApproved::class, [ProjectForecastOnRecurringChange::class, 'handle']);
        $events->listen(RecurringSeriesCadenceFlipped::class, [ProjectForecastOnRecurringChange::class, 'handle']);
        $events->listen(RecurringSeriesRejected::class, [ProjectForecastOnRecurringChange::class, 'handle']);
        $events->listen(RecurringSeriesMetricsRefreshed::class, [ProjectForecastOnRecurringChange::class, 'handle']);

        $events->listen(DriftAlertDismissedCancelled::class, [ProjectForecastOnDriftDismissed::class, 'handle']);

        // Wave 4 scenario lifecycle events. Each event fans out into
        // baseline + affected-scenario projection horizons via the
        // ProjectForecastOnScenarioChange listener.
        $events->listen(ScenarioCreated::class, [ProjectForecastOnScenarioChange::class, 'handle']);
        $events->listen(ScenarioMutated::class, [ProjectForecastOnScenarioChange::class, 'handle']);
        $events->listen(ScenarioDeleted::class, [ProjectForecastOnScenarioChange::class, 'handle']);
    }

    /**
     * Inject the top-nav `forecastShortfallCount` into
     * `core::livewire.top-nav` via the View Factory contract.
     *
     * The contract is resolved through `$this->app->make()` to keep the
     * DI-only invariant visible at the call site; the global `view()`
     * helper is forbidden in module code.
     *
     * The composer fires once per top-nav render. A later wave swaps
     * the closure body to read the per-user active-shortfall count from
     * the Forecasting Public services with a boot-scoped memo (same
     * pattern as the DriftAlerts top-nav composer). For now the badge
     * is always zero — the slot is reserved so the swap-in is a
     * body-only change.
     */
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
}
