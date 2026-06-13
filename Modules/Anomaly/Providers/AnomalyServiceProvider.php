<?php

declare(strict_types=1);

namespace Modules\Anomaly\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Detectors\DuplicateChargeDetector;
use Modules\Anomaly\Internal\Detectors\FirstTimeMerchantDetector;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Internal\Listeners\EvaluateAnomaliesOnTransactionImport;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Anomaly\Public\Services\AnomalySuppressionRuleQuery;
use Modules\Import\Public\Events\TransactionImported;

/**
 * Wires the Anomaly module.
 *
 * register() binds the stateless in-tree services as singletons. In this
 * wave only the sole-mutator state machine exists; the Public queries /
 * actions, the detector, the queued jobs, and the Livewire surface land
 * in later plans (02 evaluator, 03 read/write surface, 04 jobs, 05 UI)
 * and register their own singletons / components here as they arrive.
 *
 * boot() conditionally loads the module's migrations, routes, and views
 * and (Plan 04) registers the synchronous TransactionImported listener
 * that QUEUES a unique DetectAnomaliesJob — detection runs on the queue,
 * never inline in the import transaction (D-12 / T-09-14). The top-nav
 * anomaly badge composer and the Livewire component registrations remain
 * Plan 05 TODO stubs.
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

        // Dismiss-as-expected (creates ±15% suppression rules, D-17) and
        // the two-path rule removal (settings delete + undo re-open, D-18).
        $this->app->singleton(DismissAnomalyAlertAsExpected::class);
        $this->app->singleton(RemoveAnomalySuppressionRule::class);
    }

    public function boot(Dispatcher $events): void
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

        // Plan 04: queue a unique DetectAnomaliesJob on every import (D-12).
        // The listener stays synchronous but only DISPATCHES the job — the
        // baseline math runs on the queue, never in the import transaction
        // (T-09-14). class_exists-guarded so the skeleton waves cannot wire
        // detection before the evaluator + job exist (mirrors the
        // SearchServiceProvider TransactionImported registration shape).
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
        // TODO(Plan 05): register the Livewire SFCs (anomaly section of the
        //   /drift alerts home, dashboard anomaly badge) + the top-nav
        //   anomaly open-count badge view composer.
    }
}
