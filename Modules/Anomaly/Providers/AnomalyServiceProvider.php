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
use Modules\Anomaly\Internal\Http\Livewire\AnomalySettingsSection;
use Modules\Anomaly\Internal\Http\Livewire\DashboardAnomalyBadge;
use Modules\Anomaly\Internal\Listeners\EvaluateAnomaliesOnTransactionImport;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Anomaly\Public\Services\AnomalySuppressionRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Public\Events\TransactionImported;

/**
 * @link ../../../.docs/features/anomaly/architecture.md
 */
final class AnomalyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnomalyAlertStateMachine::class);

        // Every binding below is a singleton: each collaborator is
        // stateless and depends only on other singleton bindings
        // (DatabaseManager / Clock / the Public query services).
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
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'anomaly');
        }

        $livewire->component('anomaly.dashboard-anomaly-badge', DashboardAnomalyBadge::class);
        $livewire->component('anomaly.settings-section', AnomalySettingsSection::class);

        $this->registerNavBadgeComposer();

        // The listener stays synchronous but only DISPATCHES the job — the
        // baseline math runs on the queue, never in the import transaction.
        // class_exists-guarded so a partially-booted module never wires
        // detection before the evaluator + job exist.
        if (class_exists(EvaluateAnomaliesOnTransactionImport::class)) {
            $events->listen(
                TransactionImported::class,
                [EvaluateAnomaliesOnTransactionImport::class, 'handle'],
            );
        }

        // The scheduled safety-net sweep + snooze-revival sweep are
        // registered in routes/console.php (anomaly.safety-net-sweep,
        // anomaly.revive-snoozes); the full-history backfill is dispatched
        // on first activation (Plan 05 settings toggle).
    }

    // Injects the anomaly open-alert count into the sidebar's existing
    // `navCounts` map via the View Factory contract (the global `view()`
    // helper is forbidden in module code). A boot-scoped memo collapses
    // repeated renders within a single boot cycle to a single COUNT query.
    private function registerNavBadgeComposer(): void
    {
        $app = $this->app;
        $factory = $app->make(ViewFactoryContract::class);

        /** @var array<int, int> $cache */
        $cache = [];

        $factory->composer('core::livewire.app-sidebar', static function (View $compose) use ($app, &$cache): void {
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
