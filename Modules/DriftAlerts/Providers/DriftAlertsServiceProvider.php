<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Internal\Http\Livewire\DashboardDriftBadge;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftThresholdEditor;
use Modules\DriftAlerts\Internal\Http\Livewire\SavingsInsightsCard;
use Modules\DriftAlerts\Internal\Http\Livewire\SubscriptionDriftWatchPage;
use Modules\DriftAlerts\Internal\Listeners\EvaluateDriftOnMetricsRefreshed;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Services\CancellationImpactQuery;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\DriftAlerts\Public\Services\SubscriptionDriftWatchQuery;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;

/**
 * Wires the Drift Alerts module.
 *
 * register() binds the stateless in-tree services as singletons (state
 * machine, detector, queries, Public Actions). Queued jobs and Livewire
 * components are intentionally NOT bound as singletons — jobs are
 * serialised/unserialised by the queue worker and Livewire components
 * are instantiated per-request by `LivewireManager`, so both bypass the
 * container's singleton cache by design.
 *
 * boot() conditionally loads the module's migrations, routes, and views,
 * registers the three Livewire SFCs (DriftPage, DashboardDriftBadge,
 * DriftThresholdEditor), wires the detector listener to the Recurring
 * metrics-refreshed event, and installs the top-nav badge composer via
 * `registerTopNavBadgeComposer()`. The badge composer injects
 * `driftOpenCount` into `core::livewire.top-nav` through the
 * ViewFactoryContract — the global `view()` helper is forbidden by
 * project convention.
 *
 * The dashboard drift count badge is rendered directly via the
 * `@livewire('drift-alerts.dashboard-drift-badge')` directive on the
 * dashboard view, so the provider does not register a composer for it.
 */
final class DriftAlertsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DriftAlertStateMachine::class);
        $this->app->singleton(DriftEvaluator::class);
        $this->app->singleton(DriftAlertQuery::class);
        $this->app->singleton(CancellationImpactQuery::class);
        $this->app->singleton(SubscriptionDriftWatchQuery::class);
        $this->app->singleton(SavingsInsightsQuery::class);
        $this->app->singleton(AcknowledgeDriftAlert::class);
        $this->app->singleton(SnoozeDriftAlert::class);
        $this->app->singleton(DismissDriftAlertAsCancelled::class);
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
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'drift-alerts');
        }

        $livewire->component('drift-alerts.drift-page', DriftPage::class);
        $livewire->component('drift-alerts.subscription-drift-watch-page', SubscriptionDriftWatchPage::class);
        $livewire->component('drift-alerts.savings-insights-card', SavingsInsightsCard::class);
        $livewire->component('drift-alerts.dashboard-drift-badge', DashboardDriftBadge::class);
        $livewire->component('drift-alerts.drift-threshold-editor', DriftThresholdEditor::class);

        $this->registerListener($events);
        $this->registerTopNavBadgeComposer();
    }

    /**
     * Subscribe the drift detector to the Recurring module's
     * per-series metrics-refreshed event. The listener queues the
     * `DetectDriftAlertsJob` for the affected `(user, series)` pair;
     * the job is `ShouldBeUniqueUntilProcessing` so concurrent
     * triggers collapse safely.
     */
    private function registerListener(Dispatcher $events): void
    {
        $events->listen(
            RecurringSeriesMetricsRefreshed::class,
            EvaluateDriftOnMetricsRefreshed::class,
        );
    }

    /**
     * Inject the top-nav `Drift` open-alert count into
     * `core::livewire.top-nav` via the View Factory contract.
     *
     * The contract is resolved through `$this->app->make()` to keep
     * the DI-only invariant visible at the call site; the global
     * `view()` helper is forbidden in module code.
     *
     * The composer fires once per top-nav render. A boot-scoped memo
     * (the `$cache` array captured by reference inside the closure)
     * collapses repeated renders within a single boot cycle to a
     * single COUNT query — relevant when a Livewire roundtrip
     * re-emits the nav as part of the same response. Under traditional
     * FPM, `boot()` runs once per request, so the memo is effectively
     * request-scoped.
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
                $compose->with('driftOpenCount', 0);

                return;
            }
            $user = $currentUser->user();
            $userId = $user->id;
            if (! array_key_exists($userId, $cache)) {
                $query = $app->make(DriftAlertQuery::class);
                $cache[$userId] = $query->openCountForUser($user);
            }
            $compose->with('driftOpenCount', $cache[$userId]);
        });
    }
}
