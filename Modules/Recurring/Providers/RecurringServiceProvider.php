<?php

declare(strict_types=1);

namespace Modules\Recurring\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Actions\EditRecurringSeriesName;
use Modules\Recurring\Public\Actions\RejectRecurringSeries;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;
use Modules\Recurring\Public\Actions\UnRejectRecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * Wires the Recurring module.
 *
 * register() binds every in-tree service as a singleton (state
 * machine, detectors, queries, Public Actions, sweep job) and tags
 * the concrete detectors under `recurring.detector` so the sweep
 * job receives them via iterable injection on `handle()`.
 *
 * boot() conditionally loads the module's migrations, routes, and
 * views and registers the `/recurring/review` Livewire SFC.
 */
final class RecurringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecurringSeriesStateMachine::class);
        $this->app->singleton(CadenceInferrer::class);
        $this->app->singleton(ClusterKeyComposer::class);
        $this->app->singleton(ExpenseSeriesDetector::class);
        $this->app->singleton(IncomeSeriesDetector::class);
        $this->app->singleton(DetectRecurringSeriesJob::class);

        $this->app->tag([
            ExpenseSeriesDetector::class,
            IncomeSeriesDetector::class,
        ], 'recurring.detector');

        $this->app->singleton(RecurringSeriesQuery::class);
        $this->app->singleton(ApproveRecurringSeries::class);
        $this->app->singleton(RejectRecurringSeries::class);
        $this->app->singleton(SnoozeRecurringSeries::class);
        $this->app->singleton(EditRecurringSeriesName::class);
        $this->app->singleton(UnRejectRecurringSeries::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'recurring');
        }

        $livewire->component('recurring.recurring-review-page', RecurringReviewPage::class);
    }
}
