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
use Illuminate\Support\Facades\Cache;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

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
 * Single permitted facade exception: the `Cache::driver('redis')`
 * call inside `uniqueVia()`. The Laravel queue infrastructure invokes
 * `uniqueVia()` at queue-push time before constructor DI completes;
 * a constructor-injected `Repository` is not an option. The
 * `tests/Contracts/BoundaryArchTest.php` allow-list grants this file
 * FQN the "no Laravel facades in module code" exemption.
 *
 * Dispatched from `Modules\Import\Public\Actions\ConfirmImport` AFTER
 * the import's outer DB transaction commits — never inside the
 * transaction closure (RESEARCH Pitfall 3). The Redis queue driver
 * does not share the SQLite transaction frame, so an in-transaction
 * dispatch would let the worker see stale state.
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
        // The Cache facade is the single permitted facade use in
        // module code (BoundaryArchTest carve-out). Reason: Laravel
        // calls uniqueVia() before constructor DI completes — there
        // is no path to inject a Repository at this point.
        return Cache::driver('redis');
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
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
