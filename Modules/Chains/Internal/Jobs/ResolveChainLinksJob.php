<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

/**
 * The first queued job in the project. Runs both Phase 5 resolvers
 * (ICS bulk-iDEAL decomposition + PayPal funding chain) for one user
 * at a time.
 *
 * Concurrency contract:
 *  - `ShouldBeUniqueUntilProcessing` keyed on `uniqueId() = userId`
 *    prevents two parallel passes for the same user — the lock is
 *    released the moment a worker begins `handle()`, leaving a fresh
 *    dispatch room to enqueue while the prior pass finishes.
 *  - `tries = 3` + `backoff = [60, 300, 900]` per D-103 — three
 *    retries with 1m / 5m / 15m delays before final-fail.
 *
 * Audit-row lifecycle (issue #1 + #8):
 *  - `ConfirmImport` inserts a `pending` row before dispatching so the
 *    wizard's wire:poll has a row to read from its first tick.
 *  - `handle()` flips the row to `running` with `started_at` set on
 *    work begin, then to `complete` with `linked_count` set when both
 *    resolvers finish without throwing.
 *  - A `JobFailed` listener registered in
 *    `ChainsServiceProvider::boot()` catches a final-retry exhaustion
 *    and flips the row to `failed` with a truncated `last_error` line.
 *
 * Queue-uniqueness lock resolution is delegated to the shared
 * `Modules\Core\Public\Support\LockStore` helper: `uniqueVia()`
 * returns `LockStore::forUniqueJobs()`, which resolves the cache store
 * named by `config('cache.locks_store')`.
 *
 * Dispatched from `Modules\Import\Public\Actions\ConfirmImport` AFTER
 * the import's outer DB transaction commits — never inside the
 * transaction closure. The queue driver does not share the SQLite
 * transaction frame, so an in-transaction dispatch would let the
 * worker see stale state.
 */
final class ResolveChainLinksJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry attempts before final failure (D-103). */
    public int $tries = 3;

    /**
     * Exponential backoff in seconds (D-103).
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        // 10-minute lock ceiling — long enough for any resolver pass
        // to finish, short enough that a worker crash unblocks the
        // next dispatch promptly.
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        RetypeByAliasResolver $retypeResolver,
        PairsTransferLegs $pairer,
        UpsertsCardStatements $cardStatementUpserter,
        IcsSettlementResolver $icsResolver,
        PaypalFundingResolver $paypalResolver,
    ): void {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        $now = $clock->now()->toDateTimeString();
        $jobId = $this->job?->getJobId();
        $runId = $db->connection()->table('chain_resolution_runs')->insertGetId([
            'user_id' => $this->userId,
            'job_uuid' => is_string($jobId) ? $jobId : null,
            'status' => 'running',
            'started_at' => $now,
            'linked_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $beforeCount = $db->connection()
            ->table('chain_links')
            ->where('user_id', $this->userId)
            ->count();

        // Healing passes — must run BEFORE the chain resolvers because
        // the downstream resolvers iterate `transfer_out` / `transfer_in`
        // rows AND read from `card_statements`. Without these three
        // passes the wizard-order race + the per-import card-statement
        // upsert race leave the ledger in a state where chain resolvers
        // iterate empty sets.
        //
        //   1. UpsertsCardStatements::upsertForUser — promotes every
        //      ICS-kind statement_summaries row for the user into a
        //      card_statements row, independent of import_run_id.
        //      Catches up legacy installs whose ConfirmImport path
        //      missed the per-import upsert (rolled-back transaction,
        //      pre-Phase-16.1.2.1 builds, or a stale packaged-app
        //      bundle that shipped without the Stage A unconditional
        //      call). Idempotent via the UNIQUE constraint inside
        //      insertOrIgnore.
        //
        //   2. RetypeByAliasResolver — flips `expense` / `income` rows
        //      whose counterparty_iban resolves through
        //      `known_counterparty_ibans` to one of the user's own
        //      accounts. Idempotent + self-healing for late-added aliases.
        //
        //   3. PairsTransferLegs::pairOrphansForUser — pairs any
        //      transfer leg whose partner is now persisted but never
        //      went through the per-row `TransactionImported` listener
        //      (which is the only path the legacy preview-import flow
        //      had for closing pair_transaction_id). The retyped rows
        //      above are the canonical case; legacy installs may also
        //      have orphans from before this code shipped.
        $cardStatementUpserter->upsertForUser($user);
        $retypeResolver->resolveForUser($user);
        $pairer->pairOrphansForUser($user);

        $icsResolver->resolveForUser($user);
        $paypalResolver->resolveForUser($user);

        $afterCount = $db->connection()
            ->table('chain_links')
            ->where('user_id', $this->userId)
            ->count();

        $completedAt = $clock->now()->toDateTimeString();
        $db->connection()
            ->table('chain_resolution_runs')
            ->where('id', $runId)
            ->update([
                'status' => 'complete',
                'completed_at' => $completedAt,
                'linked_count' => $afterCount - $beforeCount,
                'updated_at' => $completedAt,
            ]);
    }
}
