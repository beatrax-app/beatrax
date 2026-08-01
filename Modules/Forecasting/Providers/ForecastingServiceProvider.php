<?php

declare(strict_types=1);

namespace Modules\Forecasting\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Forecasting\Internal\Http\Livewire\AccountBufferEditor;
use Modules\Forecasting\Internal\Http\Livewire\ForecastHighlightsTile;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Internal\Http\Livewire\ModelWhatIfDropdown;
use Modules\Forecasting\Internal\Http\Livewire\OpeningBalanceEditor;
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
use Modules\Forecasting\Public\Actions\CreateAmountChangeScenarioForSeries;
use Modules\Forecasting\Public\Actions\CreateCancellationScenarioForAlert;
use Modules\Forecasting\Public\Actions\CreateCancellationScenarioForSeries;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Actions\EditScenarioMutation;
use Modules\Forecasting\Public\Actions\RemoveScenarioMutation;
use Modules\Forecasting\Public\Actions\RenameScenario;
use Modules\Forecasting\Public\Actions\SetAccountForecastBuffer;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Forecasting\Public\Events\ScenarioCreated;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Modules\Forecasting\Public\Events\ScenarioMutated;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

/**
 * @link ../../../.docs/features/forecasting/architecture.md
 */
final class ForecastingServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(ProjectForecastOnRecurringChange::class);
        $this->app->singleton(ProjectForecastOnDriftDismissed::class);
        $this->app->singleton(ProjectForecastOnScenarioChange::class);

        // The job itself is constructor-positional (userId, scenarioId,
        // horizonDays) so it is dispatched, not container-resolved — no
        // singleton entry.
        $this->app->singleton(BalanceAnchorResolver::class);
        $this->app->singleton(NetWorthQuery::class);
        $this->app->singleton(RangeProjector::class);
        $this->app->singleton(DailyFold::class);
        $this->app->singleton(ProjectionPipeline::class);
        $this->app->singleton(ForecastRunStateMachine::class);

        $this->app->singleton(ChainAwareForecastRouter::class);
        $this->app->singleton(ShortfallDetector::class);

        // Scenario applier (in-memory transform on top of the baseline
        // routed contributions — the scenario-isolation boundary).
        $this->app->singleton(ScenarioApplier::class);

        $this->app->singleton(SetAccountForecastBuffer::class);
        $this->app->singleton(ForecastHighlightsQuery::class);

        $this->app->singleton(SetAccountOpeningBalance::class);

        $this->app->singleton(CreateScenario::class);
        $this->app->singleton(RenameScenario::class);
        $this->app->singleton(DeleteScenario::class);
        $this->app->singleton(AddScenarioMutation::class);
        $this->app->singleton(RemoveScenarioMutation::class);
        $this->app->singleton(EditScenarioMutation::class);

        // Launchpad Public Actions (atomic CreateScenario +
        // AddScenarioMutation pairs wrapped in a DB transaction).
        $this->app->singleton(CreateCancellationScenarioForAlert::class);
        $this->app->singleton(CreateCancellationScenarioForSeries::class);
        $this->app->singleton(CreateAmountChangeScenarioForSeries::class);

        // Livewire Components are intentionally NOT bound as singletons:
        // the mount/render lifecycle resolves a fresh instance per request,
        // and singleton-binding would leak stale public-property state
        // across requests under a future long-running process (Octane).

        $this->app->singleton(ForecastDtoMapper::class);
        $this->app->singleton(ForecastQuery::class);
        $this->app->singleton(ScenarioQuery::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $this->loadModuleResources('forecasting');

        $livewire->component('forecasting.forecast-page', ForecastPage::class);
        $livewire->component('forecasting.account-buffer-editor', AccountBufferEditor::class);
        $livewire->component('forecasting.forecast-highlights-tile', ForecastHighlightsTile::class);
        $livewire->component('forecasting.scenario-editor-sidebar', ScenarioEditorSidebar::class);
        $livewire->component('forecasting.model-what-if-dropdown', ModelWhatIfDropdown::class);
        $livewire->component('forecasting.opening-balance-editor', OpeningBalanceEditor::class);

        $this->registerListeners($events);
        $this->registerTopNavBadgeComposer();
    }

    private function registerListeners(Dispatcher $events): void
    {
        $events->listen(RecurringSeriesApproved::class, [ProjectForecastOnRecurringChange::class, 'handle']);
        $events->listen(RecurringSeriesCadenceFlipped::class, [ProjectForecastOnRecurringChange::class, 'handle']);
        $events->listen(RecurringSeriesRejected::class, [ProjectForecastOnRecurringChange::class, 'handle']);
        $events->listen(RecurringSeriesMetricsRefreshed::class, [ProjectForecastOnRecurringChange::class, 'handle']);

        $events->listen(DriftAlertDismissedCancelled::class, [ProjectForecastOnDriftDismissed::class, 'handle']);

        // Scenario lifecycle events fan out into baseline +
        // affected-scenario projection horizons via the listener below.
        $events->listen(ScenarioCreated::class, [ProjectForecastOnScenarioChange::class, 'handle']);
        $events->listen(ScenarioMutated::class, [ProjectForecastOnScenarioChange::class, 'handle']);
        $events->listen(ScenarioDeleted::class, [ProjectForecastOnScenarioChange::class, 'handle']);
    }

    // The View Factory contract is resolved through $this->app->make() to
    // keep the DI-only invariant visible at the call site; the global
    // view() helper is forbidden in module code. $cache memoizes the
    // per-user count for the lifetime of this composer's boot scope.
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
