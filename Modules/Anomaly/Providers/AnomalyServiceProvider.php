<?php

declare(strict_types=1);

namespace Modules\Anomaly\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Detectors\DuplicateChargeDetector;
use Modules\Anomaly\Internal\Detectors\FirstTimeMerchantDetector;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Anomaly\Public\Services\AnomalySuppressionRuleQuery;

/**
 * Wires the Anomaly module.
 *
 * register() binds the stateless in-tree services as singletons. In this
 * wave only the sole-mutator state machine exists; the Public queries /
 * actions, the detector, the queued jobs, and the Livewire surface land
 * in later plans (02 evaluator, 03 read/write surface, 04 jobs, 05 UI)
 * and register their own singletons / components here as they arrive.
 *
 * boot() conditionally loads the module's migrations, routes, and views.
 * The TransactionImported listener, the top-nav anomaly badge composer,
 * and the Livewire component registrations are intentionally left as
 * Plan 04/05 TODO stubs — the import-completion hook is NOT wired here so
 * the skeleton cannot fire detection before the evaluator exists.
 */
final class AnomalyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnomalyAlertStateMachine::class);

        // Plan 02: the three stateless detectors + the evaluator that
        // orchestrates them. Singletons because they hold no per-request
        // state (they read the DatabaseManager / Clock / RecurringSeriesQuery
        // bindings, all themselves singletons).
        $this->app->singleton(LargeVsTypicalDetector::class);
        $this->app->singleton(FirstTimeMerchantDetector::class);
        $this->app->singleton(DuplicateChargeDetector::class);
        $this->app->singleton(AnomalyEvaluator::class);

        // Plan 03: the Public read surface. Singletons because they hold no
        // per-request state (they read the DatabaseManager / Clock /
        // CounterpartyProfileQuery bindings, all themselves singletons).
        $this->app->singleton(AnomalyAlertQuery::class);
        $this->app->singleton(AnomalySuppressionRuleQuery::class);

        // The three lifecycle Actions. Singletons (state machine +
        // dispatcher + clock dependencies are all singletons).
        $this->app->singleton(AcknowledgeAnomalyAlert::class);
        $this->app->singleton(SnoozeAnomalyAlert::class);
        $this->app->singleton(DismissAnomalyAlert::class);

        // TODO(Plan 03, Task 3): bind DismissAnomalyAlertAsExpected +
        //   RemoveAnomalySuppressionRule as singletons.
    }

    public function boot(): void
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

        // TODO(Plan 04): subscribe the anomaly evaluator to the Import
        //   module's TransactionImported event + register the scheduled
        //   safety-net sweep and snooze-revival sweep in routes/console.php.
        // TODO(Plan 05): register the Livewire SFCs (anomaly section of the
        //   /drift alerts home, dashboard anomaly badge) + the top-nav
        //   anomaly open-count badge view composer.
    }
}
