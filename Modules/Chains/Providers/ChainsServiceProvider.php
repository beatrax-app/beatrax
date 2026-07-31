<?php

declare(strict_types=1);

namespace Modules\Chains\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Chains\Internal\CardStatementStateMachine;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\ChainTreeWalker;
use Modules\Chains\Internal\Http\Livewire\ChainDrawer;
use Modules\Chains\Internal\Http\Livewire\ChainHintsQueue;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Internal\Http\Livewire\ChainsIndex;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Listeners\CreateChainLinkFromHint;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Services\BusChainResolutionDispatcher;
use Modules\Chains\Internal\Services\CardStatementUpserter;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Receipts\Public\Events\ChainHintDetected;

/**
 * @link ../../../.docs/features/chains/architecture.md
 */
final class ChainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CardStatementStateMachine::class);
        $this->app->singleton(ChainLinkInsertHelper::class);
        $this->app->singleton(IcsSettlementResolver::class);
        $this->app->singleton(PaypalFundingResolver::class);
        $this->app->singleton(ResolveChainLinksJob::class);
        $this->app->singleton(DispatchesChainResolution::class, BusChainResolutionDispatcher::class);
        $this->app->bind(UpsertsCardStatements::class, CardStatementUpserter::class);
        $this->app->singleton(CardStatementUpserter::class);

        // Singleton so the listener shares one DatabaseManager + Clock
        // pair across events.
        $this->app->singleton(CreateChainLinkFromHint::class);

        $this->app->singleton(ChainTreeWalker::class);
        $this->app->singleton(ChainLinkQuery::class);
        $this->app->singleton(CardStatementQuery::class);
        $this->app->singleton(ConfirmChainLink::class);
        $this->app->singleton(RejectChainLink::class);
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
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'chains');
        }
        $livewire->component('chains.chain-drawer', ChainDrawer::class);
        $livewire->component('chains.chain-review-queue', ChainReviewQueue::class);
        $livewire->component('chains.chain-hints-queue', ChainHintsQueue::class);
        $livewire->component('chains.chains-index', ChainsIndex::class);

        $this->registerJobFailedListener($events);
        $this->registerTopNavBadgeComposer();

        // ChainHintDetected is dispatched from RecordReceipt after the
        // canonical transaction is persisted, so the FK constraint on
        // chain_links.from_transaction_id binds cleanly.
        $events->listen(ChainHintDetected::class, [CreateChainLinkFromHint::class, 'handle']);
    }

    // Resolves the View Factory contract through $this->app->make() rather
    // than the view() global helper, keeping the project's DI-only
    // posture visible at the call site.
    private function registerTopNavBadgeComposer(): void
    {
        $app = $this->app;
        $factory = $app->make(ViewFactoryContract::class);

        $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
            $currentUser = $app->make(CurrentUser::class);
            if (! $currentUser->isAuthenticated()) {
                $compose->with('chainOpenCandidateCount', 0);

                return;
            }
            $query = $app->make(ChainLinkQuery::class);
            $compose->with('chainOpenCandidateCount', $query->openCandidateCount($currentUser->user()));
        });
    }

    // Flips the most recent running chain_resolution_runs row for the
    // job's user to failed when the queue worker exhausts the retry
    // budget. Uses the injected Dispatcher::listen() rather than the
    // Queue::failing facade to keep larastan-strict-rules' noFacade rule satisfied.
    private function registerJobFailedListener(Dispatcher $events): void
    {
        $app = $this->app;

        $events->listen(JobFailed::class, function (JobFailed $event) use ($app): void {
            $jobName = $event->job->resolveName();
            if (! str_contains($jobName, 'ResolveChainLinksJob')) {
                return;
            }

            $userId = $this->extractUserIdFromFailedJob($event);
            if ($userId === null) {
                return;
            }

            $db = $app->make(DatabaseManager::class);
            $clock = $app->make(Clock::class);
            $now = $clock->now()->toDateTimeString();

            $messageLines = preg_split('/\r?\n/', $event->exception->getMessage());
            $firstLine = is_array($messageLines) && $messageLines !== [] ? $messageLines[0] : '';
            $lastError = substr(
                $event->exception::class.': '.$firstLine,
                0,
                500,
            );

            $db->connection()
                ->table('chain_resolution_runs')
                ->where('user_id', $userId)
                ->where('status', JobRunStatus::Running->value)
                ->orderByDesc('id')
                ->limit(1)
                ->update([
                    'status' => JobRunStatus::Failed->value,
                    'completed_at' => $now,
                    'last_error' => $lastError,
                    'updated_at' => $now,
                ]);
        });
    }

    // The job class declares public readonly int $userId, so a defensive
    // regex isolates the integer value across both the compact and
    // named-arg serialiser shapes.
    private function extractUserIdFromFailedJob(JobFailed $event): ?int
    {
        $data = $event->job->payload()['data'] ?? null;
        $command = is_array($data) ? ($data['command'] ?? null) : null;

        if (! is_string($command) || preg_match('/userId[^0-9-]+(-?\d+)/', $command, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
