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
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnDriftDismissed;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnRecurringChange;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnScenarioChange;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

/**
 * Wires the Forecasting module.
 *
 * register() binds the in-tree event listeners as singletons so a single
 * listener instance is reused across the four upstream events the module
 * subscribes to. Heavier services (projection pipeline, jobs, Public
 * queries and actions) are bound by later waves once their classes land;
 * Wave 0 deliberately keeps the container surface minimal.
 *
 * boot() conditionally loads migrations, routes, and views (each guard
 * is necessary because subsequent waves add those directories — Wave 0
 * ships only the Routes file), wires the cross-module event listeners
 * to the Recurring (Phase 8) + DriftAlerts (Phase 9) Public events, and
 * installs the top-nav badge composer. The badge composer injects
 * `forecastShortfallCount` into `core::livewire.top-nav` through the
 * ViewFactoryContract — the global `view()` helper is forbidden by
 * project convention.
 *
 * The `/forecast` Livewire SFC is registered as a placeholder so the
 * route binds and the framework boots; a later wave swaps the body in
 * with the real 30/60/90 horizon switch + per-account chart + scenario
 * CRUD panel. The dashboard highlights component lands in the same
 * later wave.
 */
final class ForecastingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProjectForecastOnRecurringChange::class);
        $this->app->singleton(ProjectForecastOnDriftDismissed::class);
        $this->app->singleton(ProjectForecastOnScenarioChange::class);
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

        $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
            // Reading $app->make(CurrentUser::class) here keeps the
            // closure shape identical to the wave-3 swap-in target so
            // adding the active-shortfall count is a body-only change.
            $currentUser = $app->make(CurrentUser::class);
            if (! $currentUser->isAuthenticated()) {
                $compose->with('forecastShortfallCount', 0);

                return;
            }
            $compose->with('forecastShortfallCount', 0);
        });
    }
}
