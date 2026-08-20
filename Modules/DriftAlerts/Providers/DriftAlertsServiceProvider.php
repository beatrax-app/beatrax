<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\LoadsModuleResources;
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

final class DriftAlertsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

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
        $this->loadModuleResources('drift-alerts');

        $livewire->component('drift-alerts.drift-page', DriftPage::class);
        $livewire->component('drift-alerts.subscription-drift-watch-page', SubscriptionDriftWatchPage::class);
        $livewire->component('drift-alerts.savings-insights-card', SavingsInsightsCard::class);
        $livewire->component('drift-alerts.dashboard-drift-badge', DashboardDriftBadge::class);
        $livewire->component('drift-alerts.drift-threshold-editor', DriftThresholdEditor::class);

        $this->registerListener($events);
        $this->registerTopNavBadgeComposer();
    }

    private function registerListener(Dispatcher $events): void
    {
        $events->listen(
            RecurringSeriesMetricsRefreshed::class,
            EvaluateDriftOnMetricsRefreshed::class,
        );
    }

    // The by-reference $cache collapses repeated top-nav renders within one boot
    // cycle to a single COUNT per user.
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
