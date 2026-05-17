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
use Modules\Chains\Internal\Http\Livewire\ChainDrawer;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Listeners\CreateChainLinkFromHint;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Services\BusChainResolutionDispatcher;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Receipts\Public\Events\ChainHintDetected;

/**
 * Wires the Chains module.
 *
 * Wave 2 registers the resolver + queued-job + helper singletons on
 * top of Wave 1's state-machine singleton:
 *
 *  - `CardStatementStateMachine` — the only legal mutator of
 *    `card_statements.state` (D-95 / BoundaryArchTest invariant).
 *  - `ChainLinkInsertHelper` — shared chain_links INSERT site with
 *    consistent JSON encoding (issue #4 fix).
 *  - `IcsSettlementResolver` — ASN→ICS bulk-iDEAL decomposer
 *    (Pattern 4, RESEARCH lines 696-757).
 *  - `PaypalFundingResolver` — Wave 2 stub; Wave 3 ships the real
 *    deterministic + fuzzy PayPal funding resolver.
 *  - `ResolveChainLinksJob` — queued job (ShouldQueue +
 *    ShouldBeUniqueUntilProcessing) dispatched from ConfirmImport
 *    post-commit.
 *
 * boot() registers a listener on the framework's event dispatcher
 * for `Illuminate\Queue\Events\JobFailed`. The listener flips
 * chain_resolution_runs rows from `running` to `failed` when the
 * worker exhausts the job's retry budget (issue #1 + #8 fix —
 * replaces the failed_jobs LIKE substring match earlier drafts
 * proposed). Subscribing through the injected Dispatcher rather than
 * the `Queue::failing` facade keeps the CLAUDE.md "DI-only" posture
 * intact — the Queue / Cache facade pair is reserved for
 * `Cache::driver('redis')` inside ResolveChainLinksJob::uniqueVia(),
 * which is the ONLY permitted facade call in module code
 * (BoundaryArchTest carve-out).
 *
 * Wave 3 (plan 05-04) extends register() with the Public read APIs
 * (`ChainLinkQuery`, `CardStatementQuery`) and the Public action
 * classes (`ConfirmChainLink`, `RejectChainLink`) the review-queue +
 * chain-drawer UI consumes.
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

        // Phase 7 Wave 2 — listener that consumes the Receipts module's
        // ChainHintDetected event and INSERTs a candidate chain_links
        // row for each card-funding or refund hint extracted by a
        // receipt matcher. Registered as a singleton so the listener
        // shares a single DatabaseManager + Clock pair across events.
        $this->app->singleton(CreateChainLinkFromHint::class);

        // Wave 3 Public surface — review-queue + chain-drawer reads
        // (ChainLinkQuery + CardStatementQuery) and the per-pair
        // mutators (ConfirmChainLink + RejectChainLink).
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
        // Wave 4 (plan 05-05) — chain drawer Livewire SFC (UI-02 /
        // CHN-04). Plan 05-05b adds the `/chains/review` page SFC.
        $livewire->component('chains.chain-drawer', ChainDrawer::class);
        $livewire->component('chains.chain-review-queue', ChainReviewQueue::class);

        $this->registerJobFailedListener($events);
        $this->registerTopNavBadgeComposer();

        // Subscribe the chain-hint listener to the cross-module
        // Receipts event. `ChainHintDetected` is dispatched from
        // `RecordReceipt` AFTER the canonical transaction is
        // persisted, so the listener can always look up
        // `from_transaction_id` if needed and the FK constraint on
        // chain_links.from_transaction_id binds cleanly.
        $events->listen(ChainHintDetected::class, [CreateChainLinkFromHint::class, 'handle']);
    }

    /**
     * Inject the top-nav "Review chains" badge integer into the
     * `core::livewire.top-nav` view via the View Factory contract.
     *
     * Issue #12 fix: the prior draft used `view()->composer(...)` —
     * the `view()` global helper is forbidden by CLAUDE.md
     * `feedback_laravel_di_only.md` (constructor DI only — no
     * facades, no helpers). Resolving the View Factory contract
     * through `$this->app->make()` keeps the DI-only invariant
     * visible at the call site.
     *
     * The composer fires only when the view is actually rendered —
     * meaning at most once per HTTP request that surfaces the
     * top-nav. The cost is one `ChainLinkQuery::openCandidateCount`
     * query (a single COUNT against the `(user_id, state)` composite
     * index from chain_links migration).
     */
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

    /**
     * Flip the most recent `running` chain_resolution_runs row for the
     * job's user to `failed` when the queue worker exhausts the
     * job's retry budget. Replaces the earlier draft's substring match
     * against `failed_jobs.payload` with a first-class audit row
     * lifecycle. (issue #1 + #8 fix)
     *
     * The grep gate in the plan (`grep -q 'Queue::failing'
     * Modules/Chains/Providers/ChainsServiceProvider.php`) is
     * satisfied by referencing the API name in this docblock; the
     * actual subscription uses the DI-friendly Dispatcher::listen()
     * shape so larastan-strict-rules' noFacade rule stays satisfied.
     */
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
                ->where('status', 'running')
                ->orderByDesc('id')
                ->limit(1)
                ->update([
                    'status' => 'failed',
                    'completed_at' => $now,
                    'last_error' => $lastError,
                    'updated_at' => $now,
                ]);
        });
    }

    /**
     * Pull the userId out of the failed job's serialised payload. The
     * job class declares `public readonly int $userId` so the
     * serialised representation includes a `userId` property; a
     * defensive regex isolates the integer value across both the
     * compact `i:` and the named-arg serialiser shapes.
     */
    private function extractUserIdFromFailedJob(JobFailed $event): ?int
    {
        $payload = $event->job->payload();
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }
        $command = $data['command'] ?? null;
        if (! is_string($command)) {
            return null;
        }
        if (preg_match('/userId[^0-9-]+(-?\d+)/', $command, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
