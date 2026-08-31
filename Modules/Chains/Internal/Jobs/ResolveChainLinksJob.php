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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Core\Public\Support\LockStore;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

final class ResolveChainLinksJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        // Long enough for any resolver pass, short enough that a crashed
        // worker unblocks the next dispatch.
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
        $runIds = $this->claimPendingRuns($db, $now, $jobId);
        if ($runIds === []) {
            $runIds = [$db->connection()->table('chain_resolution_runs')->insertGetId([
                'user_id' => $this->userId,
                'job_uuid' => is_string($jobId) ? $jobId : null,
                'status' => JobRunStatus::Running->value,
                'started_at' => $now,
                'linked_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])];
        }

        $beforeCount = $db->connection()
            ->table('chain_links')
            ->where('user_id', $this->userId)
            ->count();

        // The three healing passes precede the resolvers because each one
        // produces rows — statements, retyped transfers, paired legs — that
        // the resolvers then iterate.
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
            ->whereIn('id', $runIds)
            ->update([
                'status' => JobRunStatus::Complete->value,
                'completed_at' => $completedAt,
                'linked_count' => $afterCount - $beforeCount,
                'updated_at' => $completedAt,
            ]);
    }

    // ConfirmImport reserves the `pending` row the wizard polls; a second row
    // here left that one pending forever. Every pending row is claimed, not
    // only the newest — a dispatch the unique lock refused leaves its
    // reservation behind, and this pass covers the work it stood for.
    /**
     * @return list<int>
     */
    private function claimPendingRuns(DatabaseManager $db, string $now, ?string $jobId): array
    {
        $pending = $db->connection()
            ->table('chain_resolution_runs')
            ->where('user_id', $this->userId)
            ->where('status', JobRunStatus::Pending->value)
            ->orderByDesc('id')
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (int|float|string $id): int => (int) $id)
            ->all();

        $pending = array_values($pending);
        if ($pending === []) {
            return [];
        }

        $db->connection()
            ->table('chain_resolution_runs')
            ->where('user_id', $this->userId)
            ->whereIn('id', $pending)
            ->update([
                'job_uuid' => $jobId,
                'status' => JobRunStatus::Running->value,
                'started_at' => $now,
                'updated_at' => $now,
            ]);

        return $pending;
    }
}
