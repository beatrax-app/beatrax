<?php

declare(strict_types=1);

namespace Modules\Recurring\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\Http\Livewire\FixedPaymentsCard;
use Modules\Recurring\Internal\Http\Livewire\RecurringPage;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Internal\Http\Livewire\RecurringSeriesDetailPage;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\Services\BusRecurringDetectionDispatcher;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Actions\EditRecurringSeriesName;
use Modules\Recurring\Public\Actions\EditRecurringSeriesVarianceTolerance;
use Modules\Recurring\Public\Actions\RejectRecurringSeries;
use Modules\Recurring\Public\Actions\SetDriftThresholdForSeries;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;
use Modules\Recurring\Public\Actions\UnRejectRecurringSeries;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Psr\Log\LoggerInterface;

/**
 * Wires the Recurring module.
 *
 * register() binds every in-tree service as a singleton (state
 * machine, detectors, queries, Public Actions, sweep job) and tags
 * the concrete detectors under `recurring.detector` so the sweep
 * job receives them via iterable injection on `handle()`.
 *
 * boot() conditionally loads the module's migrations, routes, and
 * views, registers the four Livewire SFCs (RecurringPage,
 * RecurringReviewPage, RecurringSeriesDetailPage, FixedPaymentsCard),
 * and installs the top-nav badge composer via
 * `registerTopNavBadgeComposer()`. The badge composer injects
 * `recurringPendingCount` into `core::livewire.top-nav` through the
 * ViewFactoryContract — the global `view()` helper is forbidden by
 * project convention.
 *
 * The dashboard fixed-payments card is rendered directly via the
 * `@livewire('recurring.fixed-payments-card')` directive on the
 * dashboard view, so the provider does not register a composer for
 * it.
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

        // Bind DetectRecurringSeriesJob::handle()'s resolution explicitly.
        // The method takes `iterable $detectors`, which Container::call
        // cannot auto-resolve (iterable is the pseudo-type Traversable|
        // array with no class to instantiate; contextual class-bindings
        // do not fire for method calls). Both the sync queue driver
        // (used in tests) and the database queue worker (production)
        // dispatch via Dispatcher::dispatchNow → Container::call, so
        // binding the method here covers every dispatch path.
        //
        // CRYPT-01 (14.1-08): Session/AppLockKeyService/
        // EncryptionMigrationService are resolved here too, so BOTH real
        // dispatch origins (RecurringPage::reDetect()'s dispatchSync AND
        // the routes/console.php daily scheduler's queued dispatch) reach
        // handle() with their true per-run context — a request has an
        // unlocked Session (KEK present); the queue worker's Session was
        // never unlocked (KEK absent). LoggerInterface is resolved here
        // too so the KEK-absence warning actually reaches the log in
        // production (previously unwired — bare `handle()` test callers
        // are unaffected since all four new parameters are nullable).
        $this->app->bindMethod(
            [DetectRecurringSeriesJob::class, 'handle'],
            static function (DetectRecurringSeriesJob $job, Container $c): void {
                /** @var iterable<SeriesDetector> $detectors */
                $detectors = $c->tagged('recurring.detector');
                $job->handle(
                    $c->make(DatabaseManager::class),
                    $c->make(Clock::class),
                    $detectors,
                    $c->make(RecurringSeriesStateMachine::class),
                    $c->make(Session::class),
                    $c->make(AppLockKeyService::class),
                    $c->make(EncryptionMigrationService::class),
                    $c->make(LoggerInterface::class),
                );
            },
        );

        $this->app->singleton(RecurringSeriesQuery::class);
        $this->app->singleton(FixedPaymentsViewQuery::class);
        $this->app->singleton(ApproveRecurringSeries::class);
        $this->app->singleton(RejectRecurringSeries::class);
        $this->app->singleton(SnoozeRecurringSeries::class);
        $this->app->singleton(EditRecurringSeriesName::class);
        $this->app->singleton(EditRecurringSeriesVarianceTolerance::class);
        $this->app->singleton(SetDriftThresholdForSeries::class);
        $this->app->singleton(UnRejectRecurringSeries::class);
        $this->app->singleton(FixedPaymentsCard::class);

        $this->app->singleton(DispatchesRecurringDetection::class, BusRecurringDetectionDispatcher::class);
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

        $livewire->component('recurring.recurring-page', RecurringPage::class);
        $livewire->component('recurring.recurring-review-page', RecurringReviewPage::class);
        $livewire->component('recurring.recurring-series-detail-page', RecurringSeriesDetailPage::class);
        $livewire->component('recurring.fixed-payments-card', FixedPaymentsCard::class);

        $this->registerTopNavBadgeComposer();
    }

    /**
     * Inject the top-nav `Recurring` pending-suggestion count into
     * `core::livewire.top-nav` via the View Factory contract.
     *
     * The contract is resolved through `$this->app->make()` to keep
     * the DI-only invariant visible at the call site; the global
     * `view()` helper is forbidden in module code.
     *
     * The composer only fires when the top-nav view actually renders,
     * meaning at most once per HTTP request that surfaces the nav.
     */
    private function registerTopNavBadgeComposer(): void
    {
        $app = $this->app;
        $factory = $app->make(ViewFactoryContract::class);

        $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
            $currentUser = $app->make(CurrentUser::class);
            if (! $currentUser->isAuthenticated()) {
                $compose->with('recurringPendingCount', 0);

                return;
            }
            $query = $app->make(RecurringSeriesQuery::class);
            $compose->with('recurringPendingCount', $query->pendingCountForUser($currentUser->user()));
        });
    }
}
