<?php

declare(strict_types=1);

namespace Modules\Anomaly\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Detectors\DuplicateChargeDetector;
use Modules\Anomaly\Internal\Detectors\FirstTimeMerchantDetector;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Internal\Services\BusAnomalyDetectionDispatcher;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Anomaly\Public\Contracts\DispatchesAnomalyDetection;
use Modules\Anomaly\Public\Http\Livewire\AnomalySettingsSection;
use Modules\Anomaly\Public\Http\Livewire\DashboardAnomalyBadge;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Anomaly\Public\Services\AnomalySuppressionRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\LoadsModuleResources;

final class AnomalyServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(DispatchesAnomalyDetection::class, BusAnomalyDetectionDispatcher::class);

        $this->app->singleton(AnomalyAlertStateMachine::class);

        $this->app->singleton(LargeVsTypicalDetector::class);
        $this->app->singleton(FirstTimeMerchantDetector::class);
        $this->app->singleton(DuplicateChargeDetector::class);
        $this->app->singleton(AnomalyEvaluator::class);

        $this->app->singleton(AnomalyAlertQuery::class);
        $this->app->singleton(AnomalySuppressionRuleQuery::class);

        $this->app->singleton(AcknowledgeAnomalyAlert::class);
        $this->app->singleton(SnoozeAnomalyAlert::class);
        $this->app->singleton(DismissAnomalyAlert::class);

        $this->app->singleton(DismissAnomalyAlertAsExpected::class);
        $this->app->singleton(RemoveAnomalySuppressionRule::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadModuleResources('anomaly');

        $livewire->component('anomaly.dashboard-anomaly-badge', DashboardAnomalyBadge::class);
        $livewire->component('anomaly.settings-section', AnomalySettingsSection::class);

        $this->registerNavBadgeComposer();

    }

    // The per-boot `$cache` collapses repeated sidebar renders in one boot
    // cycle down to a single COUNT query.
    private function registerNavBadgeComposer(): void
    {
        $app = $this->app;
        $factory = $app->make(ViewFactoryContract::class);

        /** @var array<int, int> $cache */
        $cache = [];

        $factory->composer('shell::livewire.app-sidebar', static function (View $compose) use ($app, &$cache): void {
            $currentUser = $app->make(CurrentUser::class);

            /** @var array<string, int> $navCounts */
            $navCounts = (array) ($compose->getData()['navCounts'] ?? []);

            if (! $currentUser->isAuthenticated()) {
                $navCounts['anomaly'] = 0;
                $compose->with('navCounts', $navCounts);

                return;
            }

            $user = $currentUser->user();
            $userId = $user->id;
            if (! array_key_exists($userId, $cache)) {
                $query = $app->make(AnomalyAlertQuery::class);
                $cache[$userId] = $query->openCountForUser($user);
            }

            $navCounts['anomaly'] = $cache[$userId];
            $compose->with('navCounts', $navCounts);
        });
    }
}
