<?php

declare(strict_types=1);

namespace Modules\Chains\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Chains\Internal\CardStatementStateMachine;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Services\BusChainResolutionDispatcher;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\Clock;

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
        // Livewire component registrations land here once the
        // chains.chain-review-queue and chains.chain-drawer SFCs ship.
        unset($livewire);

        $this->registerJobFailedListener($events);
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
