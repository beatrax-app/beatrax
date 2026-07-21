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

// Queued jobs and Livewire components are intentionally NOT bound as
// singletons — both are instantiated per-use and bypass the container's
// singleton cache by design.

// The dashboard drift badge renders via @livewire() directly, so this
// provider registers no composer for it.
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

    // Subscribes the drift detector to Recurring's per-series
    // metrics-refreshed event; the listener queues a
    // ShouldBeUniqueUntilProcessing DetectDriftAlertsJob so concurrent
    // triggers collapse safely.
    private function registerListener(Dispatcher $events): void
    {
        $events->listen(
            RecurringSeriesMetricsRefreshed::class,
            EvaluateDriftOnMetricsRefreshed::class,
        );
    }

    // Resolved through $this->app->make() to keep the DI-only invariant
    // visible; the global view() helper is forbidden. The $cache array
    // captured by reference collapses repeated renders within a single
    // boot cycle to one COUNT query per user.
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
