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
use Modules\Chains\Internal\Http\Livewire\ChainHintsQueue;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Internal\Http\Livewire\ChainsIndex;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Listeners\CreateChainLinkFromHint;
use Modules\Chains\Internal\PaypalFundingSignatureKey;
use Modules\Chains\Internal\Services\BusChainResolutionDispatcher;
use Modules\Chains\Internal\Services\CardStatementUpserter;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Chains\Public\Http\Livewire\ChainDrawer;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Receipts\Public\Events\ChainHintDetected;

final class ChainsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(CardStatementStateMachine::class);
        $this->app->singleton(PaypalFundingSignatureKey::class);
        $this->app->singleton(ResolveChainLinksJob::class);
        $this->app->singleton(DispatchesChainResolution::class, BusChainResolutionDispatcher::class);
        $this->app->bind(UpsertsCardStatements::class, CardStatementUpserter::class);
        $this->app->singleton(CardStatementUpserter::class);

        $this->app->singleton(CardStatementQuery::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $this->loadModuleResources('chains');

        $livewire->component('chains.chain-drawer', ChainDrawer::class);
        $livewire->component('chains.chain-review-queue', ChainReviewQueue::class);
        $livewire->component('chains.chain-hints-queue', ChainHintsQueue::class);
        $livewire->component('chains.chains-index', ChainsIndex::class);

        $this->registerJobFailedListener($events);
        $this->registerNavBadgeComposer();

        // ChainHintDetected is dispatched from RecordReceipt after the
        // canonical transaction is persisted, so the FK constraint on
        // chain_links.from_transaction_id binds cleanly.
        $events->listen(ChainHintDetected::class, [CreateChainLinkFromHint::class, 'handle']);
    }

    // The per-boot $cache collapses repeated sidebar renders in one boot cycle
    // down to a single COUNT query, which the (user_id, state) index serves.
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
                $navCounts['chains'] = 0;
                $compose->with('navCounts', $navCounts);

                return;
            }

            $user = $currentUser->user();
            $userId = $user->id;
            if (! array_key_exists($userId, $cache)) {
                $query = $app->make(ChainLinkQuery::class);
                $cache[$userId] = $query->openCandidateCount($user);
            }

            $navCounts['chains'] = $cache[$userId];
            $compose->with('navCounts', $navCounts);
        });
    }

    // Injected Dispatcher::listen() rather than the Queue::failing facade,
    // which larastan-strict-rules' noFacade rule forbids.
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

            // Every row the claim would have taken, not the newest running one.
            // ConfirmImport reserves a `pending` row and claimPendingRuns()
            // takes ALL of them, so a job that threw before or between those
            // two left rows nothing else writes.
            $db->connection()
                ->table('chain_resolution_runs')
                ->where('user_id', $userId)
                ->whereIn('status', JobRunStatus::unfinishedValues())
                ->update([
                    'status' => JobRunStatus::Failed->value,
                    'completed_at' => $now,
                    'last_error' => $lastError,
                    'updated_at' => $now,
                ]);
        });
    }

    // The serialised command takes both a compact and a named-arg shape, so
    // $userId is matched out by regex rather than unserialised.
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
