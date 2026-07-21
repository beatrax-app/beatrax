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
 * @link ../../../../.docs/features/chains/architecture.md
 */
final class ResolveChainLinksJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    // Backoff delays in seconds between the three retry attempts above:
    // 1 minute, 5 minutes, 15 minutes.
    /** @var array<int, int> */
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

        // Healing passes run before the chain resolvers — see architecture.md
        // § Healing passes for why each one has to precede resolution.
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
