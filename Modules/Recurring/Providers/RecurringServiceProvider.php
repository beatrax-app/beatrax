<?php

declare(strict_types=1);

namespace Modules\Recurring\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Core\Public\Support\RegistersScheduledCommands;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Internal\Console\DetectRecurringSeriesCommand;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\Http\Livewire\RecurringPage;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Internal\Http\Livewire\RecurringSeriesDetailPage;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\Queries\RecurringSeriesProjector;
use Modules\Recurring\Internal\Services\BusRecurringDetectionDispatcher;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Http\Livewire\FixedPaymentsCard;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Psr\Log\LoggerInterface;

final class RecurringServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;
    use RegistersScheduledCommands;

    public function register(): void
    {
        $this->app->singleton(CadenceInferrer::class);
        $this->app->singleton(ClusterKeyComposer::class);
        $this->app->singleton(DetectRecurringSeriesJob::class);

        $this->app->tag([
            ExpenseSeriesDetector::class,
            IncomeSeriesDetector::class,
        ], 'recurring.detector');

        // handle() takes `iterable $detectors`, which Container::call cannot
        // auto-resolve, so its resolution is bound explicitly.
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

        $this->app->singleton(RecurringSeriesProjector::class);
        $this->app->singleton(RecurringOccurrenceQuery::class);
        $this->app->singleton(RecurringSeriesQuery::class);
        $this->app->singleton(FixedPaymentsViewQuery::class);
        $this->app->singleton(FixedPaymentsCard::class);

        $this->app->singleton(DispatchesRecurringDetection::class, BusRecurringDetectionDispatcher::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('recurring');

        $this->registerScheduledCommands([DetectRecurringSeriesCommand::class]);

        $livewire->component('recurring.recurring-page', RecurringPage::class);
        $livewire->component('recurring.recurring-review-page', RecurringReviewPage::class);
        $livewire->component('recurring.recurring-series-detail-page', RecurringSeriesDetailPage::class);
        $livewire->component('recurring.fixed-payments-card', FixedPaymentsCard::class);
    }
}
